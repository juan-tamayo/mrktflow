<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Pricing\Actions\ProcessCouponBatch;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

final class GenerateCouponBatch implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 1;

    public function __construct(public readonly string $batchId)
    {
        $this->onQueue('default');
    }

    public function handle(ProcessCouponBatch $processor): void
    {
        $processor->process($this->batchId);
        $row = DB::table('coupon_batches')->where('id', $this->batchId)->first();
        $status = is_object($row) ? (get_object_vars($row)['status'] ?? null) : null;
        $generated = is_object($row) ? (get_object_vars($row)['generated_count'] ?? null) : null;
        $requested = is_object($row) ? (get_object_vars($row)['requested_count'] ?? null) : null;
        if ($status === 'pending' && is_numeric($generated) && is_numeric($requested) && (int) $generated < (int) $requested) {
            self::dispatch($this->batchId);
        }
    }
}
