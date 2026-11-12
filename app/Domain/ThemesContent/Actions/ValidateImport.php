<?php

declare(strict_types=1);

namespace App\Domain\ThemesContent\Actions;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\ThemesContent\Exceptions\SectionValidationFailed;
use App\Domain\ThemesContent\Support\SectionDraftTargets;
use App\Domain\ThemesContent\Support\TemplatePackage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stages and validates a data-only template package (THEME-04, T069).
 *
 * PHP/Vue/script payloads and disallowed archive entries are refused
 * before a row is recorded. Section validation reuses T063; unknown
 * blocks never become drafts.
 */
final class ValidateImport
{
    public function __construct(
        private readonly ValidateSections $validate,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     import_id: string,
     *     documents: list<array{kind: string, page: string, slot: string, section_count: int}>,
     *     media: list<array{id: string, path: string, bytes: int, content_type: string}>,
     *     aliases: array{products: list<string>, categories: list<string>},
     *     can_apply: bool,
     *     errors: list<string>
     * }
     */
    public function validate(StaffAccount $actor, array $input): array
    {
        $file = $input['file'] ?? null;
        if ($file instanceof UploadedFile) {
            if (! $actor->hasPermission('content.write')) {
                throw new AuthorizationException;
            }
        } elseif (! $actor->hasPermission('content.read')) {
            throw new AuthorizationException;
        }
        $importId = is_string($input['import_id'] ?? null) ? $input['import_id'] : null;
        $mapping = $this->mapping($input['references'] ?? []);
        if ($file instanceof UploadedFile) {
            $stored = $this->storeUpload($actor, $file, $mapping);
            $importId = $stored['id'];
            $path = $stored['path'];
        } else {
            if ($importId === null || ! Str::isUuid($importId)) {
                TemplatePackage::reject('Upload a ZIP template package within the size and type limits.');
            }
            $record = DB::table('content_import_files')->where('id', $importId)->first();
            abort_unless($record !== null, 404);
            $key = is_string($record->storage_key) ? $record->storage_key : '';
            $path = Storage::disk('local')->path($key);
            if ($mapping === ['products' => [], 'categories' => []] && is_string($record->mapping) && $record->mapping !== '') {
                $storedMapping = json_decode($record->mapping, true);
                if (is_array($storedMapping)) {
                    $mapping = $this->mapping($storedMapping);
                }
            }
        }
        $package = TemplatePackage::inspect($path);
        $prepared = $this->prepare($package['manifest'], $mapping);
        $errors = $prepared['errors'];
        $canApply = $errors === [];
        if ($canApply) {
            try {
                foreach ($prepared['documents'] as $document) {
                    $this->validate->validate($document['sections']);
                }
            } catch (SectionValidationFailed $failure) {
                $errors = $failure->errors();
                $canApply = false;
            }
        }
        if ($file instanceof UploadedFile && $errors !== []) {
            Storage::disk('local')->delete('content-imports/'.$importId.'/package.zip');
            DB::table('content_import_files')->where('id', $importId)->delete();
            throw ValidationException::withMessages(['file' => implode(' ', $errors)]);
        }
        $report = [
            'import_id' => (string) $importId,
            'documents' => array_map(
                static fn (array $document): array => [
                    'kind' => $document['kind'],
                    'page' => $document['page'],
                    'slot' => $document['slot'],
                    'section_count' => count($document['sections']),
                ],
                $prepared['documents'],
            ),
            'media' => $package['media'],
            'aliases' => $prepared['aliases'],
            'can_apply' => $canApply,
            'errors' => $errors,
        ];
        if ($actor->hasPermission('content.write')) {
            DB::table('content_import_files')->where('id', $importId)->update([
                'mapping' => json_encode($mapping, JSON_THROW_ON_ERROR),
                'report' => json_encode($report, JSON_THROW_ON_ERROR),
            ]);
        }

        return $report;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array{products: array<string, string>, categories: array<string, string>}  $mapping
     * @return array{
     *     documents: list<array{kind: string, page: string, slot: string, sections: list<mixed>}>,
     *     aliases: array{products: list<string>, categories: list<string>},
     *     errors: list<string>
     * }
     */
    public function prepare(array $manifest, array $mapping): array
    {
        $errors = [];
        $keys = array_keys($manifest);
        sort($keys);
        $allowed = ['documents', 'kind', 'media', 'references', 'schemaVersion'];
        sort($allowed);
        if ($keys !== $allowed && array_diff($keys, $allowed) !== []) {
            $errors[] = 'The template JSON contains unknown fields.';
        }
        if (($manifest['schemaVersion'] ?? null) !== '1.0' || ($manifest['kind'] ?? null) !== 'template-package') {
            $errors[] = 'Unsupported template package contract.';
        }
        $documents = $manifest['documents'] ?? null;
        if (! is_array($documents) || ! array_is_list($documents) || $documents === []) {
            TemplatePackage::reject('A template package must declare at least one document.');
        }
        $aliases = ['products' => [], 'categories' => []];
        $prepared = [];
        foreach ($documents as $index => $document) {
            if (! is_array($document) || array_is_list($document)) {
                $errors[] = 'Document '.($index + 1).' must be an object.';

                continue;
            }
            if (($document['kind'] ?? null) !== 'section_draft') {
                $errors[] = 'Document '.($index + 1).' must import into a section draft.';

                continue;
            }
            $page = is_string($document['page'] ?? null) ? $document['page'] : '';
            $slot = is_string($document['slot'] ?? null) ? $document['slot'] : '';
            if (! SectionDraftTargets::isEditable($page, $slot)) {
                $errors[] = "Page [{$page}] has no editable section slot [{$slot}].";

                continue;
            }
            $sections = $document['sections'] ?? null;
            if (! is_array($sections) || ! array_is_list($sections)) {
                $errors[] = 'Document '.($index + 1).' sections must be a list.';

                continue;
            }
            $rewritten = $this->rewriteReferences($sections, $mapping, $aliases, $errors);
            $prepared[] = ['kind' => 'section_draft', 'page' => $page, 'slot' => $slot, 'sections' => $rewritten];
        }

        return ['documents' => $prepared, 'aliases' => $this->uniqueAliases($aliases), 'errors' => $errors];
    }

    /**
     * @param  list<mixed>  $sections
     * @param  array{products: array<string, string>, categories: array<string, string>}  $mapping
     * @param  array{products: list<string>, categories: list<string>}  $aliases
     * @param  list<string>  $errors
     * @return list<mixed>
     */
    private function rewriteReferences(array $sections, array $mapping, array &$aliases, array &$errors): array
    {
        foreach ($sections as $index => $section) {
            if (! is_array($section)) {
                continue;
            }
            if (isset($section['binding']) && is_array($section['binding'])) {
                $sections[$index]['binding'] = $this->rewriteBinding($section['binding'], $mapping, $aliases, $errors);
            }
        }

        return array_values($sections);
    }

    /**
     * @param  array<string, mixed>  $binding
     * @param  array{products: array<string, string>, categories: array<string, string>}  $mapping
     * @param  array{products: list<string>, categories: list<string>}  $aliases
     * @param  list<string>  $errors
     * @return array<string, mixed>
     */
    private function rewriteBinding(array $binding, array $mapping, array &$aliases, array &$errors): array
    {
        $kind = is_string($binding['kind'] ?? null) ? $binding['kind'] : '';
        $group = str_starts_with($kind, 'product') ? 'products' : 'categories';
        /** @var 'products'|'categories' $group */
        if (isset($binding['publicId']) && is_string($binding['publicId'])) {
            $binding['publicId'] = $this->mapAlias($binding['publicId'], $group, $mapping, $aliases, $errors);
        }
        if (isset($binding['publicIds']) && is_array($binding['publicIds'])) {
            $ids = [];
            foreach ($binding['publicIds'] as $id) {
                $ids[] = is_string($id) ? $this->mapAlias($id, $group, $mapping, $aliases, $errors) : $id;
            }
            $binding['publicIds'] = $ids;
        }

        return $binding;
    }

    /**
     * @param  'products'|'categories'  $group
     * @param  array{products: array<string, string>, categories: array<string, string>}  $mapping
     * @param  array{products: list<string>, categories: list<string>}  $aliases
     *
     * @param-out array{products: list<string>, categories: list<string>}  $aliases
     *
     * @param  list<string>  $errors
     */
    private function mapAlias(string $value, string $group, array $mapping, array &$aliases, array &$errors): string
    {
        if (! str_starts_with($value, 'ref:')) {
            return $value;
        }
        $alias = substr($value, 4);
        $aliases[$group] = array_values(array_unique([...$aliases[$group], $alias]));
        $mapped = $mapping[$group][$alias] ?? null;
        if (! is_string($mapped) || $mapped === '') {
            $errors[] = 'Map '.$group.' alias '.$alias.'.';

            return $value;
        }

        return $mapped;
    }

    /**
     * @param  array{products: list<string>, categories: list<string>}  $aliases
     * @return array{products: list<string>, categories: list<string>}
     */
    private function uniqueAliases(array $aliases): array
    {
        return [
            'products' => array_values(array_unique($aliases['products'])),
            'categories' => array_values(array_unique($aliases['categories'])),
        ];
    }

    /**
     * @return array{products: array<string, string>, categories: array<string, string>}
     */
    public function mapping(mixed $input): array
    {
        $mapping = ['products' => [], 'categories' => []];
        if (! is_array($input)) {
            return $mapping;
        }
        foreach (['products', 'categories'] as $group) {
            $values = $input[$group] ?? [];
            if (! is_array($values)) {
                continue;
            }
            foreach ($values as $alias => $id) {
                if (is_string($alias) && is_string($id) && $id !== '' && Str::isUuid($id)) {
                    $mapping[$group][$alias] = $id;
                }
            }
        }

        return $mapping;
    }

    /**
     * @param  array{products: array<string, string>, categories: array<string, string>}  $mapping
     * @return array{id: string, path: string}
     */
    private function storeUpload(StaffAccount $actor, UploadedFile $file, array $mapping): array
    {
        if (! $file->isValid() || $file->getSize() === false || $file->getSize() > TemplatePackage::MAX_ARCHIVE_BYTES) {
            TemplatePackage::reject('Upload a ZIP template package within the size and type limits.');
        }
        $real = $file->getRealPath();
        if ($real === false || is_link($file->getPathname())) {
            TemplatePackage::reject('Upload a ZIP template package within the size and type limits.');
        }
        TemplatePackage::inspect($real);
        $id = (string) Str::uuid();
        $key = 'content-imports/'.$id.'/package.zip';
        $stream = fopen($real, 'rb');
        if ($stream === false || ! Storage::disk('local')->put($key, $stream)) {
            TemplatePackage::reject('Upload a ZIP template package within the size and type limits.');
        }
        if (is_resource($stream)) {
            fclose($stream);
        }
        $checksum = hash_file('sha256', $real);
        if ($checksum === false) {
            TemplatePackage::reject('Upload a ZIP template package within the size and type limits.');
        }
        DB::table('content_import_files')->insert([
            'id' => $id,
            'staff_id' => $actor->id,
            'original_name' => substr($file->getClientOriginalName(), 0, 200),
            'storage_key' => $key,
            'checksum' => $checksum,
            'bytes' => (int) $file->getSize(),
            'mapping' => json_encode($mapping, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        return ['id' => $id, 'path' => Storage::disk('local')->path($key)];
    }
}
