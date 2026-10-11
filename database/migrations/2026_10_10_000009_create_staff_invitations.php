<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('inviter_id');
            $table->string('email', 254)->collation('utf8mb4_0900_bin')->index();
            $table->string('role', 32);
            $table->json('permissions');
            $table->char('token_digest', 64)->collation('ascii_bin')->nullable()->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_invitations');
    }
};
