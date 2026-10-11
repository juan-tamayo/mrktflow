<?php

declare(strict_types=1);

use App\Domain\Platform\Actions\QueueMedia;
use App\Domain\Platform\Actions\StoreMedia;
use App\Domain\Platform\Contracts\MediaUploadAuthorization;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
});

it('PlatformMediaQueue automatically enqueues one encrypted image job and recovers lost leases', function (): void {
    $authority = new class implements MediaUploadAuthorization
    {
        public function authorizeUpload(): string
        {
            return '994f1470-e4c5-4727-a7c6-2c4a8e5b432a';
        }
    };
    $id = app(StoreMedia::class)->store(UploadedFile::fake()->image('photo.png', 32, 24), 'public_image', $authority);
    expect(DB::table('jobs')->where('queue', 'media')->count())->toBe(1);
    expect(DB::table('jobs')->where('queue', 'media')->value('payload'))->not->toContain($id);
    expect(app(QueueMedia::class)->publish($id))->toBe(0);
    DB::table('jobs')->where('queue', 'media')->delete();
    $this->travel(91)->seconds();
    expect(app(QueueMedia::class)->recover())->toBe(1);
    expect(DB::table('jobs')->where('queue', 'media')->count())->toBe(1);
});

it('PlatformMediaQueue commits valid originals even if publication fails', function (): void {
    config(['queue.connections.database-outbox.table' => 'missing_media_fixture_queue']);
    $authority = new class implements MediaUploadAuthorization
    {
        public function authorizeUpload(): string
        {
            return '994f1470-e4c5-4727-a7c6-2c4a8e5b432a';
        }
    };
    $id = app(StoreMedia::class)->store(UploadedFile::fake()->image('photo.png', 32, 24), 'public_image', $authority);
    expect(DB::table('media_assets')->where('id', $id)->first())->status->toBe('pending')->queued_until->toBeNull();
    expect(Storage::disk('local')->exists('media/'.$id.'/original'))->toBeTrue();
});

it('PlatformMediaQueue recovers after a worker dies between file publication and metadata commit', function (): void {
    $private = base_path('.runtime/media-worker-private');
    $public = base_path('.runtime/media-worker-public');
    $requested = base_path('.runtime/media-worker-pause-request');
    $paused = base_path('.runtime/media-worker-paused');
    File::ensureDirectoryExists($private, 0700);
    File::ensureDirectoryExists($public, 0700);
    config(['filesystems.disks.local.root' => $private, 'filesystems.disks.public.root' => $public]);
    Storage::forgetDisk(['local', 'public']);
    @unlink($paused);
    file_put_contents($requested, 'test-only');
    $authority = new class implements MediaUploadAuthorization
    {
        public function authorizeUpload(): string
        {
            return '994f1470-e4c5-4727-a7c6-2c4a8e5b432a';
        }
    };
    $worker = new Process([base_path('bin/php'), base_path('tests/Fixtures/media-worker.php'), 'queue:work', 'database-outbox', '--env=testing', '--queue=media', '--once', '--timeout=60', '--tries=1'], base_path(), ['APP_ENV' => 'testing']);
    $worker->setTimeout(15);
    try {
        $id = app(StoreMedia::class)->store(UploadedFile::fake()->image('photo.png', 32, 24), 'public_image', $authority);
        $worker->start();
        $deadline = microtime(true) + 8;
        while (! file_exists($paused) && $worker->isRunning() && microtime(true) < $deadline) {
            usleep(20000);
        }
        expect(file_exists($paused))->toBeTrue();
        $worker->signal(SIGKILL);
        $worker->wait();
        expect(DB::table('media_assets')->where('id', $id)->first())->status->toBe('pending')->public_key->toBeNull();
        expect(Storage::disk('public')->allFiles())->toHaveCount(1);
        unlink($requested);
        DB::table('jobs')->where('queue', 'media')->update(['reserved_at' => now()->subSeconds(91)->timestamp]);
        DB::table('media_assets')->where('id', $id)->update(['queued_until' => now()->subSecond()]);
        expect(app(QueueMedia::class)->recover())->toBe(1);
        $resumed = new Process([base_path('bin/php'), base_path('tests/Fixtures/media-worker.php'), 'queue:work', 'database-outbox', '--env=testing', '--queue=media', '--stop-when-empty', '--sleep=0', '--timeout=60', '--tries=1'], base_path(), ['APP_ENV' => 'testing']);
        $resumed->setTimeout(15);
        expect($resumed->run())->toBe(0);
        expect(DB::table('media_assets')->where('id', $id)->first())->status->toBe('ready')->public_key->not->toBeNull();
        expect(Storage::disk('public')->allFiles())->toHaveCount(1);
        expect(DB::table('jobs')->where('queue', 'media')->count())->toBe(0);
    } finally {
        if ($worker->isRunning()) {
            $worker->stop(0, SIGKILL);
        }
        @unlink($requested);
        @unlink($paused);
        File::deleteDirectory($private);
        File::deleteDirectory($public);
    }
});
