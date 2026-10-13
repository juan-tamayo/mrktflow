<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Pricing\Actions\ReadCoupons;
use App\Domain\Pricing\Actions\SaveCoupon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CouponController
{
    public function index(Request $request, ReadCoupons $coupons): Response
    {
        Gate::authorize('pricing.manage');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);

        return Inertia::render('CouponIndex', [
            'coupons' => $coupons->staffCoupons(),
            'canWrite' => $staff->hasPermission('pricing.manage'),
        ]);
    }

    public function create(ReadCoupons $coupons): Response
    {
        Gate::authorize('pricing.manage');
        $options = $coupons->catalogOptions();

        return Inertia::render('CouponEdit', [
            'coupon' => null,
            'products' => $options['products'],
            'categories' => $options['categories'],
            'saved' => session()->get('coupon_saved', false),
        ]);
    }

    public function edit(string $couponId, ReadCoupons $coupons): Response
    {
        Gate::authorize('pricing.manage');
        try {
            $coupon = $coupons->staffCoupon($couponId);
        } catch (NotFoundHttpException) {
            abort(404);
        }
        $options = $coupons->catalogOptions();

        return Inertia::render('CouponEdit', [
            'coupon' => $coupon,
            'products' => $options['products'],
            'categories' => $options['categories'],
            'saved' => session()->get('coupon_saved', false),
        ]);
    }

    public function store(Request $request, SaveCoupon $save): RedirectResponse
    {
        Gate::authorize('pricing.manage');

        return $this->persist($request, $save, $this->validated($request, false), '/admin/coupons/create');
    }

    public function update(Request $request, string $couponId, SaveCoupon $save): RedirectResponse
    {
        Gate::authorize('pricing.manage');
        $input = $this->validated($request, true);
        $input['id'] = $couponId;

        return $this->persist($request, $save, $input, '/admin/coupons/'.$couponId);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $update): array
    {
        $keys = 'id,version,code,name,mode,amount,percent_numerator,percent_denominator,min_eligible_subtotal,starts_at,ends_at,max_redemptions,max_redemptions_per_customer,status,product_ids,category_ids';
        $data = $request->validate([
            'coupon' => ['required', 'array:'.$keys],
            'coupon.code' => ['required', 'string', 'max:32'],
            'coupon.name' => ['required', 'string', 'max:120'],
            'coupon.mode' => ['required', 'string'],
            'coupon.status' => ['required', 'string'],
            'coupon.version' => [$update ? 'required' : 'sometimes', 'nullable', 'integer'],
        ])['coupon'];
        if (! $update) {
            unset($data['id'], $data['version']);
        }

        return $data;
    }

    /** @param array<string, mixed> $input */
    private function persist(Request $request, SaveCoupon $save, array $input, string $fallback): RedirectResponse
    {
        Gate::authorize('pricing.manage');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);
        try {
            $saved = $save->save($staff, $input);
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() === 409 && ! $request->expectsJson()) {
                throw ValidationException::withMessages(['coupon' => $exception->getMessage()])->redirectTo($fallback);
            }
            throw $exception;
        }

        return redirect('/admin/coupons/'.$saved['id'])->with('coupon_saved', true);
    }
}
