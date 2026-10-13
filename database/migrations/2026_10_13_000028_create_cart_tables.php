<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('customer_id')->nullable()->constrained('customer_accounts')->restrictOnDelete();
            $table->char('guest_token_hash', 64)->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique('customer_id');
            $table->unique('guest_token_hash');
        });
        Schema::create('cart_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('cart_id')->constrained('carts')->cascadeOnDelete();
            $table->foreignUuid('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->unsignedTinyInteger('quantity');
            $table->timestamps();
            $table->unique(['cart_id', 'variant_id']);
        });
        DB::statement('ALTER TABLE carts ADD CONSTRAINT carts_owner_check CHECK ((customer_id IS NULL) <> (guest_token_hash IS NULL))');
        DB::statement('ALTER TABLE cart_lines ADD CONSTRAINT cart_lines_quantity_check CHECK (quantity >= 1 AND quantity <= 99)');
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_lines');
        Schema::dropIfExists('carts');
    }
};
