<?php

declare(strict_types=1);

use App\Domain\Fulfillment\Actions\VerifyGuestOrder;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Platform\Actions\UpdateDeliveryPreference;
use App\Domain\Platform\Support\DeliveryPolicy;
use App\Domain\Platform\Support\UnsubscribeTokens;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-16T18:00:00Z')->utc());
    config(['app.url' => 'http://127.0.0.1:8000']);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function prefVerifiedContact(string $email, string $evidence = 'double_opt_in'): string
{
    $prefs = app(UpdateDeliveryPreference::class);
    $contactId = $prefs->ensureContact($email);
    $prefs->markVerified($contactId, $evidence);

    return $contactId;
}

function prefCustomer(string $email, bool $verified = true): CustomerAccount
{
    $customer = new CustomerAccount;
    $customer->forceFill([
        'name' => 'Shopper', 'email' => $email,
        'password' => Hash::make(catalogOwnerInput()['password']),
        'is_active' => true, 'session_version' => 1,
        'email_verified_at' => $verified ? now() : null,
    ])->save();

    return $customer;
}

it('MarketingPreferences records purpose grants only for verified contacts, with provenance, and replays are no-ops', function (): void {
    $prefs = app(UpdateDeliveryPreference::class);
    $contactId = $prefs->ensureContact('  Shopper@Example.TEST ');
    $contact = DB::table('delivery_contacts')->where('id', $contactId)->first();
    expect($contact->email)->toBe('shopper@example.test')
        ->and($contact->email_verified_at)->toBeNull();
    expect($prefs->ensureContact('shopper@example.test'))->toBe($contactId);
    expect(fn () => $prefs->grant($contactId, 'newsletter', 'preference_center', 'account_verified'))
        ->toThrow(DomainException::class);
    expect($prefs->markVerified($contactId, 'double_opt_in'))->toBeTrue();
    expect($prefs->markVerified($contactId, 'double_opt_in'))->toBeFalse();
    expect($prefs->grant($contactId, 'newsletter', 'preference_center', 'account_verified'))->toBeTrue();
    $grant = DB::table('purpose_grants')->where('contact_id', $contactId)->where('purpose', 'newsletter')->first();
    expect($grant->source)->toBe('preference_center')
        ->and($grant->evidence)->toBe('account_verified')
        ->and($grant->notice_revision)->toBe(UpdateDeliveryPreference::NOTICE_REVISION)
        ->and($grant->granted_at)->not->toBeNull()
        ->and($grant->revoked_at)->toBeNull();
    $audits = DB::table('outbox_events')->where('event_type', 'platform.audit.recorded')->count();
    expect($prefs->grant($contactId, 'newsletter', 'preference_center', 'account_verified'))->toBeFalse();
    expect(DB::table('purpose_grants')->where('contact_id', $contactId)->count())->toBe(1);
    expect(DB::table('purpose_grants')->where('id', $grant->id)->value('granted_at'))->toBe($grant->granted_at);
    expect(DB::table('outbox_events')->where('event_type', 'platform.audit.recorded')->count())->toBe($audits);
    $policy = app(DeliveryPolicy::class);
    expect($policy->marketingAllowed('shopper@example.test', 'newsletter'))->toBeTrue()
        ->and($policy->marketingAllowed('shopper@example.test', 'stock_alert'))->toBeFalse();
});

it('MarketingPreferences never treats a captured checkout email as an opt-in', function (): void {
    $prefs = app(UpdateDeliveryPreference::class);
    $contactId = $prefs->ensureContact('buyer@example.test');
    $prefs->markVerified($contactId, 'checkout_receipt');
    $policy = app(DeliveryPolicy::class);
    foreach (DeliveryPolicy::PURPOSES as $purpose) {
        expect($policy->marketingAllowed('buyer@example.test', $purpose))->toBeFalse();
    }
    expect(DB::table('purpose_grants')->where('contact_id', $contactId)->count())->toBe(0);
});

