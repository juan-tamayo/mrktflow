<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Actions;

use App\Domain\CheckoutOrders\Exceptions\CartConflict;
use App\Domain\CheckoutOrders\Exceptions\CheckoutQuoteStale;
use App\Domain\CheckoutOrders\Support\CartOwner;
use App\Domain\Platform\Actions\RecordEvent;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AcceptQuote
{
    /**
     * @return array<string, mixed>
     */
    public function accept(CartOwner $owner, string $quoteId, string $fingerprint, bool $policyAcknowledged): array
    {
        if (! $policyAcknowledged) {
            throw ValidationException::withMessages(['policy' => 'Acknowledge the store policies before continuing.']);
        }

        return DB::transaction(function () use ($owner, $quoteId, $fingerprint): array {
            $quotes = app(CreateQuote::class);
            $row = $quotes->ownedRow($owner, $quoteId);
            if ($row['superseded_at'] !== null) {
                throw new CheckoutQuoteStale;
            }
            $expires = new DateTimeImmutable($row['expires_at'], new DateTimeZone('UTC'));
            if (now()->utc() >= $expires) {
                throw new CheckoutQuoteStale('This quote expired. Review the updated totals.');
            }
            $payload = $quotes->payload($row, $quoteId);
            $quotedAt = new DateTimeImmutable($row['quoted_at'], new DateTimeZone('UTC'));
            try {
                $current = $quotes->compute($owner, $payload['input'], $quotedAt);
            } catch (CartConflict) {
                throw new CheckoutQuoteStale;
            }
            if ($current['fingerprint'] !== $row['fingerprint']
                || $fingerprint !== $row['fingerprint']
                || $current['cart_revision'] !== $row['cart_revision']) {
                throw new CheckoutQuoteStale;
            }
            $now = now();
            if ($row['accepted_at'] === null) {
                DB::table('checkout_quotes')->where('id', $quoteId)->update([
                    'accepted_at' => $now,
                    'updated_at' => $now,
                ]);
                app(RecordEvent::class)->record('platform.audit.recorded', 'checkout:quote:accept:'.$quoteId, [
                    'action' => 'checkout.quote.accepted',
                    'subject_type' => 'checkout_quote',
                    'subject_id' => $quoteId,
                    'actor_type' => $owner->customerId === null ? 'system' : 'customer',
                    'actor_id' => $owner->customerId,
                    'metadata' => ['version' => $current['cart_revision']],
                ]);
            }
            $acceptedAt = $row['accepted_at'] === null
                ? $now->utc()->format('Y-m-d\\TH:i:s\\Z')
                : (new DateTimeImmutable($row['accepted_at'], new DateTimeZone('UTC')))->format('Y-m-d\\TH:i:s\\Z');

            return $quotes->publicSnapshot(
                $quoteId,
                $current['snapshot'],
                $expires->format('Y-m-d\\TH:i:s\\Z'),
                $acceptedAt,
            );
        });
    }
}
