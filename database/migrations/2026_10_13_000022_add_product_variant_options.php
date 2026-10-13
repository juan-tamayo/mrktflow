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
            $table->json('options')->nullable();
            $table->boolean('available')->default(true);
            $table->foreignUuid('media_asset_id')->nullable()->constrained('media_assets')->restrictOnDelete();
            $table->string('image_alt', 200)->nullable();
            $table->unsignedInteger('weight_grams')->nullable();
            $table->unsignedInteger('length_mm')->nullable();
            $table->unsignedInteger('width_mm')->nullable();
            $table->unsignedInteger('height_mm')->nullable();
            $table->boolean('tracks_inventory')->default(false);
        });
        Schema::create('product_option_axes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('name', 40);
            $table->unsignedTinyInteger('sort_order');
            $table->timestamps();
            $table->unique(['product_id', 'name']);
            $table->unique(['product_id', 'sort_order']);
        });
        Schema::create('product_option_values', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('axis_id')->constrained('product_option_axes')->cascadeOnDelete();
            $table->string('value', 40);
            $table->unsignedTinyInteger('sort_order');
            $table->timestamps();
            $table->unique(['axis_id', 'value']);
            $table->unique(['axis_id', 'sort_order']);
        });
        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT product_variants_weight_check CHECK (weight_grams IS NULL OR weight_grams BETWEEN 1 AND 1000000)');
        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT product_variants_length_check CHECK (length_mm IS NULL OR length_mm BETWEEN 1 AND 10000)');
        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT product_variants_width_check CHECK (width_mm IS NULL OR width_mm BETWEEN 1 AND 10000)');
        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT product_variants_height_check CHECK (height_mm IS NULL OR height_mm BETWEEN 1 AND 10000)');
        DB::table('product_variants')->whereNull('options')->update(['options' => '[]']);
    }

    public function down(): void
    {
        Schema::dropIfExists('product_option_values');
        Schema::dropIfExists('product_option_axes');
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('media_asset_id');
            $table->dropColumn(['options', 'available', 'image_alt', 'weight_grams', 'length_mm', 'width_mm', 'height_mm', 'tracks_inventory']);
        });
    }
};