it('MarketingPreferences blocks one transport under a transport suppression and everything under a global one, idempotently', function (): void {
    $prefs = app(UpdateDeliveryPreference::class);
    $contactId = prefVerifiedContact('scoped@example.test');
    $prefs->grant($contactId, 'newsletter', 'preference_center', 'account_verified');
    $prefs->grant($contactId, 'stock_alert', 'preference_center', 'account_verified');
    $policy = app(DeliveryPolicy::class);
    expect($prefs->suppress($contactId, 'sms', 'opt_out', 'preference_center'))->toBeTrue();
    expect($prefs->suppress($contactId, 'sms', 'opt_out', 'preference_center'))->toBeFalse();
    expect(DB::table('delivery_suppressions')->where('contact_id', $contactId)->whereNull('cleared_at')->count())->toBe(1);
    expect($policy->marketingAllowed('scoped@example.test', 'newsletter', 'email'))->toBeTrue()
        ->and($policy->marketingAllowed('scoped@example.test', 'newsletter', 'sms'))->toBeFalse();
    expect($prefs->suppress($contactId, 'global', 'opt_out', 'preference_center'))->toBeTrue();
    expect($policy->marketingAllowed('scoped@example.test', 'newsletter', 'email'))->toBeFalse()
        ->and($policy->marketingAllowed('scoped@example.test', 'stock_alert', 'email'))->toBeFalse();
});

it('MarketingPreferences provider feedback suppresses globally and replays safely, and only a deliberate verified-channel grant clears an opt-out suppression', function (): void {
    $prefs = app(UpdateDeliveryPreference::class);
    $policy = app(DeliveryPolicy::class);
    $complained = prefVerifiedContact('complained@example.test');
    $prefs->grant($complained, 'newsletter', 'preference_center', 'account_verified');
    expect($prefs->recordProviderFeedback('complained@example.test', 'complaint'))->toBeTrue();
    expect($prefs->recordProviderFeedback('complained@example.test', 'complaint'))->toBeFalse();
    expect(DB::table('delivery_suppressions')->where('contact_id', $complained)->where('scope', 'global')->whereNull('cleared_at')->count())->toBe(1);
    expect($policy->marketingAllowed('complained@example.test', 'newsletter'))->toBeFalse();
    $prefs->grant($complained, 'stock_alert', 'import', 'imported_consent');
    expect($policy->marketingAllowed('complained@example.test', 'stock_alert'))->toBeFalse()
        ->and(DB::table('delivery_suppressions')->where('contact_id', $complained)->whereNull('cleared_at')->count())->toBe(1);
    $optedOut = prefVerifiedContact('returning@example.test');
    $prefs->grant($optedOut, 'newsletter', 'preference_center', 'account_verified');
    $prefs->revokeAll($optedOut, 'preference_center');
    $prefs->suppress($optedOut, 'global', 'opt_out', 'preference_center');
    $prefs->grant($optedOut, 'price_alert', 'import', 'imported_consent');
    expect($policy->marketingAllowed('returning@example.test', 'price_alert'))->toBeFalse();
    $prefs->grant($optedOut, 'price_alert', 'preference_center', 'account_verified');
    expect($policy->marketingAllowed('returning@example.test', 'price_alert'))->toBeTrue()
        ->and(DB::table('delivery_suppressions')->where('contact_id', $optedOut)->where('scope', 'global')->whereNotNull('cleared_at')->count())->toBe(1);
});

