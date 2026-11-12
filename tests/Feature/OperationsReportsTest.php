<?php

declare(strict_types=1);

use App\Domain\CheckoutOrders\Actions\AcceptQuote;
use App\Domain\CheckoutOrders\Actions\CreateCheckoutIntent;
use App\Domain\CheckoutOrders\Actions\CreateQuote;
use App\Domain\CheckoutOrders\Actions\RequestRefund;
use App\Domain\CheckoutOrders\Actions\UpdateCart;
use App\Domain\CheckoutOrders\Support\CartOwner;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\StaffPermissions;
use App\Domain\Operations\Actions\BuildSalesReport;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
    Carbon::setTestNow(Carbon::parse('2026-10-16T14:00:00Z')->utc());
    config([
        'app.url' => 'http://127.0.0.1:8000',
        'services.stripe.secret' => 'sk_test_fixture',
        'services.stripe.account' => 'acct_fixture',
        'services.stripe.live' => true,
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Exclusive USD lamp fixture: 2500 merchandise − 250 SAVE10 + 300 shipping + 255 tax = 2805.
 *
 * @return array{owner: StaffAccount, order_id: string, line_id: string, physical_variant: string, digital_variant: string}
 */
function operationsReportsLivePaid(): array
{
    $catalog = refundCatalog();
    pricingSavedCoupon($catalog['owner'], [
        'code' => 'FREE100',
        'percent_numerator' => 100,
        'percent_denominator' => 100,
        'max_redemptions' => 25,
        'max_redemptions_per_customer' => null,
    ]);
    $paid = refundPaidPhysical($catalog['physical_variant'], 'guest-report-live', 'cs_report_live', 'pi_report_live');

    return [
        'owner' => $catalog['owner'],
        'order_id' => $paid['order_id'],
        'line_id' => $paid['line_id'],
        'physical_variant' => $catalog['physical_variant'],
        'digital_variant' => $catalog['digital_variant'],
    ];
}

it('OperationsReports reconciles activity and cohort figures to the captured and refunded ledger', function (): void {
    $fixture = operationsReportsLivePaid();
    $owner = $fixture['owner'];
    expect((int) DB::table('payment_attempts')->where('order_id', $fixture['order_id'])->value('live'))->toBe(1);

    config(['services.stripe.live' => false]);
    $test = refundPaidPhysical($fixture['physical_variant'], 'guest-report-test', 'cs_report_test', 'pi_report_test');
    expect((int) DB::table('payment_attempts')->where('order_id', $test['order_id'])->value('live'))->toBe(0);
    config(['services.stripe.live' => true]);

    $guest = CartOwner::guest('guest-report-unpaid');
    $cart = app(UpdateCart::class)->replaceLine($guest, [
        'variant_id' => $fixture['physical_variant'],
        'quantity' => 1,
        'expected_revision' => 1,
    ]);
    $quote = app(CreateQuote::class)->create($guest, checkoutQuoteInput($cart['revision'], ['coupon' => 'SAVE10']));
    app(AcceptQuote::class)->accept($guest, $quote['id'], $quote['fingerprint'], true);
    $unpaid = app(CreateCheckoutIntent::class)->create($guest, $quote['id'], (string) Str::uuid());
    expect((string) DB::table('orders')->where('id', $unpaid['order_id'])->value('financial_status'))->toBe('unpaid');

    $digital = CartOwner::guest('guest-report-free');
    $digitalCart = app(UpdateCart::class)->replaceLine($digital, [
        'variant_id' => $fixture['digital_variant'], 'quantity' => 1, 'expected_revision' => 1,
    ]);
    $digitalQuote = app(CreateQuote::class)->create($digital, checkoutQuoteInput($digitalCart['revision'], [
        'coupon' => 'FREE100',
        'shipping' => null,
    ]));
    app(AcceptQuote::class)->accept($digital, $digitalQuote['id'], $digitalQuote['fingerprint'], true);
    $zero = app(CreateCheckoutIntent::class)->create($digital, $digitalQuote['id'], (string) Str::uuid());
    expect((string) DB::table('orders')->where('id', $zero['order_id'])->value('financial_status'))->toBe('paid')
        ->and((int) DB::table('orders')->where('id', $zero['order_id'])->value('grand_total_amount'))->toBe(0);

    fakeStripeRefund('failed', 're_report_failed', 2475, 'pi_report_live');
    $failed = app(RequestRefund::class)->request($owner, $fixture['order_id'], [
        'lines' => [['id' => $fixture['line_id'], 'quantity' => 1]],
        'shipping_amount' => 0,
        'reason' => 'Provider declined the first refund.',
    ], (string) Str::uuid());
    expect($failed['status'])->toBe('failed');

    Carbon::setTestNow(Carbon::parse('2026-10-18T15:00:00Z')->utc());
    fakeStripeRefund('succeeded', 're_report_ok', 2475, 'pi_report_live');
    $refund = app(RequestRefund::class)->request($owner, $fixture['order_id'], [
        'lines' => [['id' => $fixture['line_id'], 'quantity' => 1]],
        'shipping_amount' => 0,
        'reason' => 'Customer cancelled before shipment.',
    ], (string) Str::uuid());
    expect($refund['status'])->toBe('succeeded')->and($refund['amount'])->toBe(2475);

    DB::table('payments')->where('order_id', $fixture['order_id'])->update(['captured_at' => '2026-10-17 06:30:00']);

    $builder = app(BuildSalesReport::class);
    $captureDay = $builder->build($owner, ['from' => '2026-10-16', 'to' => '2026-10-16']);
    expect($captureDay['timezone'])->toBe('America/Los_Angeles')
        ->and($captureDay['from'])->toBe('2026-10-16')
        ->and($captureDay['to'])->toBe('2026-10-16')
        ->and($captureDay['currency'])->toBe('USD')
        ->and($captureDay['exponent'])->toBe(2)
        ->and($captureDay['rules']['include_paid'])->toBeTrue()
        ->and($captureDay['rules']['include_test'])->toBeFalse()
        ->and($captureDay['rules']['include_unpaid'])->toBeFalse()
        ->and($captureDay['rules']['refund_date'])->toBe('refund_succeeded_at')
        ->and($captureDay['activity']['paid_orders'])->toBe(2)
        ->and($captureDay['activity']['gross']['amount'])->toBe(3000)
        ->and($captureDay['activity']['discounts']['amount'])->toBe(750)
        ->and($captureDay['activity']['refunds']['amount'])->toBe(0)
        ->and($captureDay['activity']['net']['amount'])->toBe(2250)
        ->and($captureDay['activity']['tax']['amount'])->toBe(255)
        ->and($captureDay['activity']['shipping']['amount'])->toBe(300)
        ->and($captureDay['activity']['aov']['amount'])->toBe(1125)
        ->and($captureDay['cohort']['paid_orders'])->toBe(2)
        ->and($captureDay['cohort']['gross']['amount'])->toBe(3000)
        ->and($captureDay['cohort']['discounts']['amount'])->toBe(750)
        ->and($captureDay['cohort']['refunds']['amount'])->toBe(2250)
        ->and($captureDay['cohort']['net']['amount'])->toBe(0)
        ->and($captureDay['cohort']['tax']['amount'])->toBe(255)
        ->and($captureDay['cohort']['shipping']['amount'])->toBe(300)
        ->and($captureDay['cohort']['aov']['amount'])->toBe(0);

    $utcSameInstant = $builder->build($owner, ['from' => '2026-10-17', 'to' => '2026-10-17']);
    expect($utcSameInstant['activity']['paid_orders'])->toBe(0)
        ->and($utcSameInstant['activity']['gross']['amount'])->toBe(0)
        ->and($utcSameInstant['cohort']['paid_orders'])->toBe(0);

    $refundDay = $builder->build($owner, ['from' => '2026-10-18', 'to' => '2026-10-18']);
    expect($refundDay['activity']['paid_orders'])->toBe(0)
        ->and($refundDay['activity']['gross']['amount'])->toBe(0)
        ->and($refundDay['activity']['refunds']['amount'])->toBe(2250)
        ->and($refundDay['activity']['net']['amount'])->toBe(-2250)
        ->and($refundDay['activity']['tax']['amount'])->toBe(0)
        ->and($refundDay['activity']['shipping']['amount'])->toBe(0)
        ->and($refundDay['activity']['aov']['amount'])->toBe(0)
        ->and($refundDay['cohort']['paid_orders'])->toBe(0)
        ->and($refundDay['cohort']['refunds']['amount'])->toBe(0);

    $this->get('/')->assertStatus(503);
});

it('OperationsReports HTTP allows reports.read, denies other staff, and still requires owner 2FA', function (): void {
    $fixture = operationsReportsLivePaid();
    $owner = $fixture['owner'];
    $customer = new CustomerAccount;
    $customer->forceFill([
        'name' => 'Shopper', 'email' => 'shopper-reports@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']), 'is_active' => true, 'session_version' => 1,
    ])->save();
    $this->get('/admin/reports')->assertRedirect('/admin/login');
    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1])
        ->getJson('/admin/reports')->assertUnauthorized();
    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => $owner->session_version])
        ->get('/admin/reports')->assertRedirect('/admin/two-factor');
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->get('/admin')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('AdminHome')
        ->where('canReadReports', true));
    $this->get('/admin/reports?from=2026-10-16&to=2026-10-16')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Reports')
        ->where('report.timezone', 'America/Los_Angeles')
        ->where('report.rules.include_test', false)
        ->where('report.rules.include_unpaid', false)
        ->where('report.activity.paid_orders', 1)
        ->where('report.activity.gross.amount', 2500)
        ->where('report.activity.discounts.amount', 250)
        ->where('report.activity.net.amount', 2250)
        ->where('report.activity.tax.amount', 255)
        ->where('report.activity.shipping.amount', 300)
        ->where('report.activity.aov.amount', 2250)
        ->where('report.cohort.paid_orders', 1));
    $fulfillment = new StaffAccount;
    $fulfillment->forceFill([
        'name' => 'Shipper', 'email' => 'shipper-reports@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']),
        'role' => 'fulfillment', 'permissions' => StaffPermissions::forRole('fulfillment'), 'is_active' => true, 'session_version' => 1,
    ])->save();
    $this->actingAs($fulfillment, 'staff')->withSession(catalogStaffSession($fulfillment))
        ->getJson('/admin/reports')->assertForbidden();
    expect(fn () => app(BuildSalesReport::class)->build($fulfillment, ['from' => '2026-10-16', 'to' => '2026-10-16']))
        ->toThrow(AuthorizationException::class);
    $manager = new StaffAccount;
    $manager->forceFill([
        'name' => 'Manager', 'email' => 'manager-reports@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']),
        'role' => 'manager', 'permissions' => StaffPermissions::forRole('manager'), 'is_active' => true, 'session_version' => 1,
    ])->save();
    $this->actingAs($manager, 'staff')->withSession(catalogStaffSession($manager))
        ->get('/admin/reports?from=2026-10-16&to=2026-10-16')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Reports')
        ->where('report.activity.paid_orders', 1));
    $this->get('/')->assertStatus(503);
});
