<?php

declare(strict_types=1);

namespace App\Domain\Platform\Actions;

use App\Domain\Platform\Support\SetupChoices;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

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
        $choices = app(SetupChoices::class);
        $validated = Validator::make(['settings' => $input], [
            'settings' => ['required', 'array:name,currency,locale,timezone,contact_email,sender_email,sender_name,sales_countries,weight_unit,length_unit,policies'],
            'settings.name' => ['required', 'string', 'max:120'],
            'settings.currency' => ['required', Rule::in(array_column($choices->currencies(), 'code'))],
            'settings.locale' => ['required', Rule::in(['en'])],
            'settings.timezone' => ['required', 'timezone'],
            'settings.contact_email' => ['required', 'email:rfc', 'max:254'],
            'settings.sender_email' => ['required', 'email:rfc', 'max:254'],
            'settings.sender_name' => ['required', 'string', 'max:120'],
            'settings.sales_countries' => ['required', 'array', 'list', 'min:1', 'max:249'],
            'settings.sales_countries.*' => ['required', 'distinct', Rule::in(array_column($choices->countries(), 'code'))],
            'settings.weight_unit' => ['required', Rule::in(['kg', 'lb'])],
            'settings.length_unit' => ['required', Rule::in(['cm', 'in'])],
            'settings.policies' => ['required', 'array:privacy,terms,returns,shipping,digital'],
            'settings.policies.privacy' => ['required', 'string', 'max:10000'],
            'settings.policies.terms' => ['required', 'string', 'max:10000'],
            'settings.policies.returns' => ['required', 'string', 'max:10000'],
            'settings.policies.shipping' => ['required', 'string', 'max:10000'],
            'settings.policies.digital' => ['required', 'string', 'max:10000'],
        ])->validate();

        sort($validated['settings']['sales_countries']);

        return $validated['settings'];
    }
}
