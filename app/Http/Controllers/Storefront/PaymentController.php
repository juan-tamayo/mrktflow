<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Domain\CheckoutOrders\Actions\AcceptQuote;
use App\Domain\CheckoutOrders\Actions\CreateCheckoutIntent;
use App\Domain\CheckoutOrders\Actions\StartPayment;
use App\Domain\CheckoutOrders\Exceptions\CheckoutIdempotencyConflict;
use App\Domain\CheckoutOrders\Exceptions\CheckoutIntentConflict;
use App\Domain\CheckoutOrders\Exceptions\CheckoutQuoteStale;
use App\Domain\CheckoutOrders\Support\CartOwner;
use App\Http\Resources\CheckoutSessionResource;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class PaymentController
{
    public function start(Request $request, string $sessionId, StartPayment $payments): JsonResponse|RedirectResponse
    {
        $this->installed();
        try {
            $session = $payments->start(CartOwner::fromRequest($request), strtolower($sessionId));
        } catch (Throwable $exception) {
            return $this->failed($request, $exception);
        }

        return $this->saved($request, $session);
    }

    public function payQuote(Request $request, string $quoteId, CreateCheckoutIntent $intents, StartPayment $payments): JsonResponse|RedirectResponse
    {
        $this->installed();
        $fingerprint = $request->input('fingerprint');
        if (! is_string($fingerprint) || $fingerprint === '') {
            return $this->failed($request, new CheckoutQuoteStale);
        }
        try {
            $owner = CartOwner::fromRequest($request);
            app(AcceptQuote::class)->accept($owner, $quoteId, $fingerprint, true);
            $key = $request->session()->get('checkout_pay_'.$quoteId);
            if (! is_string($key) || ! Str::isUuid($key)) {
                $key = (string) Str::uuid();
                $request->session()->put('checkout_pay_'.$quoteId, $key);
            }
            $session = $payments->start($owner, $intents->create($owner, strtolower($quoteId), strtolower($key))['id']);
        } catch (Throwable $exception) {
            return $this->failed($request, $exception);
        }

        return $this->saved($request, $session);
    }

    public function returned(Request $request, string $sessionId, CreateCheckoutIntent $intents): View
    {
        $this->installed();

        return $this->status($request, $sessionId, $intents, 'Payment is still processing. This page cannot complete the order.');
    }

    public function cancelled(Request $request, string $sessionId, CreateCheckoutIntent $intents): View
    {
        $this->installed();

        return $this->status($request, $sessionId, $intents, 'Payment was not completed. Your items are still held until this checkout expires.');
    }

    private function status(Request $request, string $sessionId, CreateCheckoutIntent $intents, string $message): View
    {
        try {
            $session = $intents->snapshot(CartOwner::fromRequest($request), strtolower($sessionId));
        } catch (Throwable) {
            $session = ['attempt_status' => 'unknown', 'order_status' => 'pending'];
        }

        return view('default-theme::payment-status', [
            'message' => $message,
            'attemptStatus' => match (is_string($session['attempt_status'] ?? null) ? (string) $session['attempt_status'] : 'unknown') {
                'awaiting_payment' => 'waiting for the processor',
                'created' => 'not started',
                'unknown' => 'needs reconciliation',
                'succeeded' => 'complete',
                'failed' => 'unsuccessful',
                'expired' => 'expired',
                default => 'needs reconciliation',
            },
            'orderStatus' => match (is_string($session['order_status'] ?? null) ? (string) $session['order_status'] : 'pending') {
                'pending' => 'pending',
                'confirmed' => 'confirmed',
                default => 'pending',
            },
        ]);
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function saved(Request $request, array $session): JsonResponse|RedirectResponse
    {
        $redirect = is_string($session['provider_redirect'] ?? null) ? (string) $session['provider_redirect'] : null;
        if (! ($request->expectsJson() || $request->is('store-api/*'))) {
            if (($session['zero_total'] ?? false) === true && is_string($session['order_id'] ?? null)) {
                return redirect('/checkout/orders/'.$session['order_id']);
            }
            if ($redirect !== null) {
                return redirect()->away($redirect);
            }

            return redirect('/checkout/payment/'.$session['id'].'/return');
        }

        return (new CheckoutSessionResource($session))->response()->header('Cache-Control', 'no-store, private');
    }

    private function failed(Request $request, Throwable $exception): JsonResponse|RedirectResponse
    {
        if ($exception instanceof CheckoutQuoteStale
            || $exception instanceof CheckoutIdempotencyConflict
            || $exception instanceof CheckoutIntentConflict) {
            throw $exception;
        }
        $message = $exception instanceof InvalidArgumentException
            ? $exception->getMessage()
            : throw $exception;
        if ($request->expectsJson() || $request->is('store-api/*')) {
            $requestId = $request->attributes->get('correlation_id');

            return response()->json([
                'error' => [
                    'code' => 'VALIDATION',
                    'message' => $message,
                    'fields' => (object) [],
                    'requestId' => is_string($requestId) ? $requestId : '',
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY)->header('Cache-Control', 'no-store, private');
        }

        return redirect()->back()->withErrors(['payment' => $message]);
    }

    private function installed(): void
    {
        abort_unless(DB::table('installations')->where('id', 1)->value('status') === 'complete', 503);
    }
}
