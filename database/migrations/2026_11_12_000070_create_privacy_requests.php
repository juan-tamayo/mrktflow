<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacy_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('customer_id')->constrained('customer_accounts')->restrictOnDelete();
            $table->string('type', 16);
            $table->string('status', 16);
            $table->string('email', 254);
            $table->char('email_digest', 64);
            $table->timestamp('verified_at');
            $table->timestamp('processed_at')->nullable();
            $table->foreignUuid('processed_by_staff_id')->nullable()->constrained('staff_accounts')->restrictOnDelete();
            $table->string('storage_key', 191)->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->timestamp('export_expires_at')->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index(['status', 'export_expires_at']);
        });
        DB::statement("ALTER TABLE privacy_requests ADD CONSTRAINT privacy_requests_type_check CHECK (type IN ('export', 'deletion'))");
        DB::statement("ALTER TABLE privacy_requests ADD CONSTRAINT privacy_requests_status_check CHECK (status IN ('pending', 'completed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('privacy_requests');
    }
};
