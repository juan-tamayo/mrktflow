<?php

declare(strict_types=1);

namespace App\Domain\Identity\Support;

use InvalidArgumentException;

final class StaffPermissions
{
    /** @return list<string> */
    public static function all(): array
    {
        return [
            'admin.access', 'staff.manage', 'settings.manage', 'integrations.manage',
            'catalog.read', 'catalog.write', 'pricing.manage', 'shipping.manage',
            'orders.read', 'orders.manage', 'orders.refund', 'fulfillment.manage', 'customers.read',
            'content.read', 'content.write', 'content.publish', 'media.read', 'media.upload',
            'marketing.write', 'marketing.send', 'referrals.manage', 'seo.manage', 'hubs.write',
            'reports.read', 'ai.use',
        ];
    }

    /** @return list<string> */
    public static function forRole(string $role): array
    {
        return match ($role) {
            'owner' => self::all(),
            'manager' => [
                'admin.access', 'catalog.read', 'catalog.write', 'pricing.manage', 'shipping.manage',
                'orders.read', 'orders.manage', 'fulfillment.manage', 'customers.read',
                'content.read', 'content.write', 'content.publish', 'media.read', 'media.upload',
                'marketing.write', 'marketing.send', 'referrals.manage', 'seo.manage', 'hubs.write', 'reports.read', 'ai.use',
            ],
            'content_editor' => ['admin.access', 'catalog.read', 'content.read', 'content.write', 'media.read', 'media.upload', 'hubs.write', 'ai.use'],
            'fulfillment' => ['admin.access', 'catalog.read', 'orders.read', 'fulfillment.manage'],
            default => throw new InvalidArgumentException('Unknown staff role.'),
        };
    }
}
