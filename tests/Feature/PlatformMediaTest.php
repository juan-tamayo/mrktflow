<?php

declare(strict_types=1);

use App\Domain\Platform\Actions\CleanMedia;
use App\Domain\Platform\Actions\ProcessMedia;
use App\Domain\Platform\Actions\StoreMedia;
use App\Domain\Platform\Contracts\MediaUploadAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
});

it('PlatformMedia stores private files through generated keys and encrypted names', function (): void {
    $file = UploadedFile::fake()->createWithContent('guide.pdf', "%PDF-1.7\nfictional fixture\n%%EOF\n");
    $id = app(StoreMedia::class)->store($file, 'private_file', new MediaFixtureAuthorization);
    $asset = DB::table('media_assets')->where('id', $id)->first();
    expect($asset)->status->toBe('ready')->scan_status->toBe('not_scanned');
    expect($asset->owner_id)->toBe(MediaFixtureAuthorization::STAFF_ID);
    expect($asset->original_key)->toBe('media/'.$id.'/original');
    expect($asset->original_name)->not->toContain('guide.pdf');
    expect(Crypt::decryptString($asset->original_name))->toBe('guide.pdf');
    expect(Storage::disk('local')->get($asset->original_key))->toBe(file_get_contents($file->getRealPath()));
    expect($asset->checksum)->toBe(hash_file('sha256', $file->getRealPath()));
    expect(Storage::disk('public')->allFiles())->toBe([]);
    $this->get('/storage/'.$asset->original_key)->assertNotFound();
    $this->get('/admin/media/'.$id)->assertNotFound();
});

