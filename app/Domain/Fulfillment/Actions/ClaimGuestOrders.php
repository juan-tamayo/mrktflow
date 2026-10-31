<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment\Actions;

use App\Domain\Identity\Actions\ProveCustomerIdentity;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Platform\Actions\RecordEvent;
use Illuminate\Support\Facades\DB;

/**
 * Historical guest-order claiming (ID-05, the T009 slice T050 depends on).
 *
 * Only a verified identity proof can claim: the caller must start from the
 * authenticated customer, and this action re-proves identity inside its own
 * transaction (active account, verified email, current session version).
 * Matching is on the order's canonical email against the proven canonical
 * email — an unverified registration with the same address claims nothing,
 * and holding a guest download token never claims anything either.
 * Claiming atomically moves each eligible guest order to the account
 * (customer_id set, guest_token_hash cleared, satisfying the orders owner
 * check), repoints the entitlements' grantee snapshot, and revokes any
 * outstanding guest verification tokens for the claimed orders.
 */
final class ClaimGuestOrders
{
    public function claim(CustomerAccount $actor): int
    {
        return DB::transaction(function () use ($actor): int {
            $identity = app(ProveCustomerIdentity::class)->prove($actor);
            $orders = DB::table('orders')->whereNull('customer_id')
                ->where('email', $identity->email)
                ->orderBy('id')->lockForUpdate()->get();
            $claimed = 0;
            foreach ($orders as $order) {
                $orderId = $this->string($order, 'id');
                DB::table('orders')->where('id', $orderId)->update([
                    'customer_id' => $identity->customerId,
                    'guest_token_hash' => null,
                    'updated_at' => now(),
                ]);
                DB::table('entitlements')->where('order_id', $orderId)->whereNull('customer_id')
                    ->update(['customer_id' => $identity->customerId, 'updated_at' => now()]);
                DB::table('guest_order_verifications')->where('order_id', $orderId)
                    ->whereNull('consumed_at')->whereNull('revoked_at')
                    ->update(['revoked_at' => now(), 'token_digest' => null, 'updated_at' => now()]);
                app(RecordEvent::class)->record('platform.audit.recorded', 'guest-order:claimed:'.$orderId.':'.$identity->customerId, [
                    'action' => 'fulfillment.guest_order_claimed', 'subject_type' => 'order', 'subject_id' => $orderId,
                    'actor_type' => 'customer', 'actor_id' => $identity->customerId,
                ]);
                $claimed++;
            }

            return $claimed;
        });
    }

    private function string(object $row, string $key): string
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }
}
