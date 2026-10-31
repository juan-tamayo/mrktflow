<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entitlements', function (Blueprint $table): void {
            $table->unsignedSmallInteger('download_limit')->default(5);
            $table->unsignedSmallInteger('downloads_used')->default(0);
        });
        DB::statement('ALTER TABLE entitlements ADD CONSTRAINT entitlements_download_limit_check CHECK (download_limit BETWEEN 1 AND 1000)');
        DB::statement('ALTER TABLE entitlements ADD CONSTRAINT entitlements_downloads_used_check CHECK (downloads_used <= download_limit)');

        Schema::create('download_grants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('entitlement_id')->constrained('entitlements')->restrictOnDelete();
            $table->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained('customer_accounts')->restrictOnDelete();
            $table->char('token_digest', 64)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->uuid('transfer_id')->nullable();
            $table->timestamps();
            $table->unique('token_digest');
            $table->index(['entitlement_id', 'created_at']);
        });
        Schema::create('download_transfers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('grant_id')->constrained('download_grants')->restrictOnDelete();
            $table->foreignUuid('entitlement_id')->constrained('entitlements')->restrictOnDelete();
            $table->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained('customer_accounts')->restrictOnDelete();
            $table->foreignUuid('digital_asset_version_id')->constrained('digital_asset_versions')->restrictOnDelete();
            $table->char('session_binding', 64);
            $table->string('status', 16)->default('active');
            $table->timestamp('expires_at');
            $table->char('stream_lease_owner', 64)->nullable();
            $table->timestamp('stream_lease_expires_at')->nullable();
            $table->timestamps();
            $table->unique('grant_id');
            $table->index(['entitlement_id', 'status']);
        });
        DB::statement("ALTER TABLE download_transfers ADD CONSTRAINT download_transfers_status_check CHECK (status IN ('active', 'revoked', 'expired'))");
        Schema::table('download_grants', function (Blueprint $table): void {
            $table->foreign('transfer_id')->references('id')->on('download_transfers')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE order_events DROP CHECK order_events_type_check');
        DB::statement("ALTER TABLE order_events ADD CONSTRAINT order_events_type_check CHECK (type IN ('paid', 'cancelled', 'receipt_queued', 'receipt_resent', 'external_refund', 'dispute_opened', 'dispute_won', 'dispute_lost', 'shipment_created', 'shipment_notification_queued', 'return_requested', 'return_approved', 'return_received', 'return_restocked', 'entitlements_issued', 'download_access_requested', 'download_started'))");
    }

    public function down(): void
    {
        Schema::table('download_grants', function (Blueprint $table): void {
            $table->dropForeign(['transfer_id']);
        });
        Schema::dropIfExists('download_transfers');
        Schema::dropIfExists('download_grants');
        DB::statement('ALTER TABLE entitlements DROP CHECK entitlements_download_limit_check');
        DB::statement('ALTER TABLE entitlements DROP CHECK entitlements_downloads_used_check');
        Schema::table('entitlements', function (Blueprint $table): void {
            $table->dropColumn(['download_limit', 'downloads_used']);
        });
        DB::statement('ALTER TABLE order_events DROP CHECK order_events_type_check');
        DB::statement("ALTER TABLE order_events ADD CONSTRAINT order_events_type_check CHECK (type IN ('paid', 'cancelled', 'receipt_queued', 'receipt_resent', 'external_refund', 'dispute_opened', 'dispute_won', 'dispute_lost', 'shipment_created', 'shipment_notification_queued', 'return_requested', 'return_approved', 'return_received', 'return_restocked', 'entitlements_issued', 'download_access_requested'))");
    }
};
