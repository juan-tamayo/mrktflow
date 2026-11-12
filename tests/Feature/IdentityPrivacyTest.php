<?php

declare(strict_types=1);

use App\Domain\CheckoutOrders\Actions\ReadAdminOrders;
use App\Domain\CheckoutOrders\Actions\ResendOrderReceipt;
use App\Domain\CheckoutOrders\Actions\UpdateCart;
use App\Domain\CheckoutOrders\Support\CartOwner;
use App\Domain\Identity\Actions\CustomerAddresses;
use App\Domain\Identity\Actions\DownloadPrivacyExport;
use App\Domain\Identity\Actions\ProcessPrivacyRequest;
use App\Domain\Identity\Actions\PruneExpiredData;
use App\Domain\Identity\Actions\RegisterCustomer;
use App\Domain\Identity\Actions\RequestPrivacy;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\StaffPermissions;
use App\Domain\Platform\Actions\UpdateDeliveryPreference;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\Fixtures\CustomerRegistration;

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
    Queue::fake();
    Carbon::setTestNow(Carbon::parse('2026-10-16T14:00:00Z')->utc());
    config([
        'app.url' => 'http://127.0.0.1:8000',
        'services.stripe.secret' => 'sk_test_fixture',
        'services.stripe.account' => 'acct_fixture',
        'services.stripe.live' => false,
        'services.stripe.webhook_secret' => 'whsec_test_fixture',
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @return array{owner: StaffAccount, customer: CustomerAccount, order_id: string, password: string, formula: string, event_id: string}
 */
function identityPrivacyFixture(): array
{
    $catalog = refundCatalog();
    $formula = '=HYPERLINK("http://evil.test")';
    $password = CustomerRegistration::input()['password'];
    $customer = app(RegisterCustomer::class)->register(array_replace(CustomerRegistration::input(), [
        'name' => $formula,
        'email' => 'privacy-shopper@example.test',
    ]));
    $customer->forceFill(['email_verified_at' => now()])->save();
    $customer = $customer->fresh();
    expect($customer)->toBeInstanceOf(CustomerAccount::class);
    app(CustomerAddresses::class)->save($customer, [
        'label' => 'Home',
        'recipient' => $formula,
        'line1' => '123 Fictional Street',
        'line2' => null,
        'city' => 'Example City',
        'region' => 'CA',
        'postal_code' => '90001',
        'country_code' => 'US',
    ]);
    $contactId = app(UpdateDeliveryPreference::class)->ensureContact($customer->email, $customer->id);
    app(UpdateDeliveryPreference::class)->markVerified($contactId, 'customer_account', 'customer', $customer->id);
    $paid = refundPaidPhysical($catalog['physical_variant'], 'guest-privacy-live', 'cs_privacy_live', 'pi_privacy_live');
    DB::table('orders')->where('id', $paid['order_id'])->update([
        'customer_id' => $customer->id,
        'guest_token_hash' => null,
        'email' => $customer->email,
    ]);
    $signed = signedStripeEvent([
        'id' => 'evt_privacy_paid',
        'data' => [
            'object' => [
                'id' => 'cs_privacy_live',
                'object' => 'checkout.session',
                'customer_email' => $customer->email,
                'client_reference_id' => $paid['order_id'],
                'amount_total' => 2805,
                'currency' => 'usd',
            ],
        ],
    ]);
    postStripeWebhook($signed)->assertNoContent();
    $values = json_decode((string) DB::table('store_settings')->where('id', 1)->value('values'), true, flags: JSON_THROW_ON_ERROR);
    $values['retention'] = [
        'guest_carts_days' => 30,
        'ai_prompt_logs_days' => 30,
        'order_days' => 365,
    ];
    DB::table('store_settings')->where('id', 1)->update(['values' => json_encode($values, JSON_THROW_ON_ERROR)]);

    return [
        'owner' => $catalog['owner'],
        'customer' => $customer,
        'order_id' => $paid['order_id'],
        'password' => $password,
        'formula' => $formula,
        'event_id' => 'evt_privacy_paid',
        'variant' => $catalog['physical_variant'],
    ];
}

it('IdentityPrivacy verifies export and deletion, anonymizes profile, and retains orders receipts and payment dedup', function (): void {
    $fixture = identityPrivacyFixture();
    $customer = $fixture['customer'];
    $owner = $fixture['owner'];
    $unverified = app(RegisterCustomer::class)->register(array_replace(CustomerRegistration::input(), [
        'email' => 'unverified-privacy@example.test',
    ]));
    expect(fn () => app(RequestPrivacy::class)->request($unverified, [
        'type' => 'export',
        'current_password' => $fixture['password'],
    ]))->toThrow(AuthorizationException::class);
    expect(fn () => app(RequestPrivacy::class)->request($customer, [
        'type' => 'export',
        'current_password' => 'Wrong fictional passphrase',
    ]))->toThrow(ValidationException::class);

    $export = app(RequestPrivacy::class)->request($customer, [
        'type' => 'export',
        'current_password' => $fixture['password'],
    ]);
    $deletion = app(RequestPrivacy::class)->request($customer, [
        'type' => 'deletion',
        'current_password' => $fixture['password'],
    ]);
    expect($export['status'])->toBe('pending')->and($deletion['status'])->toBe('pending');
    expect(DB::table('outbox_events')->where('intent_key', 'audit:privacy-requested:'.$export['id'])->exists())->toBeTrue();

    $processedExport = app(ProcessPrivacyRequest::class)->process($owner, $export['id']);
    expect($processedExport['status'])->toBe('completed');
    $key = 'privacy/'.$export['id'].'.csv';
    expect(Storage::disk('local')->exists($key))->toBeTrue()
        ->and(Storage::disk('public')->exists($key))->toBeFalse();
    $csv = Storage::disk('local')->get($key);
    expect($csv)->toContain("'=HYPERLINK")
        ->and($csv)->toContain('privacy-shopper@example.test')
        ->and($csv)->not->toMatch('/(?:^|,)=HYPERLINK/m')
        ->and($csv)->not->toContain($customer->password);
    $download = app(DownloadPrivacyExport::class)->download($customer, $export['id']);
    expect($download['csv'])->toContain('privacy-shopper@example.test')
        ->and($download['headers']['Cache-Control'])->toContain('private')
        ->and($download['headers']['Cache-Control'])->toContain('no-store');

    $processedDeletion = app(ProcessPrivacyRequest::class)->process($owner, $deletion['id']);
    expect($processedDeletion['status'])->toBe('completed');
    $fresh = $customer->fresh();
    expect($fresh)->toBeInstanceOf(CustomerAccount::class)
        ->and($fresh->is_active)->toBeFalse()
        ->and($fresh->email)->not->toBe('privacy-shopper@example.test')
        ->and($fresh->email)->toEndWith('@privacy.invalid')
        ->and($fresh->name)->toBe('Deleted customer')
        ->and($fresh->email_verified_at)->toBeNull()
        ->and($fresh->session_version)->toBe(2)
        ->and(DB::table('customer_addresses')->where('customer_id', $customer->id)->count())->toBe(0);
    expect(Auth::guard('customer')->attempt([
        'email' => 'privacy-shopper@example.test',
        'password' => $fixture['password'],
        'is_active' => true,
    ]))->toBeFalse();

    $order = DB::table('orders')->where('id', $fixture['order_id'])->first();
    expect($order)->not->toBeNull()
        ->and($order->email)->toBe('privacy-shopper@example.test')
        ->and($order->customer_id)->toBe($customer->id)
        ->and($order->grand_total_amount)->toBeGreaterThan(0);
    expect(DB::table('payments')->where('order_id', $fixture['order_id'])->count())->toBe(1)
        ->and(DB::table('payment_attempts')->where('order_id', $fixture['order_id'])->count())->toBeGreaterThan(0)
        ->and(DB::table('provider_events')->where('event_id', $fixture['event_id'])->count())->toBe(1)
        ->and(DB::table('idempotency_records')->count())->toBeGreaterThan(0);
    $detail = app(ReadAdminOrders::class)->detail($owner, $fixture['order_id']);
    expect($detail['email'])->toBe('privacy-shopper@example.test')
        ->and($detail['totals']['grand_total']['amount'])->toBeGreaterThan(0);
    $resent = app(ResendOrderReceipt::class)->resend($owner, $fixture['order_id'], (string) Str::uuid());
    expect(DB::table('order_receipt_intents')->where('id', $resent['id'])->value('recipient'))->toBe('privacy-shopper@example.test');
    $contact = DB::table('delivery_contacts')->where('email', 'privacy-shopper@example.test')->first();
    expect($contact)->not->toBeNull();
    expect(DB::table('delivery_suppressions')->where('contact_id', $contact->id)->whereNull('cleared_at')->where('scope', 'global')->exists())->toBeTrue();

    $guest = app(UpdateCart::class)->replaceLine(CartOwner::guest('privacy-guest-stale'), [
        'variant_id' => $fixture['variant'],
        'quantity' => 1,
        'expected_revision' => 1,
    ]);
    DB::table('carts')->where('id', $guest['id'])->update([
        'created_at' => Carbon::parse('2026-09-01T14:00:00Z')->utc(),
        'updated_at' => Carbon::parse('2026-09-01T14:00:00Z')->utc(),
    ]);
    Carbon::setTestNow(Carbon::parse('2026-11-16T14:00:00Z')->utc());
    expect(fn () => app(DownloadPrivacyExport::class)->download($fresh, $export['id']))->toThrow(AuthorizationException::class);
    $pruned = app(PruneExpiredData::class)->prune();
    expect($pruned)->toBeGreaterThan(0)
        ->and(DB::table('carts')->where('id', $guest['id'])->exists())->toBeFalse()
        ->and(DB::table('orders')->where('id', $fixture['order_id'])->exists())->toBeTrue()
        ->and(DB::table('payments')->where('order_id', $fixture['order_id'])->exists())->toBeTrue()
        ->and(DB::table('provider_events')->where('event_id', $fixture['event_id'])->exists())->toBeTrue()
        ->and(Storage::disk('local')->exists($key))->toBeFalse();
    $this->get('/')->assertStatus(503);
});

it('IdentityPrivacy HTTP verifies the signed-in customer, lets staff process requests, and still requires owner 2FA', function (): void {
    $fixture = identityPrivacyFixture();
    $customer = $fixture['customer'];
    $owner = $fixture['owner'];
    $this->get('/admin/privacy')->assertRedirect('/admin/login');
    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1])
        ->getJson('/admin/privacy')->assertUnauthorized();
    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => $owner->session_version])
        ->get('/admin/privacy')->assertRedirect('/admin/two-factor');
    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1]);
    $this->get('/account/privacy')->assertOk()->assertSee('Export or delete');
    $this->from('/account/privacy')->post('/account/privacy', [
        'privacy' => ['type' => 'export', 'current_password' => $fixture['password']],
    ])->assertRedirect('/account/privacy');
    $this->from('/account/privacy')->post('/account/privacy', [
        'privacy' => ['type' => 'deletion', 'current_password' => $fixture['password']],
    ])->assertRedirect('/account/privacy');
    $exportId = (string) DB::table('privacy_requests')->where('type', 'export')->value('id');
    $deletionId = (string) DB::table('privacy_requests')->where('type', 'deletion')->value('id');

    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->get('/admin')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('AdminHome')
        ->where('canPrivacy', true));
    $this->get('/admin/privacy')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('PrivacyRequests')
        ->where('retention.order_days', 365)
        ->has('requests', 2));
    $this->from('/admin/privacy')->post('/admin/privacy/'.$exportId.'/process')->assertRedirect('/admin/privacy');
    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1]);
    $file = $this->get('/account/privacy/'.$exportId.'/download');
    $file->assertOk();
    expect($file->headers->get('content-type'))->toStartWith('text/csv')
        ->and($file->headers->get('cache-control'))->toContain('private')
        ->and($file->streamedContent())->toContain("'=HYPERLINK");

    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->from('/admin/privacy')->post('/admin/privacy/'.$deletionId.'/process')->assertRedirect('/admin/privacy');
    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1])
        ->get('/account/privacy')->assertRedirect('/account/login');

    $fulfillment = new StaffAccount;
    $fulfillment->forceFill([
        'name' => 'Shipper',
        'email' => 'shipper-privacy@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']),
        'role' => 'fulfillment',
        'permissions' => StaffPermissions::forRole('fulfillment'),
        'is_active' => true,
        'session_version' => 1,
    ])->save();
    $this->actingAs($fulfillment, 'staff')->withSession(catalogStaffSession($fulfillment))
        ->getJson('/admin/privacy')->assertForbidden();
    $this->get('/')->assertStatus(503);
});
