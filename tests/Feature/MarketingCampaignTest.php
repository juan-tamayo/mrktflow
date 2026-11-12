<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\SaveCategory;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\StaffPermissions;
use App\Domain\Marketing\Actions\ActivateCampaign;
use App\Domain\Marketing\Actions\PauseCampaign;
use App\Domain\Marketing\Actions\PreviewAudience;
use App\Domain\Marketing\Actions\PreviewSchedule;
use App\Domain\Marketing\Actions\SaveCampaign;
use App\Domain\Platform\Actions\UpdateDeliveryPreference;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->withoutVite();
    Carbon::setTestNow(Carbon::parse('2026-11-12T18:00:00Z')->utc());
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function t092Staff(string $email, array $permissions): StaffAccount
{
    $staff = new StaffAccount;
    $staff->forceFill([
        'name' => 'Campaign Staff',
        'email' => $email,
        'password' => Hash::make(catalogOwnerInput()['password']),
        'role' => 'content_editor',
        'permissions' => $permissions,
        'is_active' => true,
        'session_version' => 1,
    ])->save();

    return $staff;
}

function t092Contact(string $email, bool $verified = true, ?string $purpose = 'newsletter', ?string $customerId = null): string
{
    $prefs = app(UpdateDeliveryPreference::class);
    $id = $prefs->ensureContact($email, $customerId);
    if ($verified) {
        $prefs->markVerified($id, 'double_opt_in');
    }
    if ($purpose !== null && $verified) {
        $prefs->grant($id, $purpose, 'preference_center', 'account_verified');
    }

    return $id;
}

/** @param array<string, mixed> $overrides */
function t092CampaignInput(array $overrides = []): array
{
    return array_replace_recursive([
        'name' => 'November studio drop',
        'subject' => 'New lamps this week',
        'body_text' => 'Verified subscribers only. One-click unsubscribe stays in the footer later.',
        'purpose' => 'newsletter',
        'audience' => [
            'customer_status' => 'any',
            'category_ids' => [],
            'purchased_after' => null,
            'purchased_before' => null,
            'previous_campaign_id' => null,
            'previous_campaign_mode' => 'any',
        ],
        'schedule' => ['send_at' => '2026-11-13T12:00:00Z'],
        'caps' => [
            'max_recipients' => 2,
            'per_contact_24h' => 2,
            'per_contact_7d' => 3,
        ],
        'offer_reference' => null,
    ], $overrides);
}

