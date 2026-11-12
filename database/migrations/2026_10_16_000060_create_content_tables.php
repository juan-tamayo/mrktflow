<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title', 200);
            $table->string('slug', 80);
            $table->string('path', 88);
            $table->string('status', 16);
            $table->unsignedInteger('version')->default(1);
            $table->uuid('updated_by')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique('slug');
            $table->unique('path');
            $table->index(['status', 'published_at']);
        });
        Schema::create('articles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title', 200);
            $table->string('slug', 80);
            $table->string('path', 90);
            $table->string('excerpt', 500)->default('');
            $table->string('status', 16);
            $table->unsignedInteger('version')->default(1);
            $table->uuid('updated_by')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique('slug');
            $table->unique('path');
            $table->index(['status', 'published_at']);
        });
        Schema::create('menus', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('handle', 32);
            $table->string('title', 120);
            $table->unsignedInteger('version')->default(1);
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->unique('handle');
        });
        Schema::create('menu_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('menu_id')->constrained('menus')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('label', 80);
            $table->string('url', 500);
            $table->timestamps();
            $table->unique(['menu_id', 'position']);
        });
        DB::statement("ALTER TABLE pages ADD CONSTRAINT pages_status_check CHECK (status IN ('draft', 'published'))");
        DB::statement("ALTER TABLE pages ADD CONSTRAINT pages_slug_check CHECK (slug REGEXP '^[a-z0-9]+(-[a-z0-9]+)*$')");
        DB::statement("ALTER TABLE pages ADD CONSTRAINT pages_path_check CHECK (path REGEXP '^pages/[a-z0-9]+(-[a-z0-9]+)*$')");
        DB::statement("ALTER TABLE articles ADD CONSTRAINT articles_status_check CHECK (status IN ('draft', 'published'))");
        DB::statement("ALTER TABLE articles ADD CONSTRAINT articles_slug_check CHECK (slug REGEXP '^[a-z0-9]+(-[a-z0-9]+)*$')");
        DB::statement("ALTER TABLE articles ADD CONSTRAINT articles_path_check CHECK (path REGEXP '^journal/[a-z0-9]+(-[a-z0-9]+)*$')");
        DB::statement("ALTER TABLE menus ADD CONSTRAINT menus_handle_check CHECK (handle REGEXP '^[a-z][a-z0-9-]{0,31}$')");
        DB::statement("ALTER TABLE menu_items ADD CONSTRAINT menu_items_url_check CHECK (url REGEXP '^/')");
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_items');
        Schema::dropIfExists('menus');
        Schema::dropIfExists('articles');
        Schema::dropIfExists('pages');
    }
};
