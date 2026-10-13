<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Data\TwoFactorEnrollment;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\StaffTotp;
use App\Domain\Platform\Actions\RecordEvent;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;

final class ManageTwoFactor
{
    public function confirmed(StaffAccount $staff): bool
    {
        return DB::table('staff_two_factor')->where('staff_id', $staff->id)->whereNotNull('confirmed_at')->exists();
    }

    public function start(StaffAccount $staff): TwoFactorEnrollment
    {
        return DB::transaction(function () use ($staff): TwoFactorEnrollment {
            $this->lock();
            $current = StaffAccount::query()->whereKey($staff->id)->lockForUpdate()->firstOrFail();
            $row = DB::table('staff_two_factor')->where('staff_id', $current->id)->lockForUpdate()->first();
            if ($row !== null && is_string($row->pending_payload)) {
                try {
                    $pending = $this->pendingSecret($row->pending_payload, $current->id);
                    if ($pending['created'] > now()->subMinutes(StaffTotp::PENDING_MINUTES)->getTimestamp()) {
                        return $this->enrollment($current, $pending['secret']);
                    }
                } catch (RuntimeException) {
                    // Expired or rebound pending state is replaced below.
                }
            }
            $totp = StaffTotp::generate();
            $secret = $totp->getSecret();
            $payload = $this->encrypt($current->id, ['secret' => $secret, 'created' => now()->getTimestamp()]);
            if ($row === null) {
                DB::table('staff_two_factor')->insert([
                    'staff_id' => $current->id, 'pending_payload' => $payload, 'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                DB::table('staff_two_factor')->where('staff_id', $current->id)->update([
                    'pending_payload' => $payload, 'updated_at' => now(),
                ]);
            }

            return $this->enrollment($current, $secret);
        });
    }

    public function confirm(StaffAccount $staff, string $code): TwoFactorEnrollment
    {
        return DB::transaction(function () use ($staff, $code): TwoFactorEnrollment {
            $this->lock();
            $current = StaffAccount::query()->whereKey($staff->id)->lockForUpdate()->firstOrFail();
            $row = DB::table('staff_two_factor')->where('staff_id', $current->id)->lockForUpdate()->first();
            if ($row === null || ! is_string($row->pending_payload)) {
                throw ValidationException::withMessages(['code' => 'Start authenticator enrollment before confirming.']);
            }
            $pending = $this->pendingSecret($row->pending_payload, $current->id);
            if ($pending['created'] <= now()->subMinutes(StaffTotp::PENDING_MINUTES)->getTimestamp()) {
                throw ValidationException::withMessages(['code' => 'This authenticator setup expired. Start again.']);
            }
            if (StaffTotp::matchingStep($pending['secret'], $code) === null) {
                throw ValidationException::withMessages(['code' => 'The authenticator code could not be verified.']);
            }
            $codes = $this->recoveryCodes();
            $replaced = is_string($row->secret_payload);
            DB::table('staff_two_factor')->where('staff_id', $current->id)->update([
                'pending_payload' => null,
                'secret_payload' => $this->encrypt($current->id, ['secret' => $pending['secret']]),
                'recovery_payload' => $this->encrypt($current->id, ['codes' => array_map(fn (string $item): string => hash('sha256', $this->normalize($item)), $codes)]),
                'confirmed_at' => now(),
                'last_totp_step' => null,
                'updated_at' => now(),
            ]);
            app(RecordEvent::class)->record('platform.audit.recorded', 'staff:two-factor:'.$current->id.':'.Str::uuid(), [
                'action' => 'staff.two_factor_enrolled', 'subject_type' => 'staff', 'subject_id' => $current->id,
                'actor_type' => 'staff', 'actor_id' => $current->id,
                'metadata' => ['reason_code' => $replaced ? 'replaced' : 'enrolled'],
            ]);
            $enrollment = $this->enrollment($current, $pending['secret']);

            return new TwoFactorEnrollment($enrollment->secret, $enrollment->uri, $enrollment->issuer, $enrollment->label, $codes);
        });
    }

