<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CheckoutQuoteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $quote = $this->resource;
        if (! is_array($quote)) {
            return [];
        }

        return [
            'id' => $quote['id'],
            'cartRevision' => $quote['cart_revision'],
            'email' => $quote['email'],
            'fingerprint' => $quote['fingerprint'],
            'expiresAt' => $quote['expires_at'],
            'acceptedAt' => $quote['accepted_at'],
            'priceMode' => $quote['price_mode'],
            'requiresDelivery' => $quote['requires_delivery'],
            'digitalOnly' => $quote['digital_only'],
            'coupon' => $quote['coupon'],
            'merchandise' => $quote['merchandise'],
            'discount' => $quote['discount'],
            'shipping' => $quote['shipping'],
            'tax' => $quote['tax'],
            'grandTotal' => $quote['grand_total'],
            'lines' => $quote['lines'],
            'eligibleMethods' => $quote['eligible_methods'],
            'billing' => $quote['billing'],
            'shippingAddress' => $quote['shipping_address'],
            'shippingMethodId' => $quote['shipping_method_id'],
        ];
    }
}
