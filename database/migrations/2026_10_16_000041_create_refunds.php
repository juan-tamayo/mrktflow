<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE orders DROP CHECK orders_financial_status_check');
        DB::statement("ALTER TABLE orders MODIFY financial_status VARCHAR(24) NOT NULL DEFAULT 'unpaid'");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_financial_status_check CHECK (financial_status IN ('unpaid', 'paid', 'unknown', 'partially_refunded', 'refunded'))");
        DB::statement('ALTER TABLE idempotency_records DROP CHECK idempotency_records_operation_check');
        DB::statement("ALTER TABLE idempotency_records ADD CONSTRAINT idempotency_records_operation_check CHECK (operation IN ('checkout-session', 'order-refund'))");
        Schema::table('order_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedInteger('shipped_qty')->default(0);
            $table->unsignedInteger('held_qty')->default(0);
            $table->unsignedInteger('cancelled_qty')->default(0);
            $table->unsignedInteger('refunded_qty')->default(0);
            $table->unsignedInteger('eligibility_revision')->default(1);
        });
        DB::statement('ALTER TABLE order_lines ADD CONSTRAINT order_lines_qty_bounds_check CHECK (shipped_qty + held_qty + cancelled_qty <= quantity AND refunded_qty <= quantity)');
        Schema::create('refunds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignUuid('payment_id')->constrained('payments')->restrictOnDelete();
            $table->foreignUuid('staff_id')->constrained('staff_accounts')->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('shipping_amount')->default(0);
            $table->char('currency', 3);
            $table->unsignedTinyInteger('exponent');
            $table->string('kind', 16);
            $table->string('status', 16);
            $table->string('reason', 500);
            $table->string('capture_id', 191);
            $table->string('provider_refund_id', 191)->nullable();
            $table->timestamps();
            $table->index(['order_id', 'status']);
            $table->unique('provider_refund_id');
        });
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_kind_check CHECK (kind IN ('quantity', 'goodwill'))");
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_status_check CHECK (status IN ('processing', 'succeeded', 'failed', 'unknown'))");
        Schema::create('refund_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('refund_id')->constrained('refunds')->restrictOnDelete();
            $table->foreignUuid('order_line_id')->constrained('order_lines')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('amount');
            $table->unique(['refund_id', 'order_line_id']);
        });
        DB::statement('ALTER TABLE refund_lines ADD CONSTRAINT refund_lines_quantity_check CHECK (quantity >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_lines');
        Schema::dropIfExists('refunds');
        DB::statement('ALTER TABLE order_lines DROP CHECK order_lines_qty_bounds_check');
        Schema::table('order_lines', function (Blueprint $table): void {
            $table->dropColumn(['tax_amount', 'shipped_qty', 'held_qty', 'cancelled_qty', 'refunded_qty', 'eligibility_revision']);
        });
        DB::statement('ALTER TABLE idempotency_records DROP CHECK idempotency_records_operation_check');
        DB::statement("ALTER TABLE idempotency_records ADD CONSTRAINT idempotency_records_operation_check CHECK (operation IN ('checkout-session'))");
        DB::statement('ALTER TABLE orders DROP CHECK orders_financial_status_check');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_financial_status_check CHECK (financial_status IN ('unpaid', 'paid', 'unknown'))");
    }
};
