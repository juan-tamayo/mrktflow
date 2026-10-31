<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE order_events DROP CHECK order_events_type_check');
        DB::statement("ALTER TABLE order_events ADD CONSTRAINT order_events_type_check CHECK (type IN ('paid', 'cancelled', 'receipt_queued', 'receipt_resent', 'external_refund', 'dispute_opened', 'dispute_won', 'dispute_lost', 'shipment_created', 'shipment_notification_queued', 'return_requested', 'return_approved', 'return_received', 'return_restocked', 'entitlements_issued', 'download_access_requested', 'download_started', 'entitlements_revoked', 'entitlements_suspended', 'entitlements_restored'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE order_events DROP CHECK order_events_type_check');
        DB::statement("ALTER TABLE order_events ADD CONSTRAINT order_events_type_check CHECK (type IN ('paid', 'cancelled', 'receipt_queued', 'receipt_resent', 'external_refund', 'dispute_opened', 'dispute_won', 'dispute_lost', 'shipment_created', 'shipment_notification_queued', 'return_requested', 'return_approved', 'return_received', 'return_restocked', 'entitlements_issued', 'download_access_requested', 'download_started'))");
    }
};
