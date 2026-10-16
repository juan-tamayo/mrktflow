<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\CheckoutOrders\Actions\AddOrderNote;
use App\Domain\CheckoutOrders\Actions\ReadAdminOrders;
use App\Domain\CheckoutOrders\Actions\RequestRefund;
use App\Domain\Identity\Models\StaffAccount;
use App\Http\Support\StaffTwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class OrderController
{
    public function index(Request $request, ReadAdminOrders $orders): Response
    {
        Gate::authorize('orders.read');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);

        return Inertia::render('Orders', [
            'orders' => $orders->search($staff, [
                'q' => $request->query('q'),
                'from' => $request->query('from'),
                'to' => $request->query('to'),
            ]),
            'canWriteNotes' => $staff->hasPermission('orders.read'),
        ]);
    }

    public function show(Request $request, string $orderId, ReadAdminOrders $orders): Response
    {
        Gate::authorize('orders.read');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);

        return Inertia::render('OrderDetail', [
            'order' => $orders->detail($staff, $orderId),
            'canRefund' => $staff->hasPermission('orders.refund'),
            'saved' => session()->get('order_note_saved', false),
            'refundSaved' => session()->get('order_refund_saved', false),
        ]);
    }

    public function storeRefund(Request $request, string $orderId, RequestRefund $refunds, StaffTwoFactor $twoFactor): RedirectResponse
    {
        Gate::authorize('orders.refund');
        if ($twoFactor->requiresReauthentication($request)) {
            $request->session()->put('staff_reauth_required', true);
            $request->session()->put('staff_reauth_intended', '/admin/orders/'.$orderId);
            abort_if($request->expectsJson(), 403);

            return redirect('/admin/two-factor');
        }
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);
        $input = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'amount' => ['nullable', 'integer', 'min:0'],
            'shipping_amount' => ['nullable', 'integer', 'min:0'],
            'lines' => ['nullable', 'array'],
            'lines.*.id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'integer', 'min:0'],
            'idempotency_key' => ['nullable', 'uuid'],
        ]);
        $key = $request->header('Idempotency-Key') ?? ($input['idempotency_key'] ?? null);
        if (! is_string($key) || ! Str::isUuid($key)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Send a stable refund key.'])->redirectTo('/admin/orders/'.$orderId);
        }
        try {
            $refunds->request($staff, $orderId, $input, strtolower($key));
        } catch (ValidationException $exception) {
            throw $exception->redirectTo('/admin/orders/'.$orderId);
        }

        return redirect('/admin/orders/'.$orderId)->with('order_refund_saved', true);
    }

    public function storeNote(Request $request, string $orderId, AddOrderNote $notes): RedirectResponse
    {
        Gate::authorize('orders.read');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);
        $input = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
        ]);
        try {
            $notes->add($staff, $orderId, $input['note']);
        } catch (ValidationException $exception) {
            throw $exception->redirectTo('/admin/orders/'.$orderId);
        }

        return redirect('/admin/orders/'.$orderId)->with('order_note_saved', true);
    }
}
