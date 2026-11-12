<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Support\CustomerAccountAccess;
use App\Domain\Platform\Actions\RecordEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class RequestPrivacy
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{id: string, type: string, status: string}
     */
    public function request(CustomerAccount $actor, array $input): array
    {
        $data = Validator::make($input, [
            'type' => ['required', 'string', Rule::in(['export', 'deletion'])],
            'current_password' => ['required', 'string', 'max:72'],
        ])->validate();
        $password = $data['current_password'];
        $type = $data['type'];
        if (! is_string($password) || ! is_string($type) || ! Hash::check($password, $actor->password)) {
            throw ValidationException::withMessages(['current_password' => 'Enter your current password to verify this request.']);
        }

        return DB::transaction(function () use ($actor, $type): array {
            $customer = app(CustomerAccountAccess::class)->lock($actor);
            app(ProveCustomerIdentity::class)->prove($customer);
            if (! hash_equals($customer->password, $actor->password)) {
                throw ValidationException::withMessages(['current_password' => 'Enter your current password to verify this request.']);
            }
            $existing = DB::table('privacy_requests')->where('customer_id', $customer->id)
                ->where('type', $type)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                return [
                    'id' => (string) $existing->id,
                    'type' => $type,
                    'status' => 'pending',
                ];
            }
            $id = (string) Str::uuid();
            DB::table('privacy_requests')->insert([
                'id' => $id,
                'customer_id' => $customer->id,
                'type' => $type,
                'status' => 'pending',
                'email' => $customer->email,
                'email_digest' => hash('sha256', $customer->email),
                'verified_at' => now(),
                'processed_at' => null,
                'processed_by_staff_id' => null,
                'storage_key' => null,
                'row_count' => 0,
                'export_expires_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            app(RecordEvent::class)->record('platform.audit.recorded', 'audit:privacy-requested:'.$id, [
                'action' => 'identity.privacy.requested',
                'subject_type' => 'privacy_request',
                'subject_id' => $id,
                'actor_type' => 'customer',
                'actor_id' => $customer->id,
                'metadata' => ['version' => 1, 'reason_code' => $type],
            ]);

            return ['id' => $id, 'type' => $type, 'status' => 'pending'];
        });
    }
}
