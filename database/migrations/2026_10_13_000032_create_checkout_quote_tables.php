<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_quotes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('cart_id')->constrained('carts')->restrictOnDelete();
            $table->unsignedInteger('cart_revision');
            $table->foreignUuid('customer_id')->nullable()->constrained('customer_accounts')->restrictOnDelete();
            $table->char('guest_token_hash', 64)->nullable();
            $table->char('fingerprint', 64);
            $table->timestamp('quoted_at');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->text('payload');
            $table->timestamps();
            $table->index(['cart_id', 'cart_revision']);
            $table->index(['expires_at', 'superseded_at']);
        });
        DB::statement('ALTER TABLE checkout_quotes ADD CONSTRAINT checkout_quotes_owner_check CHECK ((customer_id IS NULL) <> (guest_token_hash IS NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_quotes');
    }
};
