<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_order_verifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();
            $table->char('token_digest', 64)->nullable();
            $table->char('email_digest', 64);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique('token_digest');
            $table->index(['order_id', 'created_at']);
        });
        Schema::create('download_access_intents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignUuid('verification_id')->constrained('guest_order_verifications')->restrictOnDelete();
            $table->string('template', 32);
            $table->string('recipient', 254);
            $table->string('intent_key', 191);
            $table->string('kind', 16);
            $table->string('status', 16);
            $table->timestamp('created_at');
            $table->unique('intent_key');
            $table->index(['order_id', 'created_at']);
        });
        DB::statement("ALTER TABLE download_access_intents ADD CONSTRAINT download_access_intents_template_check CHECK (template IN ('download-access'))");
        DB::statement("ALTER TABLE download_access_intents ADD CONSTRAINT download_access_intents_kind_check CHECK (kind IN ('request'))");
        DB::statement("ALTER TABLE download_access_intents ADD CONSTRAINT download_access_intents_status_check CHECK (status IN ('queued'))");
        DB::statement('ALTER TABLE order_events DROP CHECK order_events_type_check');
        DB::statement("ALTER TABLE order_events ADD CONSTRAINT order_events_type_check CHECK (type IN ('paid', 'cancelled', 'receipt_queued', 'receipt_resent', 'external_refund', 'dispute_opened', 'dispute_won', 'dispute_lost', 'shipment_created', 'shipment_notification_queued', 'return_requested', 'return_approved', 'return_received', 'return_restocked', 'entitlements_issued', 'download_access_requested'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('download_access_intents');
        Schema::dropIfExists('guest_order_verifications');
        DB::statement('ALTER TABLE order_events DROP CHECK order_events_type_check');
        DB::statement("ALTER TABLE order_events ADD CONSTRAINT order_events_type_check CHECK (type IN ('paid', 'cancelled', 'receipt_queued', 'receipt_resent', 'external_refund', 'dispute_opened', 'dispute_won', 'dispute_lost', 'shipment_created', 'shipment_notification_queued', 'return_requested', 'return_approved', 'return_received', 'return_restocked', 'entitlements_issued'))");
    }
};
