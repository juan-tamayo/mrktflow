# Native marketing and SEO design

Revision 0.1 · October 5, 2026. Extends the initial commerce plan at the user's request. All three new modules and the expanded coupon engine are required for the first public release. Numeric limits below are proposed defaults, configurable within safe application bounds.

## Product scope

| Tool | Merchant workflow | Shopper experience |
| --- | --- | --- |
| Abandoned-cart recovery | Choose inactivity window, email steps, audience and optional incentive; preview/test; activate | Relevant reminder, current basket restoration and clear opt-out |
| Urgency | Select actual stock threshold, scheduled offer or coupon expiry; target pages | Accurate low-stock/sale-ending message, dismissible and bounded |
| Social proof | Enable qualified purchase aggregates and moderate submitted reviews | Real sales evidence and authentic ratings without disclosing buyer identity |
| Landing hubs | Choose grouping rules and layout; preview candidates; publish or approve a rule | Searchable collection/guide with useful copy, live products and comparisons |
| Coupon engine | Create campaign codes, code batches, recipient offers and automatic promotions | Validated, explainable savings, with no surprising stacking |
| Referral engine | Set friend/advocate rewards and qualification policy | Share a link, receive a first-order offer, see earned coupon status |
| Campaign tools | Email signup, opt-in segments, scheduled broadcasts and offer banners | Relevant messages and self-service preferences |
| Measurement | Inspect first-party funnel, UTM and campaign reports | Tracking follows store privacy settings and shopper choices |

## Ownership and integration

Pricing owns promotion rules, coupon grants, financial eligibility, allocations, holds, budgets and redemption. Marketing and referrals request issuance using stable source intent IDs. Checkout calls pricing and records opaque source/program/campaign references in order snapshots; it does not import marketing/referral modules. Those modules consume committed order events through their own listeners.

Platform owns shared contact-purpose and delivery-suppression primitives; marketing owns the consent/signup workflow, campaigns, recovery episodes, reviews and conversion-message policies. Referrals owns attribution and reward qualification. SEO-hubs owns grouping/SEO projections and calls the existing content publisher. Storefront renders public DTOs from these providers. Operations reads their report projections and supplies shared delivery/diagnostic UI without becoming a dependency of campaign business logic.

## Contacts, permissions and email delivery

Use a contact identity linked optionally to a customer, with normalized email, verification time and separate purpose grants (`cart_recovery`, `newsletter`, `stock_alert`, `price_alert`). Store grant/revocation time, source, notice revision and confirmation evidence. Existing watch subscriptions retain ownership of item-level choices but consult shared platform suppression primitives; neither module imports the other.

Default behavior requires explicit verified opt-in for promotional/recovery mail. Checkout contact details do not silently enroll a customer. A merchant's launch-market policy must be configured before sending; this product default is not a universal legal classification of every message type.

The preference center supports purpose-level opt-out and all-marketing opt-out. Do not disable essential order/download/security email through an all-marketing toggle. Hard bounces and complaints suppress affected delivery globally; staff cannot clear them by silently re-importing the contact. Provider feedback events verify signatures and deduplicate. For plain SMTP, show feedback limitations and provide an explicit manual suppression workflow.

One-off campaigns support verified opt-in segments by category interest explicitly supplied, customer/new-customer status, purchase window and previous campaign activity. Snapshot the chosen audience for audit, but recheck consent/suppression at each send. Do not build arbitrary SQL or upload purchased lists as subscribed contacts. Existing external lists may be imported as unverified contacts until valid consent evidence is provided through the defined import path.

