<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\AdjustStock;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Actions\VersionDigitalAsset;
use App\Domain\CheckoutOrders\Actions\AcceptQuote;
use App\Domain\CheckoutOrders\Actions\CreateCheckoutIntent;
use App\Domain\CheckoutOrders\Actions\CreateQuote;
use App\Domain\CheckoutOrders\Actions\UpdateCart;
use App\Domain\CheckoutOrders\Support\CartOwner;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Operations\Actions\ReadInstallationRunbook;
use App\Domain\Platform\Actions\InstallationPrerequisites;
use App\Domain\Platform\Actions\StoreMedia;
use App\Domain\Platform\Contracts\MediaUploadAuthorization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
    Carbon::setTestNow(Carbon::parse('2026-11-12T22:00:00Z')->utc());
    config(['app.url' => 'http://127.0.0.1:8000']);
    Http::fake(fn () => throw new RuntimeException('external providers must not be called during install rehearsal'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @return array{owner: StaffAccount, physical_variant: string, digital_variant: string}
 */
function operationsInstallSandboxCatalog(StaffAccount $owner): array
{
    shippingSavedRules($owner, [
        'zones' => [shippingZoneInput(['methods' => [shippingMethodInput(['amount' => 300])]])],
    ]);
    pricingSavedTaxRules($owner);
    pricingSavedCoupon($owner, [
        'code' => 'FREE100',
        'percent_numerator' => 100,
        'percent_denominator' => 100,
        'max_redemptions' => 25,
        'max_redemptions_per_customer' => null,
    ]);
    $image = catalogReadyImage($owner->id);
    $physical = app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'status' => 'published', 'price_amount' => 2500,
    ]));
    $physicalVariant = (string) DB::table('product_variants')->where('product_id', $physical['id'])->value('id');
    app(AdjustStock::class)->adjust($owner, [
        'variant_id' => $physicalVariant,
        'operation_key' => (string) Str::uuid(),
        'tracks_inventory' => true,
        'on_hand' => 4,
        'low_stock_threshold' => 1,
    ]);
    $digital = app(SaveProduct::class)->save($owner, catalogDigitalProductInput($image, [
        'status' => 'published', 'price_amount' => 500,
    ]));
    $digitalVariant = (string) DB::table('product_variants')->where('product_id', $digital['id'])->value('id');
    $ready = app(VersionDigitalAsset::class)->version($owner, [
        'product_id' => $digital['id'],
        'label' => 'Lighting guide',
        'file' => catalogPrivateTextFile(),
    ]);
    app(VersionDigitalAsset::class)->attach($owner, [
        'product_id' => $digital['id'],
        'variant_id' => $digitalVariant,
        'version_ids' => [$ready['id']],
    ]);

    return [
        'owner' => $owner,
        'physical_variant' => $physicalVariant,
        'digital_variant' => $digitalVariant,
    ];
}

function operationsInstallFreeOrder(string $guestToken, string $variantId, ?string $shipping): string
{
    $guest = CartOwner::guest($guestToken);
    $cart = app(UpdateCart::class)->replaceLine($guest, [
        'variant_id' => $variantId, 'quantity' => 1, 'expected_revision' => 1,
    ]);
    $input = checkoutQuoteInput($cart['revision'], ['coupon' => 'FREE100']);
    if ($shipping === null) {
        $input['shipping'] = null;
    }
    $quote = app(CreateQuote::class)->create($guest, $input);
    expect($quote['grand_total']['amount'])->toBe(0);
    app(AcceptQuote::class)->accept($guest, $quote['id'], $quote['fingerprint'], true);
    $intent = app(CreateCheckoutIntent::class)->create($guest, $quote['id'], (string) Str::uuid());
    expect($intent['zero_total'])->toBeTrue()
        ->and($intent['order_status'])->toBe('confirmed')
        ->and($intent['attempt_status'])->toBe('succeeded')
        ->and(DB::table('payment_attempts')->where('id', $intent['id'])->value('provider'))->toBe('local');

    return $intent['order_id'];
}

it('OperationsInstall runbook documents the supported host, services, permissions, and 30-minute owner path', function (): void {
    $guide = app(ReadInstallationRunbook::class)->read();
    expect($guide['target_setup_seconds'])->toBe(1800)
        ->and($guide['complete'])->toBeTrue()
        ->and($guide['documents'])->toHaveKeys(['installation', 'hosting', 'release_evidence', 'environment_example'])
        ->and($guide['missing'])->toBe([]);
    foreach ($guide['documents'] as $document) {
        expect($document['exists'])->toBeTrue()
            ->and($document['missing_headings'])->toBe([]);
    }
    expect($guide['environment']['debug_default'])->toBe('false')
        ->and($guide['environment']['mailer_default'])->toBe('array')
        ->and($guide['environment']['app_key'])->toBe('')
        ->and($guide['environment']['database_password'])->toBe('')
        ->and($guide['environment']['stripe_secret'])->toBe('')
        ->and($guide['environment']['forbids_secrets'])->toBeTrue();
});

it('OperationsInstall refuses a publicly claimable first owner before the CLI secret', function (): void {
    $this->get('/')->assertStatus(503)->assertSee('This store is getting ready');
    $this->get('/setup')->assertNotFound();
    $this->postJson('/setup/owner', ['owner' => catalogOwnerInput()])->assertForbidden();
    $this->postJson('/setup/access', ['setup_secret' => str_repeat('0', 64)])->assertStatus(422);
    expect(DB::table('staff_accounts')->count())->toBe(0)
        ->and(DB::table('installations')->value('status'))->not->toBe('complete');
});

