<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_notification_intents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('shipment_id')->constrained('shipments')->restrictOnDelete();
            $table->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('template', 32);
            $table->string('recipient', 254);
            $table->string('intent_key', 191);
            $table->string('kind', 16);
            $table->string('status', 16);
            $table->timestamp('created_at');
            $table->unique('intent_key');
            $table->unique(['shipment_id', 'template'], 'shipment_notification_intents_shipment_unique');
            $table->index(['order_id', 'created_at']);
        });
        DB::statement("ALTER TABLE shipment_notification_intents ADD CONSTRAINT shipment_notification_intents_template_check CHECK (template IN ('shipment'))");
        DB::statement("ALTER TABLE shipment_notification_intents ADD CONSTRAINT shipment_notification_intents_kind_check CHECK (kind IN ('event'))");
        DB::statement("ALTER TABLE shipment_notification_intents ADD CONSTRAINT shipment_notification_intents_status_check CHECK (status IN ('queued'))");
        DB::statement('ALTER TABLE order_events DROP CHECK order_events_type_check');
        DB::statement("ALTER TABLE order_events ADD CONSTRAINT order_events_type_check CHECK (type IN ('paid', 'cancelled', 'receipt_queued', 'receipt_resent', 'external_refund', 'dispute_opened', 'dispute_won', 'dispute_lost', 'shipment_created', 'shipment_notification_queued'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_notification_intents');
        DB::statement('ALTER TABLE order_events DROP CHECK order_events_type_check');
        DB::statement("ALTER TABLE order_events ADD CONSTRAINT order_events_type_check CHECK (type IN ('paid', 'cancelled', 'receipt_queued', 'receipt_resent', 'external_refund', 'dispute_opened', 'dispute_won', 'dispute_lost', 'shipment_created'))");
    }
};
