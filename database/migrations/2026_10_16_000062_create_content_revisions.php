<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('subject_type', 32);
            $table->string('subject_id', 160);
            $table->unsignedInteger('revision');
            $table->longText('payload');
            $table->uuid('created_by')->nullable();
            $table->timestamp('created_at');
            $table->unique(['subject_type', 'subject_id', 'revision']);
            $table->index(['subject_type', 'subject_id', 'revision']);
        });
        DB::statement("ALTER TABLE content_revisions ADD CONSTRAINT content_revisions_subject_type_check CHECK (subject_type IN ('page', 'article', 'section_draft'))");
        Schema::table('pages', function (Blueprint $table): void {
            $table->unsignedInteger('draft_revision')->default(1);
            $table->unsignedInteger('published_revision')->nullable();
        });
        Schema::table('articles', function (Blueprint $table): void {
            $table->unsignedInteger('draft_revision')->default(1);
            $table->unsignedInteger('published_revision')->nullable();
        });
        Schema::table('content_drafts', function (Blueprint $table): void {
            $table->unsignedInteger('published_revision')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('content_drafts', function (Blueprint $table): void {
            $table->dropColumn('published_revision');
        });
        Schema::table('articles', function (Blueprint $table): void {
            $table->dropColumn(['draft_revision', 'published_revision']);
        });
        Schema::table('pages', function (Blueprint $table): void {
            $table->dropColumn(['draft_revision', 'published_revision']);
        });
        Schema::dropIfExists('content_revisions');
    }
};
