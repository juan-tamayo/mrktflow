<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\SaveCategory;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Identity\Actions\RegisterCustomer;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Support\StaffPermissions;
use App\Domain\Pricing\Actions\ApplyCoupon;
use App\Domain\Pricing\Actions\ImportRecipientGrants;
use App\Domain\Pricing\Actions\IssuePromotionGrant;
use App\Domain\Pricing\Actions\ProcessCouponBatch;
use App\Domain\Pricing\Actions\ReadPromotionCampaigns;
use App\Domain\Pricing\Actions\RequestCouponBatch;
use App\Domain\Pricing\Actions\SavePromotionCampaign;
use App\Domain\Pricing\Contracts\CouponCodeGenerator;
use App\Domain\Pricing\Exceptions\PricingCouponConflict;
use App\Domain\Pricing\Exceptions\PricingCouponRejected;
use App\Domain\Pricing\Support\RandomCouponCodeGenerator;
use App\Jobs\GenerateCouponBatch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Fixtures\CustomerRegistration;

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
});

function pricingVerifiedCustomer(string $email): CustomerAccount
{
    $customer = app(RegisterCustomer::class)->register(array_replace(CustomerRegistration::input(), [
        'email' => $email,
    ]));
    $customer->forceFill(['email_verified_at' => now()])->save();

    return $customer->fresh();
}

it('PricingCouponGrant issues reusable codes through a stable source intent', function (): void {
    $owner = catalogInstalledOwner();
    $image = catalogReadyImage($owner->id);
    $product = app(SaveProduct::class)->save($owner, catalogProductInput($image, ['status' => 'published']));
    $variantId = (string) DB::table('product_variants')->where('product_id', $product['id'])->value('id');
    $campaign = app(SavePromotionCampaign::class)->save($owner, pricingCampaignInput([
        'kind' => 'reusable',
        'name' => 'Reusable studio ten',
    ]));
    $intent = (string) Str::uuid();
    $first = app(IssuePromotionGrant::class)->issue([
        'campaign_id' => $campaign['id'],
        'issuer' => 'staff',
        'source_intent_id' => $intent,
        'code' => 'studio-10',
    ]);
    $replay = app(IssuePromotionGrant::class)->issue([
        'campaign_id' => $campaign['id'],
        'issuer' => 'staff',
        'source_intent_id' => $intent,
        'code' => 'studio-10',
    ]);
    expect($replay['id'])->toBe($first['id'])
        ->and($replay['code'])->toBe('STUDIO-10')
        ->and(DB::table('coupon_grants')->count())->toBe(1)
        ->and(DB::table('coupons')->where('code_normalized', 'STUDIO-10')->count())->toBe(1);
    expect(fn () => app(IssuePromotionGrant::class)->issue([
        'campaign_id' => $campaign['id'],
        'issuer' => 'staff',
        'source_intent_id' => $intent,
        'code' => 'OTHER10',
    ]))->toThrow(PricingCouponConflict::class);
    $line = pricingCouponLine($product['id'], $variantId, 1, pricingCouponUsd(1000));
    $applied = app(ApplyCoupon::class)->apply('studio-10', [$line], new DateTimeImmutable('2026-10-13T00:00:00Z'));
    expect($applied->code)->toBe('STUDIO-10');
    $this->get('/')->assertStatus(503);
});

it('PricingCouponGrant generates a bounded unique batch once per intent', function (): void {
    $owner = catalogInstalledOwner();
    $campaign = app(SavePromotionCampaign::class)->save($owner, pricingCampaignInput([
        'kind' => 'batch',
        'name' => 'Unique batch',
        'max_redemptions' => 1,
    ]));
    $intent = (string) Str::uuid();
    $first = app(RequestCouponBatch::class)->request($owner, [
        'campaign_id' => $campaign['id'],
        'source_intent_id' => $intent,
        'requested_count' => 3,
    ]);
    GenerateCouponBatch::dispatchSync($first['id']);
    $replay = app(RequestCouponBatch::class)->request($owner, [
        'campaign_id' => $campaign['id'],
        'source_intent_id' => $intent,
        'requested_count' => 3,
    ]);
    GenerateCouponBatch::dispatchSync($replay['id']);
    expect($replay['id'])->toBe($first['id'])
        ->and((int) DB::table('coupon_batches')->where('id', $first['id'])->value('generated_count'))->toBe(3)
        ->and(DB::table('coupon_grants')->where('batch_id', $first['id'])->count())->toBe(3);
    $codes = DB::table('coupons')->orderBy('code_normalized')->pluck('code_normalized')->all();
    expect($codes)->toHaveCount(3)
        ->and($codes)->toHaveCount(count(array_unique($codes)));
    expect(fn () => app(RequestCouponBatch::class)->request($owner, [
        'campaign_id' => $campaign['id'],
        'source_intent_id' => $intent,
        'requested_count' => 4,
    ]))->toThrow(PricingCouponConflict::class);
    expect(fn () => app(RequestCouponBatch::class)->request($owner, [
        'campaign_id' => $campaign['id'],
        'source_intent_id' => (string) Str::uuid(),
        'requested_count' => 1001,
    ]))->toThrow(ValidationException::class);
    $denied = $owner->fresh();
    $denied->forceFill(['role' => 'fulfillment', 'permissions' => StaffPermissions::forRole('fulfillment')])->save();
    expect(fn () => app(RequestCouponBatch::class)->request($denied, [
        'campaign_id' => $campaign['id'],
        'source_intent_id' => (string) Str::uuid(),
        'requested_count' => 2,
    ]))->toThrow(AuthorizationException::class);
});

