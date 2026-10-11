<?php

use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Models\StaffAccount;

return [
    'defaults' => ['guard' => 'staff', 'passwords' => 'staff'],
    'guards' => ['staff' => ['driver' => 'session', 'provider' => 'staff'], 'customer' => ['driver' => 'session', 'provider' => 'customer']],
    'providers' => ['staff' => ['driver' => 'eloquent', 'model' => StaffAccount::class], 'customer' => ['driver' => 'eloquent', 'model' => CustomerAccount::class]],
    // Password reset routes/broker are added with the verified recovery workflow.
    'passwords' => [],
    'password_timeout' => 900,
];