it('OperationsInstall rehearses clean owner setup, private-file denial, and physical digital mixed sandbox purchases', function (): void {
    $started = hrtime(true);
    $this->get('/')->assertStatus(503);
    $this->get('/setup')->assertNotFound();
    $this->postJson('/setup/owner', ['owner' => catalogOwnerInput()])->assertForbidden();

    $required = collect(app(InstallationPrerequisites::class)->report())->where('required', true);
    expect($required->every(fn (array $check): bool => $check['passed']))->toBeTrue();

    Artisan::call('setup:secret');
    $secret = collect(explode("\n", Artisan::output()))
        ->map(fn (string $line): string => trim($line))
        ->first(fn (string $line): bool => (bool) preg_match('/^[0-9a-f]{64}$/D', $line));
    expect($secret)->toBeString()->toHaveLength(64);
    expect(json_encode(DB::table('installations')->first()))->not->toContain($secret);

    $this->get('/setup')->assertOk();
    $this->post('/setup/access', ['setup_secret' => $secret])->assertRedirect('/setup');
    $settings = json_decode((string) file_get_contents(base_path('tests/Fixtures/store-settings.json')), true, flags: JSON_THROW_ON_ERROR);
    $this->post('/setup/settings', ['settings' => $settings, 'version' => 0])->assertRedirect('/setup');
    $this->post('/setup/owner', ['owner' => catalogOwnerInput()])->assertRedirect('/admin/two-factor');
    $this->get('/setup')->assertNotFound();
    $this->postJson('/setup/owner', ['owner' => catalogOwnerInput()])->assertForbidden();
    expect(fn () => Artisan::call('setup:secret'))->toThrow(HttpException::class);
    $this->get('/')->assertStatus(503);
    $this->assertAuthenticated('staff');
    expect(DB::table('installations')->value('status'))->toBe('complete')
        ->and(DB::table('installations')->value('secret_digest'))->toBeNull()
        ->and(DB::table('staff_accounts')->count())->toBe(1);

    $owner = StaffAccount::query()->where('email', 'owner@example.test')->firstOrFail();
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $assetId = app(StoreMedia::class)->store(catalogPrivateTextFile(), 'private_file', new class($owner->id) implements MediaUploadAuthorization
    {
        public function __construct(private readonly string $ownerId)
        {
        }

        public function authorizeUpload(): string
        {
            return $this->ownerId;
        }
    });
    $key = (string) DB::table('media_assets')->where('id', $assetId)->value('original_key');
    Auth::guard('staff')->logout();
    $this->flushSession();
    $this->get('/storage/'.$key)->assertNotFound();
    $this->get('/storage/private/'.$key)->assertNotFound();
    $this->get('/.env')->assertNotFound();

    $catalog = operationsInstallSandboxCatalog($owner);
    $physical = operationsInstallFreeOrder('guest-install-physical', $catalog['physical_variant'], 'required');
    $digital = operationsInstallFreeOrder('guest-install-digital', $catalog['digital_variant'], null);
    $guest = CartOwner::guest('guest-install-mixed');
    $cart = app(UpdateCart::class)->replaceLine($guest, [
        'variant_id' => $catalog['physical_variant'], 'quantity' => 1, 'expected_revision' => 1,
    ]);
    $cart = app(UpdateCart::class)->replaceLine($guest, [
        'variant_id' => $catalog['digital_variant'], 'quantity' => 1, 'expected_revision' => $cart['revision'],
    ]);
    $quote = app(CreateQuote::class)->create($guest, checkoutQuoteInput($cart['revision'], ['coupon' => 'FREE100']));
    expect($quote['grand_total']['amount'])->toBe(0);
    app(AcceptQuote::class)->accept($guest, $quote['id'], $quote['fingerprint'], true);
    $mixed = app(CreateCheckoutIntent::class)->create($guest, $quote['id'], (string) Str::uuid());
    $orderIds = [$physical, $digital, $mixed['order_id']];
    expect($orderIds)->each->not->toBe('')
        ->and(DB::table('orders')->whereIn('id', $orderIds)->pluck('status')->unique()->values()->all())->toBe(['confirmed'])
        ->and((int) DB::table('order_lines')->where('order_id', $mixed['order_id'])->count())->toBe(2);

    $elapsedSeconds = (hrtime(true) - $started) / 1_000_000_000;
    expect($elapsedSeconds)->toBeLessThan(app(ReadInstallationRunbook::class)->read()['target_setup_seconds'])
        ->and(config('mail.default'))->toBe('array')
        ->and(DB::table('customer_accounts')->count())->toBe(0);
});

it('OperationsInstall CLI prints the runbook checklist without secrets', function (): void {
    $this->artisan('operations:install-check')
        ->expectsOutputToContain('target_setup_seconds: 1800')
        ->expectsOutputToContain('installation: ready')
        ->expectsOutputToContain('hosting: ready')
        ->expectsOutputToContain('homepage: HTTP 503 until the storefront-open slice')
        ->assertSuccessful();
    expect(Artisan::output())->not->toContain('Fictional owner passphrase')
        ->and(Artisan::output())->not->toMatch('/[0-9a-f]{64}/');
});
