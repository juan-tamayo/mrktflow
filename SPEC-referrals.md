# Module: referrals

Objective: a native refer-a-friend engine with accountable reward qualification and coupon delivery. Depends on platform, identity, pricing and checkout-orders. Inherits [marketing rules](docs/marketing-rules.md), [commerce rules](docs/commerce-rules.md) and [commands](docs/coding-handoff.md).

## Requirements

- **REF-01:** Owner configures a versioned program: eligibility, friend offer, advocate reward, qualifying net merchandise threshold, attribution window, reward delay, expiry and per-person/program limits. V1 rewards are coupons; cash payouts, affiliate commissions and stored-value balances are excluded.
- **REF-02:** Verified customers enroll and obtain opaque share links/codes, terms and a private reward dashboard. They share using copy/link controls; MRKTFlow does not collect friends' address books or send unsolicited invitations on their behalf.
- **REF-03:** Record a consent-eligible referral touch; default is the first eligible referral in 30 days. A shopper-entered valid referral code explicitly replaces that attribution before quote acceptance. Freeze advocate/program-version attribution on the order and allow at most one advocate per referred customer qualification.
- **REF-04:** Friend discount is a pricing-owned recipient-bound coupon, applied before purchase only after recipient verification and first-customer checks. Qualify the advocate from the friend's first eligible paid order, net of merchandise discounts/refunds and excluding tax/shipping. Pending/cancelled/test/zero-value orders never earn rewards.
- **REF-05:** Delay reward release until the configured hold period passes and order eligibility is rechecked; default 14 days after the later of payment and physical fulfillment, or payment for digital-only orders. Order/refund/dispute replays cannot grant twice. Late adverse events revoke unused rewards or create a review case if already used; never debit a customer's payment method.
- **REF-06:** Reject known self-referral and repeat reward qualification; atomic uniqueness and budget counters prevent parallel duplicate rewards. Matching contact/account identifiers are hard checks; shared address/network signals are review hints, not automatic household bans. Suspected abuse is held for a reasoned staff decision.
- **REF-07:** Dashboard and exports show referral clicks, verified friends, pending/qualified/rejected referrals, reward issuance/redemption/expiry/reversal and net referred sales. Staff adjustments have reasons and audit history; no exposure of the friend's order details to the advocate.

## Verification

Target `php artisan test --filter=Referrals`. Cover link/code precedence, boundary expiry, parallel first orders, refund before/after reward, duplicate events, budget exhaustion, self-referral, different legitimate household members and cross-customer dashboard access. Browser flow: share → verified friend purchase → qualification delay → coupon reward → redemption.
