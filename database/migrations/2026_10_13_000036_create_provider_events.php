<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider', 16);
            $table->string('account_ref', 255);
            $table->string('environment', 8);
            $table->string('event_id', 191);
            $table->string('event_type', 128);
            $table->string('processing_state', 16);
            $table->longText('payload');
            $table->char('payload_digest', 64);
            $table->uuid('correlation_id');
            $table->timestamp('received_at');
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->unique(['provider', 'account_ref', 'environment', 'event_id'], 'provider_events_dedup_unique');
            $table->index(['processing_state', 'next_attempt_at']);
        });
        DB::statement("ALTER TABLE provider_events ADD CONSTRAINT provider_events_provider_check CHECK (provider IN ('stripe'))");
        DB::statement("ALTER TABLE provider_events ADD CONSTRAINT provider_events_environment_check CHECK (environment IN ('test', 'live'))");
        DB::statement("ALTER TABLE provider_events ADD CONSTRAINT provider_events_state_check CHECK (processing_state IN ('received', 'processing', 'processed', 'failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_events');
    }
};
