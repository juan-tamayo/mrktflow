<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->unsignedTinyInteger('max_quantity')->default(99);
        });
        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT product_variants_max_quantity_check CHECK (max_quantity BETWEEN 1 AND 99)');
        DB::statement('ALTER TABLE products DROP CHECK products_type_check');
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_type_check CHECK (type IN ('physical', 'digital'))");

        Schema::create('digital_assets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained('products')->restrictOnDelete();
            $table->string('label', 120);
            $table->timestamps();
            $table->index(['product_id', 'created_at']);
        });
        Schema::create('digital_asset_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('digital_asset_id')->constrained('digital_assets')->restrictOnDelete();
            $table->foreignUuid('media_asset_id')->constrained('media_assets')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 16);
            $table->unsignedBigInteger('bytes');
            $table->string('content_type', 100);
            $table->char('checksum', 64);
            $table->timestamps();
            $table->unique(['digital_asset_id', 'version']);
            $table->unique('media_asset_id');
        });
        Schema::create('variant_digital_assets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignUuid('digital_asset_id')->constrained('digital_assets')->restrictOnDelete();
            $table->foreignUuid('digital_asset_version_id')->constrained('digital_asset_versions')->restrictOnDelete();
            $table->unsignedTinyInteger('sort_order');
            $table->timestamps();
            $table->unique(['variant_id', 'digital_asset_id']);
            $table->unique(['variant_id', 'sort_order']);
        });
        DB::statement("ALTER TABLE digital_asset_versions ADD CONSTRAINT digital_asset_versions_status_check CHECK (status IN ('processing', 'ready', 'quarantined', 'failed'))");
        DB::statement('ALTER TABLE digital_asset_versions ADD CONSTRAINT digital_asset_versions_bytes_check CHECK (bytes BETWEEN 1 AND 524288000)');
        DB::statement('ALTER TABLE digital_asset_versions ADD CONSTRAINT digital_asset_versions_version_check CHECK (version >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('variant_digital_assets');
        Schema::dropIfExists('digital_asset_versions');
        Schema::dropIfExists('digital_assets');
        DB::statement('ALTER TABLE product_variants DROP CHECK product_variants_max_quantity_check');
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropColumn('max_quantity');
        });
        DB::table('products')->where('type', 'digital')->update(['type' => 'physical']);
        DB::statement('ALTER TABLE products DROP CHECK products_type_check');
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_type_check CHECK (type = 'physical')");
    }
};
