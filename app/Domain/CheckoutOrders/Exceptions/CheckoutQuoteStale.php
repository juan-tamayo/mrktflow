<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class CheckoutQuoteStale extends RuntimeException
{
    public function __construct(string $message = 'This quote is no longer current. Review the updated totals.')
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() || $request->is('store-api/*')) {
            $requestId = $request->attributes->get('correlation_id');

            return response()->json([
                'error' => [
                    'code' => 'STALE',
                    'message' => $this->getMessage(),
                    'fields' => (object) [],
                    'requestId' => is_string($requestId) ? $requestId : '',
                ],
            ], Response::HTTP_CONFLICT)->header('Cache-Control', 'no-store, private');
        }

        return redirect('/checkout')->withErrors(['quote' => $this->getMessage()]);
    }
}
