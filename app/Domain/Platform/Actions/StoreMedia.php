<?php

declare(strict_types=1);

namespace App\Domain\Platform\Actions;

use App\Domain\Platform\Contracts\MediaUploadAuthorization;
use App\Domain\Platform\Support\MediaStoragePolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use Throwable;

final class StoreMedia
{
    public function store(UploadedFile $file, string $kind, MediaUploadAuthorization $authorization): string
    {
        $owner = $authorization->authorizeUpload();
        if (! Str::isUuid($owner)) {
            throw new AuthorizationException('A valid staff principal is required.');
        }
        // Preserve a committed staging record before file I/O; callers attach the returned asset separately.
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Media intake must run outside a business transaction.');
        }
        app(MediaStoragePolicy::class)->assertPrivate();
        $name = $file->getClientOriginalName();
        $path = $file->getRealPath();
        if (! in_array($kind, ['public_image', 'private_file'], true) || ! $file->isValid()
            || $path === false || is_link($file->getPathname()) || ! is_file($path)
            || $name === '' || strlen($name) > 180 || preg_match('/[\x00-\x1F\x7F\/\\\\]/', $name)) {
            $this->invalid();
        }
        $size = filesize($path);
        $limit = config()->integer($kind === 'public_image' ? 'media.image_max_bytes' : 'media.private_max_bytes');
        if ($size === false || $size < 1 || $size > $limit) {
            $this->invalid();
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $types = $kind === 'public_image'
            ? ['image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png'], 'image/webp' => ['webp']]
            : config()->array('media.private_types');
        if (! is_string($mime) || ! isset($types[$mime]) || ! in_array($extension, $types[$mime], true)) {
            $this->invalid();
        }
        $dimensions = $kind === 'public_image' ? @getimagesize($path) : null;
        if ($kind === 'public_image' && ($dimensions === false || $dimensions === null
            || $dimensions[0] < 1 || $dimensions[1] < 1
            || max($dimensions[0], $dimensions[1]) > config()->integer('media.image_max_dimension')
            || config()->integer('media.image_max_pixels') < $dimensions[0] * $dimensions[1])) {
            $this->invalid();
        }
        $checksum = hash_file('sha256', $path);
        if ($checksum === false) {
            throw new RuntimeException('Media could not be read.');
        }
        $id = (string) Str::uuid();
        $key = 'media/'.$id.'/original';
        DB::table('media_assets')->insert([
            'id' => $id, 'owner_id' => $owner, 'kind' => $kind, 'original_name' => Crypt::encryptString($name),
            'original_key' => $key, 'content_type' => $mime, 'bytes' => $size, 'checksum' => $checksum,
            'width' => $dimensions[0] ?? null, 'height' => $dimensions[1] ?? null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $stream = null;
        $written = false;
        try {
            $stream = @fopen($path, 'rb');
            if ($stream === false || ! Storage::disk('local')->put($key, $stream)) {
                throw new RuntimeException('Private media write failed.');
            }
            $written = true;
            DB::transaction(function () use ($id, $kind, $owner): void {
                DB::table('media_assets')->where('id', $id)->update([
                    'status' => $kind === 'public_image' ? 'pending' : 'ready', 'updated_at' => now(),
                ]);
                app(RecordEvent::class)->record('platform.audit.recorded', 'media:uploaded:'.$id, [
                    'action' => 'media.uploaded', 'subject_type' => 'media', 'subject_id' => $id,
                    'actor_type' => 'staff', 'actor_id' => $owner,
                ]);
            });
        } catch (Throwable $exception) {
            $code = $written ? 'persistence_failed' : 'storage_failed';
            DB::table('media_assets')->where('id', $id)->update(['status' => 'failed', 'error_code' => $code, 'updated_at' => now()]);
            Log::warning('media.upload_failed', ['media_id' => $id, 'error_code' => $code, 'exception_class' => $exception::class]);
            throw new RuntimeException('Media storage failed.');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $id;
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['file' => 'Use an allowed file type and filename within the configured size and image limits.']);
    }
}
