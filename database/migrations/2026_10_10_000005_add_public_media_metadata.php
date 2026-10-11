<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->string('public_type', 100)->nullable();
            $table->unsignedBigInteger('public_bytes')->nullable();
            $table->char('public_checksum', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->dropColumn(['public_type', 'public_bytes', 'public_checksum']);
        });
    }
};
