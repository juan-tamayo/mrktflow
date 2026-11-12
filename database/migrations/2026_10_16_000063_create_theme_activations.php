<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('theme_activations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('theme_id', 191)->collation('utf8mb4_0900_bin');
            $table->char('checksum', 64);
            $table->text('section_types')->nullable();
            $table->text('template_keys')->nullable();
            $table->uuid('activated_by');
            $table->timestamp('activated_at');
            $table->timestamp('replaced_at')->nullable();
            $table->index(['theme_id', 'activated_at']);
            $table->index('replaced_at');
        });
        DB::statement('ALTER TABLE theme_activations ADD CONSTRAINT theme_activations_theme_id_fk FOREIGN KEY (theme_id) REFERENCES installed_themes (theme_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('theme_activations');
    }
};
