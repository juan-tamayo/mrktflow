<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->string('provider_account', 255)->nullable();
            $table->boolean('live')->default(false);
            $table->char('outbound_hash', 64)->nullable();
            $table->text('hosted_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->dropColumn(['provider_account', 'live', 'outbound_hash', 'hosted_url']);
        });
    }
};
