<?php

use App\Domain\Identity\Models\StaffAccount;

return [
    'defaults' => ['guard' => 'staff', 'passwords' => 'staff'],
    'guards' => ['staff' => ['driver' => 'session', 'provider' => 'staff']],
    'providers' => ['staff' => ['driver' => 'eloquent', 'model' => StaffAccount::class]],
    // Password reset routes/broker are added with the verified recovery workflow.
    'passwords' => [],
    'password_timeout' => 900,
];
