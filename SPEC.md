# MRKTFlow product specification

Revision: 0.3 · October 10, 2026 · Status: audited implementation plan; foundation ready to begin.

The user's product direction is confirmed. Technical choices labeled **proposed default** are concrete starting decisions, not claims of user approval. This request delivers documentation and a coding backlog only.

## 1. Objective

Give independent merchants and developers a focused commerce platform they own and install themselves. The product should offer the integrated store operations expected from a hosted commerce product while retaining the deployment and customization freedom of a self-hosted application. It has no WordPress dependency, mandatory hosted control plane, platform billing, or license server required for selling.

A merchant can install MRKTFlow, configure their business, add physical and digital products, customize a storefront, collect payment, ship orders, deliver downloads, and maintain content without assembling a collection of essential commerce plugins. A developer can build a theme or product presentation using PHP/Blade, HTML, CSS, and Vue without changing commerce internals. An AI-assisted workflow can draft content and layouts within defined contracts.

## 2. Confirmed requirements and working defaults

| Topic | Decision | Status |
| --- | --- | --- |
| Framework | Laravel and Vue | User confirmed |
| Deployment | One independent store per installation | Interpretation of self-hosted install |
| Distribution | Free and open source | User confirmed |
| Product types | Physical and digital; mixed carts | User confirmed |
| Extensibility | PHP/HTML/CSS/Vue templates, content pages, category pages, product templates | User confirmed |
| Shopping tools | Watchlists and side-by-side product comparison | User confirmed |
| AI | Built-in drafts and external template imports | User confirmed |
| Native marketing | Abandoned-cart recovery, urgency/social proof, automatic product hubs, coupon/referral engine and SEO | User confirmed |
| Currency and language | One configured currency and one publishing language per store; translation-ready strings | Proposed default |
| Sales territory | Explicit merchant allowlist of countries; sample store uses US/USD, not a hard-coded platform restriction | Proposed default; launch market unresolved |
| Fulfillment | One stock location and shipping origin; multiple shipments per order | Proposed default |
| Payments | Stripe hosted checkout for immediate card/wallet payments; adapter boundary for future providers | Proposed default; availability must match launch market |
| Shipping | Native rules and manually entered tracking; live carrier quotes/label purchasing later | Proposed default |
| Tax | Explicit merchant-configured tax classes and destination rules; no automatic filing or universal tax compliance claim | Proposed default; market validation required |
| License | MIT candidate; no license text selected or added yet | Open before publication |

“One-time install” describes deployment. Stores still need maintained hosting, backups, security updates, and their chosen payment/email/AI services. External service charges are separate from the free platform.

## 3. Users

- **Owner:** installs the store, controls staff and integrations, configures policies, reviews operational health.
- **Store manager:** manages products, pricing, orders, customers, shipping, and reports within assigned permissions.
- **Content editor:** creates pages, edits visual layouts, uses AI, and publishes only if granted that permission.
- **Fulfillment staff:** sees necessary order/address data, records shipments, handles approved returns; cannot edit payment credentials.
- **Theme developer:** creates trusted theme packages using documented contracts and a development environment.
- **Shopper:** browses, searches, compares, saves products, buys as guest or account holder, tracks orders, downloads purchases.

## 4. First public release: required scope

All rows below are first-release requirements, even where implementation lands in a later milestone. The first checkout demonstration is an internal milestone, not the complete promised product.

