<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installed_themes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('theme_id', 191)->collation('utf8mb4_0900_bin')->unique();
            $table->string('name', 120)->nullable();
            $table->string('version', 32)->nullable();
            $table->string('schema_version', 16)->nullable();
            $table->string('path', 500);
            $table->text('manifest');
            $table->char('checksum', 64);
            $table->string('status', 16);
            $table->text('errors')->nullable();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE installed_themes ADD CONSTRAINT installed_themes_status_check CHECK (status IN ('valid', 'invalid'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('installed_themes');
    }
};