it('MarketingPreferences signed unsubscribe: a scanner GET changes nothing and the cookie-free POST revokes only its purpose, replay-safe', function (): void {
    catalogInstalledOwner();
    $prefs = app(UpdateDeliveryPreference::class);
    $contactId = prefVerifiedContact('unsub@example.test');
    $prefs->grant($contactId, 'newsletter', 'preference_center', 'account_verified');
    $prefs->grant($contactId, 'stock_alert', 'preference_center', 'account_verified');
    $token = app(UnsubscribeTokens::class)->issue($contactId, 'newsletter');
    $this->get('/preferences/unsubscribe?token='.$token)->assertOk();
    expect(DB::table('purpose_grants')->where('contact_id', $contactId)->where('purpose', 'newsletter')->value('revoked_at'))->toBeNull();
    $this->post('/preferences/unsubscribe', ['token' => $token])->assertOk();
    $grant = DB::table('purpose_grants')->where('contact_id', $contactId)->where('purpose', 'newsletter')->first();
    expect($grant->revoked_at)->not->toBeNull()
        ->and($grant->revoked_source)->toBe('unsubscribe_link');
    expect(DB::table('purpose_grants')->where('contact_id', $contactId)->where('purpose', 'stock_alert')->value('revoked_at'))->toBeNull();
    $this->post('/preferences/unsubscribe', ['token' => $token])->assertOk();
    expect(DB::table('purpose_grants')->where('contact_id', $contactId)->where('purpose', 'newsletter')->value('revoked_at'))->toBe($grant->revoked_at);
    $policy = app(DeliveryPolicy::class);
    expect($policy->marketingAllowed('unsub@example.test', 'newsletter'))->toBeFalse()
        ->and($policy->marketingAllowed('unsub@example.test', 'stock_alert'))->toBeTrue();
});

it('MarketingPreferences one-click unsubscribe accepts the token in the URL as providers send it', function (): void {
    catalogInstalledOwner();
    $prefs = app(UpdateDeliveryPreference::class);
    $contactId = prefVerifiedContact('oneclick@example.test');
    $prefs->grant($contactId, 'cart_recovery', 'preference_center', 'account_verified');
    $token = app(UnsubscribeTokens::class)->issue($contactId, 'cart_recovery');
    $this->post('/preferences/unsubscribe?token='.$token, ['List-Unsubscribe' => 'One-Click'])->assertOk();
    expect(DB::table('purpose_grants')->where('contact_id', $contactId)->where('purpose', 'cart_recovery')->value('revoked_at'))->not->toBeNull();
});

