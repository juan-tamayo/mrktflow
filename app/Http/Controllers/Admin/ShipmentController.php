<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Fulfillment\Actions\CreateShipment;
use App\Domain\Fulfillment\Rules\TrackingUrl;
use App\Domain\Identity\Models\StaffAccount;
use App\Http\Support\StaffTwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ShipmentController
{
    public function store(Request $request, string $orderId, CreateShipment $shipments, StaffTwoFactor $twoFactor): RedirectResponse
    {
        Gate::authorize('fulfillment.manage');
        if ($twoFactor->requiresReauthentication($request)) {
            $request->session()->put('staff_reauth_required', true);
            $request->session()->put('staff_reauth_intended', '/admin/orders/'.$orderId);
            abort_if($request->expectsJson(), 403);

            return redirect('/admin/two-factor');
        }
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);
        $input = $request->validate([
            'carrier' => ['nullable', 'string', 'max:120'],
            'tracking_number' => ['nullable', 'string', 'max:191'],
            'tracking_url' => ['nullable', 'string', 'max:1024', new TrackingUrl],
            'lines' => ['nullable', 'array'],
            'lines.*.id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'integer', 'min:0'],
            'idempotency_key' => ['nullable', 'uuid'],
        ]);
        $key = $request->header('Idempotency-Key') ?? ($input['idempotency_key'] ?? null);
        if (! is_string($key) || ! Str::isUuid($key)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Send a stable shipment key.'])->redirectTo('/admin/orders/'.$orderId);
        }
        try {
            $shipments->create($staff, $orderId, $input, strtolower($key));
        } catch (ValidationException $exception) {
            throw $exception->redirectTo('/admin/orders/'.$orderId);
        }

        return redirect('/admin/orders/'.$orderId)->with('order_shipment_saved', true);
    }
}
