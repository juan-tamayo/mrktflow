<?php

namespace App\Providers;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\StaffPermissions;
use App\Domain\Platform\Support\TestDatabaseGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach (StaffPermissions::all() as $permission) {
            Gate::define($permission, fn (?Authenticatable $principal): bool => $principal instanceof StaffAccount && $principal->hasPermission($permission));
        }
        if (! $this->app->environment('local', 'testing')) {
            config(['app.debug' => false, 'session.secure' => true]);
        }

        config(['session.encrypt' => true, 'session.http_only' => true, 'session.same_site' => 'lax']);

        // A cached production configuration must not hide the test runner's intent.
        if ($this->app->environment('testing') || ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? null) === 'testing') {
            TestDatabaseGuard::verify($this->app->make(Application::class));
        }
    }
}
