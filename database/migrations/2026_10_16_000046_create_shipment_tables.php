<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE orders DROP CHECK orders_fulfillment_status_check');
        DB::statement("ALTER TABLE orders MODIFY fulfillment_status VARCHAR(24) NOT NULL DEFAULT 'unfulfilled'");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_fulfillment_status_check CHECK (fulfillment_status IN ('unfulfilled', 'not_required', 'partially_fulfilled', 'fulfilled'))");
        DB::statement('ALTER TABLE idempotency_records DROP CHECK idempotency_records_operation_check');
        DB::statement("ALTER TABLE idempotency_records ADD CONSTRAINT idempotency_records_operation_check CHECK (operation IN ('checkout-session', 'order-refund', 'order-cancel', 'order-shipment'))");
        DB::statement('ALTER TABLE order_events DROP CHECK order_events_type_check');
        DB::statement("ALTER TABLE order_events ADD CONSTRAINT order_events_type_check CHECK (type IN ('paid', 'cancelled', 'receipt_queued', 'receipt_resent', 'external_refund', 'dispute_opened', 'dispute_won', 'dispute_lost', 'shipment_created'))");
        Schema::create('shipments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignUuid('staff_id')->constrained('staff_accounts')->restrictOnDelete();
            $table->string('carrier', 120)->nullable();
            $table->string('tracking_number', 191)->nullable();
            $table->string('tracking_url', 1024)->nullable();
            $table->timestamps();
            $table->index(['order_id', 'created_at']);
        });
        Schema::create('shipment_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('shipment_id')->constrained('shipments')->restrictOnDelete();
            $table->foreignUuid('order_line_id')->constrained('order_lines')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unique(['shipment_id', 'order_line_id']);
        });
        DB::statement('ALTER TABLE shipment_lines ADD CONSTRAINT shipment_lines_quantity_check CHECK (quantity >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_lines');
        Schema::dropIfExists('shipments');
        DB::statement('ALTER TABLE order_events DROP CHECK order_events_type_check');
        DB::statement("ALTER TABLE order_events ADD CONSTRAINT order_events_type_check CHECK (type IN ('paid', 'cancelled', 'receipt_queued', 'receipt_resent', 'external_refund', 'dispute_opened', 'dispute_won', 'dispute_lost'))");
        DB::statement('ALTER TABLE idempotency_records DROP CHECK idempotency_records_operation_check');
        DB::statement("ALTER TABLE idempotency_records ADD CONSTRAINT idempotency_records_operation_check CHECK (operation IN ('checkout-session', 'order-refund', 'order-cancel'))");
        DB::statement('ALTER TABLE orders DROP CHECK orders_fulfillment_status_check');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_fulfillment_status_check CHECK (fulfillment_status IN ('unfulfilled', 'not_required'))");
    }
};
