<?php

declare(strict_types=1);

namespace App\Domain\AiAuthoring\Actions;

use App\Domain\AiAuthoring\Contracts\AiAuthoringProvider;
use App\Domain\AiAuthoring\Data\AiAuthoringRequest;
use App\Domain\AiAuthoring\Data\AiAuthoringResult;
use App\Domain\AiAuthoring\Data\UsageHold;
use App\Domain\AiAuthoring\Exceptions\AiAuthoringRejected;
use App\Domain\AiAuthoring\Support\AiModels;
use App\Domain\Identity\Models\StaffAccount;
use App\Jobs\GenerateAuthoringDraft;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

final class DispatchAuthoringRun
{
    public function __construct(
        private BuildPublicContext $context,
        private ReserveUsage $usage,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function queue(StaffAccount $actor, array $input): string
    {
        $data = Validator::make(['run' => $input], [
            'run' => ['required', 'array:prompt,locale,product_ids,base_revision_id,max_output_tokens'],
            'run.prompt' => ['required', 'string', 'min:1', 'max:4000'],
            'run.locale' => ['required', 'string', 'max:16'],
            'run.product_ids' => ['present', 'array', 'max:20'],
            'run.product_ids.*' => ['uuid'],
            'run.base_revision_id' => ['required', 'uuid'],
            'run.max_output_tokens' => ['required', 'integer', 'min:1', 'max:8192'],
        ])->validate()['run'];
        $productIds = array_values(array_unique(array_map(strtolower(...), $data['product_ids'])));
        if (count($productIds) !== count($data['product_ids'])) {
            throw ValidationException::withMessages(['run.product_ids' => 'Select distinct published products.']);
        }
        $this->context->products($productIds);
        $revision = strtolower((string) $data['base_revision_id']);
        if (DB::table('content_revisions')->where('id', $revision)->first() === null) {
            throw new AiAuthoringRejected;
        }
        $settings = DB::table('ai_settings')->where('id', 1)->first();
        if ($settings === null || ! in_array((string) $settings->model, AiModels::permitted(), true)) {
            throw new AiAuthoringRejected;
        }
        $model = (string) $settings->model;
        $runId = DB::transaction(function () use ($actor, $data, $productIds, $revision, $model): string {
            $intentId = (string) Str::uuid();
            $runId = (string) Str::uuid();
            $hold = $this->usage->reserve($actor, new UsageHold(
                $intentId,
                (string) Str::uuid(),
                $actor->id,
                min(24576, (int) $data['max_output_tokens'] * 3),
                now()->addMinutes(30)->toDateTimeImmutable(),
            ));
            $now = now();
            DB::table('ai_runs')->insert([
                'id' => $runId,
                'intent_id' => $intentId,
                'reservation_id' => $hold->reservationId,
                'staff_id' => $actor->id,
                'provider' => AiModels::PROVIDER,
                'model' => $model,
                'base_revision_id' => $revision,
                'status' => 'queued',
                'repair_attempt' => 0,
                'locale' => (string) $data['locale'],
                'prompt' => (string) $data['prompt'],
                'product_ids' => json_encode($productIds, JSON_THROW_ON_ERROR),
                'max_output_tokens' => (int) $data['max_output_tokens'],
                'cancel_requested' => false,
                'status_message' => null,
                'provider_attempts' => 0,
                'record_operation_key' => (string) Str::uuid(),
                'release_operation_key' => (string) Str::uuid(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $runId;
        });
        GenerateAuthoringDraft::dispatch($runId);

        return $runId;
    }

    public function cancel(StaffAccount $actor, string $runId): void
    {
        $this->assertRunId($runId);
        DB::transaction(function () use ($actor, $runId): void {
            $run = $this->lockRun($runId);
            $this->assertActor($actor, $run);
            if ($this->terminal($this->string($run, 'status'))) {
                return;
            }
            DB::table('ai_runs')->where('id', $runId)->update([
                'cancel_requested' => true,
                'updated_at' => now(),
            ]);
            if ($this->string($run, 'status') === 'queued') {
                $this->usage->release($actor, $this->string($run, 'intent_id'), $this->string($run, 'release_operation_key'));
                $this->finish($runId, 'cancelled', 'Generation cancelled.');
            }
        });
    }

    public function process(string $runId, AiAuthoringProvider $provider): void
    {
        $this->assertRunId($runId);
        $prepared = DB::transaction(function () use ($runId): ?object {
            $run = $this->lockRun($runId);
            $status = $this->string($run, 'status');
            if ($this->terminal($status)) {
                return null;
            }
            if ($this->flag($run, 'cancel_requested') || $status === 'cancelled') {
                if ($status === 'queued') {
                    $staff = StaffAccount::query()->find($this->string($run, 'staff_id'));
                    if ($staff instanceof StaffAccount) {
                        $this->usage->release($staff, $this->string($run, 'intent_id'), $this->string($run, 'release_operation_key'));
                    }
                    $this->finish($runId, 'cancelled', 'Generation cancelled.');
                }

                return null;
            }
            if ((int) $this->string($run, 'provider_attempts') >= 1) {
                $this->recordUnknown($run, 'Provider timed out.');

                return null;
            }
            DB::table('ai_runs')->where('id', $runId)->update([
                'status' => 'running',
                'provider_attempts' => (int) $this->string($run, 'provider_attempts') + 1,
                'updated_at' => now(),
            ]);

            return DB::table('ai_runs')->where('id', $runId)->lockForUpdate()->first();
        });
        if ($prepared === null) {
            return;
        }
        try {
            $result = $provider->generate($this->request($prepared));
        } catch (Throwable) {
            $this->abandon($runId);

            return;
        }
        $this->complete($prepared, $result);
    }

    public function abandon(string $runId): void
    {
        $this->assertRunId($runId);
        DB::transaction(function () use ($runId): void {
            $run = $this->lockRun($runId);
            if ($this->terminal($this->string($run, 'status'))) {
                return;
            }
            $this->recordUnknown($run, 'Provider timed out.');
        });
    }

    private function complete(object $prepared, AiAuthoringResult $result): void
    {
        DB::transaction(function () use ($prepared, $result): void {
            $run = $this->lockRun($this->string($prepared, 'id'));
            if ($this->terminal($this->string($run, 'status'))) {
                return;
            }
            $staff = StaffAccount::query()->find($this->string($run, 'staff_id'));
            if (! $staff instanceof StaffAccount) {
                throw new AiAuthoringRejected;
            }
            $this->usage->record(
                $staff,
                $this->string($run, 'intent_id'),
                $this->string($run, 'record_operation_key'),
                $result->inputTokens,
                $result->outputTokens,
            );
            $cancelled = $this->flag($run, 'cancel_requested');
            if ($result->status === 'candidate' && $result->candidate !== null && ! $cancelled) {
                DB::table('ai_drafts')->insert([
                    'id' => (string) Str::uuid(),
                    'run_id' => $this->string($run, 'id'),
                    'payload' => json_encode($result->candidate->jsonSerialize(), JSON_THROW_ON_ERROR),
                    'status' => 'candidate',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->finish($this->string($run, 'id'), 'completed', 'Draft ready for review.');

                return;
            }
            [$status, $message] = $this->outcome($result->status, $cancelled);
            $this->finish($this->string($run, 'id'), $status, $message);
        });
    }

    /** @return array{0: string, 1: string} */
    private function outcome(string $resultStatus, bool $cancelled): array
    {
        if ($cancelled) {
            return ['cancelled', 'Generation cancelled.'];
        }

        return match ($resultStatus) {
            'rate_limited' => ['failed', 'Provider rate limited.'],
            'unavailable' => ['unknown', 'Provider unavailable.'],
            'timeout' => ['unknown', 'Provider timed out.'],
            'refused' => ['failed', 'Provider refused the request.'],
            'invalid_output' => ['failed', 'Provider output was rejected.'],
            'incomplete' => ['failed', 'Provider response was incomplete.'],
            'failed' => ['failed', 'Provider generation failed.'],
            'cancelled' => ['cancelled', 'Generation cancelled.'],
            default => ['unknown', 'Provider response was inconclusive.'],
        };
    }

    private function request(object $run): AiAuthoringRequest
    {
        $raw = get_object_vars($run)['product_ids'] ?? null;
        $ids = is_string($raw) ? json_decode($raw, true, 8, JSON_THROW_ON_ERROR) : $raw;
        if (! is_array($ids) || ! array_is_list($ids)) {
            throw new InvalidArgumentException('Invalid stored product context.');
        }

        return new AiAuthoringRequest(
            $this->string($run, 'intent_id'),
            $this->string($run, 'base_revision_id'),
            $this->string($run, 'reservation_id'),
            $this->string($run, 'model'),
            $this->string($run, 'locale'),
            $this->string($run, 'prompt'),
            $this->context->products($ids),
            (int) $this->string($run, 'max_output_tokens'),
            (int) $this->string($run, 'repair_attempt'),
        );
    }

    private function recordUnknown(object $run, string $message): void
    {
        $staff = StaffAccount::query()->find($this->string($run, 'staff_id'));
        if ($staff instanceof StaffAccount) {
            $this->usage->record(
                $staff,
                $this->string($run, 'intent_id'),
                $this->string($run, 'record_operation_key'),
                null,
                null,
            );
        }
        $this->finish($this->string($run, 'id'), 'unknown', $message);
    }

    private function finish(string $runId, string $status, string $message): void
    {
        DB::table('ai_runs')->where('id', $runId)->update([
            'status' => $status,
            'status_message' => $message,
            'updated_at' => now(),
        ]);
    }

    private function lockRun(string $runId): object
    {
        $run = DB::table('ai_runs')->where('id', $runId)->lockForUpdate()->first();
        if ($run === null) {
            throw new AiAuthoringRejected;
        }

        return $run;
    }

    private function assertActor(StaffAccount $actor, object $run): void
    {
        if ($actor->id !== $this->string($run, 'staff_id') || ! $actor->hasPermission('ai.use')
            || DB::table('ai_authors')->where('staff_id', $actor->id)->lockForUpdate()->first() === null) {
            throw new AuthorizationException;
        }
    }

    private function terminal(string $status): bool
    {
        return in_array($status, ['completed', 'failed', 'cancelled', 'unknown'], true);
    }

    private function assertRunId(string $runId): void
    {
        if (! Str::isUuid($runId) || $runId !== strtolower($runId)) {
            throw new InvalidArgumentException('Invalid authoring run.');
        }
    }

    private function string(object $row, string $key): string
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    private function flag(object $row, string $key): bool
    {
        $value = $this->string($row, $key);

        return $value === '1' || $value === 'true';
    }
}