Required sender setup covers domain identity/authentication instructions, test delivery, unsubscribe headers and visible footer controls. Promotional mail uses one-click unsubscribe semantics; email-scanner GET requests do not perform destructive preference changes. One-click POST uses a purpose-scoped signed token and does not require login/CSRF cookies. Provider acceptance, delivery, bounce and open/click metrics are distinct. Ordinary SMTP cannot guarantee exactly-once delivery; unknown transport results remain visible rather than blindly blasting a duplicate. See [Gmail sender guidance](https://support.google.com/mail/answer/81126) for current provider requirements; no deliverability guarantee is implied.

## Abandonment lifecycle

An episode is `(cartId, activityRevision)` with states `watching → eligible → scheduled → active → recovered | stopped | expired`. Meaningful activity is a shopper cart-line/address/checkout change; background repricing, bot page loads and worker reads do not keep a cart alive. Default inactivity is one hour; the three sends are at +1h, +24h and +72h from the last meaningful activity. After renewed activity, cancel the episode and start a new observation window; retain the contact frequency cap to prevent repeated resets sending more messages.

Eligibility requires a nonempty purchasable basket, verified eligible contact/purpose grant, published products and no completed/confirmed order for the cart lineage. Exclude carts with awaiting-payment or unknown attempts until provider reconciliation resolves them. For the final dispatch decision, recheck current cart, revision, suppression, payment and order states. A confirmed purchase at that check cancels the send; an email already accepted by the transport cannot be recalled if payment succeeds milliseconds later. Document that race honestly and ensure all later steps stop.

Send intent uniqueness: episode + step + channel. Default caps: three messages per episode, at most two recovery emails/contact/24h and three/contact/7d across episodes; do not “catch up” suppressed/late steps in a burst. A materially edited sequence is versioned; active episodes retain a known version or explicitly migrate without duplicate intents. Merchant can pause all recovery, one flow or one contact.

Recovery links contain an opaque high-entropy token stored only as a hash, expire after seven days and are noindex/no-store with no-referrer handling. GET shows a generic continue page and does not consume the token, mutate carts, expose their contents or authenticate a customer. Explicit POST claims the link, strips the token from the browser URL, then restores only product/variant IDs and requested quantities into the current shopper's cart after validation. No stored email/address/payment state is restored. A recipient-bound discount additionally requires email/account verification; possession of a forwarded recovery link never proves identity.

Record source episode/campaign on the new cart and order, but retain no secret in analytics/log URLs. Unavailable or repriced items are shown clearly. Restoration never renews a hosted payment session or reserves stock by itself. Full payment/quote checks run again.

## Promotions and coupon engine

Support reusable public codes, unique bulk code batches, recipient-bound recovery/referral/reward codes, and one automatically selected promotion. Modes: fixed merchandise amount, percentage with an explicit maximum discount, or free eligible shipping. Rules include time window, catalog scopes, minimum eligible subtotal, verified recipient/new-customer eligibility, total/per-recipient redemptions, channel/source and campaign discount budget. BOGO/bundles, store credit, cash payouts and a generic pricing-rule language remain out of v1.

Preserve the original **one entered coupon per cart** rule. Add at most one automatic promotion. Default stacking is disabled: calculate each eligible alternative independently and show the chosen better total; on equal totals prefer the shopper-entered coupon. Configured stacking requires both rules to allow the pairing. Then apply the automatic merchandise reduction, the entered merchandise coupon, and at most one shipping reduction in that order. Cap each allocation at its remaining eligible base and calculate tax last. No reduction applies twice to the same original base by mistake.

Quote includes proposed/applied/rejected offers and reasons. An entered coupon that loses to a better automatic offer remains visible as not applied and consumes no usage. Price changes, discount caps or expired grants invalidate the accepted quote. Sale prices remain the base for this calculation unless a rule explicitly excludes sale-priced lines.

Issue codes through a unique `(issuer, sourceIntentId)` request; retries return the same grant. Coupon strings are random and collision-safe, never predictable from customer/order IDs. Generated codes have server-side audience restrictions; obscurity alone is not a restriction. Code expiry is an absolute UTC instant and does not reset on visits or emails. Usage and discount-budget reservations are atomic with checkout holds and resolve with the existing payment pipeline. Percent offers need a finite per-order cap to bound budget exposure.

Snapshot applied promotion versions, allocated discounts, campaign/source references and net paid amounts on order lines. Refunds use those allocations and never recalculate against today's campaign. Default budget/redemption usage is not replenished after refunds. Bulk generation is bounded, resumable and audited, with private expiring export; pause/revoke unredeemed grants without changing historical orders. Revoke does not silently change a provider session already accepted: coordinate cancellation/requote when safe, otherwise honor the frozen authorized offer and prevent future use.

## Referral lifecycle

Advocate enrolls with a verified customer account. Link `/r/{code}` resolves to a validated internal destination, with no open redirect or buyer identity leakage. Landing views alone create no reward. Store a 30-day first eligible referral touch when the configured attribution storage policy permits; without persistent consent, allow explicit code entry or a transient current-session flow. A valid explicitly entered code overrides a passive touch before final quote confirmation.

Friend identity must be verified before a first-order discount is granted. Unique program+friend identity qualification and an atomic hold prevent simultaneous checkouts both claiming the first reward. Known existing purchasers are ineligible under the default program. Self-referral through identical customer/contact identities is rejected. Do not claim perfect fraud prevention from email or IP alone; masked/shared payment information may be unavailable and no raw card fingerprint is required.

States: `attributed → purchase_pending → qualifying → reward_pending → rewarded`; alternatives `rejected`, `cancelled`, `reversed`. Qualifying net merchandise excludes shipping/tax and subtracts allocated discounts and successful refunds; unresolved refunds/disputes suspend release. Default reward waits 14 days from payment or completed physical fulfillment, whichever is later; digital-only uses payment time. Owner chooses a hold appropriate to the store's return policy before launch.

Recheck after the hold. Issue advocate reward coupon once through pricing's stable issuance API. Per-advocate and program caps reserve capacity atomically at qualification; release cancelled claims, commit rewarded claims. Reward coupon requires the advocate's verified identity and has an absolute expiry. A late refund revokes an unused coupon; an already applied/unknown-payment coupon is flagged for review without rewriting another customer's order, creating negative cash balances or charging stored payment methods. Fraud/chargeback cases can disable new reward issuance while retaining auditable history.

## Urgency, social proof and reviews

Low-stock message uses `available` quantity for the selected tracked physical variant and a configured low-stock threshold, fetched from a short-lived read model. Default freshness TTL 60 seconds; hide the message if it cannot refresh. Digital/untracked products do not show scarce-unit claims. Discount/end-time countdowns use server-provided UTC time and absolute eligibility expiry; reload, new tab or new device never restarts the offer.

Aggregate purchase proof uses net eligible product orders over a stated window, for example “8 orders included this product in the last 7 days,” not fabricated “someone just bought” claims. Default minimum is five qualifying orders in that window; hide lower-volume proof. Store a rebuildable aggregate with maximum five-minute lag, omit private identities/locations and avoid claims of live viewers in v1. Refund/cancellation corrections invalidate aggregates. A partially refunded order counts for a product only while that product retains qualifying quantity and positive net paid merchandise value; refunding all of that product removes its contribution even if other order lines remain. Custom merchant text cannot substitute a fabricated numeric fact.

Reviews are written by verified purchasers, one per customer/product (verified guest identity may claim the same entitlement). Rating 1–5, text, optional disclosed display alias, product and eligibility references; no uploaded review media in v1. Anonymous-to-public display is supported. Publication is moderated for relevance/abuse with reason codes and history. A later refund does not erase a legitimate review: retain purchase verification history, and exclude only documented fraud/test/fabricated eligibility through audited moderation. Public aggregates derive from published genuine reviews. Automated incentivized-review solicitations are outside v1; no reward is conditioned on positive sentiment.

Toasts are nonmodal, dismissible and do not steal focus; honor reduced motion and allow shoppers to disable them for the session. Render one at a time, at least 30 seconds apart, at most three/session; do not replay on every navigation. Exclude checkout/payment/account/download routes and avoid covering cart controls. Campaign priority resolves simultaneous messages deterministically. Activity widgets fail closed without blocking page rendering or checkout.

## Automatic landing hubs and SEO

Hub rules choose finite, meaningful product groups: category + selected attributes, brand, use case or product family. Each rule has normalized intent/group fingerprint, approved section layout, matching query, minimum products, canonical destination and publication mode. Default creation cap: 50 draft candidates/run and 500 active hubs/store. Default minimum three matching published products; editor override for a curated buying guide requires substantive unique content and explicit publication.

Automatic refresh updates live product bindings/availability while retaining editorial copy and existing URLs. Creation defaults to draft. Bounded auto-publication is available only after the merchant explicitly activates a specific deterministic rule whose preview/quality checks pass; AI-generated or materially rewritten editorial text still requires review. Templates provide useful guide content, relevant comparisons and internal links, not a page for every filter permutation. Google identifies scaled low-value content and doorway abuse as spam; the design uses useful-content and duplication checks instead of claiming that generated page volume is an SEO benefit. [Google spam policies](https://developers.google.com/search/docs/essentials/spam-policies).

Route policy:

- Canonical indexable hubs use `/collections/{slug}`; each has unique title, intent, helpful content and a product set or a reviewed editorial purpose. Similar duplicate hubs merge with deliberate redirects.
- Public products use `/products/{slug}`; selected variants use a stable `?variant={publicId}` value with the primary product URL as canonical in the v1 grouped-product model. Preserve variant-specific availability/price in the rendered selection and structured data.
- Marketing/UTM/referral query parameters never create a new canonical URL. Internal navigation and sitemaps use the same canonical URLs.
- Paginated hub/category pages have distinct stable URLs and self-canonicals; page 2 must not falsely canonicalize to page 1. Search results and unapproved facets are noindex and absent from sitemaps; keep canonical semantics truthful to each result set instead of pointing unrelated filters to a generic category.
- `robots.txt` is not an access control and is not a substitute for crawl-visible noindex. Do not block a URL whose noindex directive a crawler must first read. Limit facet links/pagination combinatorics in the UI.
- A temporarily empty hub retains useful editorial content but loses sitemap/index eligibility until reviewed; a permanently removed hub returns 410 or redirects only to a genuinely equivalent replacement. Out-of-stock product pages remain useful product pages; they are not automatically deleted.

Stable URLs, crawlable anchors and consistent canonicals follow [Google's ecommerce URL guidance](https://developers.google.com/search/docs/specialty/ecommerce/designing-a-url-structure-for-ecommerce-sites). Rich-result presentation remains at the search provider's discretion. Use Product/Offer and product-variant data for actual products; only publish rating data visible on the page and derived from eligible reviews. Hubs use collection/list/breadcrumb semantics. [Google product structured-data guidance](https://developers.google.com/search/docs/appearance/structured-data/product).

SEO tools include editable title/description previews, canonical/index controls with guardrails, redirect manager, sitemap/robots preview, broken-link/orphan/duplicate-intent audit, alt-text gaps, and valid structured-data previews. Site verification accepts typed meta token values, never arbitrary script paste. Merchant catalog feed supports validated XML/CSV export, stock/price freshness timestamps, known GTIN/MPN/brand identifiers and missing-field reports; no invented identifiers. Feed export does not create or verify an external merchant account.

## Measurement and attribution

Record minimal first-party events: consented landing/campaign touches, product view, cart change, checkout start, paid order and refund. Browser events can be blocked/spoofed; financial conversion facts come only from committed server events. Respect the store's consent policy before persistent campaign identifiers; no fingerprinting or default third-party pixels. Use allowlisted, bounded UTM fields and never retain recovery tokens or full URLs containing personal data.

Default reporting attribution: last eligible non-direct campaign touch in seven days before checkout acceptance, frozen with the order; direct visits do not replace that touch. Referral qualification has its independent 30-day rules. A recovery conversion requires a valid recovery-restore event within seven days and matching cart lineage; a purchase merely following an email send is not “recovered.” Report overlapping referral/recovery dimensions without summing them as separate extra orders. Show unique paid orders, gross/net merchandise, discounts and refunds; label attributed revenue, not causal uplift.

Email opens are optional and off by default; they are not reliable conversion evidence. Separate provider-accepted sends, delivered mail when feedback is available, link visits and explicit shopper restore actions. Exclude obvious crawler/prefetch traffic from qualified-click reports and show the limits. Proposed raw interaction retention: 90 days; contact consent/suppression and order/reward ledgers follow configured lifecycle policies, with minimal suppression evidence retained to prevent accidental re-enrollment. Marketing metrics cannot be a required dependency of checkout.