| Area | Included |
| --- | --- |
| Installation | Prerequisite checks, secure first-owner setup, settings wizard, persistent install lock, diagnostics, documented update/restore |
| Identity | Separate staff/customer authorization, owner recovery, staff permissions, customer accounts, guest checkout, password reset, staff two-factor authentication |
| Catalog | Physical/digital products, variants/SKUs, prices, sale dates, images, categories, attributes, stock, CSV import/export, reusable typed product schemas |
| Storefront | Responsive theme, homepage, catalog/category/search pages, product detail, cart, checkout, customer account, orders, downloads, saved items, comparison |
| Pricing | Currency-aware money, sale prices, campaign/unique/recipient coupon codes, one entered coupon plus at most one eligible automatic promotion, explicit stacking rules, manual taxes and immutable allocations |
| Checkout | Guest and account checkout, address validation, shipping eligibility, authoritative totals, payment failure/retry, signed webhook processing, receipts |
| Orders | Search/detail/timeline, notes, cancellation, partial/full refunds, line quantities, explicit financial and fulfillment states |
| Physical fulfillment | Stock reservations, partial shipments, tracking links, shipment emails, manual return records and explicit restocking |
| Digital fulfillment | Private file uploads, versioned assets, payment-gated entitlements, expiring download links, download limits, refund revocation |
| Watchlist | Guest/account saves, login merge, optional stock/price alert subscriptions with verified email, unsubscribe |
| Compare | Two to four products or selected variants; typed comparable attributes, different-value emphasis, unit consistency |
| Themes and content | Native default theme, typed section editor, menus, ordinary content pages and articles, category/product layout assignment, revisions, preview, publish, rollback |
| AI | Optional store-owned credentials, page/section/copy/product-schema drafts, validation, diff/preview, explicit apply/publish, usage budget and failure recovery |
| Imports | Safe data-only template import in admin; trusted code-theme installation through developer deployment |
| Operations | Transactional email, audit log, paid-sales/refund reports, exports, failed-job visibility, backup/update/restore runbooks |
| Native marketing | Recovery sequences and incentives, verified opt-in email campaigns, signup forms, offer banners, real stock/expiry urgency, aggregate purchase proof, moderated verified-purchase reviews |
| Referral engine | Share links/codes, first-friend offer, delayed advocate coupon rewards, abuse review, caps and refund reversal |
| Product landing hubs | Rule-generated product collections/guides, live membership, bounded publication, AI-assisted editorial drafts and internal links |
| SEO and accessibility | Server-rendered content, metadata editor/audits, canonical/index controls, redirects, sitemap, product/variant/review structured data, catalog feeds and accessible theme/admin |

## 5. Explicit first-release exclusions

Multi-tenant hosted SaaS, multi-vendor marketplaces, WordPress compatibility, subscriptions/recurring billing, POS, gift cards/store credit, loyalty points, B2B quotations, multi-currency checkout, translated catalog management, multi-warehouse allocation, bundles mixing physical/digital fulfillment inside one SKU, live carrier rates, label purchasing, tax filing, automated tax-service integration, and a third-party extension marketplace. Marketing exclusions: SMS/push messaging, ad buying, third-party pixel management, cash affiliate payouts, gift-card/store-credit rewards, BOGO/bundle pricing, review media and a general-purpose visual automation builder.

These are roadmap candidates, not hidden dependencies. V1 supports a basket containing separate physical and digital line items. A digital product may contain several downloadable files. External AI can author full code themes, which use the trusted developer installation path.

## 6. Complete reference journeys

1. **First sale:** owner installs → configures country/currency/email/payment/shipping/tax/policies → creates a category and physical product → previews theme → publishes → guest pays → stock commits → receipt sends → staff records shipment → customer opens tracking.
2. **Digital sale:** owner uploads a private file → attaches a version to a digital variant → customer pays → entitlement appears → customer accesses verified order/download area → short-lived link delivers the allowed file → full refund revokes future access.
3. **Mixed cart:** shopper adds a physical item and digital item → only physical quantities/weight enter shipping → tax evaluates each line's class → payment succeeds once → downloads unlock immediately → physical fulfillment remains pending.
4. **Product research:** guest saves a product and compares compatible products → registers or signs in → saved items merge without duplicates → opts into a back-in-stock email → later follows the alert to the live product.
5. **Content authoring:** editor chooses a page/category/product layout → writes a prompt or imports a supported template → receives a validated draft → reviews mobile/desktop previews and content changes → authorized publisher publishes → prior revision remains recoverable.
6. **Developer extension:** developer adds a camera product schema and a Blade/Vue product template → compatibility checks pass → owner assigns it → new attributes appear in editor, filters, detail page, and comparison without a core fork.
7. **Recover a cart:** eligible shopper leaves a basket → native sequence schedules a reminder → shopper explicitly restores current items → any recipient-bound offer is validated → payment stops remaining recovery steps → report links the paid order once.
8. **Refer a friend:** verified customer shares a link → eligible friend verifies and purchases with their offer → the qualification/return hold passes → advocate receives one coupon reward → refund/dispute events recheck unused rewards.
9. **Publish a product hub:** merchant previews a grouping rule → reviews useful guide copy and product matches → publishes a canonical hub → membership refreshes with catalog changes → SEO audit flags empty/duplicate/broken pages.

