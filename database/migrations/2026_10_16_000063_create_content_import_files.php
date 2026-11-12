<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_import_files', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('staff_id');
            $table->string('original_name', 200);
            $table->string('storage_key', 255);
            $table->char('checksum', 64);
            $table->unsignedInteger('bytes');
            $table->json('mapping')->nullable();
            $table->json('report')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->json('apply_result')->nullable();
            $table->timestamp('created_at');
            $table->index('staff_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_import_files');
    }
};
