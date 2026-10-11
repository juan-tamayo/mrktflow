<?php

declare(strict_types=1);

namespace App\Domain\Platform\Support;

use ResourceBundle;

final class SetupChoices
{
    /** @return list<array{code: string, label: string}> */
    public function countries(): array
    {
        $bundle = ResourceBundle::create('en', 'ICUDATA-region')?->get('Countries');
        if (! $bundle instanceof ResourceBundle) {
            throw new \RuntimeException('Country data is unavailable. Check the intl extension.');
        }
        $choices = [];
        foreach ($bundle as $code => $label) {
            if (is_string($code) && preg_match('/^[A-Z]{2}$/', $code) && is_string($label)
                && ! in_array($code, ['AC', 'CP', 'DG', 'EA', 'EU', 'EZ', 'IC', 'QO', 'TA', 'UN', 'XA', 'XB', 'XK', 'ZZ'], true)) {
                $choices[] = ['code' => $code, 'label' => $label];
            }
        }
        usort($choices, static fn (array $a, array $b): int => strcmp($a['label'], $b['label']));

        return $choices;
    }

    /** @return list<array{code: string, label: string}> */
    public function currencies(): array
    {
        $bundle = ResourceBundle::create('en', 'ICUDATA-curr')?->get('Currencies');
        if (! $bundle instanceof ResourceBundle) {
            throw new \RuntimeException('Currency data is unavailable. Check the intl extension.');
        }
        $choices = [];
        foreach ($bundle as $code => $details) {
            if (is_string($code) && preg_match('/^[A-Z]{3}$/', $code) && $details instanceof ResourceBundle
                && ! in_array($code, ['XXX', 'XTS'], true)) {
                $label = $details->get(1);
                if (is_string($label)) {
                    $choices[] = ['code' => $code, 'label' => $code.' — '.$label];
                }
            }
        }
        usort($choices, static fn (array $a, array $b): int => strcmp($a['code'], $b['code']));

        return $choices;
    }
}