it('PricingCouponGrant imports recipient grants once and denies the wrong shopper', function (): void {
    $owner = catalogInstalledOwner();
    $image = catalogReadyImage($owner->id);
    $product = app(SaveProduct::class)->save($owner, catalogProductInput($image, ['status' => 'published']));
    $variantId = (string) DB::table('product_variants')->where('product_id', $product['id'])->value('id');
    $alice = pricingVerifiedCustomer('alice-grant@example.test');
    $bob = pricingVerifiedCustomer('bob-grant@example.test');
    $campaign = app(SavePromotionCampaign::class)->save($owner, pricingCampaignInput([
        'kind' => 'recipient',
        'name' => 'Recovery ten',
    ]));
    $intent = (string) Str::uuid();
    $first = app(ImportRecipientGrants::class)->import($owner, [
        'campaign_id' => $campaign['id'],
        'source_intent_id' => $intent,
        'customer_ids' => [$alice->id],
        'expires_at' => '2026-12-01T00:00:00Z',
    ]);
    $replay = app(ImportRecipientGrants::class)->import($owner, [
        'campaign_id' => $campaign['id'],
        'source_intent_id' => $intent,
        'customer_ids' => [$alice->id],
        'expires_at' => '2026-12-01T00:00:00Z',
    ]);
    expect($replay['id'])->toBe($first['id'])
        ->and($replay['grant_ids'])->toBe($first['grant_ids'])
        ->and(DB::table('coupon_grants')->count())->toBe(1);
    expect(fn () => app(ImportRecipientGrants::class)->import($owner, [
        'campaign_id' => $campaign['id'],
        'source_intent_id' => $intent,
        'customer_ids' => [$bob->id],
        'expires_at' => '2026-12-01T00:00:00Z',
    ]))->toThrow(PricingCouponConflict::class);
    $code = (string) DB::table('coupons')->value('code_normalized');
    $line = pricingCouponLine($product['id'], $variantId, 1, pricingCouponUsd(1000));
    $quoted = new DateTimeImmutable('2026-11-13T00:00:00Z');
    $applied = app(ApplyCoupon::class)->apply($code, [$line], $quoted, $alice->id);
    expect($applied->code)->toBe($code);
    expect(fn () => app(ApplyCoupon::class)->apply($code, [$line], $quoted, $bob->id))
        ->toThrow(PricingCouponRejected::class);
    expect(fn () => app(ApplyCoupon::class)->apply($code, [$line], $quoted, null))
        ->toThrow(PricingCouponRejected::class);
    $unverified = app(RegisterCustomer::class)->register(array_replace(CustomerRegistration::input(), [
        'email' => 'unverified-grant@example.test',
    ]));
    expect(fn () => app(ImportRecipientGrants::class)->import($owner, [
        'campaign_id' => $campaign['id'],
        'source_intent_id' => (string) Str::uuid(),
        'customer_ids' => [$unverified->id],
    ]))->toThrow(ValidationException::class);
});

