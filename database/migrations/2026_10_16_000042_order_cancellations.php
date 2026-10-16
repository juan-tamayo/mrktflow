<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE idempotency_records DROP CHECK idempotency_records_operation_check');
        DB::statement("ALTER TABLE idempotency_records ADD CONSTRAINT idempotency_records_operation_check CHECK (operation IN ('checkout-session', 'order-refund', 'order-cancel'))");
        DB::statement('ALTER TABLE refunds DROP CHECK refunds_kind_check');
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_kind_check CHECK (kind IN ('quantity', 'goodwill', 'cancellation'))");
        DB::statement('ALTER TABLE orders DROP CHECK orders_download_status_check');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_download_status_check CHECK (download_status IN ('locked', 'entitled', 'not_required', 'revoked'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE orders DROP CHECK orders_download_status_check');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_download_status_check CHECK (download_status IN ('locked', 'entitled', 'not_required'))");
        DB::statement('ALTER TABLE refunds DROP CHECK refunds_kind_check');
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_kind_check CHECK (kind IN ('quantity', 'goodwill'))");
        DB::statement('ALTER TABLE idempotency_records DROP CHECK idempotency_records_operation_check');
        DB::statement("ALTER TABLE idempotency_records ADD CONSTRAINT idempotency_records_operation_check CHECK (operation IN ('checkout-session', 'order-refund'))");
    }
};
