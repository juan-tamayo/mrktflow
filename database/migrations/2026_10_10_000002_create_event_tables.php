<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_type', 128);
            $table->unsignedSmallInteger('schema_version');
            $table->string('intent_key', 191)->collation('ascii_bin')->unique();
            $table->longText('payload');
            $table->char('payload_digest', 64);
            $table->uuid('correlation_id');
            $table->timestamp('occurred_at');
            $table->timestamp('published_at')->nullable();
        });
        Schema::create('consumer_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('event_id')->constrained('outbox_events')->restrictOnDelete();
            $table->string('consumer', 100);
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->timestamp('queued_until')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestamps();
            $table->unique(['event_id', 'consumer']);
            $table->index(['status', 'available_at', 'queued_until']);
        });
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('action', 128);
            $table->string('subject_type', 64);
            $table->string('subject_id', 64);
            $table->string('actor_type', 32)->default('system');
            $table->uuid('actor_id')->nullable();
            $table->json('metadata');
            $table->uuid('correlation_id');
            $table->timestamp('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('consumer_receipts');
        Schema::dropIfExists('outbox_events');
    }
};
