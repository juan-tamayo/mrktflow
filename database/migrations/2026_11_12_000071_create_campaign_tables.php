<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_campaigns', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('status', 16);
            $table->unsignedInteger('revision')->default(1);
            $table->uuid('draft_version_id')->nullable();
            $table->uuid('activated_version_id')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'updated_at']);
        });
        Schema::create('campaign_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('campaign_id')->constrained('marketing_campaigns')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('subject', 180);
            $table->string('body_text', 8000);
            $table->string('purpose', 32);
            $table->json('audience_filters');
            $table->json('schedule');
            $table->json('caps');
            $table->uuid('offer_reference')->nullable();
            $table->foreignUuid('created_by')->constrained('staff_accounts')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->unique(['campaign_id', 'version']);
        });
        Schema::create('audience_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('campaign_id')->constrained('marketing_campaigns')->restrictOnDelete();
            $table->foreignUuid('campaign_version_id')->constrained('campaign_versions')->restrictOnDelete();
            $table->foreignUuid('contact_id')->constrained('delivery_contacts')->restrictOnDelete();
            $table->boolean('eligible');
            $table->string('exclusion_reason', 32)->nullable();
            $table->timestamp('created_at');
            $table->unique(['campaign_version_id', 'contact_id']);
            $table->index(['campaign_id', 'eligible']);
        });
        DB::statement("ALTER TABLE marketing_campaigns ADD CONSTRAINT marketing_campaigns_status_check CHECK (status IN ('draft', 'scheduled', 'paused'))");
        DB::statement("ALTER TABLE campaign_versions ADD CONSTRAINT campaign_versions_purpose_check CHECK (purpose IN ('newsletter'))");
        DB::statement("ALTER TABLE audience_snapshots ADD CONSTRAINT audience_snapshots_reason_check CHECK (exclusion_reason IS NULL OR exclusion_reason IN ('unverified', 'no_grant', 'suppressed', 'filter'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('audience_snapshots');
        Schema::dropIfExists('campaign_versions');
        Schema::dropIfExists('marketing_campaigns');
    }
};
