<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installations', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('status', 32)->default('unconfigured');
            $table->char('secret_digest', 64)->nullable();
            $table->uuid('generation')->nullable();
            $table->timestamp('secret_expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
        DB::table('installations')->insert(['id' => 1, 'status' => 'unconfigured', 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('store_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedInteger('version')->default(1);
            $table->json('values');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_settings');
        Schema::dropIfExists('installations');
    }
};
