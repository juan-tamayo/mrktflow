<?php

namespace App\Providers;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\StaffPermissions;
use App\Domain\Platform\Support\TestDatabaseGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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
        RateLimiter::for('staff-login', function (Request $request): array {
            $email = $request->input('email');
            $identity = hash('sha256', is_string($email) ? mb_strtolower(trim($email), 'UTF-8') : '');

            return [Limit::perMinute(5)->by('staff:'.$identity.':'.$request->ip()), Limit::perMinute(30)->by('staff-ip:'.$request->ip())];
        });
        RateLimiter::for('customer-login', function (Request $request): array {
            $email = $request->input('email');
            $identity = hash('sha256', is_string($email) ? mb_strtolower(trim($email), 'UTF-8') : '');

            return [Limit::perMinute(5)->by('customer:'.$identity.':'.$request->ip()), Limit::perMinute(30)->by('customer-ip:'.$request->ip())];
        });
        RateLimiter::for('customer-recovery', function (Request $request): array {
            $email = $request->input('email');
            $identity = hash('sha256', is_string($email) ? mb_strtolower(trim($email), 'UTF-8') : '');

            return [Limit::perMinute(3)->by('customer-recovery:'.$identity), Limit::perMinute(10)->by('customer-recovery-ip:'.$request->ip())];
        });
        RateLimiter::for('customer-verification', fn (Request $request): array => [
            Limit::perMinute(3)->by('customer-verification:'.$request->user('customer')?->getAuthIdentifier()),
            Limit::perMinute(10)->by('customer-verification-ip:'.$request->ip()),
        ]);
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
