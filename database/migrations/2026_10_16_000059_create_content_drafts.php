<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_drafts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('page_key', 64);
            $table->string('slot', 64);
            $table->longText('sections');
            $table->unsignedInteger('version');
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['page_key', 'slot']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_drafts');
    }
};
