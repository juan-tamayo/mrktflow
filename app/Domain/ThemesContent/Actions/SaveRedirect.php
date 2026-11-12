<?php

declare(strict_types=1);

namespace App\Domain\ThemesContent\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Records public content slug redirects and refuses loops (THEME-05, T070).
 *
 * Destinations are live page/article paths only. Cart, checkout, account and
 * other private routes cannot be redirect targets.
 */
final class SaveRedirect
{
    public function save(string $from, string $to): void
    {
        $fromPath = $this->normalize($from);
        $toPath = $this->normalize($to);
        if ($fromPath === $toPath) {
            throw ValidationException::withMessages([
                'redirect' => 'Redirects cannot target their own path.',
            ]);
        }
        $this->assertPublicContentPath($fromPath);
        $this->assertPublicContentPath($toPath);

        DB::transaction(function () use ($fromPath, $toPath): void {
            $canonical = $this->canonicalDestination($fromPath, $toPath);
            if ($canonical === $fromPath) {
                throw ValidationException::withMessages([
                    'redirect' => 'Redirects cannot form a loop.',
                ]);
            }
            $now = now();
            DB::table('content_redirects')->where('from_path', $canonical)->delete();
            DB::table('content_redirects')->where('to_path', $fromPath)->update(['to_path' => $canonical, 'updated_at' => $now]);
            DB::table('content_redirects')->whereColumn('from_path', 'to_path')->delete();
            $existing = DB::table('content_redirects')->where('from_path', $fromPath)->lockForUpdate()->first();
            if ($existing === null) {
                DB::table('content_redirects')->insert([
                    'id' => (string) Str::uuid(),
                    'from_path' => $fromPath,
                    'to_path' => $canonical,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                return;
            }
            DB::table('content_redirects')->where('id', $existing->id)->update([
                'to_path' => $canonical,
                'updated_at' => $now,
            ]);
        });
    }

    public function resolve(string $path): ?string
    {
        $normalized = $this->normalize($path);
        $row = DB::table('content_redirects')->where('from_path', $normalized)->first();

        return $row === null ? null : (string) $row->to_path;
    }

    public function recordPublishedPathChange(?string $fromDocumentPath, string $toDocumentPath): void
    {
        if ($fromDocumentPath === null || $fromDocumentPath === '' || $fromDocumentPath === $toDocumentPath) {
            return;
        }
        $this->save('/'.$fromDocumentPath, '/'.$toDocumentPath);
    }

    private function canonicalDestination(string $fromPath, string $toPath): string
    {
        $canonical = $toPath;
        $seen = [$fromPath => true, $toPath => true];
        while (true) {
            $next = DB::table('content_redirects')->where('from_path', $canonical)->lockForUpdate()->first();
            if ($next === null) {
                return $canonical;
            }
            $canonical = (string) $next->to_path;
            if (isset($seen[$canonical])) {
                throw ValidationException::withMessages([
                    'redirect' => 'Redirects cannot form a loop.',
                ]);
            }
            $seen[$canonical] = true;
        }
    }

    private function normalize(string $path): string
    {
        $trimmed = '/'.ltrim(strtolower(trim($path)), '/');

        return rtrim($trimmed, '/') === '' ? '/' : rtrim($trimmed, '/');
    }

    private function assertPublicContentPath(string $path): void
    {
        if (preg_match('#^/(pages|journal)/[a-z0-9]+(?:-[a-z0-9]+)*$#D', $path) === 1) {
            return;
        }
        throw ValidationException::withMessages([
            'redirect' => 'Redirects must stay on published page or article paths.',
        ]);
    }
}
