<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wishlists', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('customer_id')->nullable()->constrained('customer_accounts')->restrictOnDelete();
            $table->char('guest_token_hash', 64)->nullable();
            $table->timestamps();
            $table->unique('customer_id');
            $table->unique('guest_token_hash');
        });
        Schema::create('wishlist_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('wishlist_id')->constrained('wishlists')->cascadeOnDelete();
            $table->foreignUuid('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->timestamp('saved_at');
            $table->timestamps();
            $table->unique(['wishlist_id', 'variant_id']);
            $table->index(['wishlist_id', 'saved_at']);
        });
        DB::statement('ALTER TABLE wishlists ADD CONSTRAINT wishlists_owner_check CHECK ((customer_id IS NULL) <> (guest_token_hash IS NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('wishlist_items');
        Schema::dropIfExists('wishlists');
    }
};
