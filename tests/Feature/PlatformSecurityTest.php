<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    $this->withoutVite();
});

it('PlatformSecurity keeps the unconfigured store and administration closed', function (): void {
    $this->get('/')->assertStatus(503)->assertSee('This store is getting ready');
    $this->get('/admin')->assertStatus(503);
    $this->postJson('/admin/products', ['title' => 'Unauthorized'])->assertNotFound();
    $this->postJson('/register', ['role' => 'owner'])->assertNotFound();
});

it('PlatformSecurity protects ordinary and error responses with strict headers', function (string $path): void {
    $response = $this->get($path);
    $response->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    $csp = $response->headers->get('Content-Security-Policy');
    expect($csp)->toContain("frame-ancestors 'none'", "object-src 'none'", "base-uri 'self'")
        ->not->toContain("'unsafe-inline'", "'unsafe-eval'");
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
})->with(['/', '/admin', '/missing']);

it('PlatformSecurity does not disclose exception details', function (): void {
    Route::get('/security-exception', fn () => throw new RuntimeException('PRIVATE_SENTINEL'));
    $this->get('/security-exception')->assertStatus(500)
        ->assertDontSee('PRIVATE_SENTINEL')->assertDontSee(base_path())
        ->assertHeader('X-Frame-Options', 'DENY');
});

it('PlatformSecurity leaves no generic private-storage serving route', function (): void {
    expect(config('filesystems.disks.local.serve'))->toBeFalse();
    $this->get('/storage/private/secret.pdf')->assertNotFound();
    $this->get('/.env')->assertNotFound();
});

it('PlatformSecurity rejects browser mutations without CSRF proof', function (): void {
    Route::middleware('web')->post('/security-probe', fn () => response()->json(['accepted' => true]));
    $this->app->instance('env', 'local');
    $this->postJson('/security-probe')->assertStatus(419);
});

it('PlatformSecurity forces safe production settings and rejects plain HTTP', function (): void {
    $this->app->instance('env', 'production');
    config(['app.url' => 'https://store.example.test', 'app.debug' => true, 'session.secure' => false, 'session.encrypt' => false, 'session.http_only' => false]);
    // Production settings are forced first, but a test process may not boot
    // under a production identity or bypass the database guard.
    expect(fn () => (new AppServiceProvider($this->app))->boot())->toThrow(RuntimeException::class);
    expect(config('app.debug'))->toBeFalse();
    expect(config('session.secure'))->toBeTrue();
    expect(config('session.encrypt'))->toBeTrue();
    expect(config('session.http_only'))->toBeTrue();
    $this->get('http://store.example.test/')->assertStatus(400);
    $this->get('http://store.example.test/', ['X-Forwarded-Proto' => 'https'])->assertStatus(400);
    $this->get('https://store.example.test/')->assertStatus(503)
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    $this->get('https://attacker.example.test/')->assertStatus(400);
});
