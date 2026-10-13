<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('principal_scope', 80);
            $table->string('operation', 32);
            $table->uuid('idempotency_key');
            $table->char('payload_hash', 64);
            $table->string('status', 16);
            $table->uuid('resource_id')->nullable();
            $table->uuid('attempt_id')->nullable();
            $table->timestamps();
            $table->unique(['principal_scope', 'operation', 'idempotency_key'], 'idempotency_records_scope_unique');
        });
        Schema::create('orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('number', 24);
            $table->foreignUuid('cart_id')->constrained('carts')->restrictOnDelete();
            $table->unsignedInteger('cart_revision');
            $table->foreignUuid('quote_id')->constrained('checkout_quotes')->restrictOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained('customer_accounts')->restrictOnDelete();
            $table->char('guest_token_hash', 64)->nullable();
            $table->string('email', 254);
            $table->string('status', 16);
            $table->char('currency', 3);
            $table->unsignedTinyInteger('exponent');
            $table->unsignedBigInteger('merchandise_amount');
            $table->unsignedBigInteger('discount_amount');
            $table->unsignedBigInteger('shipping_amount');
            $table->unsignedBigInteger('tax_amount');
            $table->unsignedBigInteger('grand_total_amount');
            $table->string('price_mode', 16);
            $table->char('fingerprint', 64);
            $table->string('coupon_code', 32)->nullable();
            $table->uuid('coupon_id')->nullable();
            $table->unsignedInteger('coupon_revision')->nullable();
            $table->uuid('shipping_method_id')->nullable();
            $table->boolean('zero_total');
            $table->text('payload');
            $table->timestamps();
            $table->unique('number');
            $table->unique(['cart_id', 'cart_revision']);
            $table->index(['customer_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
        Schema::create('order_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();
            $table->uuid('cart_line_id');
            $table->foreignUuid('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->restrictOnDelete();
            $table->string('type', 16);
            $table->string('sku', 64);
            $table->string('title', 191);
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('subtotal_amount');
            $table->unsignedBigInteger('discount_amount');
            $table->unsignedBigInteger('total_amount');
            $table->string('tax_class_key', 64);
            $table->foreignUuid('digital_asset_version_id')->nullable()->constrained('digital_asset_versions')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->unique(['order_id', 'cart_line_id']);
        });
        Schema::create('order_addresses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('kind', 16);
            $table->string('recipient', 120);
            $table->string('line1', 200);
            $table->string('line2', 200)->nullable();
            $table->string('city', 120);
            $table->string('region', 120)->nullable();
            $table->string('postal_code', 32)->nullable();
            $table->char('country_code', 2);
            $table->timestamp('created_at');
            $table->unique(['order_id', 'kind']);
        });
        Schema::create('payment_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('status', 16);
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->unsignedTinyInteger('exponent');
            $table->string('provider', 16)->nullable();
            $table->string('provider_session_id', 191)->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index('order_id');
            $table->unique('provider_session_id');
        });
        DB::statement("ALTER TABLE idempotency_records ADD CONSTRAINT idempotency_records_status_check CHECK (status IN ('processing', 'completed'))");
        DB::statement("ALTER TABLE idempotency_records ADD CONSTRAINT idempotency_records_operation_check CHECK (operation IN ('checkout-session'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('pending', 'confirmed', 'cancelled', 'on_hold', 'completed'))");
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_owner_check CHECK ((customer_id IS NULL) <> (guest_token_hash IS NULL))');
        DB::statement("ALTER TABLE order_lines ADD CONSTRAINT order_lines_type_check CHECK (type IN ('physical', 'digital'))");
        DB::statement("ALTER TABLE order_addresses ADD CONSTRAINT order_addresses_kind_check CHECK (kind IN ('billing', 'shipping'))");
        DB::statement("ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_status_check CHECK (status IN ('created', 'awaiting_payment', 'unknown', 'failed', 'succeeded', 'expired'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('order_addresses');
        Schema::dropIfExists('order_lines');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('idempotency_records');
    }
};
