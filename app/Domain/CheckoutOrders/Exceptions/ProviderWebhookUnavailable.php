<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Exceptions;

use RuntimeException;

final class ProviderWebhookUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The provider event could not be recorded.');
    }
}
