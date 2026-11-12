<?php

declare(strict_types=1);

namespace App\Domain\ThemesContent\Actions;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\ProcessMedia;
use App\Domain\Platform\Actions\RecordEvent;
use App\Domain\Platform\Actions\StoreMedia;
use App\Domain\Platform\Contracts\MediaUploadAuthorization;
use App\Domain\ThemesContent\Support\TemplatePackage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/**
 * Applies a validated template package to drafts only (THEME-04, T069).
 *
 * Live published pointers never move. Replaying the same import intent
 * returns the stored result without a second draft write.
 */
final class ApplyImport
{
    public function __construct(
        private readonly ValidateImport $validate,
        private readonly SaveSectionDraft $saveDraft,
        private readonly ReadSectionDraft $readDraft,
        private readonly StoreMedia $storeMedia,
        private readonly ProcessMedia $processMedia,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     import_id: string,
     *     applied: bool,
     *     replayed: bool,
     *     documents: list<array{page: string, slot: string, version: int, section_count: int}>,
     *     media: list<array{id: string, url: string}>
     * }
     */
    public function apply(StaffAccount $actor, array $input): array
    {
        if (! $actor->hasPermission('content.write')) {
            throw new AuthorizationException;
        }
        $importId = is_string($input['import_id'] ?? null) ? $input['import_id'] : '';
        abort_unless($importId !== '' && Str::isUuid($importId), 404);
        $record = DB::table('content_import_files')->where('id', $importId)->first();
        abort_unless($record !== null, 404);
        if (is_string($record->apply_result) && $record->apply_result !== '') {
            $stored = json_decode($record->apply_result, true, flags: JSON_THROW_ON_ERROR);
            if (is_array($stored)) {
                return $this->normalized($stored, $importId);
            }
        }
        $mapping = $this->validate->mapping($input['references'] ?? []);
        if ($mapping === ['products' => [], 'categories' => []] && is_string($record->mapping) && $record->mapping !== '') {
            $storedMapping = json_decode($record->mapping, true);
            if (is_array($storedMapping)) {
                $mapping = $this->validate->mapping($storedMapping);
            }
        }
        $report = $this->validate->validate($actor, ['import_id' => $importId, 'references' => $mapping]);
        if (! $report['can_apply']) {
            throw ValidationException::withMessages(['file' => $report['errors'][0] ?? 'The template could not be applied.']);
        }
        $path = Storage::disk('local')->path((string) $record->storage_key);
        $package = TemplatePackage::inspect($path);
        $prepared = $this->validate->prepare($package['manifest'], $mapping);
        $urls = $this->storeMedia($actor, $path, $package['media']);
        $written = [];
        foreach ($prepared['documents'] as $document) {
            $sections = $this->rewriteMediaPaths($document['sections'], $urls);
            $current = $this->readDraft->read($document['page'], $document['slot']);
            $saved = $this->saveDraft->save($actor, $document['page'], $document['slot'], $current['version'], $sections);
            $written[] = [
                'page' => $document['page'],
                'slot' => $document['slot'],
                'version' => $saved['version'],
                'section_count' => count($saved['sections']),
            ];
        }
        $result = [
            'import_id' => $importId,
            'applied' => true,
            'replayed' => false,
            'documents' => $written,
            'media' => array_map(
                static fn (string $id, string $url): array => ['id' => $id, 'url' => $url],
                array_keys($urls),
                array_values($urls),
            ),
        ];
        DB::table('content_import_files')->where('id', $importId)->update([
            'applied_at' => now(),
            'apply_result' => json_encode($result, JSON_THROW_ON_ERROR),
            'mapping' => json_encode($mapping, JSON_THROW_ON_ERROR),
        ]);
        app(RecordEvent::class)->record('platform.audit.recorded', 'content:import:apply:'.$importId, [
            'action' => 'content.import.applied',
            'subject_type' => 'content_import',
            'subject_id' => $importId,
            'actor_type' => 'staff',
            'actor_id' => $actor->id,
            'metadata' => ['document_count' => count($written), 'media_count' => count($urls)],
        ]);

        return $result;
    }

