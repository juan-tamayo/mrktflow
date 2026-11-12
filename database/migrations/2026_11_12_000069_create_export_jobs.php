<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('staff_id')->constrained('staff_accounts')->restrictOnDelete();
            $table->string('dataset', 16);
            $table->string('status', 16);
            $table->string('storage_key', 191)->nullable();
            $table->char('token_digest', 64);
            $table->unsignedInteger('row_count')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamps();
            $table->unique('token_digest');
            $table->index(['staff_id', 'created_at']);
            $table->index(['status', 'expires_at']);
        });
        DB::statement("ALTER TABLE export_jobs ADD CONSTRAINT export_jobs_dataset_check CHECK (dataset IN ('customers', 'orders', 'products'))");
        DB::statement("ALTER TABLE export_jobs ADD CONSTRAINT export_jobs_status_check CHECK (status IN ('pending', 'ready', 'failed', 'expired'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('export_jobs');
    }
};
