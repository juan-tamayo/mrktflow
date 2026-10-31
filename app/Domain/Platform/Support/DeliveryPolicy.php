<?php

declare(strict_types=1);

namespace App\Domain\Platform\Support;

use Illuminate\Support\Facades\DB;

/**
 * The single delivery decision point (PLAT-07, MKT-02).
 *
 * Marketing sends are allowed only when all of these hold: the contact
 * exists, its email is verified, a purpose grant is currently in effect,
 * and no active suppression covers the whole contact ('global') or the
 * requested transport. Capturing an email at checkout creates a contact
 * but never a grant, so capture alone can never authorize a send.
 *
 * Essential transactional templates (order receipt, shipment notice,
 * download access, account challenge) are classified here and are never
 * blocked by marketing consent or suppression: an all-marketing opt-out
 * must not silence the mail a customer needs to receive what they bought
 * or to secure their account.
 */
final class DeliveryPolicy
{
    /** @var list<string> */
    public const PURPOSES = ['cart_recovery', 'newsletter', 'stock_alert', 'price_alert'];

    /** @var list<string> */
    public const TRANSPORTS = ['email', 'sms'];

    /** @var list<string> */
    public const ESSENTIAL_TEMPLATES = ['order-receipt', 'shipment', 'download-access', 'customer-challenge'];

    public function marketingAllowed(string $email, string $purpose, string $transport = 'email'): bool
    {
        if (! in_array($purpose, self::PURPOSES, true) || ! in_array($transport, self::TRANSPORTS, true)) {
            return false;
        }
        $contact = DB::table('delivery_contacts')
            ->where('email', mb_strtolower(trim($email), 'UTF-8'))
            ->first();
        if ($contact === null || $contact->email_verified_at === null) {
            return false;
        }
        $grant = DB::table('purpose_grants')
            ->where('contact_id', $contact->id)
            ->where('purpose', $purpose)
            ->first();
        if ($grant === null || $grant->revoked_at !== null) {
            return false;
        }

        return ! DB::table('delivery_suppressions')
            ->where('contact_id', $contact->id)
            ->whereNull('cleared_at')
            ->whereIn('scope', ['global', $transport])
            ->exists();
    }

    /**
     * Essential mail is a classification, not a consent decision: known
     * transactional templates are always deliverable at this layer.
     */
    public function essentialAllowed(string $template): bool
    {
        return in_array($template, self::ESSENTIAL_TEMPLATES, true);
    }
}
