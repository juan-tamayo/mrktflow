<?php

declare(strict_types=1);

namespace App\Domain\Platform\Actions;

use App\Domain\Platform\Support\DeliveryPolicy;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Consent and suppression transitions for shared delivery contacts
 * (PLAT-07, MKT-02).
 *
 * Consent is a fact with provenance, not a boolean: every purpose grant
 * records source, notice revision and confirmation evidence alongside its
 * granted/revoked timestamps, and every real transition writes one
 * platform audit. Transitions are idempotent — applying the state that
 * is already in effect changes nothing and audits nothing.
 *
 * Verification and consent are separate facts: a contact may be verified
 * (its address demonstrably belongs to someone) while holding no grants
 * at all, which is exactly the state a checkout-captured email is in.
 * Grants require a verified contact.
 *
 * Suppressions are facts too. A global suppression with reason
 * `complaint` or `hard_bounce` is never cleared here — in particular not
 * by re-importing the contact. An `opt_out`/`manual` global suppression
 * is cleared only as part of the contact's own deliberate grant through
 * a protected verified channel (the preference center or a double
 * opt-in confirmation); unprotected sources (imports, replays) can
 * record a grant fact but can never clear a suppression, so a suppressed
 * contact cannot be silently re-enrolled.
 */
final class UpdateDeliveryPreference
{
    public const NOTICE_REVISION = '2026-10-v1';

    /** @var list<string> */
    private const PROTECTED_SOURCES = ['preference_center', 'double_opt_in'];

    /** @var list<string> */
    private const SCOPES = ['global', 'email', 'sms'];

    /** @var list<string> */
    private const REASONS = ['opt_out', 'complaint', 'hard_bounce', 'manual'];