function t092PaidOrder(string $email, string $productId, string $variantId, ?string $customerId, string $at): string
{
    $cartId = (string) Str::uuid();
    $quoteId = (string) Str::uuid();
    $orderId = (string) Str::uuid();
    $now = Carbon::parse($at)->utc();
    $guestHash = $customerId === null ? hash('sha256', 'campaign-guest:'.$email) : null;
    DB::table('carts')->insert([
        'id' => $cartId,
        'customer_id' => $customerId,
        'guest_token_hash' => $guestHash,
        'revision' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('checkout_quotes')->insert([
        'id' => $quoteId,
        'cart_id' => $cartId,
        'cart_revision' => 1,
        'customer_id' => $customerId,
        'guest_token_hash' => $guestHash,
        'fingerprint' => hash('sha256', $orderId),
        'quoted_at' => $now,
        'expires_at' => $now->copy()->addMinutes(15),
        'payload' => Crypt::encryptString('{}'),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('orders')->insert([
        'id' => $orderId,
        'number' => 'MF-C'.substr(str_replace('-', '', $orderId), 0, 8),
        'cart_id' => $cartId,
        'cart_revision' => 1,
        'quote_id' => $quoteId,
        'customer_id' => $customerId,
        'guest_token_hash' => $guestHash,
        'email' => $email,
        'status' => 'confirmed',
        'financial_status' => 'paid',
        'fulfillment_status' => 'unfulfilled',
        'download_status' => 'not_required',
        'currency' => 'USD',
        'exponent' => 2,
        'merchandise_amount' => 2499,
        'discount_amount' => 0,
        'shipping_amount' => 0,
        'tax_amount' => 0,
        'grand_total_amount' => 2499,
        'price_mode' => 'exclusive',
        'fingerprint' => hash('sha256', $orderId),
        'zero_total' => false,
        'payload' => Crypt::encryptString('{}'),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('order_lines')->insert([
        'id' => (string) Str::uuid(),
        'order_id' => $orderId,
        'cart_line_id' => (string) Str::uuid(),
        'variant_id' => $variantId,
        'product_id' => $productId,
        'type' => 'physical',
        'sku' => 'CAMP-SKU',
        'title' => 'Campaign fixture',
        'quantity' => 1,
        'subtotal_amount' => 2499,
        'discount_amount' => 0,
        'total_amount' => 2499,
        'tax_class_key' => 'standard',
        'created_at' => $now,
    ]);

    return $orderId;
}

it('MarketingCampaign drafts versioned campaigns and previews verified opt-in audiences with caps', function (): void {
    $owner = catalogInstalledOwner();
    t092Contact('unverified@example.test', false, null);
    t092Contact('alerts@example.test', true, 'stock_alert');
    $suppressed = t092Contact('stopped@example.test');
    app(UpdateDeliveryPreference::class)->suppress($suppressed, 'global', 'opt_out', 'preference_center');
    t092Contact('one@example.test');
    t092Contact('two@example.test');
    t092Contact('three@example.test');
    $saved = app(SaveCampaign::class)->save($owner, t092CampaignInput());
    expect($saved['revision'])->toBe(1)
        ->and($saved['status'])->toBe('draft')
        ->and(DB::table('marketing_campaigns')->count())->toBe(1)
        ->and(DB::table('campaign_versions')->count())->toBe(1);
    $preview = app(PreviewAudience::class)->preview($owner, $saved['id']);
    expect($preview['eligible'])->toBe(3)
        ->and($preview['would_send'])->toBe(2)
        ->and($preview['capped'])->toBe(1)
        ->and($preview['excluded']['unverified'])->toBe(1)
        ->and($preview['excluded']['no_grant'])->toBe(1)
        ->and($preview['excluded']['suppressed'])->toBe(1)
        ->and($preview)->not->toHaveKey('emails');
    $updated = app(SaveCampaign::class)->save($owner, t092CampaignInput([
        'id' => $saved['id'],
        'version' => 1,
        'subject' => 'Updated lamps',
    ]));
    expect($updated['revision'])->toBe(2)
        ->and(DB::table('campaign_versions')->where('campaign_id', $saved['id'])->count())->toBe(2);
    expect(fn () => app(SaveCampaign::class)->save($owner, t092CampaignInput([
        'id' => $saved['id'],
        'version' => 1,
        'subject' => 'Stale',
    ])))->toThrow(HttpException::class);
    expect(DB::table('campaign_versions')->where('campaign_id', $saved['id'])->orderByDesc('version')->value('subject'))
        ->toBe('Updated lamps');
    expect(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(0);
    $this->get('/')->assertStatus(503);
});

it('MarketingCampaign filters customer status, category interest, purchase windows and previous campaign activity', function (): void {
    $owner = catalogInstalledOwner();
    $image = catalogReadyImage($owner->id);
    $lighting = app(SaveCategory::class)->save($owner, catalogCategoryInput(['status' => 'published']));
    $lamps = app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'status' => 'published', 'category_ids' => [$lighting['id']],
    ]));
    $lampVariant = (string) DB::table('product_variants')->where('product_id', $lamps['id'])->value('id');
    $buyer = new CustomerAccount;
    $buyer->forceFill([
        'name' => 'Buyer', 'email' => 'buyer@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']),
        'is_active' => true, 'session_version' => 1, 'email_verified_at' => now(),
    ])->save();
    $newbie = new CustomerAccount;
    $newbie->forceFill([
        'name' => 'New', 'email' => 'new@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']),
        'is_active' => true, 'session_version' => 1, 'email_verified_at' => now(),
    ])->save();
    t092Contact('guest@example.test');
    t092Contact('buyer@example.test', true, 'newsletter', $buyer->id);
    t092Contact('new@example.test', true, 'newsletter', $newbie->id);
    t092PaidOrder('buyer@example.test', $lamps['id'], $lampVariant, $buyer->id, '2026-11-01T00:00:00Z');
    t092PaidOrder('guest@example.test', $lamps['id'], $lampVariant, null, '2026-10-01T00:00:00Z');
    $first = app(SaveCampaign::class)->save($owner, t092CampaignInput(['name' => 'First blast', 'caps' => ['max_recipients' => 50]]));
    app(ActivateCampaign::class)->activate($owner, $first['id'], 1);
    $customersOnly = app(SaveCampaign::class)->save($owner, t092CampaignInput([
        'name' => 'Customers',
        'audience' => ['customer_status' => 'customer'],
        'caps' => ['max_recipients' => 50],
    ]));
    expect(app(PreviewAudience::class)->preview($owner, $customersOnly['id'])['eligible'])->toBe(2);
    $newOnly = app(SaveCampaign::class)->save($owner, t092CampaignInput([
        'name' => 'New customers',
        'audience' => ['customer_status' => 'new_customer'],
        'caps' => ['max_recipients' => 50],
    ]));
    expect(app(PreviewAudience::class)->preview($owner, $newOnly['id'])['eligible'])->toBe(1);
    $window = app(SaveCampaign::class)->save($owner, t092CampaignInput([
        'name' => 'Recent lamps',
        'audience' => [
            'category_ids' => [$lighting['id']],
            'purchased_after' => '2026-10-15T00:00:00Z',
            'purchased_before' => '2026-11-12T00:00:00Z',
        ],
        'caps' => ['max_recipients' => 50],
    ]));
    expect(app(PreviewAudience::class)->preview($owner, $window['id'])['eligible'])->toBe(1);
    $excludedPrior = app(SaveCampaign::class)->save($owner, t092CampaignInput([
        'name' => 'Not first blast',
        'audience' => [
            'previous_campaign_id' => $first['id'],
            'previous_campaign_mode' => 'excluded',
        ],
        'caps' => ['max_recipients' => 50],
    ]));
    expect(app(PreviewAudience::class)->preview($owner, $excludedPrior['id'])['eligible'])->toBe(0);
    expect(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(0);
});

it('MarketingCampaign keeps draft, activate and send permissions distinct and never auto-sends', function (): void {
    $owner = catalogInstalledOwner();
    t092Contact('one@example.test');
    $editor = t092Staff('editor@example.test', ['admin.access', 'marketing.write']);
    $activator = t092Staff('activator@example.test', ['admin.access', 'marketing.activate']);
    $sender = t092Staff('sender@example.test', ['admin.access', 'marketing.send']);
    $fulfillment = t092Staff('ship@example.test', StaffPermissions::forRole('fulfillment'));
    expect($editor->hasPermission('marketing.activate'))->toBeFalse()
        ->and($editor->hasPermission('marketing.send'))->toBeFalse()
        ->and($activator->hasPermission('marketing.write'))->toBeFalse()
        ->and($activator->hasPermission('marketing.send'))->toBeFalse()
        ->and($sender->hasPermission('marketing.write'))->toBeFalse()
        ->and($owner->hasPermission('marketing.activate'))->toBeTrue();
    expect(fn () => app(SaveCampaign::class)->save($activator, t092CampaignInput()))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(SaveCampaign::class)->save($sender, t092CampaignInput()))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(SaveCampaign::class)->save($fulfillment, t092CampaignInput()))
        ->toThrow(AuthorizationException::class);
    $draft = app(SaveCampaign::class)->save($editor, t092CampaignInput());
    expect(fn () => app(ActivateCampaign::class)->activate($editor, $draft['id'], 1))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(ActivateCampaign::class)->activate($sender, $draft['id'], 1))
        ->toThrow(AuthorizationException::class);
    app(ActivateCampaign::class)->activate($activator, $draft['id'], 1);
    expect(DB::table('marketing_campaigns')->where('id', $draft['id'])->value('status'))->toBe('scheduled')
        ->and(DB::table('audience_snapshots')->where('campaign_id', $draft['id'])->where('eligible', true)->count())->toBe(1);
    expect(fn () => app(PauseCampaign::class)->pause($editor, $draft['id'], 2))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(PauseCampaign::class)->pause($sender, $draft['id'], 2))
        ->toThrow(AuthorizationException::class);
    $schedule = app(PreviewSchedule::class)->preview($sender, $draft['id']);
    expect($schedule['paused'])->toBeFalse()
        ->and($schedule['next_send_at'])->toBe('2026-11-13T12:00:00Z')
        ->and($schedule['due'])->toBeFalse()
        ->and($schedule['dispatched'])->toBeFalse();
    expect(fn () => app(PreviewSchedule::class)->dispatch($editor, $draft['id']))
        ->toThrow(AuthorizationException::class);
    $notDue = app(PreviewSchedule::class)->dispatch($sender, $draft['id']);
    expect($notDue['dispatched'])->toBeFalse()->and($notDue['reason'])->toBe('not_due');
    expect(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(0);
    expect(DB::table('marketing_campaigns')->count())->toBe(1);
});

