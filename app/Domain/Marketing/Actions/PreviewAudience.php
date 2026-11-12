<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Actions;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Marketing\Support\CampaignAccess;
use App\Domain\Marketing\Support\CampaignRecords;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class PreviewAudience
{
    /**
     * @return array{
     *     eligible: int,
     *     would_send: int,
     *     capped: int,
     *     purpose: string,
     *     excluded: array{unverified: int, no_grant: int, suppressed: int, filter: int},
     *     contact_ids: list<string>
     * }
     */
    public function preview(StaffAccount $actor, string $campaignId): array
    {
        if (! CampaignAccess::canView($actor)) {
            throw new AuthorizationException;
        }
        $records = app(CampaignRecords::class);
        $campaign = $records->campaign($campaignId);
        $version = $records->workingVersion($campaign);

        return $this->evaluate($version);
    }

    /**
     * @param  array<string, mixed>  $version
     * @return array{
     *     eligible: int,
     *     would_send: int,
     *     capped: int,
     *     purpose: string,
     *     excluded: array{unverified: int, no_grant: int, suppressed: int, filter: int},
     *     contact_ids: list<string>
     * }
     */
    public function evaluate(array $version): array
    {
        $records = app(CampaignRecords::class);
        $filters = is_array($version['audience'] ?? null) ? $version['audience'] : [];
        $caps = is_array($version['caps'] ?? null) ? $version['caps'] : [];
        $purpose = $records->string($version, 'purpose');
        $max = max(1, $records->int($caps, 'max_recipients'));
        $after = $records->instant($filters['purchased_after'] ?? null);
        $before = $records->instant($filters['purchased_before'] ?? null);
        $categoryIds = [];
        foreach (is_array($filters['category_ids'] ?? null) ? $filters['category_ids'] : [] as $categoryId) {
            if (is_string($categoryId)) {
                $categoryIds[] = strtolower($categoryId);
            }
        }
        $previousId = is_string($filters['previous_campaign_id'] ?? null) ? (string) $filters['previous_campaign_id'] : null;
        $previousMode = is_string($filters['previous_campaign_mode'] ?? null) ? (string) $filters['previous_campaign_mode'] : 'any';
        $status = is_string($filters['customer_status'] ?? null) ? (string) $filters['customer_status'] : 'any';
        $purchases = $this->purchases();
        $prior = $this->priorContacts($previousId);
        $excluded = ['unverified' => 0, 'no_grant' => 0, 'suppressed' => 0, 'filter' => 0];
        $eligible = [];
        foreach (DB::table('delivery_contacts')->orderBy('id')->limit(10000)->get() as $contact) {
            $reason = $this->reason($contact, $purpose, $status, $categoryIds, $after, $before, $previousMode, $prior, $purchases);
            if ($reason === null) {
                $eligible[] = $records->string($contact, 'id');

                continue;
            }
            match ($reason) {
                'unverified' => $excluded['unverified']++,
                'no_grant' => $excluded['no_grant']++,
                'suppressed' => $excluded['suppressed']++,
                default => $excluded['filter']++,
            };
        }
        $count = count($eligible);
        $wouldSend = min($count, $max);

        return [
            'eligible' => $count,
            'would_send' => $wouldSend,
            'capped' => max(0, $count - $wouldSend),
            'purpose' => $purpose,
            'excluded' => $excluded,
            'contact_ids' => $eligible,
        ];
    }

    /**
     * @param  list<string>  $categoryIds
     * @param  list<string>  $prior
     * @param  list<array{email: string, customer_id: ?string, product_id: string, created_at: DateTimeImmutable}>  $purchases
     */
    private function reason(
        object $contact,
        string $purpose,
        string $status,
        array $categoryIds,
        ?DateTimeImmutable $after,
        ?DateTimeImmutable $before,
        string $previousMode,
        array $prior,
        array $purchases,
    ): ?string {
        $records = app(CampaignRecords::class);
        $contactId = $records->string($contact, 'id');
        $email = $records->string($contact, 'email');
        $customerId = $records->nullable($contact, 'customer_id');
        if ($records->nullable($contact, 'email_verified_at') === null) {
            return 'unverified';
        }
        $grant = DB::table('purpose_grants')
            ->where('contact_id', $contactId)
            ->where('purpose', $purpose)
            ->first();
        if ($grant === null || $grant->revoked_at !== null) {
            return 'no_grant';
        }
        $suppressed = DB::table('delivery_suppressions')
            ->where('contact_id', $contactId)
            ->whereNull('cleared_at')
            ->whereIn('scope', ['global', 'email'])
            ->exists();
        if ($suppressed) {
            return 'suppressed';
        }
        if ($status === 'customer' && $customerId === null) {
            return 'filter';
        }
        if ($status === 'guest' && $customerId !== null) {
            return 'filter';
        }
        $matchedPurchases = $this->matchingPurchases($purchases, $email, $customerId, $categoryIds, $after, $before);
        if ($status === 'new_customer' && ($customerId === null || $this->hasPaidOrder($purchases, $email, $customerId))) {
            return 'filter';
        }
        if ($categoryIds !== [] || $after !== null || $before !== null) {
            if ($matchedPurchases === []) {
                return 'filter';
            }
        }
        if ($previousMode === 'included' && ! in_array($contactId, $prior, true)) {
            return 'filter';
        }
        if ($previousMode === 'excluded' && in_array($contactId, $prior, true)) {
            return 'filter';
        }

        return null;
    }

    /**
     * @return list<array{email: string, customer_id: ?string, product_id: string, created_at: DateTimeImmutable}>
     */
    private function purchases(): array
    {
        $records = app(CampaignRecords::class);
        $rows = [];
        $query = DB::table('orders')
            ->join('order_lines', 'order_lines.order_id', '=', 'orders.id')
            ->whereIn('orders.financial_status', ['paid', 'partially_refunded'])
            ->select(['orders.email', 'orders.customer_id', 'orders.created_at', 'order_lines.product_id']);
        foreach ($query->get() as $row) {
            $created = $records->instant($records->string($row, 'created_at'));
            if ($created === null) {
                continue;
            }
            $rows[] = [
                'email' => mb_strtolower($records->string($row, 'email'), 'UTF-8'),
                'customer_id' => $records->nullable($row, 'customer_id'),
                'product_id' => strtolower($records->string($row, 'product_id')),
                'created_at' => $created,
            ];
        }

        return $rows;
    }

    /** @return list<string> */
    private function priorContacts(?string $campaignId): array
    {
        if ($campaignId === null) {
            return [];
        }
        $ids = [];
        foreach (DB::table('audience_snapshots')->where('campaign_id', $campaignId)->where('eligible', true)->get() as $row) {
            $ids[] = app(CampaignRecords::class)->string($row, 'contact_id');
        }

        return $ids;
    }

    /**
     * @param  list<array{email: string, customer_id: ?string, product_id: string, created_at: DateTimeImmutable}>  $purchases
     * @param  list<string>  $categoryIds
     * @return list<array{email: string, customer_id: ?string, product_id: string, created_at: DateTimeImmutable}>
     */
    private function matchingPurchases(
        array $purchases,
        string $email,
        ?string $customerId,
        array $categoryIds,
        ?DateTimeImmutable $after,
        ?DateTimeImmutable $before,
    ): array {
        $email = mb_strtolower($email, 'UTF-8');
        $products = $categoryIds === [] ? null : $this->productsIn($categoryIds);
        $matched = [];
        foreach ($purchases as $purchase) {
            $same = $purchase['email'] === $email || ($customerId !== null && $purchase['customer_id'] === $customerId);
            if (! $same) {
                continue;
            }
            if ($after !== null && $purchase['created_at'] < $after) {
                continue;
            }
            if ($before !== null && $purchase['created_at'] >= $before) {
                continue;
            }
            if ($products !== null && ! in_array($purchase['product_id'], $products, true)) {
                continue;
            }
            $matched[] = $purchase;
        }

        return $matched;
    }

    /**
     * @param  list<array{email: string, customer_id: ?string, product_id: string, created_at: DateTimeImmutable}>  $purchases
     */
    private function hasPaidOrder(array $purchases, string $email, string $customerId): bool
    {
        $email = mb_strtolower($email, 'UTF-8');
        foreach ($purchases as $purchase) {
            if ($purchase['email'] === $email || $purchase['customer_id'] === $customerId) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<string>  $categoryIds
     * @return list<string>
     */
    private function productsIn(array $categoryIds): array
    {
        $ids = [];
        foreach (DB::table('product_categories')->whereIn('category_id', $categoryIds)->get() as $row) {
            $ids[] = strtolower(app(CampaignRecords::class)->string($row, 'product_id'));
        }

        return $ids;
    }
}
