<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Actions;

use App\Domain\Shipping\Contracts\ShippingQuoter;
use App\Domain\Shipping\Data\ShippingDestination;
use App\Domain\Shipping\Data\ShippingQuote;
use App\Domain\Shipping\Data\ShippingQuoteLine;
use App\Domain\Shipping\Data\ShippingQuoteRequest;
use App\Domain\Shipping\Exceptions\ShippingInvalidQuote;
use App\Domain\Shipping\Exceptions\ShippingMissingMeasurement;
use App\Domain\Shipping\Exceptions\ShippingNoService;
use App\Domain\Shipping\Rules\MatchDestination;
use App\Domain\Shipping\Support\StoreMoneySettings;
use Illuminate\Support\Facades\DB;
use JsonException;

final class QuoteShipping implements ShippingQuoter
{
    public function quote(ShippingQuoteRequest $request): ShippingQuote
    {
        $money = app(StoreMoneySettings::class)->current();
        $physical = array_values(array_filter($request->lines, fn (ShippingQuoteLine $line): bool => $line->isPhysical()));
        if ($physical === []) {
            return $this->digitalOnlyQuote($money);
        }
        $physicalSubtotal = 0;
        $classes = [];
        $weightGrams = 0;
        $missingWeight = false;
        foreach ($physical as $line) {
            if ($physicalSubtotal > StoreMoneySettings::MAX_SAFE - $line->discountedAmount) {
                throw new ShippingInvalidQuote;
            }
            $physicalSubtotal += $line->discountedAmount;
            $classes[$line->classKey] = true;
            if ($line->weightGrams === null) {
                $missingWeight = true;

                continue;
            }
            $lineWeight = $line->weightGrams * $line->quantity;
            if ($weightGrams > StoreMoneySettings::MAX_SAFE - $lineWeight) {
                throw new ShippingInvalidQuote;
            }
            $weightGrams += $lineWeight;
        }
        $classKeys = array_keys($classes);
        $known = DB::table('shipping_classes')->whereIn('class_key', $classKeys)->pluck('class_key')->all();
        if (count($known) !== count($classKeys)) {
            throw new ShippingNoService;
        }
        $origin = DB::table('shipping_origins')->where('id', 1)->first();
        if ($origin === null) {
            throw new ShippingNoService;
        }
        $revision = $this->int($origin, 'revision');
        $methods = $this->candidateMethods($request->destination);
        $eligible = [];
        $sawMissingMeasurement = false;
        foreach ($methods as $method) {
            if (! $this->coversClasses($method, $classKeys)) {
                continue;
            }
            $quoted = $this->quoteMethod($method, $physicalSubtotal, $missingWeight ? null : $weightGrams);
            if ($quoted['reason'] === 'missing_measurement') {
                $sawMissingMeasurement = true;
            }
            $eligible[] = $quoted;
        }
        $offered = array_values(array_filter($eligible, fn (array $method): bool => $method['offered']));
        if ($offered === []) {
            if ($sawMissingMeasurement) {
                throw new ShippingMissingMeasurement;
            }
            throw new ShippingNoService;
        }
        $chosen = $this->chosen($offered, $request->methodId);
        $charge = $chosen['amount'];
        $pickup = $chosen['pickup'];
        $taxDestination = null;
        if (is_array($pickup)) {
            $taxDestination = new ShippingDestination(
                $pickup['country_code'],
                $pickup['region_code'],
                $pickup['postal_code'],
            );
        } elseif ($request->destination !== null && $chosen['requiresDeliveryAddress']) {
            $taxDestination = $request->destination;
        }
        $quote = new ShippingQuote(
            false,
            $chosen['requiresDeliveryAddress'],
            $physicalSubtotal,
            $revision,
            $charge,
            $eligible,
            $chosen,
            $taxDestination,
            is_array($pickup) ? $pickup['instructions'] : null,
            '',
            $money['currency'],
            $money['exponent'],
        );

        return $this->withFingerprint($quote);
    }

    /**
     * @param  array{currency: string, exponent: int, countries: list<string>}  $money
     */
    private function digitalOnlyQuote(array $money): ShippingQuote
    {
        $origin = DB::table('shipping_origins')->where('id', 1)->first();
        $quote = new ShippingQuote(
            true,
            false,
            0,
            $origin === null ? 0 : $this->int($origin, 'revision'),
            0,
            [],
            null,
            null,
            null,
            '',
            $money['currency'],
            $money['exponent'],
        );

        return $this->withFingerprint($quote);
    }

