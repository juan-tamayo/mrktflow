<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\RecordEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class ArchiveProduct
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{id: string, status: string, version: int}
     */
    public function archive(StaffAccount $actor, array $input): array
    {
        if (! $actor->hasPermission('catalog.write')) {
            throw new AuthorizationException;
        }
        $data = Validator::make(['product' => $input], [
            'product' => ['required', 'array:id,version'],
            'product.id' => ['required', 'uuid'],
            'product.version' => ['required', 'integer', 'min:1', 'max:4294967295'],
        ])->validate()['product'];

        return DB::transaction(function () use ($actor, $data): array {
            $existing = DB::table('products')->where('id', $data['id'])->lockForUpdate()->first();
            abort_unless($existing !== null, 404);
            if ($existing->status === 'archived') {
                return [
                    'id' => (string) $existing->id,
                    'status' => 'archived',
                    'version' => (int) $existing->version,
                ];
            }
            abort_unless((int) $existing->version === (int) $data['version'], 409, 'This product changed. Reload before archiving.');
            abort_if((int) $existing->version >= 4294967295, 409);
            $version = ((int) $existing->version) + 1;
            $now = now();
            DB::table('products')->where('id', $data['id'])->update([
                'status' => 'archived',
                'version' => $version,
                'archived_at' => $now,
                'updated_at' => $now,
            ]);
            app(RecordEvent::class)->record('platform.audit.recorded', 'catalog:product:'.$data['id'].':'.$version, [
                'action' => 'catalog.product.archived',
                'subject_type' => 'product',
                'subject_id' => $data['id'],
                'actor_type' => 'staff',
                'actor_id' => $actor->id,
                'metadata' => ['version' => $version],
            ]);

            return ['id' => $data['id'], 'status' => 'archived', 'version' => $version];
        });
    }
}
