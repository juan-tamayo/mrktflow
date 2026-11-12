<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Operations\Support\ExportCsv;
use App\Domain\Platform\Support\MediaStoragePolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use stdClass;

final class BuildStoreExport
{
    public function build(string $exportId): void
    {
        if (! Str::isUuid($exportId) || $exportId !== strtolower($exportId)) {
            throw new InvalidArgumentException('Unknown export.');
        }
        app(MediaStoragePolicy::class)->assertPrivate();
        DB::transaction(function () use ($exportId): void {
            $job = DB::table('export_jobs')->where('id', $exportId)->lockForUpdate()->first();
            if ($job === null || (string) $job->status !== 'pending') {
                return;
            }
            if (now()->gte($job->expires_at)) {
                DB::table('export_jobs')->where('id', $exportId)->update([
                    'status' => 'expired',
                    'updated_at' => now(),
                ]);

                return;
            }
            $dataset = (string) $job->dataset;
            [$csv, $rows] = $this->csv($dataset);
            $key = 'exports/'.$exportId.'.csv';
            if (! Storage::disk('local')->put($key, $csv)) {
                throw new RuntimeException('Private export storage failed.');
            }
            DB::table('export_jobs')->where('id', $exportId)->update([
                'status' => 'ready',
                'storage_key' => $key,
                'row_count' => $rows,
                'updated_at' => now(),
            ]);
        });
    }

    public function fail(string $exportId): void
    {
        if (! Str::isUuid($exportId)) {
            return;
        }
        DB::table('export_jobs')->where('id', $exportId)->where('status', 'pending')->update([
            'status' => 'failed',
            'updated_at' => now(),
        ]);
    }

    /** @return array{0: string, 1: int} */
    private function csv(string $dataset): array
    {
        $headers = match ($dataset) {
            'customers' => ['id', 'name', 'email', 'is_active', 'email_verified_at', 'created_at'],
            'orders' => ['number', 'email', 'status', 'financial_status', 'fulfillment_status', 'grand_total_amount', 'currency', 'created_at'],
            'products' => ['sku', 'title', 'slug', 'description', 'status', 'type', 'price_amount'],
            default => throw new InvalidArgumentException('Unknown export dataset.'),
        };
        $lines = [ExportCsv::line($headers)];
        $rows = $this->records($dataset);
        foreach ($rows as $row) {
            $cells = [];
            foreach ($headers as $header) {
                $cells[] = $this->cell($row, $header);
            }
            $lines[] = ExportCsv::line($cells);
        }

        return [implode("\n", $lines)."\n", count($rows)];
    }

    /** @return list<stdClass> */
    private function records(string $dataset): array
    {
        $query = match ($dataset) {
            'customers' => DB::table('customer_accounts')->orderBy('created_at')->orderBy('id')
                ->select(['id', 'name', 'email', 'is_active', 'email_verified_at', 'created_at']),
            'orders' => DB::table('orders')->orderBy('created_at')->orderBy('id')
                ->select(['number', 'email', 'status', 'financial_status', 'fulfillment_status', 'grand_total_amount', 'currency', 'created_at']),
            default => DB::table('product_variants')
                ->join('products', 'product_variants.product_id', '=', 'products.id')
                ->orderBy('product_variants.sku')
                ->orderBy('products.id')
                ->select([
                    'product_variants.sku',
                    'products.title',
                    'products.slug',
                    'products.description',
                    'products.status',
                    'products.type',
                    'product_variants.price_amount',
                ]),
        };

        $records = [];
        foreach ($query->get() as $row) {
            $records[] = $row;
        }

        return $records;
    }

    private function cell(stdClass $row, string $header): string
    {
        $values = get_object_vars($row);
        $value = $values[$header] ?? null;
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if ($value === null) {
            return '';
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
