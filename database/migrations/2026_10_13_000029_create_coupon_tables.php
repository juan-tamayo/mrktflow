<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code_normalized', 32);
            $table->string('name', 120);
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
            $table->unsignedInteger('held_count')->default(0);
            $table->unsignedInteger('redeemed_count')->default(0);
            $table->unsignedInteger('revision')->default(1);
            $table->string('status', 16);
            $table->timestamps();
            $table->unique('code_normalized');
            $table->index(['status', 'starts_at', 'ends_at']);
        });
        Schema::create('coupon_scopes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('coupon_id')->constrained('coupons')->restrictOnDelete();
            $table->string('scope_type', 16);
            $table->uuid('target_id');
            $table->timestamp('created_at');
            $table->unique(['coupon_id', 'scope_type', 'target_id']);
            $table->index('target_id');
        });
        Schema::create('coupon_operations', function (Blueprint $table): void {
            $table->uuid('operation_key')->primary();
            $table->uuid('intent_id');
            $table->string('action', 16);
            $table->char('payload_hash', 64);
            $table->timestamp('created_at');
            $table->index('intent_id');
        });
        Schema::create('coupon_holds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('intent_id');
            $table->foreignUuid('coupon_id')->constrained('coupons')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->uuid('customer_key')->nullable();
            $table->string('status', 16);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique('intent_id');
            $table->index(['coupon_id', 'customer_key', 'status']);
            $table->index(['status', 'expires_at']);
        });
        Schema::create('coupon_redemptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('coupon_id')->constrained('coupons')->restrictOnDelete();
            $table->foreignUuid('hold_id')->constrained('coupon_holds')->restrictOnDelete();
            $table->uuid('intent_id');
            $table->uuid('customer_key')->nullable();
            $table->unsignedInteger('revision');
            $table->timestamp('committed_at');
            $table->unique('intent_id');
            $table->unique('hold_id');
            $table->index(['coupon_id', 'customer_key']);
        });
        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_mode_check CHECK (mode IN ('fixed', 'percentage', 'free_shipping'))");
        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_status_check CHECK (status IN ('draft', 'active', 'paused'))");
        DB::statement('ALTER TABLE coupons ADD CONSTRAINT coupons_usage_check CHECK (held_count + redeemed_count < 4294967295)');
        DB::statement("ALTER TABLE coupon_scopes ADD CONSTRAINT coupon_scopes_type_check CHECK (scope_type IN ('product', 'category'))");
        DB::statement("ALTER TABLE coupon_operations ADD CONSTRAINT coupon_operations_action_check CHECK (action IN ('hold', 'commit', 'release'))");
        DB::statement("ALTER TABLE coupon_holds ADD CONSTRAINT coupon_holds_status_check CHECK (status IN ('active', 'committed', 'released'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('coupon_holds');
        Schema::dropIfExists('coupon_operations');
        Schema::dropIfExists('coupon_scopes');
        Schema::dropIfExists('coupons');
    }
};
