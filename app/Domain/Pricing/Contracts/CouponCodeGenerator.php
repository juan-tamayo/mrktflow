<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Contracts;

interface CouponCodeGenerator
{
    public function next(): string;
}
