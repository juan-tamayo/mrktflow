<?php

declare(strict_types=1);

namespace App\Domain\Platform\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class OutboundHttp
{
    public function client(): PendingRequest
    {
        // Adapters own endpoint allowlists and idempotency. Never retry mutations implicitly.
        return Http::connectTimeout(5)->timeout(20)->withoutRedirecting()->withOptions(['verify' => true]);
    }
}
