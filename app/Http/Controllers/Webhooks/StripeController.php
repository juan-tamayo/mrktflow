<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Domain\CheckoutOrders\Actions\AcceptProviderEvent;
use App\Domain\CheckoutOrders\Exceptions\ProviderWebhookRejected;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class StripeController
{
    public function store(Request $request, AcceptProviderEvent $inbox): Response
    {
        try {
            $inbox->accept(
                $request->getContent(),
                $request->header('Stripe-Signature'),
                $request->header('Stripe-Account'),
            );
        } catch (ProviderWebhookRejected) {
            return response('', Response::HTTP_BAD_REQUEST)->header('Cache-Control', 'no-store, private');
        } catch (Throwable) {
            return response('', Response::HTTP_SERVICE_UNAVAILABLE)->header('Cache-Control', 'no-store, private');
        }

        return response('', Response::HTTP_NO_CONTENT)->header('Cache-Control', 'no-store, private');
    }
}
