<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE idempotency_records DROP CHECK idempotency_records_operation_check');
        DB::statement("ALTER TABLE idempotency_records ADD CONSTRAINT idempotency_records_operation_check CHECK (operation IN ('checkout-session', 'order-refund', 'order-cancel', 'order-shipment', 'order-return', 'order-return-approve', 'order-return-receive', 'order-return-restock'))");
        DB::statement('ALTER TABLE order_events DROP CHECK order_events_type_check');
        DB::statement("ALTER TABLE order_events ADD CONSTRAINT order_events_type_check CHECK (type IN ('paid', 'cancelled', 'receipt_queued', 'receipt_resent', 'external_refund', 'dispute_opened', 'dispute_won', 'dispute_lost', 'shipment_created', 'shipment_notification_queued', 'return_requested', 'return_approved', 'return_received', 'return_restocked'))");
        DB::statement('ALTER TABLE stock_movements DROP CHECK stock_movements_reason_check');
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_reason_check CHECK (reason IN ('hold', 'commit', 'release', 'ship', 'adjust', 'restock'))");
        DB::statement('ALTER TABLE inventory_operations DROP CHECK inventory_operations_action_check');
        DB::statement("ALTER TABLE inventory_operations ADD CONSTRAINT inventory_operations_action_check CHECK (action IN ('hold', 'commit', 'release', 'ship', 'adjust', 'restock'))");
        Schema::create('order_returns', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignUuid('staff_id')->constrained('staff_accounts')->restrictOnDelete();
            $table->string('status', 24);
            $table->string('reason', 500);
            $table->timestamps();
            $table->index(['order_id', 'created_at']);
        });
        DB::statement("ALTER TABLE order_returns ADD CONSTRAINT order_returns_status_check CHECK (status IN ('requested', 'approved', 'partially_received', 'received', 'rejected'))");
        Schema::create('return_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('return_id')->constrained('order_returns')->restrictOnDelete();
            $table->foreignUuid('order_line_id')->constrained('order_lines')->restrictOnDelete();
            $table->unsignedInteger('requested_qty');
            $table->unsignedInteger('approved_qty')->default(0);
            $table->unsignedInteger('received_qty')->default(0);
            $table->unsignedInteger('restocked_qty')->default(0);
            $table->unique(['return_id', 'order_line_id']);
        });
        DB::statement('ALTER TABLE return_lines ADD CONSTRAINT return_lines_stage_bounds_check CHECK (requested_qty >= 1 AND approved_qty <= requested_qty AND received_qty <= approved_qty AND restocked_qty <= received_qty)');
    }

    public function down(): void
    {
        Schema::dropIfExists('return_lines');
        Schema::dropIfExists('order_returns');
        DB::statement('ALTER TABLE inventory_operations DROP CHECK inventory_operations_action_check');
        DB::statement("ALTER TABLE inventory_operations ADD CONSTRAINT inventory_operations_action_check CHECK (action IN ('hold', 'commit', 'release', 'ship', 'adjust'))");
        DB::statement('ALTER TABLE stock_movements DROP CHECK stock_movements_reason_check');
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_reason_check CHECK (reason IN ('hold', 'commit', 'release', 'ship', 'adjust'))");
        DB::statement('ALTER TABLE order_events DROP CHECK order_events_type_check');
        DB::statement("ALTER TABLE order_events ADD CONSTRAINT order_events_type_check CHECK (type IN ('paid', 'cancelled', 'receipt_queued', 'receipt_resent', 'external_refund', 'dispute_opened', 'dispute_won', 'dispute_lost', 'shipment_created', 'shipment_notification_queued'))");
        DB::statement('ALTER TABLE idempotency_records DROP CHECK idempotency_records_operation_check');
        DB::statement("ALTER TABLE idempotency_records ADD CONSTRAINT idempotency_records_operation_check CHECK (operation IN ('checkout-session', 'order-refund', 'order-cancel', 'order-shipment'))");
    }
};
