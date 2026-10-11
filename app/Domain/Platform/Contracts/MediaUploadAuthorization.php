<?php

declare(strict_types=1);

namespace App\Domain\Platform\Contracts;

interface MediaUploadAuthorization
{
    /** Authorize against the current staff session/policy and return its opaque staff UUID. Never construct from request fields. */
    public function authorizeUpload(): string;
}
