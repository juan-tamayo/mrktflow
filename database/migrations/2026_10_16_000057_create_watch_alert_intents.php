<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watch_alert_intents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('subscription_id')->constrained('watch_subscriptions')->restrictOnDelete();
            $table->foreignUuid('contact_id')->constrained('delivery_contacts')->restrictOnDelete();
            $table->foreignUuid('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->string('alert_type', 16);
            $table->string('template', 32);
            $table->string('recipient', 254);
            $table->string('intent_key', 191);
            $table->string('event_key', 191);
            $table->string('kind', 16);
            $table->string('status', 16);
            $table->string('error_code', 64)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique('intent_key');
            $table->index(['subscription_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
        DB::statement("ALTER TABLE watch_alert_intents ADD CONSTRAINT watch_alert_intents_alert_type_check CHECK (alert_type IN ('stock', 'price'))");
        DB::statement("ALTER TABLE watch_alert_intents ADD CONSTRAINT watch_alert_intents_template_check CHECK (template IN ('watch-alert'))");
        DB::statement("ALTER TABLE watch_alert_intents ADD CONSTRAINT watch_alert_intents_kind_check CHECK (kind IN ('event'))");
        DB::statement("ALTER TABLE watch_alert_intents ADD CONSTRAINT watch_alert_intents_status_check CHECK (status IN ('queued', 'sending', 'sent', 'cancelled', 'failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('watch_alert_intents');
    }
};
