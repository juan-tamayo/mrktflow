# Commerce invariants and state transitions

Normative proposal for v1. Implement these rules before optimizing the checkout UI.

## Money, pricing and tax

- Store monetary amounts as signed 64-bit integers in the currency's minor unit, with a currency code and exponent from validated currency metadata. Never assume every currency has two decimals. Reject currency mismatches and arithmetic overflow.
- JSON money remains numeric only within JavaScript's safe-integer range (absolute value ≤9,007,199,254,740,991). Validate amount/quantity bounds before multiplication and serialization; reject unsupported totals rather than rounding or truncating them. Provider-specific amount/currency limits are an additional checkout validation, not a different money representation.
- Browser requests contain variant IDs, quantities, coupon codes, addresses and selections; server prices and rules determine totals. Quotes carry a revision/hash and short expiry. Every material change invalidates the quote.
- A store selects one base currency before selling. Changing it after orders exist is blocked in v1; editing a setting must never reinterpret existing amounts.
- Price precedence: active sale price at quote time, otherwise regular price. Sale price must be nonnegative and no higher than regular price. Zero-total checkout is supported without calling a payment provider, using the same idempotent order pipeline.
- One entered coupon per cart: fixed amount, percentage, or free shipping. Marketing expansion adds at most one automatic promotion, default no stacking, deterministic best-total selection and explicit permitted pairing as defined in [marketing rules](marketing-rules.md). Eligibility includes dates, minimum eligible subtotal, catalog scope, verified recipient/first-customer restrictions, redemption limits and campaign budget. Do not claim verified email prevents all multi-email abuse.
- Price discounts apply before tax. Fixed discounts allocate proportionally across eligible lines using integer largest-remainder allocation, stable line-ID tie breaking, and no negative line totals. Percentages use decimal/rational math, not binary float. Save per-unit/line allocations for partial refunds.
- Coupon usage is held with checkout reservations and committed on confirmed payment/zero-total order completion. Failed or expired checkouts release it. Refunds do not automatically restore a redemption in v1. Promotion budgets and first-customer claims use the same atomic hold/commit/release lifecycle. Order snapshots retain source intent, rule revision and allocated discounts; marketing/referral consumers qualify conversions from committed events without becoming checkout dependencies.
- Global pricing mode is tax-exclusive or tax-inclusive. Each taxable line uses a tax class and destination rule. Physical uses shipping destination; digital uses billing destination. Billing country alone is not presented as sufficient tax evidence for every jurisdiction.
- V1 tax rules support one effective non-compounding rate per class/destination match. Rule specificity is country → region → postal constraint; conflicts of equal specificity are rejected at configuration time. Zero-rate treatment must be explicit; missing required coverage blocks checkout rather than silently charging zero.
- Shipping has its own configured taxable class/rule; no automatic assumption that every product's shipping tax is identical. Complex mixed-rate shipping allocation, compound tax, exemptions, cross-border evidence and automated tax services require market-specific expansion before selling where necessary.
- Exclusive tax = rounded discounted taxable base × rate. Inclusive tax = rounded discounted gross × rate / (1 + rate). Round half-up at line level. Sum already-rounded line results; never re-round a basket-wide approximation.
- Exclusive grand total = discounted merchandise + discounted shipping + line taxes + shipping tax. Inclusive grand total = discounted merchandise gross + discounted shipping gross; included taxes are displayed, not added again. Persist price mode, rate/rule revision, taxable basis and allocations.

Fixture examples (two-decimal sample currency): two units at 1,000 plus one at 500; 10% merchandise coupon → 2,250; shipping 300; 10% exclusive tax on merchandise and shipping → tax 255 and total 2,805. For inclusive pricing, discounted gross 2,200 plus shipping gross 330 at 10% → included tax 230, total 2,530. Test odd-cent and zero-/three-decimal currencies separately.

## Inventory lifecycle

