<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Support\StoreCurrency;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Pricing\Actions\PreviewPricing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class PricingPreviewController
{
    public function show(Request $request): Response
    {
        Gate::authorize('pricing.manage');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);
        $preview = session()->get('pricing_preview');
        $currency = app(StoreCurrency::class)->current();

        return Inertia::render('PricingPreview', [
            'currency' => $currency['currency'],
            'exponent' => $currency['exponent'],
            'canWrite' => $staff->hasPermission('pricing.manage'),
            'preview' => is_array($preview) ? $preview : null,
        ]);
    }

    public function preview(Request $request, PreviewPricing $preview): RedirectResponse
    {
        Gate::authorize('pricing.manage');
        $data = $request->validate([
            'preview' => ['required', 'array'],
            'preview.cart' => ['required', 'in:physical,digital,mixed'],
            'preview.coupon' => ['nullable', 'string', 'max:32'],
            'preview.billing_country_code' => ['nullable', 'string', 'size:2'],
            'preview.billing_region_code' => ['nullable', 'string', 'max:16'],
            'preview.billing_postal_code' => ['nullable', 'string', 'max:16'],
            'preview.shipping_country_code' => ['nullable', 'string', 'size:2'],
            'preview.shipping_region_code' => ['nullable', 'string', 'max:16'],
            'preview.shipping_postal_code' => ['nullable', 'string', 'max:16'],
            'preview.physical_amount' => ['required', 'integer', 'min:0'],
            'preview.digital_amount' => ['required', 'integer', 'min:0'],
            'preview.physical_grams' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'preview.physical_class_key' => ['required', 'string', 'max:32'],
            'preview.digital_class_key' => ['required', 'string', 'max:32'],
            'preview.physical_tax_class_key' => ['required', 'string', 'max:32'],
            'preview.digital_tax_class_key' => ['required', 'string', 'max:32'],
            'preview.shipping_method_id' => ['nullable', 'uuid'],
        ])['preview'];

        return redirect('/admin/pricing-preview')->with('pricing_preview', $preview->preview($data));
    }
}