    /**
     * @return list<object>
     */
    private function candidateMethods(?ShippingDestination $destination): array
    {
        if ($destination === null) {
            $methods = [];
            foreach (DB::table('shipping_methods')->where('type', 'pickup')->orderBy('sort_order')->orderBy('id')->get() as $method) {
                $zone = DB::table('shipping_zones')->where('id', $this->string($method, 'zone_id'))->first();
                if ($zone === null || $this->string($zone, 'status') !== 'active') {
                    continue;
                }
                $methods[] = $method;
            }

            return $methods;
        }
        $matcher = app(MatchDestination::class);
        $matched = [];
        foreach (DB::table('shipping_zones')->where('status', 'active')->orderBy('priority')->orderBy('id')->get() as $zone) {
            $zoneId = $this->string($zone, 'id');
            foreach (DB::table('shipping_zone_matches')->where('zone_id', $zoneId)->get() as $row) {
                $match = [
                    'country_code' => $this->string($row, 'country_code'),
                    'region_code' => $this->nullable($row, 'region_code'),
                    'postal_pattern' => $this->nullable($row, 'postal_pattern'),
                ];
                if ($matcher->matches($match, $destination)) {
                    $matched[] = $zone;
                    break;
                }
            }
        }
        if ($matched === []) {
            throw new ShippingNoService;
        }
        $winner = $matched[0];
        $winningPriority = $this->int($winner, 'priority');
        foreach (array_slice($matched, 1) as $other) {
            if ($this->int($other, 'priority') === $winningPriority) {
                throw new ShippingNoService;
            }
        }

        return array_values(DB::table('shipping_methods')->where('zone_id', $this->string($winner, 'id'))->orderBy('sort_order')->orderBy('id')->get()->all());
    }

