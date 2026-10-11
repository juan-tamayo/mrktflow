<?php

declare(strict_types=1);

namespace App\Application\Setup;

use App\Domain\Identity\Actions\CreateFirstOwner;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\CompleteInstallation;

final class CreateInitialOwner
{
    /** @param array<string, mixed> $grant
     * @param  array<string, mixed>  $input
     */
    public function create(array $grant, array $input): StaffAccount
    {
        return app(CompleteInstallation::class)->complete($grant, fn (): StaffAccount => app(CreateFirstOwner::class)->create($input));
    }
}
