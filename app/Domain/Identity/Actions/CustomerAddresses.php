<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Support\CustomerAccountAccess;
use App\Domain\Platform\Actions\RecordEvent;
use App\Domain\Platform\Support\SetupChoices;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CustomerAddresses
{
    /** @return list<array<string, mixed>> */
    public function all(CustomerAccount $actor): array
    {
        return DB::transaction(function () use ($actor): array {
            app(CustomerAccountAccess::class)->lock($actor);

            return array_values(DB::table('customer_addresses')->where('customer_id', $actor->id)->orderBy('created_at')->orderBy('id')->limit(20)->get()
                ->map(function (object $row): array {
                    $envelope = json_decode(Crypt::decryptString($row->payload), true, flags: JSON_THROW_ON_ERROR);
                    if (! is_array($envelope) || ($envelope['customer_id'] ?? null) !== $row->customer_id
                        || ($envelope['address_id'] ?? null) !== $row->id || ! is_array($envelope['address'] ?? null)) {
                        throw new \RuntimeException('Invalid saved address binding.');
                    }
                    $payload = $envelope['address'];

                    return [
                        'id' => $row->id, 'version' => $row->version, 'label' => $payload['label'],
                        'recipient' => $payload['recipient'], 'line1' => $payload['line1'], 'line2' => $payload['line2'] ?? null,
                        'city' => $payload['city'], 'region' => $payload['region'] ?? null,
                        'postal_code' => $payload['postal_code'] ?? null, 'country_code' => $payload['country_code'],
                    ];
                })->all());
        });
    }

    /** @param array<string, mixed> $input */
    public function save(CustomerAccount $actor, array $input, ?string $id = null): string
    {
        $data = Validator::make(['address' => $input], [
            'address' => ['required', 'array:label,recipient,line1,line2,city,region,postal_code,country_code,version'],
            'address.label' => ['required', 'string', 'max:60'],
            'address.recipient' => ['required', 'string', 'max:120'],
            'address.line1' => ['required', 'string', 'max:200'],
            'address.line2' => ['nullable', 'string', 'max:200'],
            'address.city' => ['required', 'string', 'max:120'],
            'address.region' => ['nullable', 'string', 'max:120'],
            'address.postal_code' => ['nullable', 'string', 'max:32'],
            'address.country_code' => ['required', 'string', Rule::in(array_column(app(SetupChoices::class)->countries(), 'code'))],
            'address.version' => [$id === null ? 'prohibited' : 'required', 'integer', 'min:1', 'max:4294967295'],
        ], [], ['address.line1' => 'street address', 'address.country_code' => 'country'])->validate()['address'];

        return DB::transaction(function () use ($actor, $data, $id): string {
            app(CustomerAccountAccess::class)->lock($actor);
            $version = 1;
            if ($id !== null) {
                $row = DB::table('customer_addresses')->where('id', $id)->where('customer_id', $actor->id)->lockForUpdate()->first();
                abort_unless($row !== null, 404);
                abort_unless($row->version === (int) $data['version'] && $row->version < 4294967295, 409, 'This address changed. Reload before saving.');
                $version = $row->version + 1;
            } elseif (DB::table('customer_addresses')->where('customer_id', $actor->id)->count() >= 20) {
                throw ValidationException::withMessages(['address' => 'Save up to 20 addresses. Remove one before adding another.']);
            }
            $addressId = $id ?? (string) Str::uuid();
            unset($data['version']);
            $values = ['payload' => Crypt::encryptString(json_encode([
                'customer_id' => $actor->id, 'address_id' => $addressId, 'address' => $data,
            ], JSON_THROW_ON_ERROR)), 'version' => $version, 'updated_at' => now()];
            if ($id === null) {
                DB::table('customer_addresses')->insert($values + ['id' => $addressId, 'customer_id' => $actor->id, 'created_at' => now()]);
            } else {
                DB::table('customer_addresses')->where('id', $addressId)->where('customer_id', $actor->id)->update($values);
            }
            $this->audit($actor, $addressId, $version, 'saved');

            return $addressId;
        });
    }

    public function remove(CustomerAccount $actor, string $id, int $version): void
    {
        DB::transaction(function () use ($actor, $id, $version): void {
            app(CustomerAccountAccess::class)->lock($actor);
            $row = DB::table('customer_addresses')->where('id', $id)->where('customer_id', $actor->id)->lockForUpdate()->first();
            abort_unless($row !== null, 404);
            abort_unless($row->version === $version, 409, 'This address changed. Reload before removing it.');
            DB::table('customer_addresses')->where('id', $id)->where('customer_id', $actor->id)->delete();
            $this->audit($actor, $id, $version, 'removed');
        });
    }

    private function audit(CustomerAccount $actor, string $id, int $version, string $action): void
    {
        app(RecordEvent::class)->record('platform.audit.recorded', 'customer:address:'.$id.':'.$action.':'.$version, [
            'action' => 'customer.address_'.$action, 'subject_type' => 'customer_address', 'subject_id' => $id,
            'actor_type' => 'customer', 'actor_id' => $actor->id, 'metadata' => ['version' => $version],
        ]);
    }
}
