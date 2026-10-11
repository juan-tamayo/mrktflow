<?php

declare(strict_types=1);

use App\Domain\Identity\Actions\ProveCustomerIdentity;
use App\Domain\Identity\Models\CustomerAccount;
use Illuminate\Foundation\Testing\DatabaseMigrations;

uses(DatabaseMigrations::class);

it('IdentityCustomer refuses ownership proof outside an atomic transaction', function (): void {
    expect(fn () => app(ProveCustomerIdentity::class)->prove(new CustomerAccount))->toThrow(LogicException::class);
});
