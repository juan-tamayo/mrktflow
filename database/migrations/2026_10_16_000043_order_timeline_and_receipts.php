<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('type', 32);
            $table->uuid('source_event_id')->nullable();
            $table->json('payload');
            $table->timestamp('created_at');
            $table->index(['order_id', 'created_at']);
            $table->unique(['order_id', 'type', 'source_event_id'], 'order_events_source_unique');
        });
        Schema::create('order_receipt_intents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();
            $table->uuid('source_event_id')->nullable();
            $table->string('template', 32);
            $table->string('recipient', 254);
            $table->string('intent_key', 191);
            $table->string('kind', 16);
            $table->string('status', 16);
            $table->timestamp('created_at');
            $table->unique('intent_key');
            $table->unique(['source_event_id', 'template'], 'order_receipt_intents_event_unique');
            $table->index(['order_id', 'created_at']);
        });
        DB::statement("ALTER TABLE order_events ADD CONSTRAINT order_events_type_check CHECK (type IN ('paid', 'cancelled', 'receipt_queued', 'receipt_resent'))");
        DB::statement("ALTER TABLE order_receipt_intents ADD CONSTRAINT order_receipt_intents_kind_check CHECK (kind IN ('event', 'resend'))");
        DB::statement("ALTER TABLE order_receipt_intents ADD CONSTRAINT order_receipt_intents_status_check CHECK (status IN ('queued'))");
        DB::statement("ALTER TABLE order_receipt_intents ADD CONSTRAINT order_receipt_intents_template_check CHECK (template IN ('order-receipt'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('order_receipt_intents');
        Schema::dropIfExists('order_events');
    }
};
