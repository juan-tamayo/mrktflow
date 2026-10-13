<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_schemas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('semantic_key', 40);
            $table->string('label', 80);
            $table->string('comparison_group', 40);
            $table->string('default_template_key', 80);
            $table->uuid('current_version_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique('semantic_key');
        });
        Schema::create('product_schema_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_schema_id')->constrained('product_schemas')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('comparison_group', 40);
            $table->string('default_template_key', 80);
            $table->timestamps();
            $table->unique(['product_schema_id', 'version']);
        });
        Schema::table('product_schemas', function (Blueprint $table): void {
            $table->foreign('current_version_id')->references('id')->on('product_schema_versions')->restrictOnDelete();
        });
        Schema::create('attribute_definitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('semantic_key', 80);
            $table->string('type', 16);
            $table->string('unit', 16)->nullable();
            $table->boolean('comparable');
            $table->boolean('filterable');
            $table->timestamps();
            $table->unique('semantic_key');
        });
        Schema::create('attribute_enum_options', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('attribute_definition_id')->constrained('attribute_definitions')->restrictOnDelete();
            $table->string('option_value', 40);
            $table->unsignedTinyInteger('sort_order');
            $table->timestamps();
            $table->unique(['attribute_definition_id', 'option_value'], 'attr_enum_def_value_unique');
            $table->unique(['attribute_definition_id', 'sort_order'], 'attr_enum_def_sort_unique');
        });
        Schema::create('product_schema_version_fields', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('schema_version_id')->constrained('product_schema_versions')->restrictOnDelete();
            $table->foreignUuid('attribute_definition_id')->constrained('attribute_definitions')->restrictOnDelete();
            $table->string('label', 120);
            $table->boolean('required');
            $table->unsignedTinyInteger('sort_order');
            $table->timestamps();
            $table->unique(['schema_version_id', 'attribute_definition_id'], 'schema_ver_attr_unique');
            $table->unique(['schema_version_id', 'sort_order'], 'schema_ver_sort_unique');
        });
        Schema::create('attribute_values', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('attribute_definition_id')->constrained('attribute_definitions')->restrictOnDelete();
            $table->string('string_value', 500)->nullable();
            $table->bigInteger('integer_value')->nullable();
            $table->decimal('decimal_value', 18, 6)->nullable();
            $table->boolean('boolean_value')->nullable();
            $table->date('date_value')->nullable();
            $table->string('enum_value', 40)->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'attribute_definition_id'], 'attr_value_product_def_unique');
        });
        Schema::table('products', function (Blueprint $table): void {
            $table->foreignUuid('schema_version_id')->nullable()->after('status')->constrained('product_schema_versions')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE attribute_definitions ADD CONSTRAINT attribute_definitions_type_check CHECK (type IN ('string', 'integer', 'decimal', 'boolean', 'date', 'enum'))");
        DB::statement('ALTER TABLE attribute_values ADD CONSTRAINT attribute_values_one_slot_check CHECK ((string_value IS NOT NULL) + (integer_value IS NOT NULL) + (decimal_value IS NOT NULL) + (boolean_value IS NOT NULL) + (date_value IS NOT NULL) + (enum_value IS NOT NULL) = 1)');
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('schema_version_id');
        });
        Schema::dropIfExists('attribute_values');
        Schema::dropIfExists('product_schema_version_fields');
        Schema::dropIfExists('attribute_enum_options');
        Schema::dropIfExists('attribute_definitions');
        Schema::table('product_schemas', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('current_version_id');
        });
        Schema::dropIfExists('product_schema_versions');
        Schema::dropIfExists('product_schemas');
    }
};