    public function ensureContact(string $email, ?string $customerId = null): string
    {
        $email = $this->normalizeEmail($email);

        return DB::transaction(function () use ($email, $customerId): string {
            $existing = DB::table('delivery_contacts')->where('email', $email)->lockForUpdate()->first();
            if ($existing !== null) {
                if ($customerId !== null && $existing->customer_id === null) {
                    DB::table('delivery_contacts')->where('id', $existing->id)
                        ->update(['customer_id' => $customerId, 'updated_at' => now()]);
                }

                return (string) $existing->id;
            }
            $id = (string) Str::uuid();
            DB::table('delivery_contacts')->insert([
                'id' => $id,
                'email' => $email,
                'customer_id' => $customerId,
                'email_verified_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $id;
        });
    }

    public function markVerified(string $contactId, string $evidence, string $actorType = 'system', ?string $actorId = null): bool
    {
        $this->requireProvenance($evidence, 'evidence');

        return DB::transaction(function () use ($contactId, $evidence, $actorType, $actorId): bool {
            $contact = $this->lockContact($contactId);
            if ($contact->email_verified_at !== null) {
                return false;
            }
            DB::table('delivery_contacts')->where('id', $contactId)
                ->update(['email_verified_at' => now(), 'updated_at' => now()]);
            $this->audit('delivery.verified', $contactId, $actorType, $actorId, $evidence);

            return true;
        });
    }

    public function grant(string $contactId, string $purpose, string $source, string $evidence, string $actorType = 'system', ?string $actorId = null): bool
    {
        $this->requirePurpose($purpose);
        $this->requireProvenance($source, 'source');
        $this->requireProvenance($evidence, 'evidence');

        return DB::transaction(function () use ($contactId, $purpose, $source, $evidence, $actorType, $actorId): bool {
            $contact = $this->lockContact($contactId);
            if ($contact->email_verified_at === null) {
                throw new DomainException('Purpose grants require a verified contact.');
            }
            $changed = false;
            if (in_array($source, self::PROTECTED_SOURCES, true)) {
                $cleared = DB::table('delivery_suppressions')
                    ->where('contact_id', $contactId)
                    ->where('scope', 'global')
                    ->whereIn('reason', ['opt_out', 'manual'])
                    ->whereNull('cleared_at')
                    ->update(['cleared_at' => now(), 'cleared_source' => $source]);
                if ($cleared > 0) {
                    $this->audit('delivery.suppression_cleared', $contactId, $actorType, $actorId, $source);
                    $changed = true;
                }
            }
            $row = DB::table('purpose_grants')
                ->where('contact_id', $contactId)
                ->where('purpose', $purpose)
                ->lockForUpdate()
                ->first();
            if ($row === null) {
                DB::table('purpose_grants')->insert([
                    'id' => (string) Str::uuid(),
                    'contact_id' => $contactId,
                    'purpose' => $purpose,
                    'source' => $source,
                    'notice_revision' => self::NOTICE_REVISION,
                    'evidence' => $evidence,
                    'granted_at' => now(),
                    'revoked_at' => null,
                    'revoked_source' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->audit('delivery.granted', $contactId, $actorType, $actorId, $source);
                $changed = true;
            } elseif ($row->revoked_at !== null) {
                DB::table('purpose_grants')->where('id', $row->id)->update([
                    'source' => $source,
                    'notice_revision' => self::NOTICE_REVISION,
                    'evidence' => $evidence,
                    'granted_at' => now(),
                    'revoked_at' => null,
                    'revoked_source' => null,
                    'updated_at' => now(),
                ]);
                $this->audit('delivery.granted', $contactId, $actorType, $actorId, $source);
                $changed = true;
            }

            return $changed;
        });
    }

    public function revoke(string $contactId, string $purpose, string $source, string $actorType = 'system', ?string $actorId = null): bool
    {
        $this->requirePurpose($purpose);
        $this->requireProvenance($source, 'source');

        return DB::transaction(function () use ($contactId, $purpose, $source, $actorType, $actorId): bool {
            $this->lockContact($contactId);
            $row = DB::table('purpose_grants')
                ->where('contact_id', $contactId)
                ->where('purpose', $purpose)
                ->lockForUpdate()
                ->first();
            if ($row === null || $row->revoked_at !== null) {
                return false;
            }
            DB::table('purpose_grants')->where('id', $row->id)->update([
                'revoked_at' => now(),
                'revoked_source' => $source,
                'updated_at' => now(),
            ]);
            $this->audit('delivery.revoked', $contactId, $actorType, $actorId, $source);

            return true;
        });
    }

    public function revokeAll(string $contactId, string $source, string $actorType = 'system', ?string $actorId = null): int
    {
        $revoked = 0;
        foreach (DeliveryPolicy::PURPOSES as $purpose) {
            if ($this->revoke($contactId, $purpose, $source, $actorType, $actorId)) {
                $revoked++;
            }
        }

        return $revoked;
    }

    public function suppress(string $contactId, string $scope, string $reason, string $source, string $actorType = 'system', ?string $actorId = null): bool
    {
        if (! in_array($scope, self::SCOPES, true)) {
            throw new InvalidArgumentException('Unknown suppression scope.');
        }
        if (! in_array($reason, self::REASONS, true)) {
            throw new InvalidArgumentException('Unknown suppression reason.');
        }
        $this->requireProvenance($source, 'source');

        return DB::transaction(function () use ($contactId, $scope, $reason, $source, $actorType, $actorId): bool {
            $this->lockContact($contactId);
            $active = DB::table('delivery_suppressions')
                ->where('contact_id', $contactId)
                ->where('scope', $scope)
                ->whereNull('cleared_at')
                ->exists();
            if ($active) {
                return false;
            }
            DB::table('delivery_suppressions')->insert([
                'id' => (string) Str::uuid(),
                'contact_id' => $contactId,
                'scope' => $scope,
                'reason' => $reason,
                'source' => $source,
                'cleared_at' => null,
                'cleared_source' => null,
                'created_at' => now(),
            ]);
            $this->audit('delivery.suppressed', $contactId, $actorType, $actorId, $reason);

            return true;
        });
    }

    /**
     * Provider complaint / hard-bounce feedback: a global suppression
     * recorded against the address, whether or not a contact row already
     * exists. Deliveries of the same feedback are idempotent.
     */
    public function recordProviderFeedback(string $email, string $kind, string $source = 'provider_feedback'): bool
    {
        if (! in_array($kind, ['complaint', 'hard_bounce'], true)) {
            throw new InvalidArgumentException('Unknown provider feedback kind.');
        }
        $contactId = $this->ensureContact($email);

        return $this->suppress($contactId, 'global', $kind, $source);
    }

    /**
     * @return array{email: string, verified: bool, purposes: array{cart_recovery: bool, newsletter: bool, stock_alert: bool, price_alert: bool}, suppressions: array<int, array{scope: string, reason: string, source: string}>}
     */
    public function state(string $contactId): array
    {
        $contact = DB::table('delivery_contacts')->where('id', $contactId)->first();
        if ($contact === null) {
            throw new DomainException('Unknown delivery contact.');
        }
        $grants = DB::table('purpose_grants')->where('contact_id', $contactId)->get();
        $purposes = [];
        foreach (DeliveryPolicy::PURPOSES as $purpose) {
            $row = $grants->firstWhere('purpose', $purpose);
            $purposes[$purpose] = $row !== null && $row->revoked_at === null;
        }
        $suppressions = DB::table('delivery_suppressions')
            ->where('contact_id', $contactId)
            ->whereNull('cleared_at')
            ->orderBy('created_at')
            ->get()
            ->map(fn ($row): array => ['scope' => (string) $row->scope, 'reason' => (string) $row->reason, 'source' => (string) $row->source])
            ->all();

        return [
            'email' => (string) $contact->email,
            'verified' => $contact->email_verified_at !== null,
            'purposes' => $purposes,
            'suppressions' => $suppressions,
        ];
    }

    /** @return object{id: string, email: string, email_verified_at: string|null} */
    private function lockContact(string $contactId): object
    {
        /** @var object{id: string, email: string, email_verified_at: string|null}|null $contact */
        $contact = DB::table('delivery_contacts')->where('id', $contactId)->lockForUpdate()->first();
        if ($contact === null) {
            throw new DomainException('Unknown delivery contact.');
        }

        return $contact;
    }

    private function normalizeEmail(string $email): string
    {
        $email = mb_strtolower(trim($email), 'UTF-8');
        if ($email === '' || strlen($email) > 254) {
            throw new InvalidArgumentException('A valid contact email is required.');
        }

        return $email;
    }

    private function requirePurpose(string $purpose): void
    {
        if (! in_array($purpose, DeliveryPolicy::PURPOSES, true)) {
            throw new InvalidArgumentException('Unknown delivery purpose.');
        }
    }

    private function requireProvenance(string $value, string $field): void
    {
        if ($value === '' || strlen($value) > 64) {
            throw new InvalidArgumentException("A {$field} is required for every preference transition.");
        }
    }

    private function audit(string $action, string $contactId, string $actorType, ?string $actorId, string $reasonCode): void
    {
        app(RecordEvent::class)->record('platform.audit.recorded', 'audit:delivery-preference:'.(string) Str::uuid(), [
            'action' => $action,
            'subject_type' => 'delivery_contact',
            'subject_id' => $contactId,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'metadata' => ['reason_code' => $reasonCode],
        ]);
    }
}
