<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\CheckoutOrders\Actions\ReconcileRefund;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

final class ReconcilePendingRefunds implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('payments');
    }

    public function handle(): void
    {
        $reconcile = app(ReconcileRefund::class);
        $ids = DB::table('refunds')->whereIn('status', ['unknown', 'processing'])
            ->orderBy('updated_at')->limit(20)->pluck('id');
        foreach ($ids as $id) {
            if (is_string($id)) {
                $reconcile->reconcile($id);
            }
        }
    }
}