## 7. Release acceptance

- A documented installation on the supported reference server reaches a test purchase in at most 30 minutes after infrastructure and provider credentials are available. Measure with someone other than the implementer.
- Physical-only, digital-only, and mixed carts pass payment, cancellation, refund, and recovery tests.
- Concurrent purchases of the last tracked unit cannot both reserve it. Duplicate payment events cannot create duplicate orders, allocations, entitlements, or queued receipt intents.
- A downloadable file cannot be fetched anonymously, through a guessed path, after entitlement revocation, or for a different customer's order.
- A merchant creates a typed product schema, compares its attributes, and changes its visual template without changing inventory or prices.
- Data-only imports and built-in AI never execute supplied PHP, Blade directives, JavaScript, or Vue template expressions.
- A developer-installed theme can include PHP/Blade and compiled Vue assets. The UI clearly identifies this as executable trusted code.
- Recovery stops at the final dispatch check after conversion/opt-out; retries do not duplicate send intents or incentives. Restored links reveal no private checkout data.
- Coupon/referral limits survive concurrent checkouts; net qualifying spend, reward holds and later refunds are correctly reconciled.
- Urgency and social proof derive from real current data; toasts respect route/frequency/accessibility limits, and reviews are verified/moderated.
- Generated hubs meet publication/indexing rules; sitemaps, canonical URLs, feeds and structured data agree with visible products and reviews.
- All storefront and admin journeys have usable loading, empty, error, permission-denied, and success states; the reference theme meets the quality targets.
- Upgrade and restore rehearsals retain products, orders, private files, settings, and theme revisions.
- Every mandatory module criterion and launch checklist in [quality and operations](docs/quality-and-operations.md) passes. No unimplemented adapter is presented as a working integration.

## 8. Engineering contract

Use the stack, commands, structure, conventions, and test strategy defined in [architecture](docs/architecture.md) and [coding handoff](docs/coding-handoff.md). Each module specification adds its own requirements and tests. [Commerce rules](docs/commerce-rules.md) are normative for payment, money, stock, and entitlement behavior. [Marketing rules](docs/marketing-rules.md) extend the promotion, recovery, referral and SEO contracts without replacing those commerce invariants.

**Always:** enforce authorization and validation server-side; preserve immutable commerce history; make external side effects retry-safe; include migrations and focused tests with behavior changes; document contract changes; keep secrets out of source and logs.

**Resolve with the owner before changing:** product scope, license, target sales market, supported hosting baseline, new paid-service dependency, or the ability to execute untrusted templates. Routine implementation details inside the agreed design do not require repeated approval.

**Never:** edit vendor files, make AI output executable automatically, trust browser prices, mark orders paid from a return URL, erase financial history to hide failures, weaken required tests, or operate on the unrelated parent repository.

## 9. Open decisions

[Open decisions](docs/open-decisions.md) separates choices that affect early implementation from launch-only configuration. The project can begin with the proposed defaults when coding is requested; launch-market tax/payment fit, licensing, and actual provider accounts must be resolved before public distribution or real sales as specified there.
