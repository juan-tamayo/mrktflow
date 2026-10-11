<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('owner_id')->index();
            $table->string('kind', 32);
            $table->text('original_name');
            $table->string('original_key', 191)->collation('ascii_bin')->unique();
            $table->string('public_key', 191)->collation('ascii_bin')->nullable()->unique();
            $table->string('content_type', 100);
            $table->unsignedBigInteger('bytes');
            $table->char('checksum', 64);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('status', 16)->default('staging');
            $table->string('scan_status', 16)->default('not_scanned');
            $table->string('error_code', 64)->nullable();
            $table->timestamps();
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
