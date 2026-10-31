<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_disputes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignUuid('payment_id')->constrained('payments')->restrictOnDelete();
            $table->string('provider_dispute_id', 191);
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->unsignedTinyInteger('exponent');
            $table->string('status', 16);
            $table->string('reason', 64)->nullable();
            $table->boolean('held_order')->default(false);
            $table->timestamps();
            $table->unique('provider_dispute_id');
            $table->index(['order_id', 'status']);
        });
        DB::statement("ALTER TABLE order_disputes ADD CONSTRAINT order_disputes_status_check CHECK (status IN ('opened', 'won', 'lost'))");
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('dispute_status', 16)->default('none')->after('download_status');
        });
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_dispute_status_check CHECK (dispute_status IN ('none', 'open', 'won', 'lost'))");
        DB::statement('ALTER TABLE orders DROP CHECK orders_download_status_check');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_download_status_check CHECK (download_status IN ('locked', 'entitled', 'not_required', 'revoked', 'suspended'))");
        DB::statement('ALTER TABLE order_events DROP CHECK order_events_type_check');
        DB::statement("ALTER TABLE order_events ADD CONSTRAINT order_events_type_check CHECK (type IN ('paid', 'cancelled', 'receipt_queued', 'receipt_resent', 'external_refund', 'dispute_opened', 'dispute_won', 'dispute_lost'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE order_events DROP CHECK order_events_type_check');
        DB::statement("ALTER TABLE order_events ADD CONSTRAINT order_events_type_check CHECK (type IN ('paid', 'cancelled', 'receipt_queued', 'receipt_resent', 'external_refund'))");
        DB::statement('ALTER TABLE orders DROP CHECK orders_download_status_check');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_download_status_check CHECK (download_status IN ('locked', 'entitled', 'not_required', 'revoked'))");
        DB::statement('ALTER TABLE orders DROP CHECK orders_dispute_status_check');
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('dispute_status');
        });
        Schema::dropIfExists('order_disputes');
    }
};
