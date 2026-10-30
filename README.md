# MRKTFlow

A free, open-source, self-hosted commerce platform built with **Laravel** and **Vue**. One installation runs one independent store selling physical and digital products, with native shipping, secure downloads, watchlists, comparisons, extensible themes, and built-in marketing/SEO tools.

- **No WordPress.** Commerce is a first-class Laravel application.
- **No mandatory platform SaaS.** Merchants install on infrastructure they control.
- **Physical + digital (+ mixed carts)** in the first public release.
- **Server-rendered storefront** so search engines and assistants can read real product pages.

Repository: [github.com/juan-tamayo/mrktflow](https://github.com/juan-tamayo/mrktflow)

> **Project state (October 2026):** secure foundation and catalog/cart/pricing/checkout core are substantially implemented and locally verified. The unopened storefront and administration still return HTTP 503 until install/owner completion opens them. Hosted Quality CI evidence and live Stripe sandbox proof are still incomplete in places. **This is not a production-ready store for real sales yet.**

---

## Start here

1. [SPEC.md](SPEC.md) — product scope, users, first-release definition, exclusions.
2. [CAPABILITY-MAP.md](CAPABILITY-MAP.md) — fifteen module specifications.
3. [Architecture](docs/architecture.md), [data model](docs/data-model.md), [template contracts](docs/template-contracts.md).
4. [Implementation plan](tasks/plan.md) and [ordered backlog](tasks/todo.md).
5. [Readiness audit](docs/readiness-audit.md) and [coding handoff](docs/coding-handoff.md) when implementing.
6. [Native marketing and SEO](docs/marketing-rules.md) — recovery, coupons/referrals, hubs, crawl/structured-data contracts (authoritative).

For installation, isolated databases, and quality commands, see [development](docs/development.md) and [continuous integration](docs/continuous-integration.md). Prefer `bin/php`, `bin/composer`, and `bin/npm` so runtime selection stays consistent.

### For LLMs and coding agents

Treat these as the machine-readable product brief, in this order:

| Priority | Document | Why |
| --- | --- | --- |
| 1 | [SPEC.md](SPEC.md) | Confirmed scope, release acceptance, hard exclusions |
| 2 | [docs/marketing-rules.md](docs/marketing-rules.md) | SEO URL policy, hubs, structured data, attribution |
| 3 | [CAPABILITY-MAP.md](CAPABILITY-MAP.md) + module specs | Module ownership and requirements |
| 4 | [tasks/todo.md](tasks/todo.md) | What is done vs still open (checkboxes + evidence notes) |
| 5 | [docs/commerce-rules.md](docs/commerce-rules.md) | Money, inventory, payments, refunds, entitlements |
| 6 | This README | Orientation only; do not invent features beyond SPEC/todo |

Storefront content is designed to be **server-rendered HTML** with stable canonical URLs so crawlers and assistants see the same product facts a shopper sees. Do not assume a shipped `llms.txt` or public marketing site yet; when those exist they should summarize the same contracts rather than diverge from SPEC.

---

## Confirmed direction

- Laravel + Vue; commerce independent of WordPress.
- Install on owner-controlled infrastructure; no mandatory platform subscription.
- Free/open-source distribution. Exact license is a release decision; **MIT is the proposed default** (not finalized — see [open decisions](docs/open-decisions.md)).
- Physical products, digital products, and mixed carts in the first public release.
- PHP/Blade, HTML, CSS, and Vue developer themes; reusable product data schemas and visual templates.
- Built-in AI-assisted authoring plus imports of externally authored templates; merchants preview and explicitly publish; the store works without an AI account.
- Native recovery campaigns, urgency/social proof, verified reviews, automatic product hubs, coupon campaigns, and referral rewards are **first-release** features.

---

## Current progress (honest snapshot)

Status is derived from [tasks/todo.md](tasks/todo.md). Prefer that file for task-level evidence; this table is a map.

| Milestone | Focus | Status |
| --- | --- | --- |
| **M0** Foundation | Repo, Laravel/Vue toolchain, tests, installer/settings, jobs/health, media, staff session, theme/payment/AI spikes | Mostly done locally; **T004b** hosted CI merge evidence still pending; **T009** customer identity still open |
| **M1** Catalog | Physical/digital products, variants, categories, search/filters, CSV import/export, public contracts | Done locally (T013–T024); hosted Quality often pending per task notes |
| **M2** Cart & quotes | Cart ownership/merge, money/pricing, coupons holds, shipping/tax quotes | Done locally (T025–T033); hosted Quality pending in places |
| **M3** Payment & orders | Checkout session, Stripe adapter contracts, refunds, order timeline | Largely done locally (T034–T043); **live Stripe sandbox** not executed without credentials; **T044–T045** still open |
| **M4** Fulfillment | Partial shipments, tracking, returns/restock, digital entitlements/downloads | **Not started** (T046–T054) |
| **M5** Watch & compare | Saved products, stock/price alerts, typed comparison | **Not started** (T091, T055–T060) |
| **M6** Themes & content | Theme registry, section editor, pages/articles, revisions, SEO/redirects/cache | **Not started** (T061–T072) |
| **M7** Built-in AI | Config, bounded generation, validation, diff/preview/apply | **Not started** (T073–T078) |
| **M9** Native marketing | Campaigns, recovery, coupons stacking, urgency, proof, reviews, attribution | **Not started** (T092–T105) |
| **M10** Hubs, referrals, SEO tooling | Landing hubs, structured data, feeds, referral program, growth reporting | **Not started** (T106–T120) |
| **M8** Ops & release | Transactional email, reports, exports, retention, install/update/restore polish | **Not started** (T079–T090); runs after marketing milestones in the ordered plan |

Until the store is fully installed and opened, anonymous storefront routes remain closed (HTTP 503). That is intentional.

---

## What’s still missing for first public release

Everything below is **required for v1** per [SPEC.md](SPEC.md) §4, even when it lands in a later milestone. Use this as the gap list.

### Platform & identity
- Finish customer accounts / guest ownership (**T009**) and remaining privileged 2FA/reauth polish called out in identity evidence.
- Hosted Quality CI green on `main` with required checks (**T004b**).
- Install lock completion, owner recovery, staff invitations/permissions end-to-end.

### Commerce core still open
- External refund/dispute reconciliation and checkout recovery browser gate (**T044–T045**).
- **Live Stripe sandbox** evidence (charges, webhooks, capture races, refunds) — local adapter work exists; provider proof does not without test credentials.
- Physical fulfillment: partial shipments, tracking emails, returns with deliberate restock (**T046–T048**).
- Digital fulfillment: entitlements, verified download area, streaming grants, revocation, asset updates, mixed-order gate (**T049–T054**).

### Shopping tools
- Shared contact preferences / suppression primitives (**T091**).
- Watchlists, verified alerts, typed compare UI (**T055–T060**).

### Themes, content, AI
- Default theme system slots, section editor, pages/articles/nav, product/category layouts, revisions, preview/publish/rollback (**T061–T069**, **T071–T072**).
- Built-in AI drafting with budgets, validation, and explicit apply (**T073–T078**).

### Native marketing, hubs, SEO product features
- Campaigns, coupon batches/auto offers, abandoned-cart recovery, urgency, social proof, moderated reviews, first-party attribution (**T092–T105**).
- Product landing hubs, structured data, catalog feeds, site verification, referrals, funnel reporting (**T106–T120**).
- Merchant SEO controls: metadata editor, redirects, sitemap/robots preview, audits (**T070**, **T108–T110**).

### Operations & launch blockers
- Transactional email, sales/refund reports, exports, retention, backup/update/restore runbooks (**T079–T090**).
- Open decisions that must be resolved before public distribution or real sales: **license text**, **launch market** (tax/payment fit), provider accounts, sender/domain auth, referral economics, maintainer/release policy — see [docs/open-decisions.md](docs/open-decisions.md).

---

## Roadmap ideas (explicitly not v1)

These are [SPEC.md](SPEC.md) §5 exclusions and natural follow-ons. They are **candidates after first public release**, not hidden dependencies and not promises.

| Area | Ideas |
| --- | --- |
| Commerce depth | Subscriptions / recurring billing, gift cards & store credit, loyalty points, BOGO/bundles, B2B quotes, multi-currency checkout |
| Ops & logistics | Live carrier rates, label purchasing, multi-warehouse allocation, automated tax filing / tax-service integration, POS |
| Platform shape | Multi-tenant hosted SaaS, multi-vendor marketplaces, third-party extension marketplace, WordPress compatibility |
| Content & locales | Translated catalog management, richer visual automation builder, review media uploads |
| Marketing channels | SMS / push messaging, ad buying tools, third-party pixel management, cash affiliate payouts |
| Digital extras | License keys, DRM, external URL delivery, streaming beyond current download grants |
| Discoverability | Public docs site, `llms.txt` / agent-readable product card, demo store, package registry listing |

---

## SEO, crawlability, and LLM-friendly storefront design

Authoritative rules live in [docs/marketing-rules.md](docs/marketing-rules.md). Summary for humans and agents:

### URL and index policy
- Products: `/products/{slug}`; variants via stable `?variant={publicId}` with the **primary product URL as canonical** in the v1 grouped-product model.
- Hubs/collections: `/collections/{slug}` — unique title, intent, helpful content, and a real product set (or reviewed editorial purpose).
- Marketing/UTM/referral query params **never** create a new canonical URL.
- Paginated hub/category pages use distinct stable URLs and **self-canonicals** (page 2 does not pretend to be page 1).
- Search results and unapproved facets are **noindex** and stay out of sitemaps.
- Empty hubs can keep useful editorial copy but lose sitemap/index eligibility until reviewed; removed hubs return **410** or redirect only to a genuinely equivalent URL.
- `robots.txt` is not access control and must not block URLs whose `noindex` a crawler needs to read first.

### Structured data and feeds
- Emit Product/Offer (and variant) structured data that matches **visible** page content.
- Publish review aggregate markup only from **eligible, moderated, verified-purchase** reviews shown on the page.
- Hubs use collection/list/breadcrumb semantics — not fake Product markup.
- Catalog feeds: validated XML/CSV export with stock/price freshness, known GTIN/MPN/brand when present, and missing-field reports — **never invent identifiers**.
- Site verification accepts typed meta tokens only (no arbitrary script paste).

### Landing hubs (anti-spam by design)
- Rule-generated collections/guides with live membership refresh.
- Defaults: draft creation, caps on candidates/active hubs, minimum matching products.
- Bounded auto-publication only after an explicit merchant-activated deterministic rule passes quality checks.
- AI-written editorial copy still requires human review before publish.
- Goal is useful guides and internal links — **not** a page for every filter permutation (doorway/scaled thin content is out of scope).

### Measurement
- Consent-aware **first-party** events and UTM allowlists.
- No fingerprinting and no default third-party pixels in v1.
- Financial conversion facts come from **committed server events**, not browser claims.
- Default reporting: last eligible non-direct campaign touch in seven days before checkout, frozen with the order.

### Why this matters for LLMs
Assistants summarizing or recommending products should prefer the canonical product/hub HTML and the structured data on those pages. This README + SPEC describe the **platform**; a merchant’s live store HTML is the source of truth for that store’s catalog once the storefront is open.

---

## Specification index

| Document | Purpose |
| --- | --- |
| [Product specification](SPEC.md) | Users, scope, business rules, definition of release |
| [Capability map](CAPABILITY-MAP.md) | Index of the fifteen module specifications |
| [Architecture](docs/architecture.md) | Stack, structure, runtime, coding conventions |
| [Data model](docs/data-model.md) | Entities, ownership, constraints, history |
| [Commerce rules](docs/commerce-rules.md) | Money, inventory, payments, refunds, digital entitlements |
| [Native marketing and SEO](docs/marketing-rules.md) | Recovery, coupons/referrals, proof/reviews, hubs, attribution |
| [Interface contracts](docs/interfaces.md) | Routes, response shapes, service boundaries |
| [Template contracts](docs/template-contracts.md) | Themes, sections, content, product schemas, AI output |
| [Experience map](docs/experience-map.md) | Admin and customer screens and journeys |
| [Quality and operations](docs/quality-and-operations.md) | Security, tests, performance, install, recovery |
| [Decision records](docs/decisions/README.md) | Reasons behind architecture choices |
| [Readiness audit](docs/readiness-audit.md) | Audit findings and phase gates |
| [Open decisions](docs/open-decisions.md) | Defaults and deadlines for remaining choices |
| [Requirements traceability](docs/traceability.md) | Module requirements ↔ backlog |
| [Research sources](docs/sources.md) | Official references |

---

## Commands

See [development](docs/development.md) for installation, isolated database provisioning, running the app, and all quality checks. See [continuous integration](docs/continuous-integration.md) for the workflow and pending hosted-run evidence.

```bash
bin/composer install
bin/npm install
bin/php artisan test
bin/npm run build
bin/npm run test:unit -- --run
# browser smoke (isolated DB): bin/npm run test:e2e -- --project=chromium
```

---

## Workspace notes

- Default branch: `main` on GitHub (`juan-tamayo/mrktflow`).
- Implementation evidence historically landed on feature branches / `codex/foundation`; check [tasks/todo.md](tasks/todo.md) for per-task branch/PR notes.
- Environment files, local database data, dependencies, build artifacts, and browser traces are gitignored.
- Do not operate on unrelated parent directories; this repository is the only worktree for MRKTFlow.

## License

Distribution license is **not finalized**. MIT is the working candidate ([D02](docs/open-decisions.md)). Do not assume SPDX terms until a `LICENSE` file is added for public packaging.
