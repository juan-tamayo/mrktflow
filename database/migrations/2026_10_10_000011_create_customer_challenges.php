<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_challenges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('customer_id')->constrained('customer_accounts')->cascadeOnDelete();
            $table->enum('purpose', ['verify_email', 'reset_password']);
            $table->char('token_digest', 64)->collation('ascii_bin')->nullable()->unique();
            $table->char('email_digest', 64)->collation('ascii_bin');
            $table->unsignedInteger('session_version');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'purpose']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_challenges');
    }
};
