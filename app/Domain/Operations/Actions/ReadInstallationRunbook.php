<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Operations\Support\InstallationRunbook;
use RuntimeException;

final class ReadInstallationRunbook
{
    /**
     * @return array{
     *     target_setup_seconds: int,
     *     complete: bool,
     *     missing: list<string>,
     *     documents: array<string, array{path: string, exists: bool, missing_headings: list<string>}>,
     *     environment: array{debug_default: string, mailer_default: string, app_key: string, database_password: string, stripe_secret: string, forbids_secrets: bool}
     * }
     */
    public function read(): array
    {
        $documents = [];
        $missing = [];
        $requiredHeadings = InstallationRunbook::requiredHeadings();
        foreach (InstallationRunbook::documentPaths() as $id => $relative) {
            $path = base_path($relative);
            $exists = is_file($path) && is_readable($path);
            $contents = $exists ? (string) file_get_contents($path) : '';
            $missingHeadings = [];
            foreach ($requiredHeadings[$id] ?? [] as $heading) {
                if (! str_contains($contents, '## '.$heading)) {
                    $missingHeadings[] = $heading;
                }
            }
            if (! $exists) {
                $missing[] = $relative;
            }
            foreach ($missingHeadings as $heading) {
                $missing[] = $relative.'#'.$heading;
            }
            $documents[$id] = [
                'path' => $relative,
                'exists' => $exists,
                'missing_headings' => $missingHeadings,
            ];
        }

        $environment = $this->environmentExample();
        if (! $environment['forbids_secrets']) {
            $missing[] = '.env.example#secrets';
        }

        return [
            'target_setup_seconds' => InstallationRunbook::TARGET_SETUP_SECONDS,
            'complete' => $missing === [],
            'missing' => $missing,
            'documents' => $documents,
            'environment' => $environment,
        ];
    }

    /**
     * @return array{debug_default: string, mailer_default: string, app_key: string, database_password: string, stripe_secret: string, forbids_secrets: bool}
     */
    private function environmentExample(): array
    {
        $path = base_path('.env.example');
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('The committed environment example is missing.');
        }
        $contents = (string) file_get_contents($path);
        $debug = $this->envValue($contents, 'APP_DEBUG');
        $mailer = $this->envValue($contents, 'MAIL_MAILER');
        $key = $this->envValue($contents, 'APP_KEY');
        $password = $this->envValue($contents, 'DB_PASSWORD');
        $stripe = $this->envValue($contents, 'STRIPE_SECRET');
        $forbids = $debug === 'false'
            && $mailer === 'array'
            && $key === ''
            && $password === ''
            && $stripe === ''
            && ! preg_match('/^APP_KEY=base64:/m', $contents)
            && ! preg_match('/sk_(live|test)_/i', $contents);

        return [
            'debug_default' => $debug,
            'mailer_default' => $mailer,
            'app_key' => $key,
            'database_password' => $password,
            'stripe_secret' => $stripe,
            'forbids_secrets' => $forbids,
        ];
    }

    private function envValue(string $contents, string $key): string
    {
        if (preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $contents, $match) !== 1) {
            return '';
        }

        return trim($match[1], " \t\"'");
    }
}
