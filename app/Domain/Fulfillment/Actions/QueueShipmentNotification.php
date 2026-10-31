<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment\Actions;

use App\Domain\CheckoutOrders\Actions\RecordOrderEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Queues the durable shipment notification intent (OPS-01), mirroring the
 * order receipt dedupe: one `event` intent per shipment, keyed
 * `shipment:{shipmentId}` and backed by a unique (shipment_id, template)
 * constraint. Replays and repeated calls return the existing intent and
 * record no second timeline event. Actual delivery remains the mail
 * transport's concern; nothing is sent here.
 */
final class QueueShipmentNotification
{
    /**
     * @return array{id: string, intent_key: string, shipment_id: string}
     */
    public function queue(string $shipmentId): array
    {
        if (! Str::isUuid($shipmentId) || $shipmentId !== strtolower($shipmentId)) {
            throw new NotFoundHttpException;
        }

        return DB::transaction(function () use ($shipmentId): array {
            $shipment = DB::table('shipments')->where('id', $shipmentId)->lockForUpdate()->first();
            if ($shipment === null) {
                throw new NotFoundHttpException;
            }
            $orderId = $this->string($shipment, 'order_id');
            $intentKey = 'shipment:'.$shipmentId;
            $existing = DB::table('shipment_notification_intents')->where('intent_key', $intentKey)->lockForUpdate()->first();
            if ($existing !== null) {
                return ['id' => $this->string($existing, 'id'), 'intent_key' => $intentKey, 'shipment_id' => $shipmentId];
            }
            $order = DB::table('orders')->where('id', $orderId)->first();
            if ($order === null) {
                throw new NotFoundHttpException;
            }
            $id = (string) Str::uuid();
            try {
                DB::table('shipment_notification_intents')->insert([
                    'id' => $id,
                    'shipment_id' => $shipmentId,
                    'order_id' => $orderId,
                    'template' => 'shipment',
                    'recipient' => $this->string($order, 'email'),
                    'intent_key' => $intentKey,
                    'kind' => 'event',
                    'status' => 'queued',
                    'created_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                $replay = DB::table('shipment_notification_intents')->where('intent_key', $intentKey)->first();
                if ($replay === null) {
                    throw new NotFoundHttpException;
                }

                return ['id' => $this->string($replay, 'id'), 'intent_key' => $intentKey, 'shipment_id' => $shipmentId];
            }
            app(RecordOrderEvent::class)->record($orderId, 'shipment_notification_queued', [
                'intent_id' => $id,
                'shipment_id' => $shipmentId,
                'template' => 'shipment',
                'number' => $this->string($order, 'number'),
            ], $shipmentId);

            return ['id' => $id, 'intent_key' => $intentKey, 'shipment_id' => $shipmentId];
        });
    }

    private function string(object $row, string $key): string
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }
}