it('PricingCouponGrant retries colliding random codes without duplicating the grant', function (): void {
    $owner = catalogInstalledOwner();
    pricingSavedCoupon($owner, ['code' => 'TAKEN01']);
    $campaign = app(SavePromotionCampaign::class)->save($owner, pricingCampaignInput([
        'kind' => 'recipient',
        'name' => 'Collision safe',
    ]));
    $alice = pricingVerifiedCustomer('collision-grant@example.test');
    $this->app->instance(CouponCodeGenerator::class, new class implements CouponCodeGenerator
    {
        /** @var list<string> */
        private array $codes = ['TAKEN01', 'FRESH01'];

        public function next(): string
        {
            $code = array_shift($this->codes);
            if ($code === null) {
                throw new RuntimeException('No generated coupon codes remain.');
            }

            return $code;
        }
    });
    $grant = app(IssuePromotionGrant::class)->issue([
        'campaign_id' => $campaign['id'],
        'issuer' => 'recovery',
        'source_intent_id' => (string) Str::uuid(),
        'recipient_customer_id' => $alice->id,
    ]);
    expect($grant['code'])->toBe('FRESH01')
        ->and(DB::table('coupon_grants')->count())->toBe(1)
        ->and(DB::table('coupons')->where('code_normalized', 'TAKEN01')->count())->toBe(1)
        ->and(DB::table('coupons')->where('code_normalized', 'FRESH01')->count())->toBe(1);
});

it('PricingCouponGrant rejects paused expired and revoked grants without duplicating benefits', function (): void {
    $owner = catalogInstalledOwner();
    $image = catalogReadyImage($owner->id);
    $product = app(SaveProduct::class)->save($owner, catalogProductInput($image, ['status' => 'published']));
    $variantId = (string) DB::table('product_variants')->where('product_id', $product['id'])->value('id');
    $alice = pricingVerifiedCustomer('paused-grant@example.test');
    $campaign = app(SavePromotionCampaign::class)->save($owner, pricingCampaignInput([
        'kind' => 'recipient',
        'name' => 'Paused recovery',
    ]));
    $grant = app(IssuePromotionGrant::class)->issue([
        'campaign_id' => $campaign['id'],
        'issuer' => 'recovery',
        'source_intent_id' => (string) Str::uuid(),
        'recipient_customer_id' => $alice->id,
        'expires_at' => '2026-11-01T00:00:00Z',
    ]);
    $line = pricingCouponLine($product['id'], $variantId, 1, pricingCouponUsd(1000));
    expect(fn () => app(ApplyCoupon::class)->apply($grant['code'], [$line], new DateTimeImmutable('2026-11-13T00:00:00Z'), $alice->id))
        ->toThrow(PricingCouponRejected::class);
    DB::table('coupon_grants')->where('id', $grant['id'])->update(['expires_at' => '2026-12-01 00:00:00']);
    DB::table('promotion_campaigns')->where('id', $campaign['id'])->update(['status' => 'paused']);
    expect(fn () => app(ApplyCoupon::class)->apply($grant['code'], [$line], new DateTimeImmutable('2026-11-13T00:00:00Z'), $alice->id))
        ->toThrow(PricingCouponRejected::class);
    expect(fn () => app(IssuePromotionGrant::class)->issue([
        'campaign_id' => $campaign['id'],
        'issuer' => 'recovery',
        'source_intent_id' => (string) Str::uuid(),
        'recipient_customer_id' => $alice->id,
    ]))->toThrow(ValidationException::class);
    DB::table('promotion_campaigns')->where('id', $campaign['id'])->update(['status' => 'active']);
    DB::table('coupon_grants')->where('id', $grant['id'])->update(['status' => 'revoked']);
    expect(fn () => app(ApplyCoupon::class)->apply($grant['code'], [$line], new DateTimeImmutable('2026-11-13T00:00:00Z'), $alice->id))
        ->toThrow(PricingCouponRejected::class);
    $denied = $owner->fresh();
    $denied->forceFill(['role' => 'fulfillment', 'permissions' => StaffPermissions::forRole('fulfillment')])->save();
    expect(fn () => app(SavePromotionCampaign::class)->save($denied, pricingCampaignInput()))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(ImportRecipientGrants::class)->import($owner, [
        'campaign_id' => $campaign['id'],
        'source_intent_id' => (string) Str::uuid(),
        'customer_ids' => [],
    ]))->toThrow(ValidationException::class);
    $reusable = app(SavePromotionCampaign::class)->save($owner, pricingCampaignInput(['kind' => 'reusable', 'name' => 'Public']));
    expect(fn () => app(RequestCouponBatch::class)->request($owner, [
        'campaign_id' => $reusable['id'],
        'source_intent_id' => (string) Str::uuid(),
        'requested_count' => 2,
    ]))->toThrow(ValidationException::class);
    expect(fn () => app(IssuePromotionGrant::class)->issue([
        'campaign_id' => 'not-a-uuid',
        'issuer' => 'staff',
        'source_intent_id' => (string) Str::uuid(),
        'code' => 'NOPE10',
    ]))->toThrow(InvalidArgumentException::class);
});

