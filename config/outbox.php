<?php

use App\Domain\CheckoutOrders\Consumers\AcknowledgeOrderPaid;
use App\Domain\Platform\Consumers\StoreAuditEvent;

return [
    'consumers' => [
        'platform.audit.recorded' => ['platform.audit' => StoreAuditEvent::class],
        'checkout.order.paid' => ['checkout.order-paid' => AcknowledgeOrderPaid::class],
    ],
    'max_attempts' => 6,
    'backoff' => [10, 30, 120, 300, 900],
];
