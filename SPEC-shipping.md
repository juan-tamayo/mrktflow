# Module: shipping

Objective: predictable shipping eligibility and charges without requiring a carrier subscription. Depends on platform only; consumes immutable physical-line snapshots, not order models. Inherits [engineering conventions](docs/architecture.md) and [commands](docs/coding-handoff.md).

## Requirements

- **SHIP-01:** Owner configures origin, shipping zones and method labels. Match allowed country/region/postal patterns with deterministic priority. Reject ambiguous same-priority rules.
- **SHIP-02:** Flat rate, free shipping above a threshold, subtotal/weight table rates, and local pickup. Threshold uses discounted physical merchandise subtotal before tax; digital value never qualifies physical shipping for free by accident.
- **SHIP-03:** Each method can include/exclude shipping classes. At least one method must cover all physical lines; unsupported mixed shipping-class baskets get a clear no-service message. V1 has one origin and no split-rate basket optimization.
- **SHIP-04:** Digital-only carts require no shipping address/method and quote zero shipping. Mixed-cart rates ignore digital lines. Missing weights block weight-based methods without inventing a weight.
- **SHIP-05:** Saved method/rule revision and quoted charge are frozen onto orders. Later rule changes do not change historical shipping fees.
- **SHIP-06:** Local pickup skips delivery-address collection but supplies the configured pickup location as physical tax destination under the chosen market policy. Display pickup instructions without promising stock before payment confirmation.

## Verification

Provides `ShippingQuoter`. Table tests cover boundaries, postal matching, unsupported destinations/classes, rule overlap, zero physical items and discount threshold crossing. Target `php artisan test --filter=Shipping`. Carrier rate APIs, labels and customs documents remain explicitly out of v1 scope.
