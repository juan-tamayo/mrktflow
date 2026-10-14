<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('PlatformDatabaseTruncation leaves migrated singletons after the first case dirtying them', function (): void {
    expect(DB::table('installations')->where('id', 1)->value('status'))->toBe('unconfigured');
    expect(DB::table('staff_security')->where('id', 1)->exists())->toBeTrue();
    expect(DB::table('shipping_classes')->where('class_key', 'general')->exists())->toBeTrue();
    expect(DB::table('tax_classes')->where('class_key', 'standard')->exists())->toBeTrue();

    DB::table('installations')->where('id', 1)->update(['status' => 'complete', 'completed_at' => now()]);
    DB::table('staff_security')->where('id', 1)->delete();
    DB::table('shipping_classes')->where('class_key', 'general')->delete();
    DB::table('tax_classes')->where('class_key', 'standard')->delete();
});

it('PlatformDatabaseTruncation restores installation, staff security, and builtin classes between cases', function (): void {
    expect(DB::table('installations')->where('id', 1)->value('status'))->toBe('unconfigured');
    expect(DB::table('installations')->where('id', 1)->value('completed_at'))->toBeNull();
    expect(DB::table('staff_security')->where('id', 1)->exists())->toBeTrue();
    expect(DB::table('shipping_classes')->where('class_key', 'general')->value('is_builtin'))->toBe(1);
    expect(DB::table('tax_classes')->where('class_key', 'standard')->value('is_builtin'))->toBe(1);
});
