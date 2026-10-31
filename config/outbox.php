<?php

use App\Domain\CheckoutOrders\Consumers\AcknowledgeOrderCancelled;
use App\Domain\CheckoutOrders\Consumers\AcknowledgeOrderPaid;
use App\Domain\Fulfillment\Consumers\IssueOrderEntitlements;
use App\Domain\Platform\Consumers\StoreAuditEvent;

return [
    'consumers' => [
        'platform.audit.recorded' => ['platform.audit' => StoreAuditEvent::class],
        'checkout.order.paid' => [
            'checkout.order-paid' => AcknowledgeOrderPaid::class,
            'fulfillment.entitlements' => IssueOrderEntitlements::class,
        ],
        'checkout.order.cancelled' => ['checkout.order-cancelled' => AcknowledgeOrderCancelled::class],
    ],
    'max_attempts' => 6,
    'backoff' => [10, 30, 120, 300, 900],
];
