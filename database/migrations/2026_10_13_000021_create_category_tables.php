<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->string('title', 200);
            $table->string('slug', 80);
            $table->string('path', 420);
            $table->text('description');
            $table->string('status', 16);
            $table->unsignedInteger('version')->default(1);
            $table->unsignedTinyInteger('depth');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique('path');
            $table->unique(['parent_id', 'slug']);
            $table->index(['status', 'path']);
        });
        Schema::create('category_redirects', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('from_path', 420);
            $table->string('to_path', 420);
            $table->timestamps();
            $table->unique('from_path');
            $table->index('to_path');
        });
        Schema::create('product_categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('category_id')->constrained('categories')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['product_id', 'category_id']);
            $table->index(['category_id', 'product_id']);
        });
        DB::statement("ALTER TABLE categories ADD CONSTRAINT categories_status_check CHECK (status IN ('draft', 'published'))");
        DB::statement('ALTER TABLE categories ADD CONSTRAINT categories_depth_check CHECK (depth BETWEEN 1 AND 5)');
        DB::statement("ALTER TABLE categories ADD CONSTRAINT categories_path_check CHECK (path REGEXP '^[a-z0-9]+(-[a-z0-9]+)*(/[a-z0-9]+(-[a-z0-9]+)*){0,4}$')");
        DB::statement("ALTER TABLE category_redirects ADD CONSTRAINT category_redirects_from_check CHECK (from_path REGEXP '^[a-z0-9]+(-[a-z0-9]+)*(/[a-z0-9]+(-[a-z0-9]+)*){0,4}$')");
        DB::statement("ALTER TABLE category_redirects ADD CONSTRAINT category_redirects_to_check CHECK (to_path REGEXP '^[a-z0-9]+(-[a-z0-9]+)*(/[a-z0-9]+(-[a-z0-9]+)*){0,4}$')");
        DB::statement('ALTER TABLE category_redirects ADD CONSTRAINT category_redirects_distinct_check CHECK (from_path <> to_path)');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_categories');
        Schema::dropIfExists('category_redirects');
        Schema::dropIfExists('categories');
    }
};
