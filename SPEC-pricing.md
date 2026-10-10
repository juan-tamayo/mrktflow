# Module: pricing

Objective: one authoritative deterministic calculation for display, checkout and refund allocation. Depends on platform and catalog. Inherits [engineering conventions](docs/architecture.md), [commands](docs/coding-handoff.md), and [commerce rules](docs/commerce-rules.md).

## Requirements

- **PRICE-01:** Currency-aware integer money; regular/sale prices and explicit sale windows; single store currency; zero-total orders supported.
- **PRICE-02:** One entered coupon per cart with fixed/percentage/free-shipping mode, validity dates, eligible products/categories, minimum spend, total/per-customer caps. Coupon holds cannot oversubscribe a cap under concurrency.
- **PRICE-03:** Explicit tax classes and destination rules, inclusive/exclusive price modes, line rounding, shipping treatment and versioned calculation evidence. Missing required tax coverage stops checkout.
- **PRICE-04:** Return a complete breakdown and line allocations. Price/stock/configuration changes invalidate checkout quotes; changing current catalog prices never changes past orders/refunds.
- **PRICE-05:** Admin can preview calculations for physical-only, digital-only and mixed carts before enabling rules. Show rule conflicts and unsupported combinations clearly.
- **PRICE-06:** Native campaigns support reusable codes, bounded unique code batches, recipient-bound recovery/referral/reward grants and automatic offers. Stable issuance intents, budget reservations, expiry and verified first-customer checks are enforced server-side.
- **PRICE-07:** Permit one entered coupon and at most one automatic promotion. Default no stacking selects the better total with entered-code tie-break; explicit permitted stacking uses the deterministic allocation order in [marketing rules](docs/marketing-rules.md). Show rejected/unapplied offers without consuming them.
- **PRICE-08:** Snapshot campaign/source/rule versions and allocations on orders. Concurrent redemptions cannot exceed program budgets or recipient limits; refunds use original allocations, and pausing an offer cannot rewrite an accepted payment session or historical order.

## Contracts and verification

Provides `PriceCalculator`. Unit/property tests cover 0/2/3-decimal currencies, odd-cent fixed discounts, inclusive taxes, dates, refund residuals, bounds and allocation sums. MySQL tests cover coupon limits. Target `php artisan test --filter=Pricing`. Market-specific tax acceptance is a launch gate; a configurable calculator is not a universal tax-compliance service.
