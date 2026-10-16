<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Domain\CheckoutOrders\Actions\CompleteFreeOrder;
use App\Domain\CheckoutOrders\Actions\CreateCheckoutIntent;
use App\Domain\CheckoutOrders\Exceptions\CheckoutQuoteStale;
use App\Domain\CheckoutOrders\Support\CartMoney;
use App\Domain\CheckoutOrders\Support\CartOwner;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class OrderStatusController
{
    public function show(Request $request, string $orderId, CompleteFreeOrder $free, CreateCheckoutIntent $intents): Response
    {
        $this->installed();
        if (! Str::isUuid($orderId) || $orderId !== strtolower($orderId)) {
            abort(404);
        }
        try {
            $session = $this->owned($request, $orderId, $intents);
            if ($session['zero_total'] === true) {
                $free->complete($session['id']);
                $session = $this->owned($request, $orderId, $intents);
            }
        } catch (Throwable $exception) {
            if ($exception instanceof CheckoutQuoteStale) {
                abort(404);
            }
            throw $exception;
        }
        $pending = ($session['order_status'] ?? '') === 'pending';
        $total = is_array($session['grand_total'] ?? null) ? $session['grand_total'] : CartMoney::format(0, 'USD', 2);
        $formatted = is_string($total['formatted'] ?? null) ? (string) $total['formatted'] : 'USD 0.00';
        $response = response()->view('default-theme::order-status', [
            'pending' => $pending,
            'zeroTotal' => $session['zero_total'] === true,
            'orderNumber' => is_string($session['order_number'] ?? null) ? (string) $session['order_number'] : '',
            'orderStatus' => $pending ? 'pending' : 'confirmed',
            'total' => $formatted,
            'refreshUrl' => '/checkout/orders/'.$orderId,
        ])->header('Cache-Control', 'no-store, private');
        if ($pending) {
            $response->headers->set('Refresh', '5');
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function owned(Request $request, string $orderId, CreateCheckoutIntent $intents): array
    {
        $attempt = DB::table('payment_attempts')->where('order_id', $orderId)->orderBy('created_at')->first();
        if ($attempt === null) {
            throw new CheckoutQuoteStale;
        }
        $id = get_object_vars($attempt)['id'] ?? null;
        if (! is_string($id) || $id === '') {
            throw new CheckoutQuoteStale;
        }

        return $intents->snapshot(CartOwner::fromRequest($request), $id);
    }

    private function installed(): void
    {
        abort_unless(DB::table('installations')->where('id', 1)->value('status') === 'complete', 503);
    }
}
