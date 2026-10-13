<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Data;

final readonly class CouponReceipt
{
    public function __construct(
        public string $intentId,
        public string $holdId,
        public string $status,
    ) {}
}
