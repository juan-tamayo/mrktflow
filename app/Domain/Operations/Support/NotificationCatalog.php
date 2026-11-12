<?php

declare(strict_types=1);

namespace App\Domain\Operations\Support;

use App\Domain\Platform\Support\DeliveryPolicy;
use InvalidArgumentException;

final class NotificationCatalog
{
    public const DISCLAIMER = 'Transactional delivery is at-least-once: a crash after the transport accepts a message can send a duplicate. This store does not promise exactly-once SMTP.';

    /** @var array<string, array{class: 'transactional'|'marketing', purpose: ?string, queue: string, label: string}> */
    public const TEMPLATES = [
        'order-receipt' => ['class' => 'transactional', 'purpose' => null, 'queue' => 'transactional-mail', 'label' => 'Order receipt'],
        'shipment' => ['class' => 'transactional', 'purpose' => null, 'queue' => 'transactional-mail', 'label' => 'Shipment notice'],
        'download-access' => ['class' => 'transactional', 'purpose' => null, 'queue' => 'transactional-mail', 'label' => 'Download access'],
        'refund' => ['class' => 'transactional', 'purpose' => null, 'queue' => 'transactional-mail', 'label' => 'Refund notice'],
        'customer-challenge' => ['class' => 'transactional', 'purpose' => null, 'queue' => 'transactional-mail', 'label' => 'Account verification or reset'],
        'newsletter' => ['class' => 'marketing', 'purpose' => 'newsletter', 'queue' => 'marketing', 'label' => 'Newsletter'],
        'watch-alert' => ['class' => 'marketing', 'purpose' => null, 'queue' => 'marketing', 'label' => 'Watch alert'],
        'cart_recovery' => ['class' => 'marketing', 'purpose' => 'cart_recovery', 'queue' => 'marketing', 'label' => 'Cart recovery'],
    ];

    /** @return array{class: 'transactional'|'marketing', purpose: ?string, queue: string, label: string} */
    public static function definition(string $template): array
    {
        $definition = self::TEMPLATES[$template] ?? null;
        if ($definition === null) {
            throw new InvalidArgumentException('Unknown notification template.');
        }

        return $definition;
    }

    public static function policyClass(string $template): string
    {
        return self::definition($template)['class'];
    }

    public static function queue(string $template): string
    {
        return self::definition($template)['queue'];
    }

    public static function purpose(string $template, ?string $explicit = null): ?string
    {
        $defined = self::definition($template)['purpose'];
        if ($defined !== null) {
            return $defined;
        }
        if (self::policyClass($template) === 'transactional') {
            return null;
        }
        if ($explicit === null || ! in_array($explicit, DeliveryPolicy::PURPOSES, true)) {
            throw new InvalidArgumentException('A marketing template requires a known purpose.');
        }

        return $explicit;
    }
}
