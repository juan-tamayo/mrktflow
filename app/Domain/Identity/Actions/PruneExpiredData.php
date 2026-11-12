<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Support\RetentionPolicy;
use App\Domain\Operations\Actions\PruneExpiredExports;
use App\Domain\Platform\Support\MediaStoragePolicy;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

final class PruneExpiredData
{
    public function prune(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 1000 || DB::transactionLevel() !== 0) {
            throw new InvalidArgumentException('Retention cleanup requires a bounded standalone batch.');
        }
        app(MediaStoragePolicy::class)->assertPrivate();
        $changed = app(PruneExpiredExports::class)->prune($limit);
        $changed += $this->prunePrivacyExports($limit);
        $changed += $this->pruneGuestCarts($limit);
        $changed += $this->pruneAiPrompts($limit);

        return $changed;
    }

    private function prunePrivacyExports(int $limit): int
    {
        $ids = DB::table('privacy_requests')->where('type', 'export')
            ->whereNotNull('storage_key')
            ->whereNotNull('export_expires_at')
            ->where('export_expires_at', '<=', now())
            ->orderBy('export_expires_at')
            ->limit($limit)
            ->pluck('id');
        $changed = 0;
        foreach ($ids as $id) {
            $changed += DB::transaction(function () use ($id): int {
                $row = DB::table('privacy_requests')->where('id', $id)->lockForUpdate()->first();
                if ($row === null || $row->storage_key === null || $row->export_expires_at === null || now()->lt($row->export_expires_at)) {
                    return 0;
                }
                $key = is_string($row->storage_key) ? $row->storage_key : '';
                if ($key !== '' && preg_match('#^privacy/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.csv$#D', $key) === 1
                    && Storage::disk('local')->exists($key)
                    && ! Storage::disk('local')->delete($key)) {
                    throw new RuntimeException('Expired privacy export cleanup failed.');
                }
                DB::table('privacy_requests')->where('id', $id)->update([
                    'storage_key' => null,
                    'updated_at' => now(),
                ]);

                return 1;
            });
        }

        return $changed;
    }

    private function pruneGuestCarts(int $limit): int
    {
        $cutoff = now()->subDays(app(RetentionPolicy::class)->guestCartsDays());
        $ids = DB::table('carts')
            ->whereNotNull('guest_token_hash')
            ->where('updated_at', '<=', $cutoff)
            ->whereNotExists(function (Builder $query): void {
                $query->selectRaw('1')->from('orders')->whereColumn('orders.cart_id', 'carts.id');
            })
            ->orderBy('updated_at')
            ->limit($limit)
            ->pluck('id');
        $changed = 0;
        foreach ($ids as $id) {
            $changed += DB::transaction(function () use ($id, $cutoff): int {
                $cart = DB::table('carts')->where('id', $id)->lockForUpdate()->first();
                if ($cart === null || $cart->guest_token_hash === null || Carbon::parse((string) $cart->updated_at)->gt($cutoff)) {
                    return 0;
                }
                if (DB::table('orders')->where('cart_id', $id)->exists()) {
                    return 0;
                }
                DB::table('cart_lines')->where('cart_id', $id)->delete();
                DB::table('carts')->where('id', $id)->delete();

                return 1;
            });
        }

        return $changed;
    }

    private function pruneAiPrompts(int $limit): int
    {
        $cutoff = now()->subDays(app(RetentionPolicy::class)->aiPromptLogsDays());
        $ids = DB::table('ai_runs')
            ->where('prompt', '!=', '')
            ->whereIn('status', ['failed', 'cancelled', 'unknown'])
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('updated_at')
            ->limit($limit)
            ->pluck('id');
        $changed = 0;
        foreach ($ids as $id) {
            $changed += DB::transaction(function () use ($id, $cutoff): int {
                $run = DB::table('ai_runs')->where('id', $id)->lockForUpdate()->first();
                if ($run === null || (string) $run->prompt === '' || Carbon::parse((string) $run->updated_at)->gt($cutoff)) {
                    return 0;
                }
                if (! in_array((string) $run->status, ['failed', 'cancelled', 'unknown'], true)) {
                    return 0;
                }
                DB::table('ai_runs')->where('id', $id)->update(['prompt' => '', 'updated_at' => now()]);

                return 1;
            });
        }

        return $changed;
    }
}
