<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_challenge_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('challenge_id')->unique()->constrained('customer_challenges')->cascadeOnDelete();
            $table->uuid('customer_id')->index();
            $table->text('payload')->nullable();
            $table->enum('status', ['pending', 'sending', 'delivered', 'cancelled', 'failed'])->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->uuid('claim_id')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->timestamp('queued_until')->nullable();
            $table->timestamp('available_at');
            $table->timestamp('expires_at')->index();
            $table->timestamp('delivered_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestamps();
            $table->index(['status', 'available_at', 'queued_until'], 'customer_mail_due_index');
            $table->index(['status', 'lease_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_challenge_deliveries');
    }
};
