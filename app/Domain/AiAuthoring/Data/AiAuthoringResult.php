<?php

declare(strict_types=1);

namespace App\Domain\AiAuthoring\Data;

use App\Domain\ThemesContent\Data\PageDraft;
use InvalidArgumentException;
use JsonException;
use stdClass;

/** Raw provider text/refusals/errors never survive into this result or its debug output. */
final readonly class AiAuthoringResult
{
    private function __construct(
        public string $status,
        public ?PageDraft $candidate,
        public ?string $providerResponseId,
        public ?int $inputTokens,
        public ?int $outputTokens,
    ) {}

    public static function unknown(): self
    {
        return new self('unknown', null, null, null, null);
    }

    public function usageKnown(): bool
    {
        return $this->inputTokens !== null && $this->outputTokens !== null;
    }

    public static function fromResponsesJson(AiAuthoringRequest $request, string $json): self
    {
        if (strlen($json) > 262144) {
            return self::unknown();
        }
        try {
            $response = json_decode($json, false, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return self::unknown();
        }
        if (! $response instanceof stdClass) {
            return self::unknown();
        }
        $response = get_object_vars($response);
        if (($response['metadata'] ?? null) instanceof stdClass) {
            $response['metadata'] = get_object_vars($response['metadata']);
        }
        if (! is_string($response['id'] ?? null) || ! preg_match('/\Aresp_[A-Za-z0-9_-]{1,250}\z/', $response['id'])
            || ($response['model'] ?? null) !== $request->model || ! is_array($response['metadata'] ?? null)
            || ($response['metadata']['intent_id'] ?? null) !== $request->intentId) {
            return self::unknown();
        }
        $usage = ($response['usage'] ?? null) instanceof stdClass ? get_object_vars($response['usage']) : null;
        $input = is_array($usage) ? ($usage['input_tokens'] ?? null) : null;
        $output = is_array($usage) ? ($usage['output_tokens'] ?? null) : null;
        $total = is_array($usage) ? ($usage['total_tokens'] ?? null) : null;
        $known = is_int($input) && is_int($output) && is_int($total) && $input >= 0 && $output >= 0
            && $input <= 1000000000 && $output <= 1000000000 && $total === $input + $output;
        $input = $known ? $input : null;
        $output = $known ? $output : null;
        $result = static fn (string $status, ?PageDraft $candidate = null): self => new self($status, $candidate, $response['id'], $input, $output);
        $status = $response['status'] ?? null;
        if (in_array($status, ['incomplete', 'failed', 'cancelled'], true)) {
            return $result($status);
        }
        if ($status !== 'completed' || ! $known || ($response['error'] ?? null) !== null || ($response['incomplete_details'] ?? null) !== null) {
            return $result('unknown');
        }
        $items = $response['output'] ?? null;
        if (! is_array($items) || ! array_is_list($items) || count($items) < 1 || count($items) > 20) {
            return $result('invalid_output');
        }
        $text = null;
        $refusal = false;
        foreach ($items as $item) {
            if (! $item instanceof stdClass) {
                return $result('invalid_output');
            }
            $item = get_object_vars($item);
            if (($item['type'] ?? null) === 'reasoning') {
                continue;
            }
            if (($item['type'] ?? null) !== 'message' || ($item['role'] ?? null) !== 'assistant' || ($item['status'] ?? null) !== 'completed'
                || ! is_array($item['content'] ?? null) || ! array_is_list($item['content']) || count($item['content']) !== 1) {
                return $result('invalid_output');
            }
            $content = $item['content'][0];
            if (! $content instanceof stdClass) {
                return $result('invalid_output');
            }
            $content = get_object_vars($content);
            if (($content['type'] ?? null) === 'refusal') {
                $refusal = true;
            } elseif (($content['type'] ?? null) === 'output_text' && is_string($content['text'] ?? null) && $text === null) {
                $text = $content['text'];
            } else {
                return $result('invalid_output');
            }
        }
        if ($refusal) {
            return $result('refused');
        }
        if ($text === null || $output > $request->maxOutputTokens) {
            return $result('invalid_output');
        }
        try {
            return $result('candidate', PageDraft::fromJson($text, $request->productIds()));
        } catch (InvalidArgumentException) {
            return $result('invalid_output');
        }
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['status' => $this->status, 'providerResponseId' => $this->providerResponseId, 'inputTokens' => $this->inputTokens, 'outputTokens' => $this->outputTokens];
    }
}