it('MarketingCampaign paused schedules are not due and still do not deliver mail', function (): void {
    $owner = catalogInstalledOwner();
    t092Contact('one@example.test');
    t092Contact('two@example.test');
    $campaign = app(SaveCampaign::class)->save($owner, t092CampaignInput([
        'schedule' => ['send_at' => '2026-11-12T17:00:00Z'],
        'caps' => ['max_recipients' => 10],
    ]));
    app(ActivateCampaign::class)->activate($owner, $campaign['id'], 1);
    $due = app(PreviewSchedule::class)->preview($owner, $campaign['id']);
    expect($due['due'])->toBeTrue()->and($due['paused'])->toBeFalse();
    $held = app(PreviewSchedule::class)->dispatch($owner, $campaign['id']);
    expect($held['dispatched'])->toBeFalse()
        ->and($held['reason'])->toBe('batches_not_enabled')
        ->and($held['would_send'])->toBe(2);
    app(PauseCampaign::class)->pause($owner, $campaign['id'], 2);
    $paused = app(PreviewSchedule::class)->preview($owner, $campaign['id']);
    expect($paused['paused'])->toBeTrue()
        ->and($paused['due'])->toBeFalse()
        ->and($paused['next_send_at'])->toBeNull()
        ->and($paused['status'])->toBe('paused');
    $blocked = app(PreviewSchedule::class)->dispatch($owner, $campaign['id']);
    expect($blocked['dispatched'])->toBeFalse()->and($blocked['reason'])->toBe('paused');
    expect(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(0);
    $this->get('/')->assertStatus(503);
});

it('MarketingCampaign HTTP keeps privileged staff behind two-factor and rejects customers', function (): void {
    $owner = catalogInstalledOwner();
    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => 1]);
    $this->get('/admin/marketing/campaigns')->assertRedirect('/admin/two-factor');
    $this->postJson('/admin/marketing/campaigns', ['campaign' => t092CampaignInput()])->assertForbidden();
    auth('staff')->logout();
    $customer = new CustomerAccount;
    $customer->forceFill([
        'name' => 'Shopper', 'email' => 'shopper@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']),
        'is_active' => true, 'session_version' => 1,
    ])->save();
    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1])
        ->postJson('/admin/marketing/campaigns', ['campaign' => t092CampaignInput()])
        ->assertUnauthorized();
    $this->get('/')->assertStatus(503);
});

