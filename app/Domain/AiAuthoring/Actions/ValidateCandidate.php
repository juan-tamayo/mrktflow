<?php

declare(strict_types=1);

namespace App\Domain\AiAuthoring\Actions;

use App\Domain\AiAuthoring\Data\AiAuthoringRequest;
use App\Domain\AiAuthoring\Data\AiAuthoringResult;
use App\Domain\ThemesContent\Actions\ValidateSections;
use App\Domain\ThemesContent\Data\PageDraft;
use App\Domain\ThemesContent\Exceptions\SectionValidationFailed;
use App\Domain\ThemesContent\Support\TemplatePackage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;

/**
 * Applies T069/T063 import schema, reference and sanitization rules to
 * untrusted provider output (AI-04). A candidate never grants tools,
 * credentials, shell, network or publication authority.
 */
final class ValidateCandidate
{
    public function __construct(private readonly ValidateSections $sections) {}

    public function validate(AiAuthoringRequest $request, AiAuthoringResult $result): AiAuthoringResult
    {
        if ($result->status !== 'candidate' || $result->candidate === null) {
            return $result;
        }
        $document = $result->candidate->jsonSerialize();
        try {
            TemplatePackage::rejectExecutable($document);
            PageDraft::fromJson(json_encode($document, JSON_THROW_ON_ERROR), $request->productIds());
            $sections = $document['sections'] ?? null;
            if (! is_array($sections)) {
                throw new InvalidArgumentException('Page draft sections must be a list.');
            }
            $this->sections->validate($sections);
        } catch (InvalidArgumentException|JsonException|SectionValidationFailed|ValidationException) {
            return $result->rejectCandidate();
        }

        return $result;
    }
}