it('PricingCouponGrant HTTP keeps privileged staff behind two-factor and rejects customers', function (): void {
    $owner = catalogInstalledOwner();
    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => 1]);
    $this->get('/admin/coupon-campaigns')->assertRedirect('/admin/two-factor');
    $this->postJson('/admin/coupon-campaigns', ['campaign' => pricingCampaignInput()])->assertForbidden();
    auth('staff')->logout();
    $customer = new CustomerAccount;
    $customer->forceFill([
        'name' => 'Shopper', 'email' => 'grant-shopper@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']), 'is_active' => true, 'session_version' => 1,
    ])->save();
    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1])
        ->postJson('/admin/coupon-campaigns', ['campaign' => pricingCampaignInput()])
        ->assertUnauthorized();
    $this->get('/')->assertStatus(503);
});

it('PricingCouponGrant HTTP lets authorized staff issue batches and recipient grants', function (): void {
    $owner = catalogInstalledOwner();
    $alice = pricingVerifiedCustomer('http-grant@example.test');
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->get('/admin')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('AdminHome')
        ->where('canManagePricing', true));
    $this->get('/admin/coupon-campaigns/create')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('CouponCampaign')
        ->where('campaign', null));
    $this->post('/admin/coupon-campaigns', ['campaign' => pricingCampaignInput([
        'kind' => 'recipient',
        'name' => 'Staff recovery',
    ])])->assertRedirect();
    $id = (string) DB::table('promotion_campaigns')->value('id');
    $this->get('/admin/coupon-campaigns/'.$id)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('CouponCampaign')
        ->where('campaign.name', 'Staff recovery')
        ->where('campaign.kind', 'recipient'));
    $importIntent = (string) Str::uuid();
    $this->post('/admin/coupon-campaigns/'.$id.'/imports', [
        'import' => [
            'source_intent_id' => $importIntent,
            'customer_ids' => [$alice->id],
            'expires_at' => '2026-12-01T00:00:00Z',
        ],
    ])->assertRedirect('/admin/coupon-campaigns/'.$id);
    $this->post('/admin/coupon-campaigns/'.$id.'/imports', [
        'import' => [
            'source_intent_id' => $importIntent,
            'customer_ids' => [$alice->id],
            'expires_at' => '2026-12-01T00:00:00Z',
        ],
    ])->assertRedirect('/admin/coupon-campaigns/'.$id);
    expect(DB::table('coupon_grants')->count())->toBe(1);
    $this->post('/admin/coupon-campaigns', ['campaign' => pricingCampaignInput([
        'kind' => 'batch',
        'name' => 'Staff batch',
    ])])->assertRedirect();
    $batchId = (string) DB::table('promotion_campaigns')->where('kind', 'batch')->value('id');
    $batchIntent = (string) Str::uuid();
    $this->post('/admin/coupon-campaigns/'.$batchId.'/batches', [
        'batch' => ['source_intent_id' => $batchIntent, 'requested_count' => 2],
    ])->assertRedirect('/admin/coupon-campaigns/'.$batchId);
    $this->post('/admin/coupon-campaigns/'.$batchId.'/batches', [
        'batch' => ['source_intent_id' => $batchIntent, 'requested_count' => 2],
    ])->assertRedirect('/admin/coupon-campaigns/'.$batchId);
    expect((int) DB::table('coupon_batches')->where('campaign_id', $batchId)->where('purpose', 'unique_codes')->value('generated_count'))->toBe(2)
        ->and((int) DB::table('coupon_batches')->where('campaign_id', $batchId)->where('purpose', 'unique_codes')->value('requested_count'))->toBe(2)
        ->and(DB::table('coupon_grants')->where('campaign_id', $batchId)->where('kind', 'batch')->count())->toBe(2)
        ->and(DB::table('coupons')->whereIn('id', DB::table('coupon_grants')->where('campaign_id', $batchId)->where('kind', 'batch')->pluck('coupon_id'))->count())->toBe(2);
    foreach (['content_editor', 'fulfillment'] as $role) {
        DB::table('staff_accounts')->where('id', $owner->id)->update(['role' => $role, 'permissions' => json_encode(StaffPermissions::forRole($role))]);
        $this->actingAs($owner->fresh(), 'staff')->withSession(catalogStaffSession($owner->fresh()))
            ->postJson('/admin/coupon-campaigns', ['campaign' => pricingCampaignInput(['name' => 'Denied'])])
            ->assertForbidden();
    }
    $this->get('/')->assertStatus(503);
});

