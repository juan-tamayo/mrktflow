<?php

use App\Domain\Platform\Actions\InstallationPrerequisites;
use App\Domain\Platform\Actions\SetupAccess;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('setup:secret', function (SetupAccess $access): int {
    app(InstallationPrerequisites::class)->assertReady();
    $secret = $access->issueSecret();
    $this->warn('Keep this installation secret private. Rotation revokes all previous setup access.');
    $this->line($secret);
    $this->info('This secret expires in 24 hours. Enter it in the setup screen; never place it in a URL.');

    return 0;
})->purpose('Issue or rotate the installation secret before first-owner completion');

Artisan::command('setup:check', function (InstallationPrerequisites $prerequisites): int {
    $failed = false;
    foreach ($prerequisites->report() as $check) {
        $this->line(($check['passed'] ? 'PASS' : ($check['required'] ? 'BLOCKED' : 'PENDING')).' '.$check['id'].': '.$check['message']);
        $failed = $failed || ($check['required'] && ! $check['passed']);
    }

    return $failed ? 1 : 0;
})->purpose('Check installation prerequisites without exposing secrets');
