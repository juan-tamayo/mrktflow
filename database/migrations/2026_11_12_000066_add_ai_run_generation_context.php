<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->string('locale', 16)->default('en-US');
            $table->string('prompt', 4000);
            $table->json('product_ids');
            $table->unsignedInteger('max_output_tokens')->default(1024);
            $table->boolean('cancel_requested')->default(false);
            $table->string('status_message', 120)->nullable();
            $table->unsignedTinyInteger('provider_attempts')->default(0);
            $table->uuid('record_operation_key');
            $table->uuid('release_operation_key');
        });
    }

    public function down(): void
    {
        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->dropColumn([
                'locale',
                'prompt',
                'product_ids',
                'max_output_tokens',
                'cancel_requested',
                'status_message',
                'provider_attempts',
                'record_operation_key',
                'release_operation_key',
            ]);
        });
    }
};