it('PricingCouponGrant versions scoped campaigns and rejects invalid grant payloads', function (): void {
    $owner = catalogInstalledOwner();
    $image = catalogReadyImage($owner->id);
    $lighting = app(SaveCategory::class)->save($owner, catalogCategoryInput(['status' => 'published']));
    $lamp = app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'status' => 'published', 'category_ids' => [$lighting['id']],
    ]));
    $variantId = (string) DB::table('product_variants')->where('product_id', $lamp['id'])->value('id');
    expect(fn () => app(SavePromotionCampaign::class)->save($owner, pricingCampaignInput([
        'mode' => 'fixed', 'amount' => null, 'percent_numerator' => null,
    ])))->toThrow(ValidationException::class);
    expect(fn () => app(SavePromotionCampaign::class)->save($owner, pricingCampaignInput([
        'percent_numerator' => null,
    ])))->toThrow(ValidationException::class);
    expect(fn () => app(SavePromotionCampaign::class)->save($owner, pricingCampaignInput([
        'percent_numerator' => 150,
    ])))->toThrow(ValidationException::class);
    expect(fn () => app(SavePromotionCampaign::class)->save($owner, pricingCampaignInput([
        'product_ids' => [(string) Str::uuid()],
    ])))->toThrow(HttpException::class);
    $campaign = app(SavePromotionCampaign::class)->save($owner, pricingCampaignInput([
        'name' => 'Scoped lamp ten',
        'amount' => '',
        'starts_at' => '2026-01-01T00:00:00Z',
        'product_ids' => [$lamp['id']],
        'category_ids' => [$lighting['id']],
    ]));
    expect(fn () => app(SavePromotionCampaign::class)->save($owner, pricingCampaignInput([
        'id' => $campaign['id'],
        'version' => 1,
        'kind' => 'batch',
        'name' => 'Cannot change kind',
    ])))->toThrow(HttpException::class);
    expect(fn () => app(SavePromotionCampaign::class)->save($owner, pricingCampaignInput([
        'id' => $campaign['id'],
        'version' => 99,
        'name' => 'Stale campaign',
    ])))->toThrow(HttpException::class);
    $updated = app(SavePromotionCampaign::class)->save($owner, pricingCampaignInput([
        'id' => $campaign['id'],
        'version' => 1,
        'name' => 'Scoped lamp ten v2',
        'mode' => 'fixed',
        'amount' => 250,
        'percent_numerator' => null,
        'percent_denominator' => null,
        'product_ids' => [$lamp['id']],
        'category_ids' => [$lighting['id']],
    ]));
    expect($updated['revision'])->toBe(2);
    $listed = app(ReadPromotionCampaigns::class)->staffCampaigns();
    expect($listed)->toHaveCount(1)
        ->and($listed[0]['name'])->toBe('Scoped lamp ten v2');
    $detail = app(ReadPromotionCampaigns::class)->staffCampaign($campaign['id']);
    expect($detail['mode'])->toBe('fixed')
        ->and($detail['amount'])->toBe(250)
        ->and($detail['product_ids'])->toBe([$lamp['id']])
        ->and($detail['category_ids'])->toBe([$lighting['id']]);
    expect(fn () => app(ReadPromotionCampaigns::class)->staffCampaign((string) Str::uuid()))
        ->toThrow(NotFoundHttpException::class);
    DB::table('promotion_versions')->where('id', (string) DB::table('promotion_campaigns')->where('id', $campaign['id'])->value('current_version_id'))
        ->update(['product_ids' => json_encode('not-an-id-list')]);
    expect(fn () => app(ReadPromotionCampaigns::class)->staffCampaign($campaign['id']))
        ->toThrow(InvalidArgumentException::class);
    DB::table('promotion_versions')->where('campaign_id', $campaign['id'])->where('version', 2)
        ->update(['product_ids' => json_encode([$lamp['id']])]);
    expect(fn () => app(IssuePromotionGrant::class)->issue([
        'campaign_id' => $campaign['id'],
        'issuer' => 'staff',
        'source_intent_id' => 'short',
        'code' => 'LAMP10',
    ]))->toThrow(InvalidArgumentException::class);
    expect(fn () => app(IssuePromotionGrant::class)->issue([
        'campaign_id' => $campaign['id'],
        'issuer' => 'staff',
        'source_intent_id' => (string) Str::uuid(),
        'code' => 'LAMP10',
        'recipient_customer_id' => 'not-a-customer',
    ]))->toThrow(ValidationException::class);
    expect(fn () => app(IssuePromotionGrant::class)->issue([
        'campaign_id' => $campaign['id'],
        'issuer' => 'staff',
        'source_intent_id' => (string) Str::uuid(),
        'code' => 'ab',
    ]))->toThrow(ValidationException::class);
    expect(fn () => app(IssuePromotionGrant::class)->issue([
        'campaign_id' => $campaign['id'],
        'issuer' => 'staff',
        'source_intent_id' => (string) Str::uuid(),
        'code' => 'LAMP10',
        'batch_id' => 'not-a-batch',
    ]))->toThrow(InvalidArgumentException::class);
    expect(fn () => app(IssuePromotionGrant::class)->issue([
        'campaign_id' => (string) Str::uuid(),
        'issuer' => 'staff',
        'source_intent_id' => (string) Str::uuid(),
        'code' => 'LAMP10',
    ]))->toThrow(HttpException::class);
    expect(fn () => app(IssuePromotionGrant::class)->issue([
        'campaign_id' => $campaign['id'],
        'issuer' => 'staff',
        'source_intent_id' => (string) Str::uuid(),
    ]))->toThrow(ValidationException::class);
    $alice = pricingVerifiedCustomer('scoped-grant@example.test');
    expect(fn () => app(IssuePromotionGrant::class)->issue([
        'campaign_id' => $campaign['id'],
        'issuer' => 'staff',
        'source_intent_id' => (string) Str::uuid(),
        'code' => 'LAMP10',
        'recipient_customer_id' => $alice->id,
    ]))->toThrow(ValidationException::class);
    $first = app(IssuePromotionGrant::class)->issue([
        'campaign_id' => $campaign['id'],
        'issuer' => 'referral',
        'source_intent_id' => (string) Str::uuid(),
        'code' => 'LAMP10',
        'expires_at' => 'not-a-date',
    ]);
    expect($first['code'])->toBe('LAMP10')
        ->and(DB::table('coupon_scopes')->where('coupon_id', $first['coupon_id'])->count())->toBe(2);
    $line = pricingCouponLine($lamp['id'], $variantId, 1, pricingCouponUsd(1000), [$lighting['id']]);
    $applied = app(ApplyCoupon::class)->apply('lamp10', [$line], new DateTimeImmutable('2026-10-13T00:00:00Z'));
    expect($applied->code)->toBe('LAMP10');
    expect(fn () => app(IssuePromotionGrant::class)->issue([
        'campaign_id' => $campaign['id'],
        'issuer' => 'staff',
        'source_intent_id' => (string) Str::uuid(),
        'code' => 'LAMP10',
    ]))->toThrow(ValidationException::class);
    DB::table('promotion_campaigns')->where('id', $campaign['id'])->update(['current_version_id' => null]);
    expect(fn () => app(ReadPromotionCampaigns::class)->staffCampaign($campaign['id']))
        ->toThrow(NotFoundHttpException::class);
    expect(fn () => app(IssuePromotionGrant::class)->issue([
        'campaign_id' => $campaign['id'],
        'issuer' => 'staff',
        'source_intent_id' => (string) Str::uuid(),
        'code' => 'LAMP20',
    ]))->toThrow(HttpException::class);
    DB::table('promotion_campaigns')->where('id', $campaign['id'])->update(['current_version_id' => (string) Str::uuid()]);
    expect(fn () => app(ReadPromotionCampaigns::class)->staffCampaign($campaign['id']))
        ->toThrow(NotFoundHttpException::class);
    expect(fn () => app(IssuePromotionGrant::class)->issue([
        'campaign_id' => $campaign['id'],
        'issuer' => 'staff',
        'source_intent_id' => (string) Str::uuid(),
        'code' => 'LAMP21',
    ]))->toThrow(HttpException::class);
    $this->get('/')->assertStatus(503);
});

