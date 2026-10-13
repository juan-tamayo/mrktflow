<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_balances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->unsignedInteger('on_hand');
            $table->unsignedInteger('reserved')->default(0);
            $table->unsignedInteger('low_stock_threshold')->nullable();
            $table->timestamps();
            $table->unique('variant_id');
        });
        Schema::create('inventory_operations', function (Blueprint $table): void {
            $table->uuid('operation_key')->primary();
            $table->uuid('intent_id');
            $table->string('action', 16);
            $table->char('payload_hash', 64);
            $table->timestamp('created_at');
            $table->index('intent_id');
        });
        Schema::create('stock_reservations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('intent_id');
            $table->foreignUuid('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->unsignedInteger('original_qty');
            $table->unsignedInteger('shipped_qty')->default(0);
            $table->unsignedInteger('released_qty')->default(0);
            $table->unsignedInteger('remaining_qty');
            $table->string('status', 16);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['intent_id', 'variant_id']);
            $table->index(['status', 'expires_at']);
            $table->index('intent_id');
        });
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source_ref', 191);
            $table->foreignUuid('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignUuid('reservation_id')->nullable()->constrained('stock_reservations')->restrictOnDelete();
            $table->integer('on_hand_delta');
            $table->integer('reserved_delta');
            $table->string('reason', 16);
            $table->timestamp('created_at');
            $table->unique('source_ref');
        });
        DB::statement('ALTER TABLE inventory_balances ADD CONSTRAINT inventory_balances_reserved_check CHECK (reserved <= on_hand)');
        DB::statement("ALTER TABLE inventory_operations ADD CONSTRAINT inventory_operations_action_check CHECK (action IN ('hold', 'commit', 'release', 'ship', 'adjust'))");
        DB::statement("ALTER TABLE stock_reservations ADD CONSTRAINT stock_reservations_status_check CHECK (status IN ('active', 'committed', 'released', 'closed'))");
        DB::statement('ALTER TABLE stock_reservations ADD CONSTRAINT stock_reservations_qty_check CHECK (original_qty = shipped_qty + released_qty + remaining_qty)');
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_reason_check CHECK (reason IN ('hold', 'commit', 'release', 'ship', 'adjust'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('inventory_operations');
        Schema::dropIfExists('inventory_balances');
    }
};
