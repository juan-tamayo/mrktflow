<?php

return [
    'health' => [
        'scheduler_stale_seconds' => (int) env('OPERATIONS_SCHEDULER_STALE_SECONDS', 180),
        'worker_lag_seconds' => (int) env('OPERATIONS_WORKER_LAG_SECONDS', 300),
        'unresolved_payment_seconds' => (int) env('OPERATIONS_UNRESOLVED_PAYMENT_SECONDS', 600),
        'backup_max_age_seconds' => (int) env('OPERATIONS_BACKUP_MAX_AGE_SECONDS', 86400),
        'disk_warn_percent' => (int) env('OPERATIONS_DISK_WARN_PERCENT', 90),
        'disk_path' => storage_path(),
        'disk_fixture' => null,
    ],
];
