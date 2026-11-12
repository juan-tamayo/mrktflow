<?php

declare(strict_types=1);

namespace App\Domain\AiAuthoring\Support;

final class AiModels
{
    public const PROVIDER = 'openai';

    /** @return list<string> */
    public static function permitted(): array
    {
        return ['gpt-4o-mini-2024-07-18', 'gpt-6-sol'];
    }
}
