<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('layout_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('subject_type', 16);
            $table->uuid('subject_id');
            $table->string('template_key', 80);
            $table->timestamps();
            $table->unique(['subject_type', 'subject_id']);
            $table->index('subject_id');
        });
        DB::statement("ALTER TABLE layout_assignments ADD CONSTRAINT layout_assignments_subject_type_check CHECK (subject_type IN ('product', 'category', 'page'))");
        DB::statement("ALTER TABLE layout_assignments ADD CONSTRAINT layout_assignments_template_key_check CHECK (template_key REGEXP '^[a-z]+\\.[a-z]+$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('layout_assignments');
    }
};
