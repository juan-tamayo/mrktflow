<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table): void {
            $table->boolean('allows_stacking')->default(false);
            $table->boolean('exclude_sale_priced')->default(false);
        });
        Schema::table('promotion_versions', function (Blueprint $table): void {
            $table->boolean('allows_stacking')->default(false);
            $table->boolean('exclude_sale_priced')->default(false);
        });
        DB::statement('ALTER TABLE promotion_campaigns DROP CHECK promotion_campaigns_kind_check');
        DB::statement("ALTER TABLE promotion_campaigns ADD CONSTRAINT promotion_campaigns_kind_check CHECK (kind IN ('reusable', 'batch', 'recipient', 'automatic'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE promotion_campaigns DROP CHECK promotion_campaigns_kind_check');
        DB::statement("ALTER TABLE promotion_campaigns ADD CONSTRAINT promotion_campaigns_kind_check CHECK (kind IN ('reusable', 'batch', 'recipient', 'automatic'))");
        Schema::table('coupons', function (Blueprint $table): void {
            $table->dropColumn(['allows_stacking', 'exclude_sale_priced']);
        });
        Schema::table('promotion_versions', function (Blueprint $table): void {
            $table->dropColumn(['allows_stacking', 'exclude_sale_priced']);
        });
    }
};
