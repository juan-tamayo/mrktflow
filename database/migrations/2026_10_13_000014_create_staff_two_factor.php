<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_two_factor', function (Blueprint $table): void {
            $table->uuid('staff_id')->primary();
            $table->foreign('staff_id')->references('id')->on('staff_accounts')->cascadeOnDelete();
            $table->text('pending_payload')->nullable();
            $table->text('secret_payload')->nullable();
            $table->text('recovery_payload')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedBigInteger('last_totp_step')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_two_factor');
    }
};
