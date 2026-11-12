<?php

declare(strict_types=1);

namespace App\Domain\ThemesContent\Actions;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\RecordEvent;
use App\Domain\ThemesContent\Contracts\ThemeRegistry;
use App\Domain\ThemesContent\Exceptions\SectionValidationFailed;
use App\Domain\ThemesContent\Exceptions\ThemeActivationFailed;
use App\Domain\ThemesContent\Support\ContentRevisions;
use App\Domain\ThemesContent\Support\ThemeCoverage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Activates a registered theme after coverage and mapping checks (THEME-06, T068).
 *
 * Failure writes nothing: the previously active theme stays active.
 * Compatible mappings are stored with the activation; live documents are
 * not rewritten, and incompatible settings are never dropped silently.
 */
final class ActivateTheme
{
    public function __construct(
        private readonly ThemeRegistry $registry,
        private readonly ValidateSections $validate,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{theme_id: string, active: bool, section_types: array<string, string>, template_keys: array<string, string>}
     */
    public function activate(StaffAccount $actor, array $input): array
    {
        if (! $actor->hasPermission('content.publish')) {
            throw new AuthorizationException;
        }
        $data = Validator::make($input, [
            'theme_id' => ['required', 'string', 'max:191'],
            'section_types' => ['sometimes', 'array', 'max:100'],
            'section_types.*' => ['required', 'string', 'max:64'],
            'template_keys' => ['sometimes', 'array', 'max:50'],
            'template_keys.*' => ['required', 'string', 'max:80'],
        ])->validate();
        $themeId = (string) $data['theme_id'];
        /** @var array<string, string> $sectionMap */
        $sectionMap = $this->stringMap($data['section_types'] ?? []);
        /** @var array<string, string> $templateMap */
        $templateMap = $this->stringMap($data['template_keys'] ?? []);

        return DB::transaction(function () use ($actor, $themeId, $sectionMap, $templateMap): array {
            $registration = $this->registry->find($themeId);
            if ($registration === null || ! $this->registry->isActivatable($themeId)) {
                throw new ThemeActivationFailed(['Theme ['.$themeId.'] is not an activatable registration.']);
            }
            $row = DB::table('installed_themes')->where('theme_id', $themeId)->lockForUpdate()->first();
            abort_unless($row !== null, 404);
            $manifest = json_decode((string) $row->manifest, true);
            if (! is_array($manifest)) {
                throw new ThemeActivationFailed(['Theme ['.$themeId.'] has an unreadable manifest.']);
            }
            $templateKeys = $this->declaredTemplates($manifest);
            $sectionTypes = $this->declaredSections($manifest);
            $errors = [];
            foreach (ThemeCoverage::missingSurfaces($templateKeys) as $surface) {
                $errors[] = "Theme is missing a {$surface} template.";
            }
            $liveSections = $this->publishedSections();
            $missingSectionTypes = [];
            foreach ($liveSections as $section) {
                $type = (string) ($section['type'] ?? '');
                if ($type !== '' && ! in_array($type, $sectionTypes, true)) {
                    $missingSectionTypes[$type] = true;
                }
            }
            foreach (array_keys($missingSectionTypes) as $type) {
                $mapped = $sectionMap[$type] ?? null;
                if ($mapped === null) {
                    $errors[] = "Live content uses section type [{$type}] which this theme does not declare. Provide a mapping or fallback.";

                    continue;
                }
                if (! in_array($mapped, $sectionTypes, true)) {
                    $errors[] = "Section mapping [{$type}] => [{$mapped}] is not declared by this theme.";

                    continue;
                }
                $probe = [];
                foreach ($liveSections as $section) {
                    if (($section['type'] ?? null) === $type) {
                        $section['type'] = $mapped;
                        $probe[] = $section;
                    }
                }
                try {
                    $this->validate->validate($probe);
                } catch (SectionValidationFailed $failure) {
                    $errors[] = "Mapping [{$type}] to [{$mapped}] would discard incompatible settings: ".$failure->getMessage();
                }
            }
            $assignedKeys = $this->assignedTemplateKeys();
            foreach ($assignedKeys as $key) {
                if (in_array($key, $templateKeys, true)) {
                    continue;
                }
                $mapped = $templateMap[$key] ?? null;
                if ($mapped === null) {
                    $errors[] = "Assigned template [{$key}] is not declared by this theme. Provide a mapping or fallback.";

                    continue;
                }
                if (! in_array($mapped, $templateKeys, true)) {
                    $errors[] = "Template mapping [{$key}] => [{$mapped}] is not declared by this theme.";

                    continue;
                }
                if (ThemeCoverage::family($key) !== ThemeCoverage::family($mapped)) {
                    $errors[] = "Template mapping [{$key}] => [{$mapped}] changes the layout family.";
                }
            }
            if ($errors !== []) {
                throw new ThemeActivationFailed($errors);
            }
            $usedSectionMap = array_intersect_key($sectionMap, $missingSectionTypes);
            $usedTemplateMap = [];
            foreach ($assignedKeys as $key) {
                if (isset($templateMap[$key]) && ! in_array($key, $templateKeys, true)) {
                    $usedTemplateMap[$key] = $templateMap[$key];
                }
            }
            $now = now();
            DB::table('theme_activations')->whereNull('replaced_at')->lockForUpdate()->update(['replaced_at' => $now]);
            DB::table('theme_activations')->insert([
                'id' => (string) Str::uuid(),
                'theme_id' => $themeId,
                'checksum' => (string) $row->checksum,
                'section_types' => $usedSectionMap === [] ? null : json_encode($usedSectionMap, JSON_THROW_ON_ERROR),
                'template_keys' => $usedTemplateMap === [] ? null : json_encode($usedTemplateMap, JSON_THROW_ON_ERROR),
                'activated_by' => $actor->id,
                'activated_at' => $now,
                'replaced_at' => null,
            ]);
            app(RecordEvent::class)->record('platform.audit.recorded', 'audit:theme-activation:'.(string) Str::uuid(), [
                'action' => 'theme.activated',
                'subject_type' => 'theme',
                'subject_id' => $themeId,
                'actor_type' => 'staff',
                'actor_id' => $actor->id,
                'metadata' => ['checksum' => (string) $row->checksum, 'section_types' => $usedSectionMap, 'template_keys' => $usedTemplateMap],
            ]);

            return [
                'theme_id' => $themeId,
                'active' => true,
                'section_types' => $usedSectionMap,
                'template_keys' => $usedTemplateMap,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    private function declaredTemplates(array $manifest): array
    {
        $templates = $manifest['templates'] ?? null;
        if (! is_array($templates) || $templates === [] || array_is_list($templates)) {
            return [];
        }

        $keys = [];
        foreach (array_keys($templates) as $key) {
            $keys[] = (string) $key;
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    private function declaredSections(array $manifest): array
    {
        $sections = $manifest['sections'] ?? [];
        if (! is_array($sections)) {
            return [];
        }
        $types = [];
        foreach ($sections as $section) {
            if (is_string($section) && $section !== '') {
                $types[] = $section;
            }
        }

        return $types;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function publishedSections(): array
    {
        $sections = [];
        $rows = DB::table('content_drafts')->whereNotNull('published_revision')->lockForUpdate()->get();
        foreach ($rows as $row) {
            $payload = ContentRevisions::payload(
                ContentRevisions::SECTION_DRAFT,
                ContentRevisions::sectionSubject((string) $row->page_key, (string) $row->slot),
                (int) $row->published_revision,
            );
            if (! is_array($payload) || ! isset($payload['sections']) || ! is_array($payload['sections'])) {
                continue;
            }
            foreach ($payload['sections'] as $section) {
                if (is_array($section)) {
                    $sections[] = $section;
                }
            }
        }

        return $sections;
    }

    /** @return list<string> */
    private function assignedTemplateKeys(): array
    {
        $keys = [];
        foreach (DB::table('layout_assignments')->lockForUpdate()->pluck('template_key') as $key) {
            if (is_string($key) && $key !== '') {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return array<string, string>
     */
    private function stringMap(mixed $map): array
    {
        if (! is_array($map) || array_is_list($map)) {
            if ($map !== [] && $map !== null) {
                throw ValidationException::withMessages(['section_types' => 'Mappings must be an object of source to destination keys.']);
            }

            return [];
        }
        $normalized = [];
        foreach ($map as $from => $to) {
            if (! is_string($from) || $from === '' || ! is_string($to) || $to === '') {
                throw ValidationException::withMessages(['section_types' => 'Mappings must use non-empty string keys and values.']);
            }
            $normalized[$from] = $to;
        }

        return $normalized;
    }
}