it('MarketingCampaign HTTP lets editors draft, activators schedule, and senders inspect without dispatching', function (): void {
    $owner = catalogInstalledOwner();
    t092Contact('one@example.test');
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->get('/admin')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('AdminHome')
        ->where('canManageMarketing', true));
    $this->get('/admin/marketing/campaigns/create')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('CampaignEdit')
        ->where('campaign', null)
        ->where('canWrite', true)
        ->where('canActivate', true)
        ->where('canSend', true));
    $created = $this->post('/admin/marketing/campaigns', ['campaign' => t092CampaignInput(['name' => 'HTTP drop'])]);
    $id = (string) DB::table('marketing_campaigns')->value('id');
    expect($id)->not->toBe('');
    $created->assertRedirect('/admin/marketing/campaigns/'.$id);
    $this->get('/admin/marketing/campaigns/'.$id)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('CampaignEdit')
        ->where('campaign.name', 'HTTP drop')
        ->where('campaign.status', 'draft'));
    $this->postJson('/admin/marketing/campaigns/'.$id.'/preview')->assertOk()
        ->assertJsonPath('eligible', 1);
    $this->post('/admin/marketing/campaigns/'.$id.'/activations', ['expected_version' => 1])
        ->assertRedirect('/admin/marketing/campaigns/'.$id);
    expect((string) DB::table('marketing_campaigns')->where('id', $id)->value('status'))->toBe('scheduled');
    $editor = t092Staff('draft@example.test', ['admin.access', 'marketing.write']);
    $this->actingAs($editor, 'staff')->withSession(catalogStaffSession($editor));
    $this->postJson('/admin/marketing/campaigns/'.$id.'/activations', ['expected_version' => 2])->assertForbidden();
    $this->postJson('/admin/marketing/campaigns/'.$id.'/sends')->assertForbidden();
    $sender = t092Staff('send@example.test', ['admin.access', 'marketing.send']);
    $this->actingAs($sender, 'staff')->withSession(catalogStaffSession($sender));
    $this->get('/admin/marketing/campaigns/'.$id)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('canWrite', false)
        ->where('canActivate', false)
        ->where('canSend', true));
    $this->postJson('/admin/marketing/campaigns', ['campaign' => t092CampaignInput(['name' => 'Nope'])])->assertForbidden();
    $this->postJson('/admin/marketing/campaigns/'.$id.'/sends')->assertOk()
        ->assertJsonPath('dispatched', false);
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->post('/admin/marketing/campaigns/'.$id.'/pause', ['expected_version' => 2])
        ->assertRedirect('/admin/marketing/campaigns/'.$id);
    $this->actingAs($sender, 'staff')->withSession(catalogStaffSession($sender));
    $this->postJson('/admin/marketing/campaigns/'.$id.'/sends')->assertOk()
        ->assertJsonPath('reason', 'paused');
    expect(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(0);
    foreach (['content_editor', 'fulfillment'] as $role) {
        $blocked = t092Staff($role.'@example.test', StaffPermissions::forRole($role));
        $this->actingAs($blocked, 'staff')->withSession(catalogStaffSession($blocked))
            ->get('/admin/marketing/campaigns')->assertForbidden();
    }
    $this->get('/')->assertStatus(503);
});

