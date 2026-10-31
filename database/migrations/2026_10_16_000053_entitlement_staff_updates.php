<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE order_events DROP CHECK order_events_type_check');
        DB::statement("ALTER TABLE order_events ADD CONSTRAINT order_events_type_check CHECK (type IN ('paid', 'cancelled', 'receipt_queued', 'receipt_resent', 'external_refund', 'dispute_opened', 'dispute_won', 'dispute_lost', 'shipment_created', 'shipment_notification_queued', 'return_requested', 'return_approved', 'return_received', 'return_restocked', 'entitlements_issued', 'download_access_requested', 'download_started', 'entitlements_revoked', 'entitlements_suspended', 'entitlements_restored', 'entitlement_update_granted', 'entitlement_limit_reset'))");
        DB::statement('ALTER TABLE idempotency_records DROP CHECK idempotency_records_operation_check');
        DB::statement("ALTER TABLE idempotency_records ADD CONSTRAINT idempotency_records_operation_check CHECK (operation IN ('checkout-session', 'order-refund', 'order-cancel', 'order-shipment', 'order-return', 'order-return-approve', 'order-return-receive', 'order-return-restock', 'entitlement-update', 'entitlement-limit-reset'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE order_events DROP CHECK order_events_type_check');
        DB::statement("ALTER TABLE order_events ADD CONSTRAINT order_events_type_check CHECK (type IN ('paid', 'cancelled', 'receipt_queued', 'receipt_resent', 'external_refund', 'dispute_opened', 'dispute_won', 'dispute_lost', 'shipment_created', 'shipment_notification_queued', 'return_requested', 'return_approved', 'return_received', 'return_restocked', 'entitlements_issued', 'download_access_requested', 'download_started', 'entitlements_revoked', 'entitlements_suspended', 'entitlements_restored'))");
        DB::statement('ALTER TABLE idempotency_records DROP CHECK idempotency_records_operation_check');
        DB::statement("ALTER TABLE idempotency_records ADD CONSTRAINT idempotency_records_operation_check CHECK (operation IN ('checkout-session', 'order-refund', 'order-cancel', 'order-shipment', 'order-return', 'order-return-approve', 'order-return-receive', 'order-return-restock'))");
    }
};
