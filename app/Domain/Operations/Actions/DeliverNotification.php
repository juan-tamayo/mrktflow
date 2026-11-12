<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Identity\Support\CustomerMailConfiguration;
use App\Domain\Platform\Support\DeliveryPolicy;
use App\Domain\Platform\Support\UnsubscribeTokens;
use App\Mail\StoreNotificationMessage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Throwable;

final class DeliverNotification
{
    public function deliver(string $id): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Network mail delivery cannot run inside a database transaction.');
        }
        $message = $this->claim($id);
        if ($message === null) {
            return;
        }
        try {
            $accepted = Mail::mailer($message['mailer'])->to($message['email'])->send(new StoreNotificationMessage(
                $id,
                $message['template'],
                $message['heading'],
                $message['body'],
                $message['fromEmail'],
                $message['fromName'],
                $message['unsubscribeUrl'],
                $message['test'],
            ));
            if ($accepted === null) {
                throw new RuntimeException('Mail was not accepted.');
            }
        } catch (Throwable $exception) {
            $this->finish($id, $message['claimId'], false);
            Log::warning('operations.mail_transport_failed', ['delivery_id' => $id, 'error_code' => 'transport_failed', 'exception_class' => $exception::class]);

            return;
        }
        $this->finish($id, $message['claimId'], true);
    }

    /**
     * @return array{claimId: string, mailer: string, email: string, template: string, heading: string, body: string, fromEmail: string, fromName: string, unsubscribeUrl: ?string, test: bool}|null
     */
    private function claim(string $id): ?array
    {
        return DB::transaction(function () use ($id): ?array {
            $row = DB::table('notification_deliveries')->where('id', $id)->lockForUpdate()->first();
            if ($row === null || ! in_array((string) $row->status, ['pending', 'sending'], true) || now()->lt($row->available_at)
                || ((string) $row->status === 'sending' && $row->lease_until !== null && now()->lt($row->lease_until))) {
                return null;
            }
            if (now()->gte($row->expires_at)) {
                $this->update($id, ['status' => 'cancelled', 'payload' => null, 'last_error_code' => 'notification_expired']);

                return null;
            }
            if ((int) $row->attempts >= 6) {
                $this->update($id, ['status' => 'failed', 'last_error_code' => 'attempts_exhausted']);

                return null;
            }
            try {
                $payload = is_string($row->payload) ? json_decode(Crypt::decryptString($row->payload), true, flags: JSON_THROW_ON_ERROR) : null;
                $valid = is_array($payload) && ! Validator::make($payload, [
                    'email' => ['required', 'string', 'email:rfc', 'max:254'],
                    'heading' => ['required', 'string', 'max:120'],
                    'body' => ['required', 'string', 'max:2000'],
                    'order_number' => ['sometimes', 'nullable', 'string', 'max:24'],
                ])->fails();
                if (! $valid || $payload['email'] !== $row->recipient) {
                    throw new RuntimeException('Invalid notification payload.');
                }
            } catch (Throwable) {
                $this->update($id, ['status' => 'failed', 'last_error_code' => 'message_invalid', 'payload' => null]);

                return null;
            }
            $template = (string) $row->template;
            $policyClass = (string) $row->policy_class;
            $kind = (string) $row->kind;
            if ($kind !== 'test' && $policyClass === 'transactional' && ! app(DeliveryPolicy::class)->essentialAllowed($template)) {
                $this->update($id, ['status' => 'cancelled', 'payload' => null, 'last_error_code' => 'unknown_template']);

                return null;
            }
            if ($kind !== 'test' && $policyClass === 'marketing') {
                $purpose = is_string($row->purpose) ? $row->purpose : null;
                if ($purpose === null || ! app(DeliveryPolicy::class)->marketingAllowed((string) $row->recipient, $purpose)) {
                    $this->update($id, ['status' => 'cancelled', 'payload' => null, 'last_error_code' => 'delivery_not_allowed']);

                    return null;
                }
            }
            $configuration = app(CustomerMailConfiguration::class)->ready();
            if ($configuration === null) {
                $this->update($id, ['status' => 'pending', 'available_at' => now()->addMinute(), 'last_error_code' => 'mail_configuration_unavailable']);

                return null;
            }
            $unsubscribeUrl = null;
            if ($policyClass === 'marketing' && $kind !== 'test') {
                $contactId = DB::table('delivery_contacts')->where('email', $row->recipient)->value('id');
                $purpose = is_string($row->purpose) ? $row->purpose : null;
                if (is_string($contactId) && $purpose !== null) {
                    $unsubscribeUrl = $configuration['baseUrl'].'/preferences/unsubscribe?token='.app(UnsubscribeTokens::class)->issue($contactId, $purpose);
                }
            }
            $claim = (string) Str::uuid();
            DB::table('notification_deliveries')->where('id', $id)->update([
                'status' => 'sending', 'claim_id' => $claim, 'lease_until' => now()->addSeconds(120),
                'attempts' => ((int) $row->attempts) + 1, 'last_error_code' => null, 'updated_at' => now(),
            ]);

            return $configuration + [
                'claimId' => $claim,
                'email' => $payload['email'],
                'template' => $template,
                'heading' => $payload['heading'],
                'body' => $payload['body'],
                'unsubscribeUrl' => $unsubscribeUrl,
                'test' => $kind === 'test',
            ];
        });
    }

    private function finish(string $id, string $claim, bool $accepted): void
    {
        DB::transaction(function () use ($id, $claim, $accepted): void {
            $row = DB::table('notification_deliveries')->where('id', $id)->lockForUpdate()->first();
            if ($row === null || (string) $row->status !== 'sending' || (string) $row->claim_id !== $claim) {
                return;
            }
            if ($accepted) {
                $this->update($id, ['status' => 'delivered', 'payload' => null, 'delivered_at' => now(), 'last_error_code' => null]);

                return;
            }
            $delay = match ((int) $row->attempts) {
                1 => 10,
                2 => 30,
                3 => 120,
                4 => 300,
                default => 900,
            };
            $this->update($id, [
                'status' => ((int) $row->attempts) >= 6 ? 'failed' : 'pending',
                'available_at' => now()->addSeconds($delay), 'last_error_code' => 'transport_failed',
            ]);
        });
    }

    /** @param array<string, mixed> $changes */
    private function update(string $id, array $changes): void
    {
        DB::table('notification_deliveries')->where('id', $id)->update($changes + ['claim_id' => null, 'lease_until' => null, 'queued_until' => null, 'updated_at' => now()]);
    }
}
