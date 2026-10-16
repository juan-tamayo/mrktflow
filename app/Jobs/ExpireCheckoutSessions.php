<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\CheckoutOrders\Actions\ExpireCheckout;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

final class ExpireCheckoutSessions implements ShouldBeEncrypted, ShouldQueue
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
        $expire = app(ExpireCheckout::class);
        $ids = DB::table('payment_attempts')->whereIn('status', ['created', 'awaiting_payment'])
            ->where('expires_at', '<=', now())
            ->orderBy('expires_at')->limit(20)->pluck('id');
        foreach ($ids as $id) {
            if (is_string($id)) {
                $expire->expire($id);
            }
        }
    }
}
