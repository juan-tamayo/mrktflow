<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_contacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('email', 254)->collation('utf8mb4_0900_bin')->unique();
            $table->foreignUuid('customer_id')->nullable()->constrained('customer_accounts')->restrictOnDelete();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });
        Schema::create('purpose_grants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('contact_id')->constrained('delivery_contacts')->restrictOnDelete();
            $table->string('purpose', 32);
            $table->string('source', 64);
            $table->string('notice_revision', 32);
            $table->string('evidence', 64);
            $table->timestamp('granted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_source', 64)->nullable();
            $table->timestamps();
            $table->unique(['contact_id', 'purpose']);
        });
        Schema::create('delivery_suppressions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('contact_id')->constrained('delivery_contacts')->restrictOnDelete();
            $table->string('scope', 16);
            $table->string('reason', 32);
            $table->string('source', 64);
            $table->timestamp('cleared_at')->nullable();
            $table->string('cleared_source', 64)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['contact_id', 'scope']);
        });
        DB::statement("ALTER TABLE purpose_grants ADD CONSTRAINT purpose_grants_purpose_check CHECK (purpose IN ('cart_recovery', 'newsletter', 'stock_alert', 'price_alert'))");
        DB::statement("ALTER TABLE delivery_suppressions ADD CONSTRAINT delivery_suppressions_scope_check CHECK (scope IN ('global', 'email', 'sms'))");
        DB::statement("ALTER TABLE delivery_suppressions ADD CONSTRAINT delivery_suppressions_reason_check CHECK (reason IN ('opt_out', 'complaint', 'hard_bounce', 'manual'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_suppressions');
        Schema::dropIfExists('purpose_grants');
        Schema::dropIfExists('delivery_contacts');
    }
};