    public function challenge(StaffAccount $staff, string $code): string
    {
        return DB::transaction(function () use ($staff, $code): string {
            $this->lock();
            $current = StaffAccount::query()->whereKey($staff->id)->lockForUpdate()->firstOrFail();
            $row = DB::table('staff_two_factor')->where('staff_id', $current->id)->lockForUpdate()->first();
            if ($row === null || $row->confirmed_at === null || ! is_string($row->secret_payload)) {
                throw ValidationException::withMessages(['code' => 'The authenticator code could not be verified.']);
            }
            $secret = $this->secret($row->secret_payload, $current->id);
            $step = StaffTotp::matchingStep($secret, $code);
            if ($step !== null) {
                if ($row->last_totp_step !== null && (int) $row->last_totp_step === $step) {
                    throw ValidationException::withMessages(['code' => 'The authenticator code could not be verified.']);
                }
                DB::table('staff_two_factor')->where('staff_id', $current->id)->update([
                    'last_totp_step' => $step, 'updated_at' => now(),
                ]);

                return 'totp';
            }
            if (! is_string($row->recovery_payload)) {
                throw ValidationException::withMessages(['code' => 'The authenticator code could not be verified.']);
            }
            $hashes = $this->recoveryHashes($row->recovery_payload, $current->id);
            $normalized = $this->normalize($code);
            $digest = hash('sha256', $normalized);
            $remaining = [];
            $matched = false;
            foreach ($hashes as $hash) {
                if (! $matched && hash_equals($hash, $digest)) {
                    $matched = true;

                    continue;
                }
                $remaining[] = $hash;
            }
            if (! $matched) {
                throw ValidationException::withMessages(['code' => 'The authenticator code could not be verified.']);
            }
            DB::table('staff_two_factor')->where('staff_id', $current->id)->update([
                'pending_payload' => null, 'secret_payload' => null, 'recovery_payload' => null,
                'confirmed_at' => null, 'last_totp_step' => null, 'updated_at' => now(),
            ]);
            app(RecordEvent::class)->record('platform.audit.recorded', 'staff:two-factor-recovery:'.$current->id.':'.Str::uuid(), [
                'action' => 'staff.two_factor_recovery_used', 'subject_type' => 'staff', 'subject_id' => $current->id,
                'actor_type' => 'staff', 'actor_id' => $current->id,
                'metadata' => ['reason_code' => 'recovery'],
            ]);

            return 'recovery';
        });
    }

    private function lock(): void
    {
        if (DB::table('staff_security')->where('id', 1)->lockForUpdate()->first() === null) {
            throw new LogicException('Staff security lock is unavailable.');
        }
    }

    private function enrollment(StaffAccount $staff, string $secret): TwoFactorEnrollment
    {
        $totp = StaffTotp::make($secret);
        $issuer = $this->issuer();
        $label = $staff->email;
        if ($label === '') {
            throw new RuntimeException('Staff authenticator label is missing.');
        }
        $totp->setIssuer($issuer);
        $totp->setLabel($label);
        $totp->setIssuerIncludedAsParameter(true);

        return new TwoFactorEnrollment($secret, $totp->getProvisioningUri(), $issuer, $staff->email);
    }

    /** @return non-empty-string */
    private function issuer(): string
    {
        $raw = DB::table('store_settings')->where('id', 1)->value('values');
        $name = is_string($raw) ? (json_decode($raw, true, flags: JSON_THROW_ON_ERROR)['name'] ?? 'MRKTFlow') : 'MRKTFlow';
        $issuer = str_replace([':', '%'], '', mb_substr(is_string($name) ? $name : 'MRKTFlow', 0, 32));
        if ($issuer === '') {
            return 'MRKTFlow';
        }

        return $issuer;
    }

    /** @return array{secret: string, created: int} */
    private function pendingSecret(string $ciphertext, string $staffId): array
    {
        $envelope = $this->decrypt($ciphertext, $staffId);
        if (! is_string($envelope['secret'] ?? null) || ! is_int($envelope['created'] ?? null)) {
            throw new RuntimeException('Invalid authenticator binding.');
        }

        return ['secret' => $envelope['secret'], 'created' => $envelope['created']];
    }

    private function secret(string $ciphertext, string $staffId): string
    {
        $envelope = $this->decrypt($ciphertext, $staffId);
        if (! is_string($envelope['secret'] ?? null)) {
            throw new RuntimeException('Invalid authenticator binding.');
        }

        return $envelope['secret'];
    }

    /** @return list<string> */
    private function recoveryHashes(string $ciphertext, string $staffId): array
    {
        $envelope = $this->decrypt($ciphertext, $staffId);
        $codes = $envelope['codes'] ?? null;
        if (! is_array($codes)) {
            throw new RuntimeException('Invalid authenticator binding.');
        }
        $hashes = [];
        foreach ($codes as $hash) {
            if (! is_string($hash)) {
                throw new RuntimeException('Invalid authenticator binding.');
            }
            $hashes[] = $hash;
        }

        return $hashes;
    }

    /** @param array<string, mixed> $payload */
    private function encrypt(string $staffId, array $payload): string
    {
        return Crypt::encryptString(json_encode(['staff_id' => $staffId] + $payload, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function decrypt(string $ciphertext, string $staffId): array
    {
        $envelope = json_decode(Crypt::decryptString($ciphertext), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($envelope) || ($envelope['staff_id'] ?? null) !== $staffId) {
            throw new RuntimeException('Invalid authenticator binding.');
        }

        return $envelope;
    }

    /** @return list<string> */
    private function recoveryCodes(): array
    {
        $codes = [];
        while (count($codes) < StaffTotp::RECOVERY_COUNT) {
            $raw = bin2hex(random_bytes(4));
            $codes[] = substr($raw, 0, 4).'-'.substr($raw, 4, 4);
        }

        return $codes;
    }

    private function normalize(string $code): string
    {
        return strtolower(str_replace(['-', ' '], '', trim($code)));
    }
}
