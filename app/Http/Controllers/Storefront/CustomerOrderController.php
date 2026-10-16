<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Domain\CheckoutOrders\Actions\ReadCustomerOrders;
use App\Domain\Identity\Models\CustomerAccount;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

final class CustomerOrderController
{
    public function index(Request $request, ReadCustomerOrders $orders): View
    {
        $customer = $request->user('customer');
        abort_unless($customer instanceof CustomerAccount, 403);

        return view('default-theme::account-orders', [
            'orders' => $orders->list($customer)['data'],
        ]);
    }

    public function show(Request $request, string $orderId, ReadCustomerOrders $orders): Response
    {
        $customer = $request->user('customer');
        abort_unless($customer instanceof CustomerAccount, 403);
        if (! Str::isUuid($orderId) || $orderId !== strtolower($orderId)) {
            abort(404);
        }
        $order = $orders->detail($customer, $orderId);

        return response()->view('default-theme::account-order', [
            'order' => $order,
        ])->header('Cache-Control', 'no-store, private');
    }
}