it('MarketingPreferences signed unsubscribe fails closed on tampered or retargeted tokens', function (): void {
    catalogInstalledOwner();
    $prefs = app(UpdateDeliveryPreference::class);
    $contactId = prefVerifiedContact('tamper@example.test');
    $prefs->grant($contactId, 'newsletter', 'preference_center', 'account_verified');
    $tokens = app(UnsubscribeTokens::class);
    $token = $tokens->issue($contactId, 'newsletter');
    $this->get('/preferences/unsubscribe?token='.$token)->assertOk();
    $this->get('/preferences/unsubscribe?token=not-a-token')->assertNotFound();
    $this->post('/preferences/unsubscribe', ['token' => $token.'x'])->assertNotFound();
    [$payload, $signature] = explode('.', $token);
    $decoded = json_decode(base64_decode(strtr($payload, '-_', '+/')), true, flags: JSON_THROW_ON_ERROR);
    $decoded['p'] = 'stock_alert';
    $forgedPayload = rtrim(strtr(base64_encode(json_encode($decoded, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    $this->post('/preferences/unsubscribe', ['token' => $forgedPayload.'.'.$signature])->assertNotFound();
    expect(DB::table('purpose_grants')->where('contact_id', $contactId)->where('purpose', 'newsletter')->value('revoked_at'))->toBeNull();
});

it('MarketingPreferences preference center lets a verified customer manage only their own contact', function (): void {
    catalogInstalledOwner();
    $customer = prefCustomer('pref@example.test');
    prefCustomer('other@example.test');
    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1]);
    $this->get('/account/preferences')->assertOk()->assertSee('Email preferences');
    $this->post('/account/preferences', ['purposes' => ['newsletter', 'price_alert']])->assertRedirect('/account/preferences');
    $prefs = app(UpdateDeliveryPreference::class);
    $contactId = DB::table('delivery_contacts')->where('email', 'pref@example.test')->value('id');
    expect($contactId)->not->toBeNull();
    $state = $prefs->state((string) $contactId);
    expect($state['verified'])->toBeTrue()
        ->and($state['purposes']['newsletter'])->toBeTrue()
        ->and($state['purposes']['price_alert'])->toBeTrue()
        ->and($state['purposes']['stock_alert'])->toBeFalse();
    expect(DB::table('delivery_contacts')->where('email', 'other@example.test')->count())->toBe(0);
    $this->post('/account/preferences', ['purposes' => []])->assertRedirect('/account/preferences');
    expect($prefs->state((string) $contactId)['purposes']['newsletter'])->toBeFalse();
    $this->post('/account/preferences', ['purposes' => ['newsletter']]);
    $this->post('/account/preferences', ['stop_all' => '1'])->assertRedirect('/account/preferences');
    $state = $prefs->state((string) $contactId);
    expect($state['purposes']['newsletter'])->toBeFalse()
        ->and($state['suppressions'])->toHaveCount(1)
        ->and($state['suppressions'][0]['scope'])->toBe('global')
        ->and($state['suppressions'][0]['reason'])->toBe('opt_out');
    expect(app(DeliveryPolicy::class)->marketingAllowed('pref@example.test', 'newsletter'))->toBeFalse();
    $this->post('/account/preferences', ['purposes' => ['newsletter']])->assertRedirect('/account/preferences');
    expect(app(DeliveryPolicy::class)->marketingAllowed('pref@example.test', 'newsletter'))->toBeTrue();
});

it('MarketingPreferences preference center refuses unverified customers and its GET never mutates consent', function (): void {
    catalogInstalledOwner();
    $unverified = prefCustomer('unverified@example.test', false);
    $this->actingAs($unverified, 'customer')->withSession(['customer_session_version' => 1]);
    $this->post('/account/preferences', ['purposes' => ['newsletter']])->assertForbidden();
    expect(DB::table('purpose_grants')->count())->toBe(0);
    $customer = prefCustomer('reader@example.test');
    $prefs = app(UpdateDeliveryPreference::class);
    $contactId = $prefs->ensureContact('reader@example.test', $customer->id);
    $prefs->markVerified($contactId, 'customer_account');
    $prefs->grant($contactId, 'newsletter', 'preference_center', 'account_verified');
    $before = DB::table('purpose_grants')->where('contact_id', $contactId)->first();
    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1]);
    $this->get('/account/preferences')->assertOk();
    $after = DB::table('purpose_grants')->where('contact_id', $contactId)->first();
    expect($after->granted_at)->toBe($before->granted_at)
        ->and($after->revoked_at)->toBeNull()
        ->and(DB::table('delivery_suppressions')->where('contact_id', $contactId)->count())->toBe(0)
        ->and(DB::table('delivery_contacts')->count())->toBe(1);
});

it('MarketingPreferences essential mail still queues for a globally suppressed contact while marketing stays blocked', function (): void {
    config([
        'services.stripe.secret' => 'sk_test_fixture',
        'services.stripe.account' => 'acct_fixture',
        'services.stripe.live' => false,
        'services.stripe.webhook_secret' => 'whsec_test_fixture',
    ]);
    $catalog = entitlementCatalog();
    $paid = dlGuestDigitalOrder($catalog, 'guest-pref-essential', 'cs_pref_essential', 'pi_pref_essential');
    $prefs = app(UpdateDeliveryPreference::class);
    $contactId = $prefs->ensureContact('guest@example.test');
    $prefs->markVerified($contactId, 'checkout_receipt');
    $prefs->grant($contactId, 'newsletter', 'preference_center', 'account_verified');
    $prefs->suppress($contactId, 'global', 'opt_out', 'preference_center');
    $policy = app(DeliveryPolicy::class);
    expect($policy->marketingAllowed('guest@example.test', 'newsletter'))->toBeFalse()
        ->and($policy->essentialAllowed('download-access'))->toBeTrue()
        ->and($policy->essentialAllowed('order-receipt'))->toBeTrue()
        ->and($policy->essentialAllowed('shipment'))->toBeTrue()
        ->and($policy->essentialAllowed('newsletter'))->toBeFalse();
    $result = app(VerifyGuestOrder::class)->requestAccess((string) $paid['order_id'], 'guest@example.test');
    expect($result)->not->toBeNull();
    expect(DB::table('download_access_intents')->where('order_id', $paid['order_id'])->where('template', 'download-access')->count())->toBe(1);
});
