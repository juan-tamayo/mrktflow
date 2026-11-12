<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->string('operation', 40)->default('draft_page');
            $table->uuid('subject_id')->nullable();
            $table->uuid('schema_id')->nullable();
            $table->unsignedInteger('expected_version')->nullable();
        });
        DB::statement('ALTER TABLE ai_runs MODIFY base_revision_id CHAR(36) NULL');
        DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_operation_check CHECK (operation IN ('draft_page', 'draft_product_copy', 'suggest_product_schema'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ai_runs DROP CHECK ai_runs_operation_check');
        DB::statement('ALTER TABLE ai_runs MODIFY base_revision_id CHAR(36) NOT NULL');
        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->dropColumn(['operation', 'subject_id', 'schema_id', 'expected_version']);
        });
    }
};
