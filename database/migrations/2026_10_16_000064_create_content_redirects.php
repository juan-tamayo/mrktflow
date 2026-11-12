<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_redirects', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('from_path', 96);
            $table->string('to_path', 96);
            $table->timestamps();
            $table->unique('from_path');
        });
        DB::statement("ALTER TABLE content_redirects ADD CONSTRAINT content_redirects_from_check CHECK (from_path REGEXP '^/(pages|journal)/[a-z0-9]+(-[a-z0-9]+)*$')");
        DB::statement("ALTER TABLE content_redirects ADD CONSTRAINT content_redirects_to_check CHECK (to_path REGEXP '^/(pages|journal)/[a-z0-9]+(-[a-z0-9]+)*$')");
        DB::statement('ALTER TABLE content_redirects ADD CONSTRAINT content_redirects_distinct_check CHECK (from_path <> to_path)');

        Schema::create('product_redirects', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('from_slug', 80);
            $table->string('to_slug', 80);
            $table->timestamps();
            $table->unique('from_slug');
        });
        DB::statement("ALTER TABLE product_redirects ADD CONSTRAINT product_redirects_from_check CHECK (from_slug REGEXP '^[a-z0-9]+(-[a-z0-9]+)*$')");
        DB::statement("ALTER TABLE product_redirects ADD CONSTRAINT product_redirects_to_check CHECK (to_slug REGEXP '^[a-z0-9]+(-[a-z0-9]+)*$')");
        DB::statement('ALTER TABLE product_redirects ADD CONSTRAINT product_redirects_distinct_check CHECK (from_slug <> to_slug)');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_redirects');
        Schema::dropIfExists('content_redirects');
    }
};