    /**
     * @param  list<array{id: string, path: string, bytes: int, content_type: string}>  $media
     * @return array<string, string>
     */
    private function storeMedia(StaffAccount $actor, string $zipPath, array $media): array
    {
        if ($media === []) {
            return [];
        }
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            TemplatePackage::reject('Upload a ZIP template package within the size and type limits.');
        }
        $urls = [];
        try {
            foreach ($media as $item) {
                $contents = $zip->getFromName($item['path']);
                if (! is_string($contents) || $contents === '') {
                    TemplatePackage::reject('Package media paths must be files under media/.');
                }
                $temporary = sys_get_temp_dir().'/mrktflow-import-'.Str::uuid().'-'.basename($item['path']);
                file_put_contents($temporary, $contents);
                try {
                    $upload = new UploadedFile($temporary, basename($item['path']), $item['content_type'], null, true);
                    $id = $this->storeMedia->store($upload, 'public_image', $this->authorization($actor));
                    $this->processMedia->process($id);
                    $publicKey = DB::table('media_assets')->where('id', $id)->value('public_key');
                    if (! is_string($publicKey) || $publicKey === '') {
                        TemplatePackage::reject('Imported media could not be published.');
                    }
                    $urls[$item['path']] = '/storage/'.$publicKey;
                    $urls['/'.$item['path']] = '/storage/'.$publicKey;
                } finally {
                    if (is_file($temporary)) {
                        unlink($temporary);
                    }
                }
            }
        } finally {
            $zip->close();
        }

        return $urls;
    }

    /**
     * @param  list<mixed>  $sections
     * @param  array<string, string>  $urls
     * @return list<mixed>
     */
    private function rewriteMediaPaths(array $sections, array $urls): array
    {
        foreach ($sections as $index => $section) {
            $sections[$index] = $this->rewriteValue($section, $urls);
        }

        return $sections;
    }

    /**
     * @param  array<string, string>  $urls
     */
    private function rewriteValue(mixed $value, array $urls): mixed
    {
        if (is_string($value) && isset($urls[$value])) {
            return $urls[$value];
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->rewriteValue($item, $urls);
            }
        }

        return $value;
    }

    private function authorization(StaffAccount $actor): MediaUploadAuthorization
    {
        return new class($actor) implements MediaUploadAuthorization
        {
            public function __construct(private readonly StaffAccount $actor) {}

            public function authorizeUpload(): string
            {
                if (! $this->actor->hasPermission('media.upload')) {
                    throw new AuthorizationException;
                }

                return $this->actor->id;
            }
        };
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return array{
     *     import_id: string,
     *     applied: bool,
     *     replayed: bool,
     *     documents: list<array{page: string, slot: string, version: int, section_count: int}>,
     *     media: list<array{id: string, url: string}>
     * }
     */
    private function normalized(array $stored, string $importId): array
    {
        $documents = [];
        $rawDocuments = is_array($stored['documents'] ?? null) ? $stored['documents'] : [];
        foreach ($rawDocuments as $document) {
            if (! is_array($document)) {
                continue;
            }
            $documents[] = [
                'page' => is_string($document['page'] ?? null) ? $document['page'] : '',
                'slot' => is_string($document['slot'] ?? null) ? $document['slot'] : '',
                'version' => is_numeric($document['version'] ?? null) ? (int) $document['version'] : 0,
                'section_count' => is_numeric($document['section_count'] ?? null) ? (int) $document['section_count'] : 0,
            ];
        }
        $media = [];
        $rawMedia = is_array($stored['media'] ?? null) ? $stored['media'] : [];
        foreach ($rawMedia as $item) {
            if (! is_array($item)) {
                continue;
            }
            $media[] = [
                'id' => is_string($item['id'] ?? null) ? $item['id'] : '',
                'url' => is_string($item['url'] ?? null) ? $item['url'] : '',
            ];
        }

        return [
            'import_id' => $importId,
            'applied' => true,
            'replayed' => true,
            'documents' => $documents,
            'media' => $media,
        ];
    }
}
