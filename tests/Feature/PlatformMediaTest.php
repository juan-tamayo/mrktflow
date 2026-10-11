<?php

declare(strict_types=1);

use App\Domain\Platform\Actions\StoreMedia;
use App\Domain\Platform\Contracts\MediaUploadAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(DatabaseMigrations::class);

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
