<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Support\SetupChoices;
use App\Domain\Pricing\Actions\PreviewTax;
use App\Domain\Pricing\Actions\ReadTaxRules;
use App\Domain\Pricing\Actions\SaveTaxRules;
use App\Domain\Pricing\Exceptions\PricingTaxRuleConflict;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class TaxController
{
    public function show(Request $request, ReadTaxRules $rules, SetupChoices $choices): Response
    {
        Gate::authorize('pricing.manage');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);
        $preview = session()->get('tax_preview');

        return Inertia::render('Taxes', [
            'rules' => $rules->staffRules(),
            'countries' => $choices->countries(),
            'canWrite' => $staff->hasPermission('pricing.manage'),
            'saved' => session()->get('tax_saved', false),
            'preview' => is_array($preview) ? $preview : null,
        ]);
    }

    public function update(Request $request, SaveTaxRules $save): RedirectResponse
    {
        Gate::authorize('pricing.manage');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);
        $input = $request->validate([
            'rules' => ['required', 'array'],
        ])['rules'];
        abort_unless(is_array($input), 422);
        try {
            $save->save($staff, $input);
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() === 409 && ! $request->expectsJson()) {
                throw ValidationException::withMessages(['rules' => $exception->getMessage()])->redirectTo('/admin/taxes');
            }
            throw $exception;
        } catch (PricingTaxRuleConflict $exception) {
            throw ValidationException::withMessages(['rules' => $exception->getMessage()])->redirectTo('/admin/taxes');
        }

        return redirect('/admin/taxes')->with('tax_saved', true);
    }

    public function preview(Request $request, PreviewTax $preview): RedirectResponse
    {
        Gate::authorize('pricing.manage');
        $data = $request->validate([
            'preview' => ['required', 'array'],
            'preview.cart' => ['required', 'in:physical,digital,mixed'],
            'preview.billing_country_code' => ['nullable', 'string', 'size:2'],
            'preview.billing_region_code' => ['nullable', 'string', 'max:16'],
            'preview.billing_postal_code' => ['nullable', 'string', 'max:16'],
            'preview.shipping_country_code' => ['nullable', 'string', 'size:2'],
            'preview.shipping_region_code' => ['nullable', 'string', 'max:16'],
            'preview.shipping_postal_code' => ['nullable', 'string', 'max:16'],
            'preview.physical_amount' => ['required', 'integer', 'min:0'],
            'preview.digital_amount' => ['required', 'integer', 'min:0'],
            'preview.shipping_amount' => ['required', 'integer', 'min:0'],
            'preview.physical_class_key' => ['required', 'string', 'max:32'],
            'preview.digital_class_key' => ['required', 'string', 'max:32'],
        ])['preview'];

        return redirect('/admin/taxes')->with('tax_preview', $preview->preview($data));
    }
}
