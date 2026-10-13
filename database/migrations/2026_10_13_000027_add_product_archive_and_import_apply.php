<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE products DROP CHECK products_status_check');
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_status_check CHECK (status IN ('draft', 'published', 'archived'))");
        Schema::table('products', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable();
        });
        Schema::table('product_import_files', function (Blueprint $table): void {
            $table->timestamp('applied_at')->nullable();
            $table->json('apply_result')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('product_import_files', function (Blueprint $table): void {
            $table->dropColumn(['applied_at', 'apply_result']);
        });
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('archived_at');
        });
        DB::table('products')->where('status', 'archived')->update(['status' => 'draft']);
        DB::statement('ALTER TABLE products DROP CHECK products_status_check');
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_status_check CHECK (status IN ('draft', 'published'))");
    }
};