    /**
     * @param  list<string>  $classKeys
     */
    private function coversClasses(object $method, array $classKeys): bool
    {
        $included = [];
        $excluded = [];
        foreach (DB::table('shipping_method_classes')->where('method_id', $this->string($method, 'id'))->get() as $row) {
            if ($this->string($row, 'mode') === 'include') {
                $included[] = $this->string($row, 'class_key');
            } else {
                $excluded[] = $this->string($row, 'class_key');
            }
        }
        foreach ($classKeys as $classKey) {
            if ($excluded !== [] && in_array($classKey, $excluded, true)) {
                return false;
            }
            if ($included !== [] && ! in_array($classKey, $included, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{
     *     id: string,
     *     label: string,
     *     type: string,
     *     amount: int|null,
     *     offered: bool,
     *     reason: string|null,
     *     requiresDeliveryAddress: bool,
     *     promisesStock: bool,
     *     rateRuleId: string|null,
     *     pickup: array{
     *         name: string,
     *         street: string,
     *         city: string,
     *         region_code: string|null,
     *         postal_code: string,
     *         country_code: string,
     *         instructions: string|null
     *     }|null
     * }
     */
    private function quoteMethod(object $method, int $physicalSubtotal, ?int $weightGrams): array
    {
        $type = $this->string($method, 'type');
        $base = [
            'id' => $this->string($method, 'id'),
            'label' => $this->string($method, 'label'),
            'type' => $type,
            'amount' => null,
            'offered' => false,
            'reason' => null,
            'requiresDeliveryAddress' => $type !== 'pickup',
            'promisesStock' => false,
            'rateRuleId' => null,
            'pickup' => $this->pickup($method),
        ];
        if ($type === 'flat') {
            return array_replace($base, ['amount' => $this->int($method, 'amount'), 'offered' => true]);
        }
        if ($type === 'free') {
            $offered = $physicalSubtotal >= $this->int($method, 'free_min_subtotal');

            return array_replace($base, [
                'amount' => $offered ? 0 : null,
                'offered' => $offered,
                'reason' => $offered ? null : 'below_threshold',
            ]);
        }
        if ($type === 'pickup') {
            return array_replace($base, ['amount' => 0, 'offered' => true]);
        }
        $basis = $this->string($method, 'table_basis');
        if ($basis === 'weight' && $weightGrams === null) {
            return array_replace($base, ['reason' => 'missing_measurement']);
        }
        $value = $basis === 'weight' ? (int) $weightGrams : $physicalSubtotal;
        foreach (DB::table('shipping_rate_rules')->where('method_id', $this->string($method, 'id'))->orderBy('sort_order')->orderBy('min_value')->get() as $row) {
            $min = $this->int($row, 'min_value');
            $max = $this->nullableInt($row, 'max_value');
            if ($value >= $min && ($max === null || $value < $max)) {
                return array_replace($base, [
                    'amount' => $this->int($row, 'amount'),
                    'offered' => true,
                    'rateRuleId' => $this->string($row, 'id'),
                ]);
            }
        }

        return array_replace($base, ['reason' => 'no_service']);
    }

    /**
     * @param  list<array{
     *     id: string,
     *     label: string,
     *     type: string,
     *     amount: int|null,
     *     offered: bool,
     *     reason: string|null,
     *     requiresDeliveryAddress: bool,
     *     promisesStock: bool,
     *     rateRuleId: string|null,
     *     pickup: array{
     *         name: string,
     *         street: string,
     *         city: string,
     *         region_code: string|null,
     *         postal_code: string,
     *         country_code: string,
     *         instructions: string|null
     *     }|null
     * }>  $offered
     * @return array{
     *     id: string,
     *     label: string,
     *     type: string,
     *     amount: int|null,
     *     offered: bool,
     *     reason: string|null,
     *     requiresDeliveryAddress: bool,
     *     promisesStock: bool,
     *     rateRuleId: string|null,
     *     pickup: array{
     *         name: string,
     *         street: string,
     *         city: string,
     *         region_code: string|null,
     *         postal_code: string,
     *         country_code: string,
     *         instructions: string|null
     *     }|null
     * }
     */
    private function chosen(array $offered, ?string $methodId): array
    {
        if ($methodId === null) {
            return $offered[0];
        }
        foreach ($offered as $method) {
            if ($method['id'] === $methodId) {
                return $method;
            }
        }
        throw new ShippingNoService;
    }

    /**
     * @return array{
     *     name: string,
     *     street: string,
     *     city: string,
     *     region_code: string|null,
     *     postal_code: string,
     *     country_code: string,
     *     instructions: string|null
     * }|null
     */
    private function pickup(object $method): ?array
    {
        $name = $this->nullable($method, 'pickup_name');
        if ($name === null) {
            return null;
        }

        return [
            'name' => $name,
            'street' => $this->string($method, 'pickup_street'),
            'city' => $this->string($method, 'pickup_city'),
            'region_code' => $this->nullable($method, 'pickup_region_code'),
            'postal_code' => $this->string($method, 'pickup_postal_code'),
            'country_code' => $this->string($method, 'pickup_country_code'),
            'instructions' => $this->nullable($method, 'pickup_instructions'),
        ];
    }

    private function withFingerprint(ShippingQuote $quote): ShippingQuote
    {
        try {
            $payload = json_encode([
                'digitalOnly' => $quote->digitalOnly,
                'physicalSubtotal' => $quote->physicalSubtotal,
                'rulesRevision' => $quote->rulesRevision,
                'charge' => $quote->charge,
                'currency' => $quote->currency,
                'exponent' => $quote->exponent,
                'methods' => array_map(fn (array $method): array => [
                    'id' => $method['id'],
                    'type' => $method['type'],
                    'amount' => $method['amount'],
                    'offered' => $method['offered'],
                    'reason' => $method['reason'],
                    'rateRuleId' => $method['rateRuleId'],
                ], $quote->eligible),
                'chosenId' => $quote->chosen['id'] ?? null,
                'tax' => $quote->taxDestination === null ? null : [
                    'country' => $quote->taxDestination->countryCode,
                    'region' => $quote->taxDestination->regionCode,
                    'postal' => $quote->taxDestination->postalCode,
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new ShippingInvalidQuote;
        }

        return new ShippingQuote(
            $quote->digitalOnly,
            $quote->requiresDeliveryAddress,
            $quote->physicalSubtotal,
            $quote->rulesRevision,
            $quote->charge,
            $quote->eligible,
            $quote->chosen,
            $quote->taxDestination,
            $quote->pickupInstructions,
            hash('sha256', $payload),
            $quote->currency,
            $quote->exponent,
        );
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
