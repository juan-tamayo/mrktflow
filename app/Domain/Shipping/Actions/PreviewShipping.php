<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Actions;

use App\Domain\Shipping\Data\ShippingDestination;
use App\Domain\Shipping\Data\ShippingPreview;
use App\Domain\Shipping\Rules\MatchDestination;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class PreviewShipping
{
    public function preview(ShippingDestination $destination, int $physicalSubtotal, ?int $weightGrams): ShippingPreview
    {
        if ($physicalSubtotal < 0 || $physicalSubtotal > 9007199254740991 || ($weightGrams !== null && ($weightGrams < 0 || $weightGrams > 9007199254740991))) {
            throw new HttpException(422, 'Preview amounts must be nonnegative integers.');
        }
        $matcher = app(MatchDestination::class);
        $matched = [];
        foreach (DB::table('shipping_zones')->where('status', 'active')->orderBy('priority')->orderBy('id')->get() as $zone) {
            $zoneId = $this->string($zone, 'id');
            $matches = [];
            foreach (DB::table('shipping_zone_matches')->where('zone_id', $zoneId)->get() as $row) {
                $matches[] = [
                    'country_code' => $this->string($row, 'country_code'),
                    'region_code' => $this->nullable($row, 'region_code'),
                    'postal_pattern' => $this->nullable($row, 'postal_pattern'),
                ];
            }
            foreach ($matches as $match) {
                if ($matcher->matches($match, $destination)) {
                    $matched[] = $zone;
                    break;
                }
            }
        }
        if ($matched === []) {
            throw new HttpException(422, 'No shipping zone covers that destination.');
        }
        $winner = $matched[0];
        $winningPriority = $this->int($winner, 'priority');
        foreach (array_slice($matched, 1) as $other) {
            if ($this->int($other, 'priority') === $winningPriority) {
                throw new HttpException(409, 'Two shipping zones with the same priority match this destination.');
            }
        }
        $methods = [];
        foreach (DB::table('shipping_methods')->where('zone_id', $this->string($winner, 'id'))->orderBy('sort_order')->orderBy('id')->get() as $method) {
            $methods[] = $this->quoteMethod($method, $physicalSubtotal, $weightGrams);
        }

        return new ShippingPreview(
            $this->string($winner, 'id'),
            $this->string($winner, 'name'),
            $winningPriority,
            $methods,
        );
    }

    /**
     * @return array{label: string, type: string, amount: int|null, offered: bool, reason: string|null}
     */
    private function quoteMethod(object $method, int $physicalSubtotal, ?int $weightGrams): array
    {
        $type = $this->string($method, 'type');
        $label = $this->string($method, 'label');
        if ($type === 'flat') {
            return ['label' => $label, 'type' => $type, 'amount' => $this->int($method, 'amount'), 'offered' => true, 'reason' => null];
        }
        if ($type === 'free') {
            $threshold = $this->int($method, 'free_min_subtotal');
            $offered = $physicalSubtotal >= $threshold;

            return ['label' => $label, 'type' => $type, 'amount' => $offered ? 0 : null, 'offered' => $offered, 'reason' => $offered ? null : 'below_threshold'];
        }
        if ($type === 'pickup') {
            return ['label' => $label, 'type' => $type, 'amount' => 0, 'offered' => true, 'reason' => null];
        }
        $basis = $this->string($method, 'table_basis');
        if ($basis === 'weight' && $weightGrams === null) {
            return ['label' => $label, 'type' => $type, 'amount' => null, 'offered' => false, 'reason' => 'missing_measurement'];
        }
        $value = $basis === 'weight' ? (int) $weightGrams : $physicalSubtotal;
        foreach (DB::table('shipping_rate_rules')->where('method_id', $this->string($method, 'id'))->orderBy('sort_order')->orderBy('min_value')->get() as $row) {
            $min = $this->int($row, 'min_value');
            $max = $this->nullableInt($row, 'max_value');
            if ($value >= $min && ($max === null || $value < $max)) {
                return ['label' => $label, 'type' => $type, 'amount' => $this->int($row, 'amount'), 'offered' => true, 'reason' => null];
            }
        }

        return ['label' => $label, 'type' => $type, 'amount' => null, 'offered' => false, 'reason' => 'no_service'];
    }

    private function string(object $row, string $key): string
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    private function nullable(object $row, string $key): ?string
    {
        $value = $this->string($row, $key);

        return $value === '' ? null : $value;
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
}
