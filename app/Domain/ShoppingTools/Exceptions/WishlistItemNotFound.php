<?php

declare(strict_types=1);

namespace App\Domain\ShoppingTools\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Raised when a removal addresses an item outside the caller's own
 * list. Foreign and nonexistent ids are indistinguishable on purpose:
 * the response must never confirm that another shopper saved an item.
 */
final class WishlistItemNotFound extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This saved item is not available.');
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
