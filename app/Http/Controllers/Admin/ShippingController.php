<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Support\SetupChoices;
use App\Domain\Shipping\Actions\PreviewShipping;
use App\Domain\Shipping\Actions\ReadShippingRules;
use App\Domain\Shipping\Actions\SaveShippingRules;
use App\Domain\Shipping\Data\ShippingDestination;
use App\Domain\Shipping\Exceptions\ShippingRuleConflict;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ShippingController
{
    public function show(Request $request, ReadShippingRules $rules, SetupChoices $choices): Response
    {
        Gate::authorize('shipping.manage');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);
        $preview = session()->get('shipping_preview');

        return Inertia::render('ShippingRules', [
            'rules' => $rules->staffRules(),
            'countries' => $choices->countries(),
            'canWrite' => $staff->hasPermission('shipping.manage'),
            'saved' => session()->get('shipping_saved', false),
            'preview' => is_array($preview) ? $preview : null,
        ]);
    }

    public function update(Request $request, SaveShippingRules $save): RedirectResponse
    {
        Gate::authorize('shipping.manage');
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
                throw ValidationException::withMessages(['rules' => $exception->getMessage()])->redirectTo('/admin/shipping');
            }
            throw $exception;
        } catch (ShippingRuleConflict $exception) {
            throw ValidationException::withMessages(['rules' => $exception->getMessage()])->redirectTo('/admin/shipping');
        }

        return redirect('/admin/shipping')->with('shipping_saved', true);
    }

    public function preview(Request $request, PreviewShipping $preview): RedirectResponse
    {
        Gate::authorize('shipping.manage');
        $data = $request->validate([
            'preview' => ['required', 'array:country_code,region_code,postal_code,physical_subtotal,weight_grams'],
            'preview.country_code' => ['required', 'string', 'size:2'],
            'preview.region_code' => ['nullable', 'string', 'max:16'],
            'preview.postal_code' => ['nullable', 'string', 'max:16'],
            'preview.physical_subtotal' => ['required', 'integer', 'min:0'],
            'preview.weight_grams' => ['nullable', 'integer', 'min:0'],
        ])['preview'];
        try {
            $quoted = $preview->preview(
                new ShippingDestination(
                    (string) $data['country_code'],
                    is_string($data['region_code'] ?? null) ? (string) $data['region_code'] : null,
                    is_string($data['postal_code'] ?? null) ? (string) $data['postal_code'] : null,
                ),
                (int) $data['physical_subtotal'],
                is_numeric($data['weight_grams'] ?? null) ? (int) $data['weight_grams'] : null,
            );
        } catch (HttpException $exception) {
            throw ValidationException::withMessages(['preview' => $exception->getMessage()])->redirectTo('/admin/shipping');
        }

        return redirect('/admin/shipping')->with('shipping_preview', $quoted->toArray());
    }
}
