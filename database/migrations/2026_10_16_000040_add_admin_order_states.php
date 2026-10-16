<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('financial_status', 16)->default('unpaid')->after('status');
            $table->string('fulfillment_status', 16)->default('unfulfilled')->after('financial_status');
            $table->string('download_status', 16)->default('not_required')->after('fulfillment_status');
            $table->index(['financial_status', 'created_at']);
            $table->index(['email', 'created_at']);
        });
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_financial_status_check CHECK (financial_status IN ('unpaid', 'paid', 'unknown'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_fulfillment_status_check CHECK (fulfillment_status IN ('unfulfilled', 'not_required'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_download_status_check CHECK (download_status IN ('locked', 'entitled', 'not_required'))");
        Schema::create('order_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignUuid('staff_id')->constrained('staff_accounts')->restrictOnDelete();
            $table->string('body', 2000);
            $table->timestamp('created_at');
            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_notes');
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['financial_status', 'created_at']);
            $table->dropIndex(['email', 'created_at']);
            $table->dropColumn(['financial_status', 'fulfillment_status', 'download_status']);
        });
    }
};
