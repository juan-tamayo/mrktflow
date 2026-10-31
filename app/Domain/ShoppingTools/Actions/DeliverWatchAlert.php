<?php

declare(strict_types=1);

namespace App\Domain\ShoppingTools\Actions;

use App\Domain\Catalog\Support\VariantAvailability;
use App\Domain\CheckoutOrders\Support\CartMoney;
use App\Domain\Identity\Support\CustomerMailConfiguration;
use App\Domain\Platform\Support\DeliveryPolicy;
use App\Domain\Platform\Support\UnsubscribeTokens;
use App\Mail\WatchAlertMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Delivers one queued watch alert intent (SHOP-04, OPS-01).
 *
 * Nothing about the catalog event that queued the intent is trusted
 * at send time: the claim rechecks, under row locks, that the
 * subscription is still active, that DeliveryPolicy still allows the
 * purpose for the contact (an unsubscribe or a suppression landing
 * after the event wins), that the variant is still listed, and that
 * the triggering condition still holds against current state. Any
 * failed recheck finishes the intent `cancelled` with the reason as
 * its error code and sends nothing. The mail itself renders from
 * current catalog state — title, current purchasable price and the
 * product link are read in the claim, never snapshotted at event
 * time — and carries the signed purpose-scoped unsubscribe link.
 * Transport failures finish the intent `failed` visibly rather than
 * retrying silently; the claim/finish discipline mirrors
 * DeliverCustomerChallengeMail (claim in a transaction, network send
 * outside it, finish in a transaction).
 */