it('PlatformMedia denies upload authority before storing data', function (): void {
    $authority = new class implements MediaUploadAuthorization
    {
        public function authorizeUpload(): string
        {
            throw new AuthorizationException('Denied');
        }
    };
    expect(fn () => app(StoreMedia::class)->store(UploadedFile::fake()->createWithContent('guide.txt', 'fictional fixture'), 'private_file', $authority))->toThrow(AuthorizationException::class);
    expect(DB::table('media_assets')->count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('PlatformMedia rejects executable content, spoofed types, SVG and unsafe names', function (): void {
    foreach ([['script.php', '<?php echo 1;'], ['fake.pdf', '<html><script>bad()</script></html>'], ['vector.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'], ['../guide.txt', 'fixture']] as [$name, $content]) {
        $fixture = UploadedFile::fake()->createWithContent('fixture.tmp', $content);
        // Preserve the hostile client name; Symfony's constructor otherwise strips directories.
        $file = new class($fixture->getRealPath(), $name, test: true) extends UploadedFile
        {
            public function getClientOriginalName(): string
            {
                return $this->getClientOriginalPath();
            }
        };
        expect(fn () => app(StoreMedia::class)->store($file, 'private_file', new MediaFixtureAuthorization))->toThrow(ValidationException::class);
    }
    expect(DB::table('media_assets')->count())->toBe(0);
});

it('PlatformMedia enforces actual byte limits and leaves no public data on write failure', function (): void {
    config(['media.private_max_bytes' => 10]);
    expect(fn () => app(StoreMedia::class)->store(UploadedFile::fake()->createWithContent('guide.txt', str_repeat('x', 11)), 'private_file', new MediaFixtureAuthorization))->toThrow(ValidationException::class);
    expect(DB::table('media_assets')->count())->toBe(0);
    config(['media.private_max_bytes' => 524288000]);
    Storage::shouldReceive('disk')->with('local')->andReturn(new class
    {
        public function put(string $key, mixed $stream): bool
        {
            throw new RuntimeException('private storage failure fixture');
        }
    });
    expect(fn () => app(StoreMedia::class)->store(UploadedFile::fake()->createWithContent('guide.txt', 'fixture'), 'private_file', new MediaFixtureAuthorization))->toThrow(RuntimeException::class, 'Media storage failed.');
    expect(DB::table('media_assets')->first())->status->toBe('failed')->error_code->toBe('storage_failed');
});

it('PlatformMedia keeps image originals private and records their pending dimensions', function (): void {
    $id = app(StoreMedia::class)->store(UploadedFile::fake()->image('photo.png', 32, 24), 'public_image', new MediaFixtureAuthorization);
    expect(DB::table('media_assets')->where('id', $id)->first())->status->toBe('pending')->width->toBe(32)->height->toBe(24)->public_key->toBeNull();
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

final class MediaFixtureAuthorization implements MediaUploadAuthorization
{
    public const STAFF_ID = '994f1470-e4c5-4727-a7c6-2c4a8e5b432a';

    public function authorizeUpload(): string
    {
        return self::STAFF_ID;
    }
}

it('PlatformMedia rejects oversized image dimensions and non-standalone intake', function (): void {
    config(['media.image_max_pixels' => 200]);
    expect(fn () => app(StoreMedia::class)->store(UploadedFile::fake()->image('large.png', 16, 16), 'public_image', new MediaFixtureAuthorization))->toThrow(ValidationException::class);
    config(['media.image_max_pixels' => 16000000, 'media.image_max_dimension' => 10]);
    expect(fn () => app(StoreMedia::class)->store(UploadedFile::fake()->image('wide.png', 11, 1), 'public_image', new MediaFixtureAuthorization))->toThrow(ValidationException::class);
    expect(fn () => DB::transaction(fn () => app(StoreMedia::class)->store(UploadedFile::fake()->createWithContent('guide.txt', 'fixture'), 'private_file', new MediaFixtureAuthorization)))
        ->toThrow(LogicException::class, 'Media intake must run outside a business transaction.');
    expect(DB::table('media_assets')->count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('PlatformMedia refuses an accidentally public private-storage root', function (): void {
    config(['filesystems.disks.local.root' => public_path()]);
    expect(fn () => app(StoreMedia::class)->store(UploadedFile::fake()->createWithContent('guide.txt', 'fixture'), 'private_file', new MediaFixtureAuthorization))
        ->toThrow(LogicException::class, 'Private media storage must remain outside the web directory.');
    expect(DB::table('media_assets')->count())->toBe(0);
});

it('PlatformMedia rebuilds public image pixels and strips appended executable content', function (): void {
    $file = UploadedFile::fake()->image('photo.png', 32, 24);
    file_put_contents($file->getRealPath(), '<?php private_fixture_payload ?>', FILE_APPEND);
    $id = app(StoreMedia::class)->store($file, 'public_image', new MediaFixtureAuthorization);
    app(ProcessMedia::class)->process($id);
    $asset = DB::table('media_assets')->where('id', $id)->first();
    expect($asset)->status->toBe('ready')->public_type->toBe('image/png');
    $bytes = Storage::disk('public')->get($asset->public_key);
    expect($bytes)->not->toContain('private_fixture_payload');
    expect(getimagesizefromstring($bytes))->not->toBeFalse();
    expect($asset->public_checksum)->toBe(hash('sha256', $bytes));
    expect(Storage::disk('local')->get($asset->original_key))->toContain('private_fixture_payload');
    app(ProcessMedia::class)->process($id);
    expect(Storage::disk('public')->allFiles())->toHaveCount(1);
});

it('PlatformMedia fails image processing closed when stored originals change', function (): void {
    $id = app(StoreMedia::class)->store(UploadedFile::fake()->image('photo.png', 32, 24), 'public_image', new MediaFixtureAuthorization);
    Storage::disk('local')->put('media/'.$id.'/original', '<script>fixture</script>');
    app(ProcessMedia::class)->process($id);
    expect(DB::table('media_assets')->where('id', $id)->first())->status->toBe('failed')->error_code->toBe('processing_failed')->public_key->toBeNull();
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('PlatformMedia leaves a failed draft when public image publication fails', function (): void {
    $id = app(StoreMedia::class)->store(UploadedFile::fake()->image('photo.png', 32, 24), 'public_image', new MediaFixtureAuthorization);
    config(['media.generated_max_bytes' => 1]);
    app(ProcessMedia::class)->process($id);
    expect(DB::table('media_assets')->where('id', $id)->first())->status->toBe('failed')->error_code->toBe('processing_failed')->public_key->toBeNull();
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('PlatformMedia rechecks storage separation before processing existing originals', function (): void {
    $id = app(StoreMedia::class)->store(UploadedFile::fake()->image('photo.png', 32, 24), 'public_image', new MediaFixtureAuthorization);
    config(['filesystems.disks.local.root' => public_path()]);
    app(ProcessMedia::class)->process($id);
    expect(DB::table('media_assets')->where('id', $id)->value('status'))->toBe('failed');
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('PlatformMedia refuses overlapping public and private disk roots', function (): void {
    $id = app(StoreMedia::class)->store(UploadedFile::fake()->image('photo.png', 32, 24), 'public_image', new MediaFixtureAuthorization);
    config(['filesystems.disks.public.root' => config('filesystems.disks.local.root')]);
    app(ProcessMedia::class)->process($id);
    expect(DB::table('media_assets')->where('id', $id)->value('status'))->toBe('failed');
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('PlatformMedia removes temporary derivatives after a failed atomic publication', function (): void {
    $id = app(StoreMedia::class)->store(UploadedFile::fake()->image('photo.png', 32, 24), 'public_image', new MediaFixtureAuthorization);
    $disk = Mockery::mock(Storage::disk('public'));
    $disk->shouldReceive('move')->once()->andReturn(false);
    Storage::set('public', $disk);
    app(ProcessMedia::class)->process($id);
    expect(DB::table('media_assets')->where('id', $id)->first())->status->toBe('failed')->public_key->toBeNull();
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('PlatformMedia never processes private downloads into public images', function (): void {
    $id = app(StoreMedia::class)->store(UploadedFile::fake()->createWithContent('guide.txt', 'fictional fixture'), 'private_file', new MediaFixtureAuthorization);
    app(ProcessMedia::class)->process($id);
    expect(DB::table('media_assets')->where('id', $id)->first())->status->toBe('ready')->public_key->toBeNull();
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('PlatformMedia cleans abandoned drafts and keeps ready originals and derivatives', function (): void {
    $failed = app(StoreMedia::class)->store(UploadedFile::fake()->createWithContent('failed.txt', 'fixture'), 'private_file', new MediaFixtureAuthorization);
    DB::table('media_assets')->where('id', $failed)->update(['status' => 'staging', 'created_at' => now()->subHours(25), 'updated_at' => now()->subHours(25)]);
    $ready = app(StoreMedia::class)->store(UploadedFile::fake()->image('photo.png', 32, 24), 'public_image', new MediaFixtureAuthorization);
    app(ProcessMedia::class)->process($ready);
    DB::table('media_assets')->where('id', $ready)->update(['created_at' => now()->subHours(25)]);
    $temporary = 'media/'.$ready.'/e2b2d2da-5a2b-4058-bc92-f0a0860708b9.tmp';
    Storage::disk('public')->put($temporary, 'generated fixture');
    touch(Storage::disk('public')->path($temporary), now()->subHours(25)->timestamp);
    app(CleanMedia::class)->clean();
    expect(Storage::disk('local')->exists('media/'.$failed.'/original'))->toBeFalse();
    expect(DB::table('media_assets')->where('id', $failed)->first())->status->toBe('failed')->error_code->toBe('upload_abandoned')->purged_at->not->toBeNull();
    expect(Storage::disk('local')->exists('media/'.$ready.'/original'))->toBeTrue();
    expect(Storage::disk('public')->exists(DB::table('media_assets')->where('id', $ready)->value('public_key')))->toBeTrue();
    expect(Storage::disk('public')->exists($temporary))->toBeFalse();
});

it('PlatformMedia cleanup preserves recent failed drafts and applies a bounded file budget', function (): void {
    $id = app(StoreMedia::class)->store(UploadedFile::fake()->createWithContent('guide.txt', 'fixture'), 'private_file', new MediaFixtureAuthorization);
    DB::table('media_assets')->where('id', $id)->update(['status' => 'failed']);
    app(CleanMedia::class)->clean();
    expect(Storage::disk('local')->exists('media/'.$id.'/original'))->toBeTrue();
    DB::table('media_assets')->where('id', $id)->update(['created_at' => now()->subHours(25), 'updated_at' => now()->subHours(25)]);
    foreach (['9e7c5a6b-3fe7-45b3-9760-10b6ee4cfbf6', 'f3a37c04-e5d4-49c7-ae51-c5bd296f9e0c'] as $suffix) {
        $key = 'media/'.$id.'/'.$suffix.'.tmp';
        Storage::disk('public')->put($key, 'generated fixture');
        touch(Storage::disk('public')->path($key), now()->subHours(25)->timestamp);
    }
    app(CleanMedia::class)->clean(fileLimit: 1);
    expect(Storage::disk('public')->allFiles())->toHaveCount(1);
    expect(Storage::disk('local')->exists('media/'.$id.'/original'))->toBeTrue();
    app(CleanMedia::class)->clean();
    expect(Storage::disk('local')->exists('media/'.$id.'/original'))->toBeFalse();
});

it('PlatformMedia reports cleanup failures without marking private drafts purged', function (): void {
    $id = app(StoreMedia::class)->store(UploadedFile::fake()->createWithContent('guide.txt', 'fixture'), 'private_file', new MediaFixtureAuthorization);
    DB::table('media_assets')->where('id', $id)->update(['status' => 'failed', 'created_at' => now()->subHours(25), 'updated_at' => now()->subHours(25)]);
    $disk = Mockery::mock(Storage::disk('local'));
    $disk->shouldReceive('delete')->once()->andThrow(new RuntimeException('private failure fixture'));
    Storage::set('local', $disk);
    expect(fn () => app(CleanMedia::class)->clean())->toThrow(RuntimeException::class, 'Media cleanup was incomplete.');
    expect(DB::table('media_assets')->where('id', $id)->value('purged_at'))->toBeNull();
});

it('PlatformMedia health detects stale processing independently of outbox age', function (): void {
    $this->artisan('platform:heartbeat')->assertSuccessful();
    $id = app(StoreMedia::class)->store(UploadedFile::fake()->image('photo.png', 32, 24), 'public_image', new MediaFixtureAuthorization);
    DB::table('media_assets')->where('id', $id)->update(['created_at' => now()->subSeconds(301)]);
    $this->artisan('platform:health')->expectsOutputToContain('media_pending: 1')->assertExitCode(1);
});