- Physical variants can be tracked or explicitly untracked; digital variants are untracked in v1. Backorders and negative tracked stock are disabled.
- Each tracked variant has `onHand` and `reserved`; available = onHand − reserved. Active checkout holds and paid-but-unshipped allocations both count as reserved.
- Adding to cart does not reserve inventory. Starting payment creates a hold under row locks, in stable variant-ID order; reserve all lines atomically or none. Lock coupon counters in a consistent order too.
- Hold states: `active → committed | released`; committed allocations become `closed` when no units remain reserved. Store original, shipped, released and remaining-reserved quantities per allocation; their nonnegative sum satisfies `original = shipped + released + remainingReserved`. Partial shipment/cancellation changes quantities without closing an allocation prematurely. Committed means allocated to a paid order, not physically shipped; payment confirmation does not increment reserved again.
- Shipping quantity q decrements onHand and reserved by q in one transaction and records an immutable stock movement. Cancelling unshipped paid quantities releases reserved stock once; returning physically received items increases onHand only after explicit restock confirmation.
- Admin stock adjustment cannot make onHand lower than reserved. Use an audited shortage resolution flow instead of silently invalidating paid allocations.
- Payment hold expiry aligns with the provider session validity. Stripe documents a 30-minute to 24-hour Checkout Session expiry range; v1 targets 30 minutes, then persists the actual returned expiry. The adapter spike still verifies cancellation/race behavior in test mode. [Stripe Checkout Session API](https://docs.stripe.com/api/checkout/sessions/create).
- Before releasing a hold for an exposed session, the scheduler confirms the provider session is terminal and non-payable. Merely reading “unpaid” is insufficient: cancel/expire a still-payable session and reconcile its final state first. Serialize local attempt transitions with row locks/revision checks while keeping network calls outside those locks. If the provider is unavailable or an outcome is unknown, keep the hold and flag reconciliation; do not assume timeout equals non-payment.
- Before changing cart contents or opening a replacement session, resolve/cancel the previous payment attempt. Never leave two payable sessions for the same checkout revision.
- If a late verified success arrives after stock was released, attempt atomic re-reservation. If unavailable, mark the paid order `on_hold`, block physical fulfillment, alert staff, and offer an idempotent refund resolution. Never oversell or silently discard a successful charge.

## Checkout and payment sequence

1. Revalidate product publication, variant availability, quantity limits, customer permission and delivery eligibility.
2. Compute authoritative quote from current rules. Shopper confirms its revision; changed totals require a refreshed confirmation.
3. Atomically claim the idempotency key and unique cart/revision checkout intent, freeze order/line/address/price snapshots, hold stock and coupon usage, create `pending` order and `created` payment attempt, and commit. Competing keys for the same cart revision return the existing authorized intent or a conflict/status reference; they cannot create another payable order. Retry a failed attempt on that order only after the old attempt is confirmed non-payable; a new purchase requires a new cart revision and explicit confirmation.
4. Outside that transaction, create the provider session with the stable attempt ID as idempotency reference. Record returned session ID and expiry. A timeout becomes `unknown`; look up/reconcile the same attempt instead of creating another.
5. Redirect to hosted payment. Return URLs only show current status and may poll; they cannot mark an order paid.
6. Verify signature on the raw webhook body, account/environment, event schema and expected currency/amount/reference. Persist the unique event in a durable inbox before returning success.
7. Worker locks the attempt/order, reconciles provider state if necessary, records payment once, commits stock/coupon holds and emits an outbox event. Duplicate or older events cannot downgrade a confirmed payment.
8. Independent idempotent consumers issue receipt intents and digital entitlements. Fulfillment staff sees paid physical quantities. Workers can be retried without recharging or double allocation.

The gateway payload represents the frozen payable total exactly. Disable provider-side promotion entry, automatic tax, adjustable quantities and shopper changes to the fulfillment destination that would bypass the accepted quote. Card billing details may be collected for provider checks but never silently replace the store's tax/delivery snapshot. Map explicit charge components with integer allocations; handle per-unit rounding residuals without changing the order total. Compare provider amount/currency with the frozen order before redirect and on confirmation. Provider line/amount/currency limits are checked before creating a payable session. These mappings require contract fixtures and sandbox evidence in T035.

Every paid/refund/dispute consumer rechecks the current authoritative order-line eligibility under concurrency control. An old `OrderPaid` event delivered after a refund must not issue new access or release a reward. A refund processed before an entitlement exists leaves durable line-level revocation/eligibility state; missing entitlement rows are not permission to grant later. This rule is independent of event arrival order.

Only immediate methods are offered in v1. Delayed bank methods need an explicit extended-hold policy before enabling. There is one successful payment per order in v1; split tender and multiple captures are excluded. Provider refunds/disputes initiated outside MRKTFlow are reconciled and visible.

Zero-total orders record a local zero-value completion fact and use the paid/confirmed fulfillment path without inventing a provider charge. They have no refundable captured money; cancellation still revokes entitlements and releases unshipped allocations.

Stripe documents signature checks, repeated delivery and non-guaranteed event order. Our durable inbox and monotonic transition rules are the chosen response to those constraints. [Stripe webhooks](https://docs.stripe.com/webhooks).

## Separate states

| Record | States and permitted transitions |
| --- | --- |
| Order | pending → confirmed / cancelled; confirmed → on_hold / completed / cancelled; on_hold → confirmed / cancelled; completed may receive returns/refunds without erasing completion history |
| Payment attempt | created → awaiting_payment / unknown / failed; awaiting_payment → succeeded / failed / expired / unknown; unknown → reconciled outcome; success is never overwritten by a stale failure |
| Financial projection | unpaid / paid / partially_refunded / refunded; dispute flag tracked separately |
| Physical fulfillment | not_required / unfulfilled / partially_fulfilled / fulfilled, derived from line quantities |
| Digital delivery | not_required / pending / available / partially_revoked / revoked, derived from entitlements |
| Refund | requested → processing / failed; processing → succeeded / failed / unknown; unknown → reconciled outcome |
| Entitlement | active → suspended / revoked / expired; suspended → active / revoked; revoked cannot be silently reactivated |

Completed means all required physical shipment work and digital entitlement issuance are complete, not that the carrier proved delivery. Tracking can independently show carrier status. Cancelling a paid order requires resolved payment/refund state, released allocations and revoked undelivered entitlements; never merely change a label.

## Partial refunds and returns

- Refund requests specify order-line quantities and optional shipping amount; compute maximum refundable value from stored original discounts/taxes, minus successful and pending refund allocations.
- Claim quantities/amounts atomically to prevent concurrent over-refunds. Use a stable refund ID for provider calls. An unknown outcome keeps the allocation locked until reconciled.
- Refund by unit allocation so rounding pennies do not accumulate; the final unit consumes the original residual. The total of all refunds may never exceed captured money, original tax, or shipped/refundable quantities.
- Return records track requested/approved/received/rejected quantities separately from refund money. A refund does not imply goods returned or restocked. A return does not itself charge or refund anything.
- A requested refund of unshipped physical quantities places those quantities on a fulfillment hold immediately. On confirmed quantity refund, cancel them and release their catalog allocations atomically; on definite failure, release the refund hold; unknown outcomes keep it. Shipment and refund actions lock/check the same order-line counters. Amount-only goodwill refunds do not cancel quantities; shipped quantity refunds use the separate return/restock workflow.
- Digital variants have maximum quantity one per order in v1. A full digital-line refund revokes its entitlement. An amount-only partial goodwill refund retains access unless authorized staff explicitly revokes it. Whole-order full refund revokes every entitlement.
- Recorded dispute opens suspend digital access and place unshipped work on hold; a resolved won dispute can restore eligible suspended access, while a lost dispute revokes access. Staff actions and provider events are audited.
- Refunds/edits cannot alter the original invoice/order snapshot. Store adjustments and separate refund records.

## Download delivery

- Files live outside public storage with opaque keys; filenames alone confer no access. A variant points to published digital asset versions; the paid order pins the version promised at purchase.
- A paid order line grants an entitlement to its customer or guest-order identity. Guest access requires an expiring email verification link whose GET only shows a confirmation page; explicit CSRF-protected confirmation POST consumes the token, rotates the session and grants scoped order access. Mail scanners must not consume tokens or authenticate. Account linking requires verified email, not merely a matching unverified registration.
- Issuing a download grant creates a single-use start token valid for five minutes plus an owner-only grant-status URL. Issuing alone does not spend an allowance. An explicit CSRF-protected start POST consumes it; GET/prefetch never spends an allowance. Consuming it rechecks ownership and current order-line/entitlement eligibility and atomically spends one allowance and creates a transfer session bound to that principal/session and asset version. Concurrent starts for the last allowance yield only one session. A lost response is recovered through the owner-only grant-status URL; replay cannot create another session or consume another allowance.
- Default limit is five grants per entitlement, adjustable with audit. A grant means one authorized transfer session, not proof that the browser saved the file. Transfer session has an absolute one-hour lifetime from start; initial-token expiry does not interrupt an already-started large transfer. Its protected route permits bounded Range/resume requests by the same principal while active, with at most one concurrent stream, without spending another allowance. Expired/revoked sessions reject new streams. Finishing a server response does not prove client receipt and does not prematurely close the resumable session. Check ownership, current line eligibility and revocation on every new stream/range request; a refund on an unrelated order line does not revoke this file.
- Stream through protected application/internal-webserver routes, never redirect to a public storage key. Revocation blocks subsequent requests; do not claim to retract bytes already transferred or guarantee immediate termination of an in-flight webserver stream. A future object-storage profile must separately document presigned-URL revocation limits. Test a transfer lasting beyond the five-minute start window and a resumed request after revocation.
- Asset updates create new versions. Existing entitlements keep their purchased version unless staff explicitly grants an update. Retain referenced versions; deletion is blocked while an active entitlement depends on them.

## Time and retries

Persist UTC timestamps and display in store timezone; evaluate rule windows from UTC instants. Preserve store timezone on historical reports. Monetary/provider intent IDs and webhook deduplication records live with the order retention lifecycle, not a short cache TTL. Provider idempotency retention is not the application's deduplication guarantee. [Stripe idempotency](https://docs.stripe.com/api/idempotent_requests).

## Money and price engine (T027)

`Money` stores nonnegative minor units with an ISO currency and ICU fraction-digit exponent, bounded by JavaScript's safe integer limit. Arithmetic uses integer addition/multiplication and `bcmath` rationals; currency mismatches and overflow fail closed. `CalculatePrice` selects an active sale when `saleStartsAt <= quotedAt < saleEndsAt` in UTC, otherwise the regular price. Fixed and percentage merchandise discounts allocate with largest remainder and stable line-ID tie-breaking; percentage totals use round-half-up on the eligible integer subtotal, then the same allocator. Per-unit residuals put leftover minor units on the last units of a line so later refunds can consume original pennies. Quotes are immutable and carry a SHA-256 fingerprint of the breakdown. Shipping may be supplied as an already-quoted integer amount; tax is not calculated here. Coupon tables persist reusable codes, scopes, and hold/commit/release counters. Tax rules and checkout quote persistence remain T031/T032.

## Payment/reservation spike (T011)

The implemented `PaymentGateway` boundary and immutable intent/evidence objects establish local contracts; no gateway adapter, payable route, order tables or catalog reservation service exists yet. Amounts are positive integer minor units bounded by JavaScript's safe integer limit, with a known ISO currency. This application limit is not a provider amount limit or a promise of provider currency support. Zero-total orders bypass this gateway. Future gateway mapping must validate currency exponent, provider limits and exact frozen component allocations before network I/O.

Persist the frozen attempt, gateway account/environment, expiry and immutable outbound parameters before creating a session. `payment:<attempt UUID>` and `refund:<refund UUID>` are distinct stable operation keys. Retries must use identical parameters; never recompute expiry on retry or reuse the key for another cart revision. A provider timeout, connection reset, malformed/unbound response, server error or unrecognized status is **unknown**, not failure. The caller keeps stock/coupon/refund allocations held and schedules bounded reconciliation. Provider calls happen outside database transactions. The evidence DTOs deliberately have no credentials, raw response, shopper fields or redirect URL; reviewed hosted URL validation and provider mapping remain T035.

| Operation / observation | Required behavior |
| --- | --- |
| Create session | Durable intent first; stable key; bind amount, currency, attempt, account and test/live mode before accepting evidence. Unknown keeps the same attempt. |
| Retrieve session | Use the recorded session ID and original intent. If the ID was lost, resolve by the persisted attempt reference within bounded provider queries. Not found is unknown unless absence can be authoritatively established. |
| Shopper back/cancel | Merely navigation. It cannot mark paid/failed, release stock, or authorize another payable attempt. |
| Cancel / local deadline | Request provider expiry, then reconcile. A local clock or expiry API error alone cannot establish non-payability. |
| Open or complete/unpaid | Keep reservation. Completed alone is not payment success. A terminal browser error is not a terminal provider state. |
| Complete/paid | Require bound successful capture/payment reference; record success monotonically once under order locks. Stale expiry/failure cannot overwrite it. |
| Expired/unpaid | Release only after authenticated reconciliation confirms no successful or unresolved underlying payment. The normalizer must inspect any related payment intent; it must not discard a processing/payment reference to manufacture this evidence. |
| Refund request | Atomically claim cumulative refundable allocations first. The DTO enforces a single refund no larger than the frozen payment; cumulative/line-level limits require future database locks. |
| Refund pending/requires_action/unknown | Keep allocations held; no additional refund for those quantities. Resolve against the same capture, refund reference, account and environment. |
| Refund succeeded | Commit money/line eligibility changes once; never treat a refund as automatic restocking. |
| Refund failed/canceled | Only bound authoritative terminal evidence releases that refund allocation. |

When a mutation response loses the provider ID, retrieval accepts a null ID to require reference-based reconciliation. A future adapter must not interpret an empty bounded list as proof of absence, or silently create another payment/refund after a provider idempotency key ages out. Escalate unresolved attempts for staff reconciliation while preventing duplicate payable attempts. Provider idempotency retention is shorter than application financial retention; durable application keys remain authoritative.

`PaymentSession` and `RefundResult` accept normalized **authenticated** evidence, not browser parameters or unsigned webhook fields. Their factories reject mismatched identities, money, account/mode and inconsistent status/capture combinations. Exceptions must be redacted and treated as unknown by the future adapter. These objects are observations, not a persisted order state machine: later consumers must enforce monotonic transitions and recheck eligibility under locks.

The executable concurrency spike uses test-created InnoDB `spike_stock` and `spike_reservations` tables, separate PHP workers/connections, a parent-held row lock/readiness barrier, and a single synthetic unit. One worker reserves and one sees sold out. Rolling back a worker reverses both the decrement and reservation insert. Tables/readiness files are removed in cleanup; nothing is migrated into merchant/development databases. Multi-line sorted locks, reservation expiry/reconciliation, duplicate webhook/refund handling and crash recovery require their own later production tests.

Official provider references checked October 10, 2026: [session status/payment status](https://docs.stripe.com/api/checkout/sessions/object), [explicit expiry of open sessions](https://docs.stripe.com/api/checkout/sessions/expire), [idempotent requests](https://docs.stripe.com/api/idempotent_requests), and [refund states](https://docs.stripe.com/api/refunds/object). These inform the contracts; no live sandbox call was made and no provider SDK was added. T035 remains pending for actual account/test-mode mapping, hosted URL safety, expiry windows, unknown-ID reconciliation, webhook authenticity and sandbox evidence.
