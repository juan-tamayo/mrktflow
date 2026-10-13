<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Support;

final class ReservedCatalogSlugs
{
    /** @return list<string> */
    public static function all(): array
    {
        return [
            'admin', 'account', 'setup', 'staff', 'storage', 'up', 'brand', 'build', 'login',
            'register', 'downloads', 'webhooks', 'store-api', 'livewire', 'products', 'categories',
            'shop', 'search', 'cart', 'checkout', 'orders', 'pages', 'journal', 'wishlist',
            'compare', 'collections', 'recover', 'email', 'r',
        ];
    }
}
