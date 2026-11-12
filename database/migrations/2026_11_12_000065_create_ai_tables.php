<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('enabled')->default(false);
            $table->string('provider', 32);
            $table->string('model', 80);
            $table->text('secret_payload')->nullable();
            $table->char('secret_hint', 4)->nullable();
            $table->unsignedInteger('daily_token_budget')->default(0);
            $table->unsignedInteger('monthly_token_budget')->default(0);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
        });
        Schema::create('ai_authors', function (Blueprint $table): void {
            $table->foreignUuid('staff_id')->primary()->constrained('staff_accounts')->restrictOnDelete();
            $table->timestamp('created_at');
        });
        Schema::create('ai_usage_operations', function (Blueprint $table): void {
            $table->uuid('operation_key')->primary();
            $table->uuid('intent_id');
            $table->string('action', 16);
            $table->char('payload_hash', 64);
            $table->timestamp('created_at');
            $table->index('intent_id');
        });
        Schema::create('ai_usage_reservations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('intent_id');
            $table->foreignUuid('staff_id')->constrained('staff_accounts')->restrictOnDelete();
            $table->unsignedInteger('estimated_tokens');
            $table->unsignedInteger('charged_tokens')->nullable();
            $table->string('status', 24);
            $table->date('period_day');
            $table->char('period_month', 7);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique('intent_id');
            $table->index(['status', 'period_day']);
            $table->index(['status', 'period_month']);
        });
        Schema::create('ai_usage_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('reservation_id')->constrained('ai_usage_reservations')->restrictOnDelete();
            $table->uuid('intent_id');
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->boolean('usage_known');
            $table->timestamp('created_at');
            $table->unique('reservation_id');
            $table->unique('intent_id');
        });
        Schema::create('ai_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('intent_id');
            $table->foreignUuid('reservation_id')->constrained('ai_usage_reservations')->restrictOnDelete();
            $table->foreignUuid('staff_id')->constrained('staff_accounts')->restrictOnDelete();
            $table->string('provider', 32);
            $table->string('model', 80);
            $table->uuid('base_revision_id');
            $table->string('status', 24);
            $table->unsignedTinyInteger('repair_attempt')->default(0);
            $table->timestamps();
            $table->unique('intent_id');
            $table->index(['staff_id', 'status']);
        });
        Schema::create('ai_drafts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('run_id')->constrained('ai_runs')->restrictOnDelete();
            $table->json('payload')->nullable();
            $table->string('status', 24);
            $table->timestamps();
            $table->unique('run_id');
        });
        $now = now();
        DB::table('ai_settings')->insert([
            'id' => 1,
            'enabled' => false,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini-2024-07-18',
            'secret_payload' => null,
            'secret_hint' => null,
            'daily_token_budget' => 0,
            'monthly_token_budget' => 0,
            'revision' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::statement("ALTER TABLE ai_settings ADD CONSTRAINT ai_settings_provider_check CHECK (provider = 'openai')");
        DB::statement("ALTER TABLE ai_usage_operations ADD CONSTRAINT ai_usage_operations_action_check CHECK (action IN ('reserve', 'record', 'release'))");
        DB::statement("ALTER TABLE ai_usage_reservations ADD CONSTRAINT ai_usage_reservations_status_check CHECK (status IN ('reserved', 'committed', 'released', 'pending_unknown'))");
        DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_status_check CHECK (status IN ('queued', 'running', 'completed', 'failed', 'cancelled', 'unknown'))");
        DB::statement("ALTER TABLE ai_drafts ADD CONSTRAINT ai_drafts_status_check CHECK (status IN ('candidate', 'applied', 'discarded'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_drafts');
        Schema::dropIfExists('ai_runs');
        Schema::dropIfExists('ai_usage_entries');
        Schema::dropIfExists('ai_usage_reservations');
        Schema::dropIfExists('ai_usage_operations');
        Schema::dropIfExists('ai_authors');
        Schema::dropIfExists('ai_settings');
    }
};
