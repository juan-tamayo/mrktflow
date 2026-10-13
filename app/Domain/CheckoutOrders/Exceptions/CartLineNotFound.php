<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CartLineNotFound extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This cart line is not available.');
    }

    public function render(Request $request): JsonResponse|Response
    {
        if ($request->expectsJson() || $request->is('store-api/*')) {
            $requestId = $request->attributes->get('correlation_id');

            return response()->json([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => $this->getMessage(),
                    'fields' => (object) [],
                    'requestId' => is_string($requestId) ? $requestId : '',
                ],
            ], Response::HTTP_NOT_FOUND)->header('Cache-Control', 'no-store, private');
        }

        throw new NotFoundHttpException($this->getMessage());
    }
}
