<?php

declare(strict_types=1);

namespace App\Domain\Platform\Actions;

use App\Domain\Platform\Support\MediaStoragePolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Throwable;

final class ProcessMedia
{
    public function process(string $id): void
    {
        if (! Str::isUuid($id) || DB::transactionLevel() !== 0) {
            throw new LogicException('Media processing requires an asset UUID outside a business transaction.');
        }
        $temporaryKey = null;
        $output = null;
        $image = null;
        try {
            DB::transaction(function () use ($id, &$temporaryKey, &$output, &$image): void {
                $asset = DB::table('media_assets')->where('id', $id)->lockForUpdate()->first();
                if ($asset === null || $asset->kind !== 'public_image' || $asset->status !== 'pending') {
                    return;
                }
                app(MediaStoragePolicy::class)->assertPublic();
                if ($asset->original_key !== 'media/'.$asset->id.'/original') {
                    throw new RuntimeException('Invalid media storage policy.');
                }
                $private = Storage::disk('local');
                if ($private->size($asset->original_key) > config()->integer('media.image_max_bytes')) {
                    throw new RuntimeException('Invalid source size.');
                }
                $source = $private->get($asset->original_key);
                if (! is_string($source)) {
                    throw new RuntimeException('Image source is unavailable.');
                }
                $dimensions = @getimagesizefromstring($source);
                if (! hash_equals($asset->checksum, hash('sha256', $source)) || $dimensions === false
                    || ! in_array($dimensions[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)
                    || $dimensions[0] < 1 || $dimensions[1] < 1
                    || max($dimensions[0], $dimensions[1]) > config()->integer('media.image_max_dimension')
                    || config()->integer('media.image_max_pixels') < $dimensions[0] * $dimensions[1]) {
                    throw new RuntimeException('Invalid source integrity or dimensions.');
                }
                $image = @imagecreatefromstring($source);
                unset($source);
                $output = tmpfile();
                if ($image === false || $output === false) {
                    throw new RuntimeException('Image decoding failed.');
                }
                imagealphablending($image, false);
                imagesavealpha($image, true);
                if (! imagepng($image, $output, 6)) {
                    throw new RuntimeException('Image encoding failed.');
                }
                $statistics = fstat($output);
                if ($statistics === false) {
                    throw new RuntimeException('Image output is unavailable.');
                }
                $size = $statistics['size'];
                if ($size < 1 || $size > config()->integer('media.generated_max_bytes')) {
                    throw new RuntimeException('Generated image exceeds size policy.');
                }
                rewind($output);
                $hash = hash_init('sha256');
                hash_update_stream($hash, $output);
                $checksum = hash_final($hash);
                rewind($output);
                $temporaryKey = 'media/'.$id.'/'.Str::uuid().'.tmp';
                $key = 'media/'.$id.'/'.$checksum.'.png';
                $public = Storage::disk('public');
                if (! $public->put($temporaryKey, $output) || ! $public->move($temporaryKey, $key)) {
                    throw new RuntimeException('Image publication failed.');
                }
                $temporaryKey = null;
                DB::table('media_assets')->where('id', $id)->update([
                    'status' => 'ready', 'public_key' => $key, 'public_type' => 'image/png',
                    'public_bytes' => $size, 'public_checksum' => $checksum, 'queued_until' => null, 'error_code' => null, 'updated_at' => now(),
                ]);
                app(RecordEvent::class)->record('platform.audit.recorded', 'media:processed:'.$id, [
                    'action' => 'media.processed', 'subject_type' => 'media', 'subject_id' => $id,
                ]);
            });
        } catch (Throwable $exception) {
            DB::table('media_assets')->where('id', $id)->where('status', 'pending')->update([
                'status' => 'failed', 'queued_until' => null, 'error_code' => 'processing_failed', 'updated_at' => now(),
            ]);
            Log::warning('media.processing_failed', ['media_id' => $id, 'error_code' => 'processing_failed', 'exception_class' => $exception::class]);
        } finally {
            if (is_resource($output)) {
                fclose($output);
            }
            if ($image instanceof \GdImage) {
                imagedestroy($image);
            }
            if ($temporaryKey !== null) {
                Storage::disk('public')->delete($temporaryKey);
            }
        }
    }
}
