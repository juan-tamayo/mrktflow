<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignUuid('attempt_id')->constrained('payment_attempts')->restrictOnDelete();
            $table->string('provider', 16);
            $table->string('provider_capture_id', 191);
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->unsignedTinyInteger('exponent');
            $table->timestamp('captured_at');
            $table->unique('order_id');
            $table->unique('attempt_id');
            $table->unique('provider_capture_id');
        });
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_provider_check CHECK (provider IN ('stripe'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
