<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title', 200);
            $table->string('slug', 80);
            $table->text('description');
            $table->string('type', 16);
            $table->string('status', 16);
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique('slug');
            $table->index(['status', 'published_at', 'id']);
        });
        Schema::create('product_variants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained('products')->restrictOnDelete();
            $table->string('sku', 64);
            $table->boolean('is_default');
            $table->string('option_fingerprint', 128);
            $table->unsignedBigInteger('price_amount');
            $table->char('currency', 3);
            $table->unsignedTinyInteger('exponent');
            $table->string('tax_class_key', 32);
            $table->string('shipping_class_key', 32);
            $table->timestamps();
            $table->unique('sku');
            $table->unique(['product_id', 'option_fingerprint']);
        });
        Schema::create('product_media', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('media_asset_id')->constrained('media_assets')->restrictOnDelete();
            $table->string('alt', 200);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['product_id', 'sort_order']);
        });
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_type_check CHECK (type = 'physical')");
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_status_check CHECK (status IN ('draft', 'published'))");
        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT product_variants_price_check CHECK (price_amount <= 9007199254740991)');
        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT product_variants_exponent_check CHECK (exponent <= 3)');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_media');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
    }
};
