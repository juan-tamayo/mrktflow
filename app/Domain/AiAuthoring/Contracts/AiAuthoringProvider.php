<?php

declare(strict_types=1);

namespace App\Domain\AiAuthoring\Contracts;

use App\Domain\AiAuthoring\Data\AiAuthoringRequest;
use App\Domain\AiAuthoring\Data\AiAuthoringResult;

interface AiAuthoringProvider
{
    /**
     * One bounded invocation, no automatic retry, tools, publication or private-context retrieval.
     * Dispatcher has committed the authorized run and maximum budget reservation before dispatch.
     * Ambiguous transport outcomes return unknown and retain the pending usage estimate.
     */
    public function generate(AiAuthoringRequest $request): AiAuthoringResult;
}
