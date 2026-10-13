<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_origins', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->char('country_code', 2);
            $table->string('region_code', 16)->nullable();
            $table->string('postal_code', 16);
            $table->string('city', 80);
            $table->string('street', 200);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
        });
        Schema::create('shipping_classes', function (Blueprint $table): void {
            $table->string('class_key', 32)->primary();
            $table->string('name', 80);
            $table->boolean('is_builtin')->default(false);
            $table->timestamps();
        });
        Schema::create('shipping_zones', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 80);
            $table->unsignedSmallInteger('priority');
            $table->string('status', 16);
            $table->timestamps();
            $table->index(['status', 'priority']);
        });
        Schema::create('shipping_zone_matches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('zone_id')->constrained('shipping_zones')->cascadeOnDelete();
            $table->char('country_code', 2);
            $table->string('region_code', 16)->nullable();
            $table->string('postal_pattern', 32)->nullable();
            $table->unique(['zone_id', 'country_code', 'region_code', 'postal_pattern'], 'shipping_zone_matches_unique');
        });
        Schema::create('shipping_methods', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('zone_id')->constrained('shipping_zones')->cascadeOnDelete();
            $table->string('label', 80);
            $table->string('type', 16);
            $table->unsignedBigInteger('amount')->nullable();
            $table->char('currency', 3);
            $table->unsignedTinyInteger('exponent');
            $table->unsignedBigInteger('free_min_subtotal')->nullable();
            $table->string('table_basis', 16)->nullable();
            $table->string('pickup_name', 80)->nullable();
            $table->string('pickup_street', 200)->nullable();
            $table->string('pickup_city', 80)->nullable();
            $table->string('pickup_region_code', 16)->nullable();
            $table->string('pickup_postal_code', 16)->nullable();
            $table->char('pickup_country_code', 2)->nullable();
            $table->string('pickup_instructions', 500)->nullable();
            $table->unsignedInteger('sort_order');
            $table->timestamps();
            $table->index(['zone_id', 'sort_order']);
        });
        Schema::create('shipping_rate_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('method_id')->constrained('shipping_methods')->cascadeOnDelete();
            $table->unsignedBigInteger('min_value');
            $table->unsignedBigInteger('max_value')->nullable();
            $table->unsignedBigInteger('amount');
            $table->unsignedInteger('sort_order');
            $table->unique(['method_id', 'min_value']);
        });
        Schema::create('shipping_method_classes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('method_id')->constrained('shipping_methods')->cascadeOnDelete();
            $table->string('class_key', 32);
            $table->string('mode', 16);
            $table->unique(['method_id', 'class_key']);
            $table->foreign('class_key')->references('class_key')->on('shipping_classes')->restrictOnDelete();
        });
        DB::table('shipping_classes')->insert([
            'class_key' => 'general',
            'name' => 'General',
            'is_builtin' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::statement("ALTER TABLE shipping_zones ADD CONSTRAINT shipping_zones_status_check CHECK (status IN ('draft', 'active'))");
        DB::statement("ALTER TABLE shipping_methods ADD CONSTRAINT shipping_methods_type_check CHECK (type IN ('flat', 'free', 'table', 'pickup'))");
        DB::statement("ALTER TABLE shipping_methods ADD CONSTRAINT shipping_methods_table_basis_check CHECK (table_basis IS NULL OR table_basis IN ('subtotal', 'weight'))");
        DB::statement("ALTER TABLE shipping_method_classes ADD CONSTRAINT shipping_method_classes_mode_check CHECK (mode IN ('include', 'exclude'))");
        DB::statement('ALTER TABLE shipping_rate_rules ADD CONSTRAINT shipping_rate_rules_range_check CHECK (max_value IS NULL OR max_value > min_value)');
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_method_classes');
        Schema::dropIfExists('shipping_rate_rules');
        Schema::dropIfExists('shipping_methods');
        Schema::dropIfExists('shipping_zone_matches');
        Schema::dropIfExists('shipping_zones');
        Schema::dropIfExists('shipping_classes');
        Schema::dropIfExists('shipping_origins');
    }
};
