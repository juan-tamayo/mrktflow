<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Domain\CheckoutOrders\Actions\AcceptQuote;
use App\Domain\CheckoutOrders\Actions\CreateQuote;
use App\Domain\CheckoutOrders\Actions\ReadCart;
use App\Domain\CheckoutOrders\Exceptions\CartConflict;
use App\Domain\CheckoutOrders\Exceptions\CheckoutQuoteStale;
use App\Domain\CheckoutOrders\Support\CartOwner;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Platform\Support\SetupChoices;
use App\Domain\Pricing\Exceptions\PricingCouponExhausted;
use App\Domain\Pricing\Exceptions\PricingCouponRejected;
use App\Domain\Pricing\Exceptions\PricingTaxCoverageMissing;
use App\Domain\Shipping\Exceptions\ShippingInvalidQuote;
use App\Domain\Shipping\Exceptions\ShippingMissingMeasurement;
use App\Domain\Shipping\Exceptions\ShippingNoService;
use App\Domain\ThemesContent\Rendering\DefaultThemeRenderer;
use App\Http\Resources\CheckoutQuoteResource;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class CheckoutController
{
    public function show(Request $request, ReadCart $read, DefaultThemeRenderer $renderer, SetupChoices $choices): View|RedirectResponse
    {
        $this->installed();
        $cart = $read->snapshot(CartOwner::fromRequest($request));
        if ($cart['lines'] === []) {
            return redirect('/cart');
        }
        $email = '';
        $customer = $request->user('customer');
        if ($customer instanceof CustomerAccount) {
            $email = (string) $customer->email;
        }

        return $renderer->checkout($cart, null, $choices->countries(), $email);
    }

    public function showQuote(Request $request, string $quoteId, CreateQuote $quotes, ReadCart $read, DefaultThemeRenderer $renderer, SetupChoices $choices): View
    {
        $this->installed();
        $owner = CartOwner::fromRequest($request);
        $quote = $quotes->snapshot($owner, $quoteId);
        $cart = $read->snapshot($owner);

        return $renderer->checkout($cart, $quote, $choices->countries(), (string) $quote['email'], (new CheckoutQuoteResource($quote))->resolve($request));
    }

    public function store(Request $request, CreateQuote $quotes): JsonResponse|RedirectResponse
    {
        $this->installed();
        try {
            $quote = $quotes->create(CartOwner::fromRequest($request), $request->all());
        } catch (Throwable $exception) {
            return $this->failed($request, $exception);
        }

        return $this->saved($request, $quote);
    }

    public function accept(Request $request, string $quoteId, AcceptQuote $accept): JsonResponse|RedirectResponse
    {
        $this->installed();
        $acknowledged = $request->boolean('policy_acknowledged') || $request->boolean('policyAcknowledged');
        $fingerprint = $request->input('fingerprint');
        if (! is_string($fingerprint) || $fingerprint === '') {
            return $this->failed($request, ValidationException::withMessages(['fingerprint' => 'This quote is no longer current. Review the updated totals.']));
        }
        try {
            $quote = $accept->accept(CartOwner::fromRequest($request), $quoteId, $fingerprint, $acknowledged);
        } catch (Throwable $exception) {
            return $this->failed($request, $exception);
        }

        return $this->saved($request, $quote);
    }

    /**
     * @param  array<string, mixed>  $quote
     */
    private function saved(Request $request, array $quote): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() || $request->is('store-api/*')) {
            return (new CheckoutQuoteResource($quote))->response()->header('Cache-Control', 'no-store, private');
        }

        return redirect('/checkout/'.$quote['id']);
    }

    private function failed(Request $request, Throwable $exception): JsonResponse|RedirectResponse
    {
        if ($exception instanceof CartConflict || $exception instanceof CheckoutQuoteStale) {
            throw $exception;
        }
        $message = match (true) {
            $exception instanceof ValidationException => $exception->getMessage(),
            $exception instanceof ShippingNoService,
            $exception instanceof ShippingMissingMeasurement,
            $exception instanceof ShippingInvalidQuote,
            $exception instanceof PricingCouponRejected,
            $exception instanceof PricingCouponExhausted,
            $exception instanceof PricingTaxCoverageMissing => $exception->getMessage(),
            default => throw $exception,
        };
        $errors = $exception instanceof ValidationException ? $exception->errors() : ['quote' => [$message]];
        if ($request->expectsJson() || $request->is('store-api/*')) {
            $requestId = $request->attributes->get('correlation_id');

            return response()->json([
                'error' => [
                    'code' => 'VALIDATION',
                    'message' => $message,
                    'fields' => (object) $errors,
                    'requestId' => is_string($requestId) ? $requestId : '',
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY)->header('Cache-Control', 'no-store, private');
        }

        return redirect()->back()->withErrors($errors);
    }

    private function installed(): void
    {
        abort_unless(DB::table('installations')->where('id', 1)->value('status') === 'complete', 503);
    }
}
