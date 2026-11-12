<?php

declare(strict_types=1);

namespace App\Domain\AiAuthoring\Adapters;

use App\Domain\AiAuthoring\Contracts\AiAuthoringProvider;
use App\Domain\AiAuthoring\Data\AiAuthoringRequest;
use App\Domain\AiAuthoring\Data\AiAuthoringResult;
use App\Domain\AiAuthoring\Support\AiModels;
use App\Domain\Platform\Support\OutboundHttp;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use JsonException;

final class SelectedProvider implements AiAuthoringProvider
{
    public function __construct(private OutboundHttp $http) {}

    public function generate(AiAuthoringRequest $request): AiAuthoringResult
    {
        $secret = $this->secret($request->model);
        if ($secret === null) {
            return AiAuthoringResult::unavailable();
        }
        try {
            $response = $this->http->client()
                ->withToken($secret)
                ->acceptJson()
                ->asJson()
                ->post('https://api.openai.com/v1/responses', $request->providerPayload());
        } catch (ConnectionException) {
            return AiAuthoringResult::timeout();
        } catch (RequestException) {
            return AiAuthoringResult::unknown();
        }
        $status = $response->status();
        if ($status === 429) {
            return AiAuthoringResult::rateLimited();
        }
        if (in_array($status, [401, 403], true) || $status >= 500) {
            return AiAuthoringResult::unavailable();
        }
        if ($status !== 200) {
            return AiAuthoringResult::unknown();
        }

        return AiAuthoringResult::fromResponsesJson($request, $response->body());
    }

    private function secret(string $model): ?string
    {
        $row = DB::table('ai_settings')->where('id', 1)->first();
        if ($row === null || ! (bool) $row->enabled || (string) $row->provider !== AiModels::PROVIDER
            || (string) $row->model !== $model || ! in_array($model, AiModels::permitted(), true)
            || ! is_string($row->secret_payload)) {
            return null;
        }
        try {
            $decoded = json_decode(Crypt::decryptString($row->secret_payload), true, 8, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return null;
        }
        if (! is_array($decoded) || ($decoded['purpose'] ?? null) !== 'ai.provider_secret'
            || ! is_string($decoded['secret'] ?? null) || $decoded['secret'] === '') {
            return null;
        }

        return $decoded['secret'];
    }
}
