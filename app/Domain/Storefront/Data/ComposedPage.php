<?php

declare(strict_types=1);

namespace App\Domain\Storefront\Data;

use InvalidArgumentException;

/**
 * A page resolved against the SystemSlots contract: the page key, the
 * theme template that fills its content slot, and every slot with its
 * final view (a theme assignment where the contract allows one, the
 * platform component everywhere else).
 */
final class ComposedPage
{
    /**
     * @param  list<array{name: string, kind: string, region: string, view: ?string}>  $slots
     */
    public function __construct(
        public readonly string $page,
        public readonly string $template,
        public readonly array $slots,
    ) {}

    /** @return array{name: string, kind: string, region: string, view: ?string} */
    public function slot(string $name): array
    {
        foreach ($this->slots as $slot) {
            if ($slot['name'] === $name) {
                return $slot;
            }
        }

        throw new InvalidArgumentException("Page [{$this->page}] has no slot [{$name}].");
    }

    public function viewFor(string $name): ?string
    {
        return $this->slot($name)['view'];
    }
}
