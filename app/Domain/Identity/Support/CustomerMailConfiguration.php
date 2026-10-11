<?php

declare(strict_types=1);

namespace App\Domain\Identity\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class CustomerMailConfiguration
{
    /** @return array{mailer: string, fromEmail: string, fromName: string, baseUrl: string}|null */
    public function ready(): ?array
    {
        $mailer = config('mail.default');
        $transport = is_string($mailer) ? config('mail.mailers.'.$mailer) : null;
        if (! is_array($transport)) {
            return null;
        }
        $driver = $transport['transport'] ?? null;
        if ($driver === 'array' && app()->environment('testing')) {
            // Only the guarded test runtime may acknowledge an in-memory transport.
        } elseif ($driver === 'smtp') {
            $port = filter_var($transport['port'] ?? null, FILTER_VALIDATE_INT);
            $timeout = $transport['timeout'] ?? null;
            if (! is_string($transport['host'] ?? null) || $transport['host'] === '' || $port === false || $port < 1 || $port > 65535
                || ! is_int($timeout) || $timeout < 1 || $timeout > 10 || ($transport['require_tls'] ?? false) !== true
                || ($transport['verify_peer'] ?? true) !== true || ! in_array($transport['scheme'] ?? null, [null, 'smtp', 'smtps'], true)
                || ! empty($transport['url'])) {
                return null;
            }
        } else {
            return null;
        }
        $baseUrl = config('app.url');
        $url = is_string($baseUrl) ? parse_url($baseUrl) : false;
        if (! is_array($url) || ! isset($url['scheme'], $url['host'])
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || ($url['scheme'] !== 'https' && ! (app()->environment('local', 'testing') && $url['scheme'] === 'http' && in_array($url['host'], ['127.0.0.1', 'localhost', '[::1]'], true)))) {
            return null;
        }
        $raw = DB::table('store_settings')->where('id', 1)->value('values');
        $settings = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($settings) || Validator::make($settings, [
            'sender_email' => ['required', 'string', 'email:rfc', 'max:254'],
            'sender_name' => ['required', 'string', 'max:120', 'not_regex:/[\r\n]/'],
        ])->fails()) {
            return null;
        }

        return ['mailer' => $mailer, 'fromEmail' => $settings['sender_email'], 'fromName' => $settings['sender_name'], 'baseUrl' => rtrim($baseUrl, '/')];
    }
}
