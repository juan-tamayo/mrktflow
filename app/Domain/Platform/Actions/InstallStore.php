<?php

declare(strict_types=1);

namespace App\Domain\Platform\Actions;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use ResourceBundle;

final class InstallStore
{
    /** @param array<string, mixed> $grant
     * @param  array<string, mixed>  $input
     */
    public function save(array $grant, array $input, int $expectedVersion): int
    {
        return DB::transaction(function () use ($grant, $input, $expectedVersion): int {
            DB::table('installations')->where('id', 1)->lockForUpdate()->first();
            app(SetupAccess::class)->authorize($grant);
            app(InstallationPrerequisites::class)->assertReady();
            $settings = $this->validate($input);
            $previous = DB::table('store_settings')->where('id', 1)->first();
            if ($previous !== null && json_decode($previous->values, true, flags: JSON_THROW_ON_ERROR) == $settings) {
                return $previous->version;
            }
            $version = $previous->version ?? 0;
            abort_unless($version === $expectedVersion, 409, 'Settings changed. Reload setup before saving.');
            DB::table('store_settings')->updateOrInsert(['id' => 1], [
                'version' => $version + 1, 'values' => json_encode($settings, JSON_THROW_ON_ERROR),
                'created_at' => $previous->created_at ?? now(), 'updated_at' => now(),
            ]);
            DB::table('installations')->where('id', 1)->update(['status' => 'pending_owner', 'updated_at' => now()]);

            return $version + 1;
        });
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validate(array $input): array
    {
        $countries = ResourceBundle::create('en', 'ICUDATA-region')?->get('Countries');
        $currencies = ResourceBundle::create('en', 'ICUDATA-curr')?->get('Currencies');
        $validated = Validator::make(['settings' => $input], [
            'settings' => ['required', 'array:name,currency,locale,timezone,contact_email,sender_email,sender_name,sales_countries,weight_unit,length_unit,policies'],
            'settings.name' => ['required', 'string', 'max:120'],
            'settings.currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/', function (string $attribute, mixed $value, Closure $fail) use ($currencies): void {
                if (! is_string($value) || ! $currencies instanceof ResourceBundle || ! $currencies->get($value) instanceof ResourceBundle || in_array($value, ['XXX', 'XTS'], true)) {
                    $fail('Choose a recognized currency.');
                }
            }],
            'settings.locale' => ['required', Rule::in(['en'])],
            'settings.timezone' => ['required', 'timezone'],
            'settings.contact_email' => ['required', 'email:rfc', 'max:254'],
            'settings.sender_email' => ['required', 'email:rfc', 'max:254'],
            'settings.sender_name' => ['required', 'string', 'max:120'],
            'settings.sales_countries' => ['required', 'array', 'min:1', 'max:249'],
            'settings.sales_countries.*' => ['required', 'string', 'distinct', 'regex:/^[A-Z]{2}$/', function (string $attribute, mixed $value, Closure $fail) use ($countries): void {
                if (! is_string($value) || ! $countries instanceof ResourceBundle || ! is_string($countries->get($value)) || in_array($value, ['AC', 'CP', 'DG', 'EA', 'EU', 'EZ', 'IC', 'QO', 'TA', 'UN', 'XA', 'XB', 'XK', 'ZZ'], true)) {
                    $fail('Choose an ISO country code.');
                }
            }],
            'settings.weight_unit' => ['required', Rule::in(['kg', 'lb'])],
            'settings.length_unit' => ['required', Rule::in(['cm', 'in'])],
            'settings.policies' => ['required', 'array:privacy,terms,returns,shipping,digital'],
            'settings.policies.privacy' => ['required', 'string', 'max:10000'],
            'settings.policies.terms' => ['required', 'string', 'max:10000'],
            'settings.policies.returns' => ['required', 'string', 'max:10000'],
            'settings.policies.shipping' => ['required', 'string', 'max:10000'],
            'settings.policies.digital' => ['required', 'string', 'max:10000'],
        ])->validate();

        return $validated['settings'];
    }
}
