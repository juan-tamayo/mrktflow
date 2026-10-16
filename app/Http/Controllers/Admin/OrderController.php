<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\CheckoutOrders\Actions\AddOrderNote;
use App\Domain\CheckoutOrders\Actions\ReadAdminOrders;
use App\Domain\Identity\Models\StaffAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
            'saved' => session()->get('order_note_saved', false),
        ]);
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
