<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CheckoutSessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $session = $this->resource;
        if (! is_array($session)) {
            return [];
        }

        return [
            'id' => $session['id'],
            'orderId' => $session['order_id'],
            'orderNumber' => $session['order_number'],
            'orderStatus' => $session['order_status'],
            'attemptStatus' => $session['attempt_status'],
            'cartRevision' => $session['cart_revision'],
            'fingerprint' => $session['fingerprint'],
            'zeroTotal' => $session['zero_total'],
            'grandTotal' => $session['grand_total'],
            'expiresAt' => $session['expires_at'],
            'providerRedirect' => $session['provider_redirect'],
        ];
    }
}
