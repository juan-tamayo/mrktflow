<?php

use App\Domain\Platform\Consumers\StoreAuditEvent;

return [
    'consumers' => [
        'platform.audit.recorded' => ['platform.audit' => StoreAuditEvent::class],
    ],
    'max_attempts' => 6,
    'backoff' => [10, 30, 120, 300, 900, 1800],
];