it('PricingCouponGrant resumes a queued unique batch and refuses finished or invalid import work', function (): void {
    $owner = catalogInstalledOwner();
    $alice = pricingVerifiedCustomer('resume-alice@example.test');
    $bob = pricingVerifiedCustomer('resume-bob@example.test');
    $inactive = pricingVerifiedCustomer('resume-inactive@example.test');
    $inactive->forceFill(['is_active' => false])->save();
    $recipient = app(SavePromotionCampaign::class)->save($owner, pricingCampaignInput([
        'kind' => 'recipient',
        'name' => 'Resume recipients',
        'mode' => 'free_shipping',
        'percent_numerator' => null,
        'percent_denominator' => null,
    ]));
    expect(fn () => app(IssuePromotionGrant::class)->issue([
        'campaign_id' => $recipient['id'],
        'issuer' => 'recovery',
        'source_intent_id' => (string) Str::uuid(),
    ]))->toThrow(ValidationException::class);
    expect(fn () => app(IssuePromotionGrant::class)->issue([
        'campaign_id' => $recipient['id'],
        'issuer' => 'recovery',
        'source_intent_id' => (string) Str::uuid(),
        'recipient_customer_id' => $inactive->id,
    ]))->toThrow(ValidationException::class);
    expect(fn () => app(IssuePromotionGrant::class)->issue([
        'campaign_id' => $recipient['id'],
        'issuer' => 'recovery',
        'source_intent_id' => (string) Str::uuid(),
        'code' => 'SECRET1',
        'recipient_customer_id' => $alice->id,
    ]))->toThrow(ValidationException::class);
    pricingSavedCoupon($owner, ['code' => 'TAKEN99']);
    $this->app->instance(CouponCodeGenerator::class, new class implements CouponCodeGenerator
    {
        public function next(): string
        {
            return 'TAKEN99';
        }
    });
    expect(fn () => app(IssuePromotionGrant::class)->issue([
        'campaign_id' => $recipient['id'],
        'issuer' => 'recovery',
        'source_intent_id' => (string) Str::uuid(),
        'recipient_customer_id' => $alice->id,
    ]))->toThrow(ValidationException::class);
    $this->app->instance(CouponCodeGenerator::class, new class implements CouponCodeGenerator
    {
        /** @var list<string> */
        private array $codes = ['!!', 'xx', 'FRESH99'];

        public function next(): string
        {
            $code = array_shift($this->codes);
            if ($code === null) {
                throw new RuntimeException('No generated coupon codes remain.');
            }

            return $code;
        }
    });
    $skipped = app(IssuePromotionGrant::class)->issue([
        'campaign_id' => $recipient['id'],
        'issuer' => 'recovery',
        'source_intent_id' => (string) Str::uuid(),
        'recipient_customer_id' => $alice->id,
    ]);
    expect($skipped['code'])->toBe('FRESH99');
    $this->app->instance(CouponCodeGenerator::class, new RandomCouponCodeGenerator);
    expect(fn () => app(ImportRecipientGrants::class)->import($owner, [
        'campaign_id' => 'not-a-campaign',
        'source_intent_id' => (string) Str::uuid(),
        'customer_ids' => [$alice->id],
    ]))->toThrow(InvalidArgumentException::class);
    expect(fn () => app(ImportRecipientGrants::class)->import($owner, [
        'campaign_id' => $recipient['id'],
        'source_intent_id' => (string) Str::uuid(),
        'customer_ids' => ['not-a-customer-id'],
    ]))->toThrow(ValidationException::class);
    $tooMany = [];
    for ($index = 0; $index < 201; $index++) {
        $tooMany[] = (string) Str::uuid();
    }
    expect(fn () => app(ImportRecipientGrants::class)->import($owner, [
        'campaign_id' => $recipient['id'],
        'source_intent_id' => (string) Str::uuid(),
        'customer_ids' => $tooMany,
    ]))->toThrow(ValidationException::class);
    $reusable = app(SavePromotionCampaign::class)->save($owner, pricingCampaignInput([
        'kind' => 'reusable',
        'name' => 'Not an import campaign',
    ]));
    expect(fn () => app(ImportRecipientGrants::class)->import($owner, [
        'campaign_id' => $reusable['id'],
        'source_intent_id' => (string) Str::uuid(),
        'customer_ids' => [$alice->id],
    ]))->toThrow(ValidationException::class);
    $denied = $owner->fresh();
    $denied->forceFill(['role' => 'fulfillment', 'permissions' => StaffPermissions::forRole('fulfillment')])->save();
    expect(fn () => app(ImportRecipientGrants::class)->import($denied, [
        'campaign_id' => $recipient['id'],
        'source_intent_id' => (string) Str::uuid(),
        'customer_ids' => [$alice->id],
    ]))->toThrow(AuthorizationException::class);
    $denied->forceFill(['role' => 'owner', 'permissions' => StaffPermissions::forRole('owner')])->save();
    $owner = $denied->fresh();
    $import = app(ImportRecipientGrants::class)->import($owner, [
        'campaign_id' => $recipient['id'],
        'source_intent_id' => (string) Str::uuid(),
        'customer_ids' => [$alice->id, $bob->id],
        'expires_at' => '2026-12-15T00:00:00Z',
    ]);
    expect($import['replayed'])->toBeFalse()
        ->and($import['grant_ids'])->toHaveCount(2)
        ->and((int) DB::table('coupon_batches')->where('id', $import['id'])->value('generated_count'))->toBe(2);
    app(ProcessCouponBatch::class)->process($import['id']);
    expect((int) DB::table('coupon_batches')->where('id', $import['id'])->value('generated_count'))->toBe(2);
    DB::table('promotion_campaigns')->where('id', $recipient['id'])->update(['status' => 'paused']);
    expect(fn () => app(ImportRecipientGrants::class)->import($owner, [
        'campaign_id' => $recipient['id'],
        'source_intent_id' => (string) Str::uuid(),
        'customer_ids' => [$alice->id],
    ]))->toThrow(ValidationException::class);
    $batchCampaign = app(SavePromotionCampaign::class)->save($owner, pricingCampaignInput([
        'kind' => 'batch',
        'name' => 'Resume unique codes',
        'max_redemptions' => 1,
    ]));
    expect(fn () => app(RequestCouponBatch::class)->request($owner, [
        'campaign_id' => 'not-a-campaign',
        'source_intent_id' => (string) Str::uuid(),
        'requested_count' => 2,
    ]))->toThrow(InvalidArgumentException::class);
    DB::table('promotion_campaigns')->where('id', $batchCampaign['id'])->update(['status' => 'paused']);
    expect(fn () => app(RequestCouponBatch::class)->request($owner, [
        'campaign_id' => $batchCampaign['id'],
        'source_intent_id' => (string) Str::uuid(),
        'requested_count' => 2,
    ]))->toThrow(ValidationException::class);
    DB::table('promotion_campaigns')->where('id', $batchCampaign['id'])->update(['status' => 'active']);
    expect(fn () => app(ProcessCouponBatch::class)->process('not-a-batch'))
        ->toThrow(InvalidArgumentException::class);
    app(ProcessCouponBatch::class)->process((string) Str::uuid());
    $small = app(RequestCouponBatch::class)->request($owner, [
        'campaign_id' => $batchCampaign['id'],
        'source_intent_id' => (string) Str::uuid(),
        'requested_count' => 1,
    ]);
    expect($small['generated_count'])->toBe(1);
    DB::table('coupon_batches')->where('id', $small['id'])->update(['status' => 'paused', 'generated_count' => 0]);
    app(ProcessCouponBatch::class)->process($small['id']);
    expect((int) DB::table('coupon_batches')->where('id', $small['id'])->value('generated_count'))->toBe(0);
    DB::table('coupon_batches')->where('id', $small['id'])->update(['status' => 'completed']);
    app(ProcessCouponBatch::class)->process($small['id']);
    expect(DB::table('coupon_grants')->where('batch_id', $small['id'])->count())->toBe(1);
    $resumed = app(RequestCouponBatch::class)->request($owner, [
        'campaign_id' => $batchCampaign['id'],
        'source_intent_id' => (string) Str::uuid(),
        'requested_count' => 51,
    ]);
    expect($resumed['generated_count'])->toBe(51)
        ->and($resumed['status'])->toBe('completed')
        ->and(DB::table('coupon_grants')->where('batch_id', $resumed['id'])->count())->toBe(51);
    $this->actingAs($owner->fresh(), 'staff')->withSession(catalogStaffSession($owner->fresh()));
    $this->get('/admin/coupon-campaigns')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('CouponCampaignIndex')
        ->has('campaigns', 3));
    $this->post('/admin/coupon-campaigns/'.$reusable['id'].'/grants', [
        'grant' => ['source_intent_id' => (string) Str::uuid(), 'code' => 'PUBLIC10'],
    ])->assertRedirect('/admin/coupon-campaigns/'.$reusable['id']);
    expect(DB::table('coupons')->where('code_normalized', 'PUBLIC10')->count())->toBe(1);
    $this->patch('/admin/coupon-campaigns/'.$reusable['id'], [
        'campaign' => pricingCampaignInput([
            'version' => 1,
            'kind' => 'reusable',
            'name' => 'Updated public offer',
        ]),
    ])->assertRedirect('/admin/coupon-campaigns/'.$reusable['id']);
    expect((string) DB::table('promotion_campaigns')->where('id', $reusable['id'])->value('name'))->toBe('Updated public offer');
    $this->get('/admin/coupon-campaigns/'.Str::uuid())->assertNotFound();
    $this->get('/')->assertStatus(503);
});
