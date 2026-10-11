<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_heartbeats', function (Blueprint $table): void {
            $table->string('name', 64)->primary();
            $table->timestamp('observed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_heartbeats');
    }
};
