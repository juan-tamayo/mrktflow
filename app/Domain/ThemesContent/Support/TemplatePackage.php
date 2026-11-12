<?php

declare(strict_types=1);

namespace App\Domain\ThemesContent\Support;

use Illuminate\Validation\ValidationException;
use ZipArchive;

/**
 * Inspects a data-only merchant template archive (THEME-04, T069).
 *
 * Executable names, traversal, symlinks, encrypted entries and unsafe
 * JSON payloads are refused before any document is applied.
 */
final class TemplatePackage
{
    public const MAX_ARCHIVE_BYTES = 52_428_800;

    public const MAX_JSON_BYTES = 2_097_152;

    public const MAX_ENTRIES = 64;

    public const MAX_UNCOMPRESSED_BYTES = 83_886_080;

    public const MAX_JSON_DEPTH = 16;

    /** @var array<string, list<string>> */
    public const IMAGE_TYPES = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
    ];

    /**
     * @return array{
     *     manifest: array<string, mixed>,
     *     media: list<array{id: string, path: string, bytes: int, content_type: string}>
     * }
     */
    public static function inspect(string $path): array
    {
        $size = filesize($path);
        if ($size === false || $size < 22 || $size > self::MAX_ARCHIVE_BYTES) {
            self::reject('Upload a ZIP template package within the size and type limits.');
        }
        $header = file_get_contents($path, false, null, 0, 4);
        if (! is_string($header) || ! in_array($header, ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"], true)) {
            self::reject('Upload a ZIP template package within the size and type limits.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! is_string($mime) || ! in_array($mime, ['application/zip', 'application/x-zip-compressed'], true)) {
            self::reject('Upload a ZIP template package within the size and type limits.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            self::reject('Upload a ZIP template package within the size and type limits.');
        }
        try {
            return self::read($zip);
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array{
     *     manifest: array<string, mixed>,
     *     media: list<array{id: string, path: string, bytes: int, content_type: string}>
     * }
     */
    private static function read(ZipArchive $zip): array
    {
        if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_ENTRIES) {
            self::reject('This package has too many archive entries.');
        }
        $uncompressed = 0;
        $manifestJson = null;
        $files = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            if ($stat === false) {
                self::reject('Archive entries must stay inside the package.');
            }
            $name = str_replace('\\', '/', $stat['name']);
            if (str_ends_with($name, '/')) {
                continue;
            }
            self::assertSafeName($name);
            $opsys = 0;
            $attr = 0;
            if ($zip->getExternalAttributesIndex($index, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX) {
                if ((($attr >> 16) & 0170000) === 0120000) {
                    self::reject('Archive entries must stay inside the package.');
                }
            }
            if ($stat['encryption_method'] !== 0) {
                self::reject('This package contains a disallowed file type.');
            }
            $bytes = (int) $stat['size'];
            $uncompressed += $bytes;
            if ($bytes < 1 || $uncompressed > self::MAX_UNCOMPRESSED_BYTES) {
                self::reject('This package exceeds the uncompressed size limit.');
            }
            $contents = $zip->getFromIndex($index, $bytes, ZipArchive::FL_UNCHANGED);
            if (! is_string($contents) || strlen($contents) !== $bytes) {
                self::reject('Archive entries must stay inside the package.');
            }
            if ($name === 'manifest.json') {
                if ($bytes > self::MAX_JSON_BYTES) {
                    self::reject('The template JSON exceeds the size limit.');
                }
                $manifestJson = $contents;

                continue;
            }
            $files[$name] = $contents;
        }
        if ($manifestJson === null) {
            self::reject('A template package must include manifest.json.');
        }
        self::assertSafePayload($manifestJson);
        try {
            $decoded = json_decode($manifestJson, true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            self::reject('The template JSON is invalid.');
        }
        if (! is_array($decoded) || array_is_list($decoded)) {
            self::reject('The template JSON is invalid.');
        }
        self::assertSafeValue($decoded);
        $media = self::media($decoded, $files);

        return ['manifest' => $decoded, 'media' => $media];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, string>  $files
     * @return list<array{id: string, path: string, bytes: int, content_type: string}>
     */
    private static function media(array $manifest, array $files): array
    {
        $declared = $manifest['media'] ?? [];
        if ($declared === []) {
            $declared = [];
        }
        if (! is_array($declared) || ! array_is_list($declared)) {
            self::reject('Package media must be a list of id and path objects.');
        }
        $media = [];
        $seen = [];
        foreach ($declared as $item) {
            if (! is_array($item) || array_is_list($item)) {
                self::reject('Package media must be a list of id and path objects.');
            }
            $id = $item['id'] ?? null;
            $path = $item['path'] ?? null;
            if (! is_string($id) || preg_match('/\A[a-z][a-z0-9-]{0,47}\z/', $id) !== 1
                || ! is_string($path) || preg_match('#\Amedia/[A-Za-z0-9._-]+\z#D', $path) !== 1) {
                self::reject('Package media paths must be files under media/.');
            }
            if (isset($seen[$id]) || isset($seen[$path])) {
                self::reject('Package media ids and paths must be unique.');
            }
            $seen[$id] = true;
            $seen[$path] = true;
            if (! isset($files[$path])) {
                self::reject('Package media paths must be files under media/.');
            }
            $contents = $files[$path];
            unset($files[$path]);
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);
            if (! is_string($mime) || ! isset(self::IMAGE_TYPES[$mime]) || ! in_array($extension, self::IMAGE_TYPES[$mime], true)) {
                self::reject('This package contains a disallowed file type.');
            }
            $dimensions = @getimagesizefromstring($contents);
            $limit = config()->integer('media.image_max_bytes');
            $pixels = config()->integer('media.image_max_pixels');
            $dimension = config()->integer('media.image_max_dimension');
            if ($dimensions === false || $dimensions[0] < 1 || $dimensions[1] < 1
                || strlen($contents) > $limit
                || max($dimensions[0], $dimensions[1]) > $dimension
                || $pixels < $dimensions[0] * $dimensions[1]) {
                self::reject('Use an allowed image type within the configured size and dimension limits.');
            }
            $media[] = ['id' => $id, 'path' => $path, 'bytes' => strlen($contents), 'content_type' => $mime];
        }
        if ($files !== []) {
            self::reject('This package contains a disallowed file type.');
        }

        return $media;
    }

    private static function assertSafeName(string $name): void
    {
        if ($name === 'manifest.json') {
            return;
        }
        if ($name === '' || str_contains($name, '..') || str_starts_with($name, '/')
            || str_contains($name, "\0") || preg_match('#\Amedia/[A-Za-z0-9._-]+\z#D', $name) !== 1) {
            self::reject('Archive entries must stay inside the package.');
        }
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            self::reject('This package contains a disallowed file type.');
        }
    }

    public static function rejectExecutable(mixed $value): void
    {
        self::assertSafeValue($value);
    }

    private static function assertSafePayload(string $json): void
    {
        if (preg_match('/<\?php|<script|javascript:|v-html|v-on:|@php|@include|\{\{|<\/script|data:text\/html|<iframe/i', $json) === 1) {
            self::reject('This package contains executable PHP, Vue or script content.');
        }
    }

    private static function assertSafeValue(mixed $value): void
    {
        if (is_string($value)) {
            self::assertSafePayload($value);
            if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
                self::reject('This package contains executable PHP, Vue or script content.');
            }

            return;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                self::assertSafeValue($item);
            }
        }
    }

    public static function reject(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
