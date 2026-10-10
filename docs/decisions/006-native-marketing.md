# ADR-006: Native marketing consumes commerce facts

Status: Proposed · Date: 2026-10-05.

## Context

The user expanded v1 with abandoned-cart recovery, urgency/social proof, automatic product landing hubs and coupon/referral engines. These features affect customer communication, payable totals, reward liability and public indexing.

## Decision

Add marketing, referrals and seo-hubs modules within the existing monolith. Pricing owns every discount/grant/financial budget; checkout freezes opaque campaign/referral/source references. Marketing/referrals consume committed order events with durable deduplication; checkout never depends on reporting or campaign delivery. Shared platform preferences support recovery, broadcasts and watch subscriptions without mutual module imports.

Hubs use the existing content/template/revision system with bounded generation and explicit rule-authorized publication. AI-generated prose remains a reviewable draft. Urgency uses real stock/expiry facts; proof uses real aggregate purchases and verified reviews. Referral rewards are coupons qualified after a delay and rechecked against refunds.

## Alternatives

Requiring third-party marketing SaaS would leave requested capabilities outside the native install. Putting reward/campaign logic directly in checkout would increase failure coupling. Unbounded automatic page generation would create duplicate/low-value content without establishing useful search intent. A separate discount calculator would risk different advertised and payable prices.

## Consequences

Three module specs, expanded pricing contracts, contact preference data, new marketing/SEO jobs and additional release tests are required. Bulk mail and hub work need isolated worker capacity. Consent, attribution, suppression, stock freshness and reward reversals are explicit product behavior. No claim of guaranteed email delivery, ranking, conversion uplift or perfect fraud prevention is made.
