<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Actions;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ReadCoupons
{
    /** @return list<array{id: string, code: string, name: string, status: string, mode: string, revision: int}> */
    public function staffCoupons(): array
    {
        $rows = [];
        foreach (DB::table('coupons')->orderByDesc('updated_at')->orderBy('id')->get() as $coupon) {
            $rows[] = [
                'id' => $this->string($coupon, 'id'),
                'code' => $this->string($coupon, 'code_normalized'),
                'name' => $this->string($coupon, 'name'),
                'status' => $this->string($coupon, 'status'),
                'mode' => $this->string($coupon, 'mode'),
                'revision' => $this->int($coupon, 'revision'),
            ];
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    public function staffCoupon(string $id): array
    {
        $coupon = DB::table('coupons')->where('id', $id)->first();
        if ($coupon === null) {
            throw new NotFoundHttpException;
        }
        $productIds = [];
        $categoryIds = [];
        foreach (DB::table('coupon_scopes')->where('coupon_id', $id)->orderBy('target_id')->get() as $scope) {
            if ($this->string($scope, 'scope_type') === 'product') {
                $productIds[] = $this->string($scope, 'target_id');
            } else {
                $categoryIds[] = $this->string($scope, 'target_id');
            }
        }

        return [
            'id' => $this->string($coupon, 'id'),
            'code' => $this->string($coupon, 'code_normalized'),
            'name' => $this->string($coupon, 'name'),
            'mode' => $this->string($coupon, 'mode'),
            'amount' => $this->nullableInt($coupon, 'amount'),
            'percent_numerator' => $this->nullableInt($coupon, 'percent_numerator'),
            'percent_denominator' => $this->nullableInt($coupon, 'percent_denominator'),
            'min_eligible_subtotal' => $this->int($coupon, 'min_eligible_subtotal'),
            'starts_at' => $this->nullableTime($coupon, 'starts_at'),
            'ends_at' => $this->nullableTime($coupon, 'ends_at'),
            'max_redemptions' => $this->nullableInt($coupon, 'max_redemptions'),
            'max_redemptions_per_customer' => $this->nullableInt($coupon, 'max_redemptions_per_customer'),
            'held_count' => $this->int($coupon, 'held_count'),
            'redeemed_count' => $this->int($coupon, 'redeemed_count'),
            'status' => $this->string($coupon, 'status'),
            'version' => $this->int($coupon, 'revision'),
            'product_ids' => $productIds,
            'category_ids' => $categoryIds,
        ];
    }

    /** @return array{products: list<array{id: string, title: string}>, categories: list<array{id: string, title: string, path: string}>} */
    public function catalogOptions(): array
    {
        $products = [];
        foreach (DB::table('products')->where('status', '!=', 'archived')->orderBy('title')->orderBy('id')->limit(200)->get() as $product) {
            $products[] = ['id' => $this->string($product, 'id'), 'title' => $this->string($product, 'title')];
        }
        $categories = [];
        foreach (DB::table('categories')->orderBy('path')->limit(200)->get() as $category) {
            $categories[] = [
                'id' => $this->string($category, 'id'),
                'title' => $this->string($category, 'title'),
                'path' => $this->string($category, 'path'),
            ];
        }

        return ['products' => $products, 'categories' => $categories];
    }

    private function string(object $row, string $key): string
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    private function int(object $row, string $key): int
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function nullableInt(object $row, string $key): ?int
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    private function nullableTime(object $row, string $key): ?string
    {
        $value = $this->string($row, $key);

        return $value === '' ? null : str_replace(' ', 'T', $value).'Z';
    }
}
