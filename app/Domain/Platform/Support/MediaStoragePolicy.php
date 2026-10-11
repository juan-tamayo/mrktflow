<?php

declare(strict_types=1);

namespace App\Domain\Platform\Support;

use LogicException;

final class MediaStoragePolicy
{
    public function assertPublic(): void
    {
        $this->assertPrivate();
        $private = realpath(config()->string('filesystems.disks.local.root'));
        $public = realpath(config()->string('filesystems.disks.public.root'));
        if ($private === false || $public === false || $public === $private
            || str_starts_with($public, $private.DIRECTORY_SEPARATOR)
            || str_starts_with($private, $public.DIRECTORY_SEPARATOR)
            || config('filesystems.disks.public.driver') !== 'local') {
            throw new LogicException('Public and private media roots must remain separate local directories.');
        }
    }

    public function assertPrivate(): void
    {
        $privateRoot = realpath(config()->string('filesystems.disks.local.root'));
        $webRoot = realpath(public_path());
        if ($privateRoot === false || $webRoot === false || $privateRoot === $webRoot
            || str_starts_with($privateRoot, $webRoot.DIRECTORY_SEPARATOR)
            || config('filesystems.disks.local.driver') !== 'local' || config('filesystems.disks.local.serve') !== false) {
            throw new LogicException('Private media storage must remain outside the web directory.');
        }
    }
}
