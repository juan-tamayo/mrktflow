<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('email', 254)->collation('utf8mb4_0900_bin')->unique();
            $table->string('password');
            $table->string('role', 32);
            $table->json('permissions');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('session_version')->default(1);
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->index(['role', 'is_active']);
        });
        Schema::create('staff_security', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
        });
        DB::table('staff_security')->insert(['id' => 1]);
        Schema::table('sessions', function (Blueprint $table): void {
            $table->uuid('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Session rollback signs users out; UUID principals cannot become numeric identifiers.
        DB::table('sessions')->delete();
        Schema::table('sessions', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });
        Schema::dropIfExists('staff_security');
        Schema::dropIfExists('staff_accounts');
    }
};
