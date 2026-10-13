<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('price_mode', 16);
            $table->string('shipping_tax_class_key', 32);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
        });
        Schema::create('tax_classes', function (Blueprint $table): void {
            $table->string('class_key', 32)->primary();
            $table->string('name', 80);
            $table->boolean('is_builtin')->default(false);
            $table->timestamps();
        });
        Schema::create('tax_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('class_key', 32);
            $table->string('name', 80);
            $table->char('country_code', 2);
            $table->string('region_code', 16)->nullable();
            $table->string('postal_pattern', 32)->nullable();
            $table->unsignedInteger('rate_numerator');
            $table->unsignedInteger('rate_denominator');
            $table->string('status', 16);
            $table->timestamps();
            $table->foreign('class_key')->references('class_key')->on('tax_classes')->restrictOnDelete();
            $table->index(['class_key', 'status']);
        });
        Schema::table('pricing_settings', function (Blueprint $table): void {
            $table->foreign('shipping_tax_class_key')->references('class_key')->on('tax_classes')->restrictOnDelete();
        });
        DB::table('tax_classes')->insert([
            'class_key' => 'standard',
            'name' => 'Standard',
            'is_builtin' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::statement("ALTER TABLE pricing_settings ADD CONSTRAINT pricing_settings_mode_check CHECK (price_mode IN ('exclusive', 'inclusive'))");
        DB::statement("ALTER TABLE tax_rules ADD CONSTRAINT tax_rules_status_check CHECK (status IN ('draft', 'active'))");
        DB::statement('ALTER TABLE tax_rules ADD CONSTRAINT tax_rules_rate_check CHECK (rate_numerator <= rate_denominator AND rate_denominator > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rules');
        Schema::dropIfExists('pricing_settings');
        Schema::dropIfExists('tax_classes');
    }
};
