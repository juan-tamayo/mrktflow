<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotion_campaigns', function (Blueprint $table): void {
            $table->unsignedBigInteger('held_amount')->default(0);
            $table->unsignedBigInteger('redeemed_amount')->default(0);
        });
        Schema::table('promotion_versions', function (Blueprint $table): void {
            $table->unsignedBigInteger('discount_budget')->nullable();
            $table->unsignedBigInteger('max_discount_amount')->nullable();
            $table->boolean('first_customer_only')->default(false);
        });
        Schema::create('promotion_budget_operations', function (Blueprint $table): void {
            $table->uuid('operation_key')->primary();
            $table->uuid('intent_id');
            $table->string('action', 16);
            $table->char('payload_hash', 64);
            $table->timestamp('created_at');
            $table->index('intent_id');
        });
        Schema::create('promotion_budget_holds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('intent_id');
            $table->foreignUuid('campaign_id')->constrained('promotion_campaigns')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->foreignUuid('coupon_id')->nullable()->constrained('coupons')->restrictOnDelete();
            $table->uuid('customer_key')->nullable();
            $table->unsignedBigInteger('amount');
            $table->boolean('first_customer')->default(false);
            $table->string('status', 16);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['intent_id', 'campaign_id']);
            $table->index(['campaign_id', 'customer_key', 'status']);
            $table->index(['status', 'expires_at']);
        });
        DB::statement("ALTER TABLE promotion_budget_operations ADD CONSTRAINT promotion_budget_operations_action_check CHECK (action IN ('hold', 'commit', 'release'))");
        DB::statement("ALTER TABLE promotion_budget_holds ADD CONSTRAINT promotion_budget_holds_status_check CHECK (status IN ('active', 'committed', 'released'))");
        DB::statement('ALTER TABLE promotion_campaigns ADD CONSTRAINT promotion_campaigns_budget_usage_check CHECK (held_amount + redeemed_amount < 10000000000000000)');
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_budget_holds');
        Schema::dropIfExists('promotion_budget_operations');
        Schema::table('promotion_versions', function (Blueprint $table): void {
            $table->dropColumn(['discount_budget', 'max_discount_amount', 'first_customer_only']);
        });
        DB::statement('ALTER TABLE promotion_campaigns DROP CHECK promotion_campaigns_budget_usage_check');
        Schema::table('promotion_campaigns', function (Blueprint $table): void {
            $table->dropColumn(['held_amount', 'redeemed_amount']);
        });
    }
};
