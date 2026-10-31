<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class FulfillmentIdempotencyConflict extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This shipment request does not match the original intent.');
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() || $request->is('store-api/*')) {
            $requestId = $request->attributes->get('correlation_id');

            return response()->json([
                'error' => [
                    'code' => 'IDEMPOTENCY',
                    'message' => $this->getMessage(),
                    'fields' => (object) [],
                    'requestId' => is_string($requestId) ? $requestId : '',
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY)->header('Cache-Control', 'no-store, private');
        }

        return redirect()->back()->withErrors(['idempotency' => $this->getMessage()]);
    }
}
