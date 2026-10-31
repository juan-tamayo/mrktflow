<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watch_subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('contact_id')->constrained('delivery_contacts')->restrictOnDelete();
            $table->foreignUuid('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->string('alert_type', 16);
            $table->string('status', 16)->default('pending');
            $table->integer('baseline_amount')->nullable();
            $table->string('baseline_currency', 3)->nullable();
            $table->unsignedTinyInteger('baseline_exponent')->nullable();
            $table->timestamps();
            $table->unique(['contact_id', 'variant_id', 'alert_type']);
            $table->index(['contact_id', 'status']);
            $table->index(['variant_id', 'status']);
        });
        Schema::create('watch_verifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('contact_id')->constrained('delivery_contacts')->restrictOnDelete();
            $table->char('token_digest', 64)->nullable();
            $table->char('email_digest', 64);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique('token_digest');
            $table->index(['contact_id', 'created_at']);
        });
        Schema::create('watch_verification_intents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('contact_id')->constrained('delivery_contacts')->restrictOnDelete();
            $table->foreignUuid('verification_id')->constrained('watch_verifications')->restrictOnDelete();
            $table->string('template', 32);
            $table->string('recipient', 254);
            $table->string('intent_key', 191);
            $table->string('kind', 16);
            $table->string('status', 16);
            $table->timestamp('created_at')->nullable();
            $table->unique('intent_key');
            $table->index(['contact_id', 'created_at']);
        });
        DB::statement("ALTER TABLE watch_subscriptions ADD CONSTRAINT watch_subscriptions_alert_type_check CHECK (alert_type IN ('stock', 'price'))");
        DB::statement("ALTER TABLE watch_subscriptions ADD CONSTRAINT watch_subscriptions_status_check CHECK (status IN ('pending', 'active', 'cancelled'))");
        DB::statement('ALTER TABLE watch_subscriptions ADD CONSTRAINT watch_subscriptions_baseline_check CHECK ((baseline_amount IS NULL AND baseline_currency IS NULL AND baseline_exponent IS NULL) OR (baseline_amount IS NOT NULL AND baseline_currency IS NOT NULL AND baseline_exponent IS NOT NULL))');
        DB::statement("ALTER TABLE watch_verification_intents ADD CONSTRAINT watch_verification_intents_template_check CHECK (template IN ('watch-verification'))");
        DB::statement("ALTER TABLE watch_verification_intents ADD CONSTRAINT watch_verification_intents_kind_check CHECK (kind IN ('request'))");
        DB::statement("ALTER TABLE watch_verification_intents ADD CONSTRAINT watch_verification_intents_status_check CHECK (status IN ('queued'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('watch_verification_intents');
        Schema::dropIfExists('watch_verifications');
        Schema::dropIfExists('watch_subscriptions');
    }
};
