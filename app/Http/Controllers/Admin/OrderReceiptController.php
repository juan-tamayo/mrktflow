<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\CheckoutOrders\Actions\ResendOrderReceipt;
use App\Domain\Identity\Models\StaffAccount;
use App\Http\Support\StaffTwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class OrderReceiptController
{
    public function store(Request $request, string $orderId, ResendOrderReceipt $receipts, StaffTwoFactor $twoFactor): RedirectResponse
    {
        Gate::authorize('orders.manage');
        if ($twoFactor->requiresReauthentication($request)) {
            $request->session()->put('staff_reauth_required', true);
            $request->session()->put('staff_reauth_intended', '/admin/orders/'.$orderId);
            abort_if($request->expectsJson(), 403);

            return redirect('/admin/two-factor');
        }
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);
        $input = $request->validate([
            'idempotency_key' => ['nullable', 'uuid'],
        ]);
        $key = $request->header('Idempotency-Key') ?? ($input['idempotency_key'] ?? null);
        if (! is_string($key) || ! Str::isUuid($key)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Send a stable receipt key.'])->redirectTo('/admin/orders/'.$orderId);
        }
        try {
            $receipts->resend($staff, $orderId, strtolower($key));
        } catch (ValidationException $exception) {
            throw $exception->redirectTo('/admin/orders/'.$orderId);
        }

        return redirect('/admin/orders/'.$orderId)->with('order_receipt_resent', true);
    }
}
