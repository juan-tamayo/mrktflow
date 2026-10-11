<?php

declare(strict_types=1);

namespace App\Domain\Identity\Support;

use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

final class StaffCredentials
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{name: string, email: string, password: string}
     */
    public static function validate(array $input): array
    {
        $data = Validator::make(['owner' => $input], [
            'owner' => ['required', 'array:name,email,password,password_confirmation'],
            'owner.name' => ['required', 'string', 'max:120'],
            'owner.email' => ['required', 'string', 'email:rfc', 'max:254'],
            'owner.password' => ['required', 'string', Password::min(12), 'confirmed', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && strlen($value) > 72) {
                    $fail('Use a password of at most 72 bytes.');
                }
            }],
        ])->validate()['owner'];

        return ['name' => $data['name'], 'email' => mb_strtolower(trim($data['email']), 'UTF-8'), 'password' => $data['password']];
    }
}
