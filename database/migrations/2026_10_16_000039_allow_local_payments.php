<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE payments DROP CHECK payments_provider_check');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_provider_check CHECK (provider IN ('stripe', 'local'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payments DROP CHECK payments_provider_check');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_provider_check CHECK (provider IN ('stripe'))");
    }
};
