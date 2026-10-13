<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Contracts;

use App\Domain\CheckoutOrders\Data\HostedCheckoutCharge;
use App\Domain\CheckoutOrders\Data\HostedCheckoutResult;
use App\Domain\CheckoutOrders\Data\PaymentIntent;
use App\Domain\CheckoutOrders\Data\PaymentSession;
use App\Domain\CheckoutOrders\Data\RefundIntent;
use App\Domain\CheckoutOrders\Data\RefundResult;

/**
 * Adapter authenticates provider evidence and binds every reference to the expected intent.
 * Ambiguous network/mutation failures return unknown; never invent a terminal outcome.
 * No transaction may remain open while these methods perform network I/O.
 */
interface PaymentGateway
{
    /** Stable key + identical frozen parameters. Caller persists intent before network I/O. */
    public function createSession(PaymentIntent $intent, HostedCheckoutCharge $charge): HostedCheckoutResult;

    /** Null ID means the caller should retry create with the same key; inability to locate is unknown. */
    public function retrieveSession(PaymentIntent $intent, ?string $sessionId): HostedCheckoutResult;

    /** Cancellation means provider expiry then reconciliation; browser back/cancel is insufficient. */
    public function expireSession(PaymentIntent $intent, string $sessionId): PaymentSession;

    public function refund(RefundIntent $intent): RefundResult;

    /** Null ID means resolve the persisted refund reference; inability to locate is unknown. */
    public function retrieveRefund(RefundIntent $intent, ?string $providerRefundId): RefundResult;
}