final class DeliverWatchAlert
{
    public function deliver(string $intentId): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Network mail delivery cannot run inside a database transaction.');
        }
        $message = $this->claim($intentId);
        if ($message === null) {
            return;
        }
        try {
            $accepted = Mail::mailer($message['mailer'])->to($message['email'])->send(new WatchAlertMessage(
                $intentId,
                $message['alertType'],
                $message['title'],
                $message['money'],
                $message['productUrl'],
                $message['unsubscribeUrl'],
                $message['fromEmail'],
                $message['fromName'],
            ));
            if ($accepted === null) {
                throw new RuntimeException('Mail was not accepted.');
            }
        } catch (Throwable $exception) {
            $this->finish($intentId, false, 'transport_failed');
            Log::warning('shopping.watch_alert_transport_failed', ['intent_id' => $intentId, 'error_code' => 'transport_failed', 'exception_class' => $exception::class]);

            return;
        }
        $this->finish($intentId, true, null);
    }

    /**
     * @return array{mailer: string, email: string, alertType: string, title: string, money: array{amount: int, currency: string, exponent: int, formatted: string}, productUrl: string, unsubscribeUrl: string, fromEmail: string, fromName: string}|null
     */
    private function claim(string $intentId): ?array
    {
        return DB::transaction(function () use ($intentId): ?array {
            $intent = DB::table('watch_alert_intents')->where('id', $intentId)->lockForUpdate()->first();
            if ($intent === null || (string) $intent->status !== 'queued') {
                return null;
            }
            $cancel = function (string $reason) use ($intentId): ?array {
                DB::table('watch_alert_intents')->where('id', $intentId)->update([
                    'status' => 'cancelled', 'error_code' => $reason, 'updated_at' => now(),
                ]);

                return null;
            };
            $subscription = DB::table('watch_subscriptions')->where('id', $intent->subscription_id)->lockForUpdate()->first();
            if ($subscription === null || (string) $subscription->status !== 'active') {
                return $cancel('subscription_inactive');
            }
            $contact = DB::table('delivery_contacts')->where('id', $intent->contact_id)->lockForUpdate()->first();
            if ($contact === null) {
                return $cancel('contact_missing');
            }
            $alertType = (string) $intent->alert_type;
            $purpose = SubscribeToWatch::PURPOSE_BY_TYPE[$alertType];
            $email = (string) $contact->email;
            if (! app(DeliveryPolicy::class)->marketingAllowed($email, $purpose)) {
                return $cancel('delivery_not_allowed');
            }
            $variant = DB::table('product_variants')
                ->join('products', 'product_variants.product_id', '=', 'products.id')
                ->leftJoin('inventory_balances', 'product_variants.id', '=', 'inventory_balances.variant_id')
                ->where('product_variants.id', $intent->variant_id)
                ->select([
                    'product_variants.id',
                    'product_variants.available as variant_listed',
                    'product_variants.tracks_inventory',
                    'product_variants.price_amount',
                    'product_variants.currency',
                    'product_variants.exponent',
                    'products.status as product_status',
                    'products.type as product_type',
                    'products.title',
                    'products.slug',
                    'inventory_balances.on_hand',
                    'inventory_balances.reserved',
                ])
                ->first();
            if ($variant === null || (string) $variant->product_status !== 'published' || ! (bool) $variant->variant_listed) {
                return $cancel('no_longer_listed');
            }
            if (! $this->conditionHolds($alertType, $variant, $subscription)) {
                return $cancel('condition_no_longer_met');
            }
            $configuration = app(CustomerMailConfiguration::class)->ready();
            if ($configuration === null) {
                DB::table('watch_alert_intents')->where('id', $intentId)->update([
                    'status' => 'failed', 'error_code' => 'mail_configuration_unavailable', 'updated_at' => now(),
                ]);

                return null;
            }
            DB::table('watch_alert_intents')->where('id', $intentId)->update([
                'status' => 'sending', 'error_code' => null, 'updated_at' => now(),
            ]);
            $token = app(UnsubscribeTokens::class)->issue((string) $contact->id, $purpose);

            return [
                'mailer' => $configuration['mailer'],
                'email' => $email,
                'alertType' => $alertType,
                'title' => (string) $variant->title,
                'money' => CartMoney::format((int) $variant->price_amount, (string) $variant->currency, (int) $variant->exponent),
                'productUrl' => $configuration['baseUrl'].'/products/'.(string) $variant->slug,
                'unsubscribeUrl' => $configuration['baseUrl'].'/preferences/unsubscribe?token='.$token,
                'fromEmail' => $configuration['fromEmail'],
                'fromName' => $configuration['fromName'],
            ];
        });
    }

    private function finish(string $intentId, bool $accepted, ?string $errorCode): void
    {
        DB::transaction(function () use ($intentId, $accepted, $errorCode): void {
            $intent = DB::table('watch_alert_intents')->where('id', $intentId)->lockForUpdate()->first();
            if ($intent === null || (string) $intent->status !== 'sending') {
                return;
            }
            DB::table('watch_alert_intents')->where('id', $intentId)->update($accepted
                ? ['status' => 'sent', 'sent_at' => now(), 'error_code' => null, 'updated_at' => now()]
                : ['status' => 'failed', 'error_code' => $errorCode, 'updated_at' => now()]);
        });
    }

    private function conditionHolds(string $alertType, object $variant, object $subscription): bool
    {
        $v = get_object_vars($variant);
        $s = get_object_vars($subscription);
        if ($alertType === 'price') {
            return ($s['baseline_amount'] ?? null) !== null
                && (string) ($v['currency'] ?? '') === (string) ($s['baseline_currency'] ?? '')
                && (int) ($v['exponent'] ?? -1) === (int) ($s['baseline_exponent'] ?? -2)
                && (int) ($v['price_amount'] ?? 0) < (int) $s['baseline_amount'];
        }
        $digital = (string) ($v['product_type'] ?? '') === 'digital';
        $hasReadyFile = $digital && DB::table('variant_digital_assets')
            ->join('digital_asset_versions', 'variant_digital_assets.digital_asset_version_id', '=', 'digital_asset_versions.id')
            ->where('variant_digital_assets.variant_id', (string) ($v['id'] ?? ''))
            ->where('digital_asset_versions.status', 'ready')
            ->exists();

        return VariantAvailability::state(
            true,
            (bool) ($v['tracks_inventory'] ?? false),
            ($v['on_hand'] ?? null) !== null ? (int) $v['on_hand'] : null,
            ($v['reserved'] ?? null) !== null ? (int) $v['reserved'] : null,
            $digital,
            $hasReadyFile,
        ) === VariantAvailability::IN_STOCK;
    }
}
