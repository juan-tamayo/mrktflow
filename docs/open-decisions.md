# Decisions and assumptions register

Prepared October 5, 2026; readiness review October 10, 2026. These are product decisions, not missing work in the specification. Defaults let engineering begin when coding is requested. Items tied to selling or publication must be resolved before that stage.

| ID | Choice | Working default | Resolve by | Consequence if changed |
| --- | --- | --- | --- | --- |
| D01 | Public name and branding | MRKTFlow, inferred from folder name | Before public release | Rename assets/docs; no domain impact |
| D02 | Open-source license | MIT candidate; final license not selected | Before public repository/package publication | Distribution terms and dependency review |
| D03 | First merchant market | Country allowlist; US/USD demo only | Before tax/payment acceptance and real sales | Address formats, taxes, provider availability, policies |
| D04 | Currency/languages | Single configured currency and publishing language per store | Before catalog foundation | Multiple currencies/languages substantially expand scope |
| D05 | First payment provider | Stripe hosted Checkout, immediate methods | Before payment adapter implementation | Substitute provider behind contract if unavailable in market |
| D06 | Shipping depth | Native zone/table/flat/free/pickup rules, manual tracking | Before shipping milestone | Live quotes and labels need provider selection and more failure states |
| D07 | Supported hosts/database | Linux, PHP 8.4, MySQL 8.4, worker + scheduler | Bootstrap | Supporting MariaDB/PostgreSQL/shared-host cron-only needs separate verification |
| D08 | AI provider/model | One adapter selected after schema-output spike; store-owned key | AI spike | Cost, availability, data terms, model compatibility |
| D09 | Watchlist meaning | Saved products plus opt-in stock/price alerts | Shopping milestone | Saved-only mode would reduce scope; no price scraping |
| D10 | Digital limits | Version-pinned downloads, default 5 started transfer grants, no entitlement expiry | Digital milestone | Streaming, license keys, DRM, external URL delivery are separate features |
| D11 | Built-in visual editor | Structured sections and token controls | Theme milestone | Arbitrary freeform editor expands accessibility/security/compatibility work |
| D12 | Maintenance governance | Tagged releases, changelog, supported release policy | Before public release | Maintainer ownership and release cadence must be assigned |
| D13 | Recovery channels/cadence | Email; 1h/24h/72h with verified purpose opt-in and contact caps | Marketing setup | SMS/push or alternate cadence adds provider/policy work |
| D14 | Referral offer values | Merchant-configured friend/advocate coupons; 30-day touch, 14-day qualification hold | Before activating a referral program | Reward economics/return policy must match actual store; no arbitrary monetary amount presumed |
| D15 | Coupon stacking | One entered code plus at most one automatic offer; best total by default, explicit mutual stacking | Promotion milestone | More combinatorial offers need a larger pricing contract |
| D16 | Hub automation | Draft creation and live membership refresh; explicit bounded rule opt-in for auto-publication; AI prose reviewed | Hub setup | Unbounded generated page publication is outside the design |
| D17 | Marketing measurement | Consent-aware first-party attribution; 7-day campaign/recovery reporting and 90-day raw events | Before campaign activation | Third-party pixels/longer retention require an explicit product/policy change |
| D18 | Social proof | Aggregated real paid-order counts and moderated verified-purchase reviews | Marketing setup | Individual buyer identity/location and live viewer counters excluded |

## Confirmed by the user

Physical **and** digital products; free/open-source distribution; built-in AI **and** external template imports; Laravel/Vue; installable independent commerce; shipping, watchlist, comparison, and product templates.

Expanded direction confirmed: native abandoned-cart recovery, urgency and social-proof tools, automatic product landing hubs, coupon/referral engine and marketing-ready SEO. Added to first-release scope. Recovery cadence, reward economics, tracking policy and sender/provider setup remain concrete proposed defaults/configuration decisions.

## Product interpretation

“Product template” covers both a reusable **product schema** (fields and comparable attributes) and a **visual template** (layout). Neither controls prices, stock, permissions, payment state, or download authorization.

“AI templates” has two paths: built-in AI/data-only imported layouts use existing validated sections; custom PHP/Vue output from an external AI tool is a trusted code package reviewed and deployed by a developer. V1 does not offer one-click execution of arbitrary AI-generated source code from the admin.

## Decisions by coding phase

- **Start M0 now when coding is requested:** retain Laravel 13, PHP 8.4, MySQL 8.4, Vue 3/TypeScript, one store/currency/language, database queues and trusted developer themes. Exact compatible package pins and local runtime provisioning are T002/T003 deliverables, not completed checks.
- **Before payment sandbox completion:** supply a test provider account and confirm the intended merchant market/provider fit (D03/D05). Fixtures unblock independent development, but cannot satisfy the sandbox gate.
- **Before tax acceptance:** validate D03 market rules. US/USD remains demo data only; do not assume a single manual rate handles every intended location or digital sale.
- **Before AI live-adapter completion:** choose/configure the provider/model and cost cap (D08). The store and manual templates continue without AI.
- **Before public release or campaigns:** finalize license/governance, sales policies, sender setup/feedback support, referral economics and tracking/retention policy. These do not block foundation implementation.
