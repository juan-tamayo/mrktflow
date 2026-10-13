# Module: checkout-orders

Objective: transform a validated cart into one accurate, paid, auditable order. Depends on platform, identity, catalog, pricing and shipping. Inherits [commerce rules](docs/commerce-rules.md), [interfaces](docs/interfaces.md), and [commands](docs/coding-handoff.md).

## Requirements

- **ORDER-01:** Persistent guest/account carts with quantity edits, unavailable-item messages, coupon feedback and explicit merge semantics. Client price input is ignored/rejected.
- **ORDER-02:** Guest/account checkout collects email, billing and only necessary shipping details, permits address correction, shows itemized total and policy acknowledgement, and confirms any repricing before payment.
- **ORDER-03:** Create a unique checkout/payment intent under an idempotency key; immutable order snapshot, stock/coupon holds, hosted Stripe payment and zero-total path follow the normative sequence.
- **ORDER-04:** Verify and durably record provider events; reconcile duplicate/unordered/missing/unknown outcomes. Amount, currency, provider account, environment and order reference must match.
- **ORDER-05:** Admin list/detail includes independent order, financial, shipment and download states; searchable order number/customer/date; timeline, internal notes and resend-receipt action with audit.
- **ORDER-06:** Quantity/amount-based partial/full refunds and cancellation enforce unrefunded bounds and payment resolution. Unknown external outcomes remain visible. A staff click cannot silently double-refund.
- **ORDER-07:** Customer/verified guest sees their own order history/status/receipt. Order identifiers alone grant no access.

## Verification

T032 implements expiring quotes and stale-total confirmation (`bin/php artisan test --filter=CheckoutOrdersQuote`). Payment fixtures and sandbox tests cover success, decline, authentication step, abandoned session, expired quote, duplicate clicks/events, timeout-after-provider-success, mismatch, zero total, partial refund and refund race. Target `php artisan test --filter=CheckoutOrders`; physical/digital/mixed browser checkout suites. Live charges are excluded from development validation. Order freeze and hosted payment remain T034/T035.
