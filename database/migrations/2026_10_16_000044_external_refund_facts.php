<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table): void {
            $table->dropForeign(['staff_id']);
        });
        DB::statement('ALTER TABLE refunds MODIFY staff_id CHAR(36) NULL');
        Schema::table('refunds', function (Blueprint $table): void {
            $table->foreign('staff_id')->references('id')->on('staff_accounts')->restrictOnDelete();
            $table->string('origin', 16)->default('staff');
        });
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_origin_check CHECK (origin IN ('staff', 'provider'))");
        DB::statement('ALTER TABLE refunds DROP CHECK refunds_kind_check');
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_kind_check CHECK (kind IN ('quantity', 'goodwill', 'cancellation', 'external'))");
        DB::statement('ALTER TABLE order_events DROP CHECK order_events_type_check');
        DB::statement("ALTER TABLE order_events ADD CONSTRAINT order_events_type_check CHECK (type IN ('paid', 'cancelled', 'receipt_queued', 'receipt_resent', 'external_refund'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE order_events DROP CHECK order_events_type_check');
        DB::statement("ALTER TABLE order_events ADD CONSTRAINT order_events_type_check CHECK (type IN ('paid', 'cancelled', 'receipt_queued', 'receipt_resent'))");
        DB::statement('ALTER TABLE refunds DROP CHECK refunds_kind_check');
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_kind_check CHECK (kind IN ('quantity', 'goodwill', 'cancellation'))");
        DB::statement('ALTER TABLE refunds DROP CHECK refunds_origin_check');
        Schema::table('refunds', function (Blueprint $table): void {
            $table->dropForeign(['staff_id']);
            $table->dropColumn('origin');
        });
        DB::statement('ALTER TABLE refunds MODIFY staff_id CHAR(36) NOT NULL');
        Schema::table('refunds', function (Blueprint $table): void {
            $table->foreign('staff_id')->references('id')->on('staff_accounts')->restrictOnDelete();
        });
    }
};
