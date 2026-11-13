<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_campaigns', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('kind', 16);
            $table->string('status', 16);
            $table->unsignedInteger('revision')->default(1);
            $table->uuid('current_version_id')->nullable();
            $table->timestamps();
            $table->index(['kind', 'status']);
        });
        Schema::create('promotion_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('campaign_id')->constrained('promotion_campaigns')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('mode', 16);
            $table->unsignedBigInteger('amount')->nullable();
            $table->unsignedInteger('percent_numerator')->nullable();
            $table->unsignedInteger('percent_denominator')->nullable();
            $table->unsignedBigInteger('min_eligible_subtotal')->default(0);
            $table->char('currency', 3);
            $table->unsignedTinyInteger('exponent');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedTinyInteger('max_redemptions_per_customer')->nullable();
            $table->json('product_ids');
            $table->json('category_ids');
            $table->foreignUuid('created_by')->constrained('staff_accounts')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->unique(['campaign_id', 'version']);
        });
        Schema::create('coupon_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('campaign_id')->constrained('promotion_campaigns')->restrictOnDelete();
            $table->unsignedInteger('campaign_version');
            $table->string('purpose', 32);
            $table->string('issuer', 32);
            $table->string('source_intent_id', 190);
            $table->char('payload_hash', 64);
            $table->unsignedInteger('requested_count');
            $table->unsignedInteger('generated_count')->default(0);
            $table->string('status', 16);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['issuer', 'source_intent_id']);
            $table->index(['campaign_id', 'status']);
        });
        Schema::create('coupon_grants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('campaign_id')->constrained('promotion_campaigns')->restrictOnDelete();
            $table->unsignedInteger('campaign_version');
            $table->foreignUuid('batch_id')->nullable()->constrained('coupon_batches')->restrictOnDelete();
            $table->foreignUuid('coupon_id')->constrained('coupons')->restrictOnDelete();
            $table->string('issuer', 32);
            $table->string('source_intent_id', 190);
            $table->char('payload_hash', 64);
            $table->string('kind', 16);
            $table->foreignUuid('recipient_customer_id')->nullable()->constrained('customer_accounts')->restrictOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->string('status', 16);
            $table->timestamps();
            $table->unique(['issuer', 'source_intent_id']);
            $table->unique('coupon_id');
            $table->index(['recipient_customer_id', 'status', 'expires_at']);
            $table->index(['campaign_id', 'status']);
        });
        DB::statement("ALTER TABLE promotion_campaigns ADD CONSTRAINT promotion_campaigns_kind_check CHECK (kind IN ('reusable', 'batch', 'recipient'))");
        DB::statement("ALTER TABLE promotion_campaigns ADD CONSTRAINT promotion_campaigns_status_check CHECK (status IN ('draft', 'active', 'paused'))");
        DB::statement("ALTER TABLE promotion_versions ADD CONSTRAINT promotion_versions_mode_check CHECK (mode IN ('fixed', 'percentage', 'free_shipping'))");
        DB::statement("ALTER TABLE coupon_batches ADD CONSTRAINT coupon_batches_purpose_check CHECK (purpose IN ('unique_codes', 'recipient_import'))");
        DB::statement("ALTER TABLE coupon_batches ADD CONSTRAINT coupon_batches_issuer_check CHECK (issuer IN ('staff', 'import', 'batch'))");
        DB::statement("ALTER TABLE coupon_batches ADD CONSTRAINT coupon_batches_status_check CHECK (status IN ('pending', 'generating', 'completed', 'paused', 'failed'))");
        DB::statement('ALTER TABLE coupon_batches ADD CONSTRAINT coupon_batches_counts_check CHECK (generated_count <= requested_count AND requested_count > 0 AND requested_count <= 1000)');
        DB::statement("ALTER TABLE coupon_grants ADD CONSTRAINT coupon_grants_issuer_check CHECK (issuer IN ('staff', 'import', 'recovery', 'referral', 'batch'))");
        DB::statement("ALTER TABLE coupon_grants ADD CONSTRAINT coupon_grants_kind_check CHECK (kind IN ('reusable', 'batch', 'recipient'))");
        DB::statement("ALTER TABLE coupon_grants ADD CONSTRAINT coupon_grants_status_check CHECK (status IN ('issued', 'revoked'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_grants');
        Schema::dropIfExists('coupon_batches');
        Schema::dropIfExists('promotion_versions');
        Schema::dropIfExists('promotion_campaigns');
    }
};
