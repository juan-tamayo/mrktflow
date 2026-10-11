<?php

declare(strict_types=1);

namespace Tests\Fixtures;

final class CustomerRegistration
{
    /** @return array{name: string, email: string, password: string, password_confirmation: string} */
    public static function input(): array
    {
        return ['name' => 'Fictional Customer', 'email' => 'SHOPPER@example.test', 'password' => 'Fictional shopper passphrase 471!', 'password_confirmation' => 'Fictional shopper passphrase 471!'];
    }
}
