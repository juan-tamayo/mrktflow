<?php

declare(strict_types=1);

namespace App\Domain\ShoppingTools\Actions;

use App\Domain\Catalog\Support\VariantAvailability;
use App\Domain\CheckoutOrders\Support\CartMoney;
use App\Domain\Platform\Support\DeliveryPolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The watch subscription read model (SHOP-03).
 *
 * Two facts are reported separately and never conflated: the
 * subscription's own lifecycle (`pending`, `active`, `cancelled`)
 * and its alertability — an active subscription is alertable only
 * while DeliveryPolicy allows the matching purpose for the contact
 * right now (verified contact, live grant, no suppression). A
 * purpose revoked through the signed unsubscribe or a provider
 * suppression therefore shows up here as `alertable: false` without
 * rewriting the subscription, and T057 rechecks the same policy
 * immediately before any send.
 *
 * Catalog privacy mirrors the wishlist exactly: a subscription whose
 * product has left publication, or whose variant is unlisted,
 * degrades to a bare placeholder carrying only its own facts — the
 * draft's title, SKU, price, slug and baseline are withheld — while
 * the subscription stays durable and returns in full if the product
 * is republished.
 */
final class ReadWatchSubscriptions
{
    /**
     * @return list<array<string, mixed>>
     */
    public function forContact(string $contactId): array
    {
        $contact = DB::table('delivery_contacts')->where('id', $contactId)->first();
        if ($contact === null) {
            return [];
        }

        return $this->hydrate($contactId, (string) $contact->email);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forEmail(string $email): array
    {
        $contact = DB::table('delivery_contacts')
            ->where('email', mb_strtolower(trim($email), 'UTF-8'))
            ->first();
        if ($contact === null) {
            return [];
        }

        return $this->hydrate((string) $contact->id, (string) $contact->email);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function hydrate(string $contactId, string $email): array
    {
        $rows = DB::table('watch_subscriptions')
            ->join('product_variants', 'watch_subscriptions.variant_id', '=', 'product_variants.id')
            ->join('products', 'product_variants.product_id', '=', 'products.id')
            ->leftJoin('inventory_balances', 'product_variants.id', '=', 'inventory_balances.variant_id')
            ->where('watch_subscriptions.contact_id', $contactId)
            ->orderBy('watch_subscriptions.created_at')
            ->orderBy('watch_subscriptions.alert_type')
            ->orderBy('watch_subscriptions.id')
            ->select([
                'watch_subscriptions.id',
                'watch_subscriptions.variant_id',
                'watch_subscriptions.alert_type',
                'watch_subscriptions.status as subscription_status',
                'watch_subscriptions.baseline_amount',
                'watch_subscriptions.baseline_currency',
                'watch_subscriptions.baseline_exponent',
                'watch_subscriptions.created_at as subscribed_at',
                'product_variants.product_id',
                'product_variants.sku',
                'product_variants.price_amount',
                'product_variants.currency',
                'product_variants.exponent',
                'product_variants.available as variant_listed',
                'product_variants.tracks_inventory',
                'products.title',
                'products.slug',
                'products.type',
                'products.status as product_status',
                'inventory_balances.on_hand',
                'inventory_balances.reserved',
            ])
            ->get();
        $readyDigital = $this->readyDigitalVariants($rows);
        $policy = app(DeliveryPolicy::class);
        $items = [];
        foreach ($rows as $row) {
            $type = (string) $row->alert_type;
            $purpose = SubscribeToWatch::PURPOSE_BY_TYPE[$type];
            $base = [
                'id' => (string) $row->id,
                'alert_type' => $type,
                'purpose' => $purpose,
                'status' => (string) $row->subscription_status,
                'alertable' => (string) $row->subscription_status === 'active' && $policy->marketingAllowed($email, $purpose),
                'subscribed_at' => Carbon::parse((string) $row->subscribed_at)->utc()->toJSON(),
            ];
            if ((string) $row->product_status !== 'published' || ! (bool) $row->variant_listed) {
                $items[] = $base + ['state' => 'removed'];

                continue;
            }
            $digital = (string) $row->type === 'digital';
            $availability = VariantAvailability::state(
                true,
                (bool) $row->tracks_inventory,
                $row->on_hand !== null ? (int) $row->on_hand : null,
                $row->reserved !== null ? (int) $row->reserved : null,
                $digital,
                isset($readyDigital[(string) $row->variant_id]),
            );
            $items[] = $base + [
                'state' => 'listed',
                'variant_id' => (string) $row->variant_id,
                'product_id' => (string) $row->product_id,
                'title' => (string) $row->title,
                'sku' => (string) $row->sku,
                'type' => (string) $row->type,
                'url' => '/products/'.(string) $row->slug,
                'money' => CartMoney::format((int) $row->price_amount, (string) $row->currency, (int) $row->exponent),
                'availability' => $availability,
                'available' => VariantAvailability::sellable($availability),
                'baseline' => $row->baseline_amount !== null
                    ? CartMoney::format((int) $row->baseline_amount, (string) $row->baseline_currency, (int) $row->baseline_exponent)
                    : null,
            ];
        }

        return $items;
    }

    /**
     * @param  iterable<object>  $rows
     * @return array<string, true>
     */
    private function readyDigitalVariants(iterable $rows): array
    {
        $digitalIds = [];
        foreach ($rows as $row) {
            $data = get_object_vars($row);
            $variantId = $data['variant_id'] ?? null;
            if (($data['type'] ?? null) === 'digital' && ($data['product_status'] ?? null) === 'published' && is_string($variantId)) {
                $digitalIds[] = $variantId;
            }
        }
        if ($digitalIds === []) {
            return [];
        }
        $ready = DB::table('variant_digital_assets')
            ->join('digital_asset_versions', 'variant_digital_assets.digital_asset_version_id', '=', 'digital_asset_versions.id')
            ->whereIn('variant_digital_assets.variant_id', $digitalIds)
            ->where('digital_asset_versions.status', 'ready')
            ->distinct()
            ->pluck('variant_digital_assets.variant_id')
            ->all();
        $lookup = [];
        foreach ($ready as $variantId) {
            if (is_string($variantId)) {
                $lookup[$variantId] = true;
            }
        }

        return $lookup;
    }
}
