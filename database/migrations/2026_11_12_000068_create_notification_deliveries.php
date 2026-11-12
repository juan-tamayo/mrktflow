<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('intent_key', 191);
            $table->string('template', 32);
            $table->string('policy_class', 16);
            $table->string('purpose', 32)->nullable();
            $table->string('recipient', 254);
            $table->string('kind', 16);
            $table->text('payload')->nullable();
            $table->string('status', 16);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->uuid('claim_id')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->timestamp('queued_until')->nullable();
            $table->timestamp('available_at');
            $table->timestamp('expires_at');
            $table->timestamp('delivered_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestamps();
            $table->unique('intent_key');
            $table->index(['status', 'available_at', 'queued_until'], 'notification_mail_due_index');
            $table->index(['status', 'lease_until']);
            $table->index('expires_at');
        });
        DB::statement("ALTER TABLE notification_deliveries ADD CONSTRAINT notification_deliveries_status_check CHECK (status IN ('pending', 'sending', 'delivered', 'cancelled', 'failed'))");
        DB::statement("ALTER TABLE notification_deliveries ADD CONSTRAINT notification_deliveries_kind_check CHECK (kind IN ('event', 'test'))");
        DB::statement("ALTER TABLE notification_deliveries ADD CONSTRAINT notification_deliveries_class_check CHECK (policy_class IN ('transactional', 'marketing'))");
        DB::statement("ALTER TABLE notification_deliveries ADD CONSTRAINT notification_deliveries_template_check CHECK (template IN ('order-receipt', 'shipment', 'download-access', 'refund', 'customer-challenge', 'newsletter', 'watch-alert', 'cart_recovery'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
