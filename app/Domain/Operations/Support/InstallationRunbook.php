<?php

declare(strict_types=1);

namespace App\Domain\Operations\Support;

final class InstallationRunbook
{
    public const TARGET_SETUP_SECONDS = 1800;

    /** @return array<string, list<string>> */
    public static function requiredHeadings(): array
    {
        return [
            'installation' => [
                'Supported runtime',
                'Secure owner setup',
                'Sandbox purchase',
                'Private-file denial',
                'Target time',
                'Opening the store',
            ],
            'hosting' => [
                'Supported host',
                'Services',
                'Permissions',
                'Reference machine',
            ],
            'release_evidence' => [
                'T084 clean-install rehearsal',
                'Pending evidence',
            ],
            'environment_example' => [],
        ];
    }

    /** @return array<string, string> */
    public static function documentPaths(): array
    {
        return [
            'installation' => 'docs/installation.md',
            'hosting' => 'docs/hosting.md',
            'release_evidence' => 'docs/release-evidence.md',
            'environment_example' => '.env.example',
        ];
    }
}