it('MarketingCampaign HTTP rejects forged campaign saves', function (): void {
    $owner = catalogInstalledOwner();
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->app->instance('env', 'local');
    $this->postJson('/admin/marketing/campaigns', ['campaign' => t092CampaignInput()])->assertStatus(419);
    $this->app->instance('env', 'testing');
});

it('MarketingCampaign rejects html templates, unknown filters and empty activation', function (): void {
    $owner = catalogInstalledOwner();
    expect(fn () => app(SaveCampaign::class)->save($owner, t092CampaignInput([
        'subject' => '<script>alert(1)</script>',
    ])))->toThrow(ValidationException::class);
    expect(fn () => app(SaveCampaign::class)->save($owner, t092CampaignInput([
        'body_text' => 'Hello <b>there</b>',
    ])))->toThrow(ValidationException::class);
    expect(fn () => app(SaveCampaign::class)->save($owner, t092CampaignInput([
        'audience' => ['sql' => 'select 1'],
    ])))->toThrow(ValidationException::class);
    $empty = app(SaveCampaign::class)->save($owner, t092CampaignInput());
    expect(fn () => app(ActivateCampaign::class)->activate($owner, $empty['id'], 1))
        ->toThrow(ValidationException::class);
    expect(DB::table('marketing_campaigns')->where('id', $empty['id'])->value('status'))->toBe('draft');
    expect(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(0);
});
