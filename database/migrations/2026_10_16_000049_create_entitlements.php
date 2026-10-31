<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entitlements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignUuid('order_line_id')->constrained('order_lines')->restrictOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained('customer_accounts')->restrictOnDelete();
            $table->foreignUuid('digital_asset_version_id')->constrained('digital_asset_versions')->restrictOnDelete();
            $table->string('status', 16);
            $table->uuid('source_event_id')->nullable();
            $table->timestamps();
            $table->unique('order_line_id');
            $table->index(['order_id', 'status']);
            $table->index(['customer_id', 'status']);
        });
        DB::statement("ALTER TABLE entitlements ADD CONSTRAINT entitlements_status_check CHECK (status IN ('active', 'suspended', 'revoked', 'expired'))");
        DB::statement('ALTER TABLE order_events DROP CHECK order_events_type_check');
        DB::statement("ALTER TABLE order_events ADD CONSTRAINT order_events_type_check CHECK (type IN ('paid', 'cancelled', 'receipt_queued', 'receipt_resent', 'external_refund', 'dispute_opened', 'dispute_won', 'dispute_lost', 'shipment_created', 'shipment_notification_queued', 'return_requested', 'return_approved', 'return_received', 'return_restocked', 'entitlements_issued'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('entitlements');
        DB::statement('ALTER TABLE order_events DROP CHECK order_events_type_check');
        DB::statement("ALTER TABLE order_events ADD CONSTRAINT order_events_type_check CHECK (type IN ('paid', 'cancelled', 'receipt_queued', 'receipt_resent', 'external_refund', 'dispute_opened', 'dispute_won', 'dispute_lost', 'shipment_created', 'shipment_notification_queued', 'return_requested', 'return_approved', 'return_received', 'return_restocked'))");
    }
};
