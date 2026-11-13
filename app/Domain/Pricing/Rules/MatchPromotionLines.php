<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Rules;

use App\Domain\Pricing\Data\CouponBasketLine;
use App\Domain\Pricing\Data\Money;
use App\Domain\Pricing\Exceptions\PricingInvalidQuote;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final class MatchPromotionLines
{
    /**
     * @param  list<CouponBasketLine>  $lines
     * @param  list<string>  $productScopes
     * @param  list<string>  $categoryScopes
     * @return array{lines: list<CouponBasketLine>, total: int}
     */
    public function eligible(array $lines, DateTimeImmutable $quotedAt, array $productScopes, array $categoryScopes, bool $excludeSalePriced): array
    {
        $paths = $this->categoryPaths($lines, $categoryScopes);
        $matched = [];
        $total = 0;
        foreach ($lines as $line) {
            if ($excludeSalePriced && $line->line->unitPriceAt($quotedAt)->amount !== $line->line->regular->amount) {
                continue;
            }
            if (! $this->lineEligible($line, $productScopes, $categoryScopes, $paths)) {
                continue;
            }
            $subtotal = $line->line->unitPriceAt($quotedAt)->times($line->line->quantity);
            if ($total > Money::MAX_SAFE - $subtotal->amount) {
                throw new PricingInvalidQuote;
            }
            $matched[] = $line;
            $total += $subtotal->amount;
        }

        return ['lines' => $matched, 'total' => $total];
    }

    /**
     * @param  list<CouponBasketLine>  $lines
     * @param  list<string>  $categoryScopes
     * @return array<string, string>
     */
    public function categoryPaths(array $lines, array $categoryScopes): array
    {
        $ids = $categoryScopes;
        foreach ($lines as $line) {
            foreach ($line->categoryIds as $categoryId) {
                $ids[] = $categoryId;
            }
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }
        $paths = [];
        foreach (DB::table('categories')->whereIn('id', $ids)->get() as $row) {
            $id = is_scalar(get_object_vars($row)['id'] ?? null) ? (string) get_object_vars($row)['id'] : '';
            $path = is_scalar(get_object_vars($row)['path'] ?? null) ? (string) get_object_vars($row)['path'] : '';
            if ($id !== '') {
                $paths[$id] = $path;
            }
        }

        return $paths;
    }

    /**
     * @param  list<string>  $productScopes
     * @param  list<string>  $categoryScopes
     * @param  array<string, string>  $categoryPaths
     */
    public function lineEligible(CouponBasketLine $line, array $productScopes, array $categoryScopes, array $categoryPaths): bool
    {
        if ($productScopes === [] && $categoryScopes === []) {
            return true;
        }
        if (in_array($line->productId, $productScopes, true)) {
            return true;
        }
        foreach ($categoryScopes as $scopeId) {
            $scopePath = $categoryPaths[$scopeId] ?? null;
            if ($scopePath === null) {
                continue;
            }
            foreach ($line->categoryIds as $categoryId) {
                $path = $categoryPaths[$categoryId] ?? null;
                if ($path === $scopePath || ($path !== null && str_starts_with($path, $scopePath.'/'))) {
                    return true;
                }
            }
        }

        return false;
    }
}
