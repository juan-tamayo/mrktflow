<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_accounts', function (Blueprint $table): void {
            $table->unsignedInteger('profile_version')->default(1);
        });
        Schema::create('customer_addresses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('customer_id')->constrained('customer_accounts')->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->text('payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_addresses');
        Schema::table('customer_accounts', function (Blueprint $table): void {
            $table->dropColumn('profile_version');
        });
    }
};
