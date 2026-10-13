<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Support;

use App\Domain\Platform\Support\SetupChoices;
use Illuminate\Support\Facades\DB;
use NumberFormatter;
use RuntimeException;

final class StoreCurrency
{
    /** @return array{currency: string, exponent: int} */
    public function current(): array
    {
        $values = DB::table('store_settings')->where('id', 1)->value('values');
        $settings = is_string($values) ? json_decode($values, true) : null;
        $currency = is_array($settings) && is_string($settings['currency'] ?? null) ? $settings['currency'] : null;
        if ($currency === null || ! in_array($currency, array_column(app(SetupChoices::class)->currencies(), 'code'), true)) {
            throw new RuntimeException('Store currency is unavailable.');
        }
        $formatter = NumberFormatter::create('en', NumberFormatter::CURRENCY);
        if (! $formatter->setTextAttribute(NumberFormatter::CURRENCY_CODE, $currency)) {
            throw new RuntimeException('Currency exponent is unavailable.');
        }
        $digits = $formatter->getAttribute(NumberFormatter::FRACTION_DIGITS);
        if ($digits < 0 || $digits > 3) {
            throw new RuntimeException('Currency exponent is unsupported.');
        }

        return ['currency' => $currency, 'exponent' => $digits];
    }
}
