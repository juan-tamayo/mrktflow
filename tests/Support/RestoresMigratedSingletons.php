<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;

trait RestoresMigratedSingletons
{
    use DatabaseTruncation;

    protected function afterTruncatingDatabase(): void
    {
        $now = now();

        DB::table('installations')->insert([
            'id' => 1,
            'status' => 'unconfigured',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('staff_security')->insert(['id' => 1]);
        DB::table('shipping_classes')->insert([
            'class_key' => 'general',
            'name' => 'General',
            'is_builtin' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('tax_classes')->insert([
            'class_key' => 'standard',
            'name' => 'Standard',
            'is_builtin' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
