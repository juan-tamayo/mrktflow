<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\RetentionPolicy;
use App\Domain\Operations\Support\ExportCsv;
use App\Domain\Platform\Actions\RecordEvent;
use App\Domain\Platform\Actions\UpdateDeliveryPreference;
use App\Domain\Platform\Support\MediaStoragePolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use stdClass;

final class ProcessPrivacyRequest
{
    /**
     * @return array{id: string, type: string, status: string}
     */
    public function process(StaffAccount $actor, string $requestId): array
    {
        if (! $actor->hasPermission('customers.read')) {
            throw new AuthorizationException;
        }
        if (! Str::isUuid($requestId) || $requestId !== strtolower($requestId)) {
            throw new InvalidArgumentException('Unknown privacy request.');
        }
        app(MediaStoragePolicy::class)->assertPrivate();

        return DB::transaction(function () use ($actor, $requestId): array {
            $request = DB::table('privacy_requests')->where('id', $requestId)->lockForUpdate()->first();
            abort_unless($request !== null, 404);
            if ((string) $request->status === 'completed') {
                return [
                    'id' => (string) $request->id,
                    'type' => (string) $request->type,
                    'status' => 'completed',
                ];
            }
            $type = (string) $request->type;
            $customer = CustomerAccount::query()->whereKey((string) $request->customer_id)->lockForUpdate()->first();
            abort_unless($customer !== null, 404);
            if ($type === 'export') {
                $this->export($request, $customer);
            } else {
                $this->delete($customer, (string) $request->email);
            }
            DB::table('privacy_requests')->where('id', $requestId)->update([
                'status' => 'completed',
                'processed_at' => now(),
                'processed_by_staff_id' => $actor->id,
                'updated_at' => now(),
            ]);
            app(RecordEvent::class)->record('platform.audit.recorded', 'audit:privacy-processed:'.$requestId, [
                'action' => 'identity.privacy.processed',
                'subject_type' => 'privacy_request',
                'subject_id' => $requestId,
                'actor_type' => 'staff',
                'actor_id' => $actor->id,
                'metadata' => ['version' => 1, 'reason_code' => $type],
            ]);

            return ['id' => $requestId, 'type' => $type, 'status' => 'completed'];
        });
    }

    private function export(stdClass $request, CustomerAccount $customer): void
    {
        $id = (string) $request->id;
        $headers = ['record_type', 'name', 'email', 'label', 'recipient', 'line1', 'city', 'country_code', 'order_number', 'order_email', 'grand_total'];
        $lines = [ExportCsv::line($headers)];
        $rows = 0;
        $lines[] = ExportCsv::line([
            'profile', $customer->name, $customer->email, '', '', '', '', '', '', '', '',
        ]);
        $rows++;
        foreach (DB::table('customer_addresses')->where('customer_id', $customer->id)->orderBy('created_at')->orderBy('id')->get() as $address) {
            $envelope = json_decode(Crypt::decryptString((string) $address->payload), true);
            $payload = is_array($envelope) && is_array($envelope['address'] ?? null) ? $envelope['address'] : [];
            $lines[] = ExportCsv::line([
                'address',
                $customer->name,
                $customer->email,
                $this->scalar($payload['label'] ?? null),
                $this->scalar($payload['recipient'] ?? null),
                $this->scalar($payload['line1'] ?? null),
                $this->scalar($payload['city'] ?? null),
                $this->scalar($payload['country_code'] ?? null),
                '',
                '',
                '',
            ]);
            $rows++;
        }
        foreach (DB::table('orders')->where('customer_id', $customer->id)->orderBy('created_at')->orderBy('id')->get() as $order) {
            $lines[] = ExportCsv::line([
                'order',
                $customer->name,
                $customer->email,
                '',
                '',
                '',
                '',
                '',
                $this->scalar($order->number ?? null),
                $this->scalar($order->email ?? null),
                $this->scalar($order->grand_total_amount ?? null),
            ]);
            $rows++;
        }
        $key = 'privacy/'.$id.'.csv';
        if (! Storage::disk('local')->put($key, implode("\n", $lines)."\n")) {
            throw new RuntimeException('Private privacy export storage failed.');
        }
        DB::table('privacy_requests')->where('id', $id)->update([
            'storage_key' => $key,
            'row_count' => $rows,
            'export_expires_at' => now()->addHours(RetentionPolicy::EXPORT_HOURS),
            'updated_at' => now(),
        ]);
    }

    private function delete(CustomerAccount $customer, string $originalEmail): void
    {
        DB::table('customer_addresses')->where('customer_id', $customer->id)->delete();
        DB::table('customer_challenges')->where('customer_id', $customer->id)->whereNull('consumed_at')->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'token_digest' => null, 'updated_at' => now()]);
        $wishlist = DB::table('wishlists')->where('customer_id', $customer->id)->lockForUpdate()->first();
        if ($wishlist !== null) {
            DB::table('wishlist_items')->where('wishlist_id', $wishlist->id)->delete();
            DB::table('wishlists')->where('id', $wishlist->id)->delete();
        }
        $contact = DB::table('delivery_contacts')->where('email', $originalEmail)->lockForUpdate()->first();
        if ($contact !== null) {
            $preferences = app(UpdateDeliveryPreference::class);
            $preferences->revokeAll((string) $contact->id, 'privacy_deletion', 'customer', $customer->id);
            $preferences->suppress((string) $contact->id, 'global', 'opt_out', 'privacy_deletion', 'customer', $customer->id);
            DB::table('watch_subscriptions')->where('contact_id', $contact->id)
                ->where('status', '!=', 'cancelled')
                ->update(['status' => 'cancelled', 'updated_at' => now()]);
        }
        $anonymized = 'deleted-'.str_replace('-', '', $customer->id).'@privacy.invalid';
        $customer->forceFill([
            'name' => 'Deleted customer',
            'email' => $anonymized,
            'password' => Hash::make(bin2hex(random_bytes(32))),
            'is_active' => false,
            'email_verified_at' => null,
            'remember_token' => null,
            'session_version' => $customer->session_version + 1,
            'profile_version' => $customer->profile_version + 1,
        ])->save();
    }

    private function scalar(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
