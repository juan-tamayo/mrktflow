<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->timestamp('queued_until')->nullable();
            $table->timestamp('purged_at')->nullable();
            $table->index(['status', 'queued_until']);
        });
    }

    public function down(): void
    {
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->dropIndex(['status', 'queued_until']);
            $table->dropColumn(['queued_until', 'purged_at']);
        });
    }
};
