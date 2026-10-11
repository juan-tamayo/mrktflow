# Interface contracts

Version 1 proposal. HTTP examples are same-origin application endpoints, not a promise of a public headless API in v1. Use Laravel resource objects and explicit DTOs. Generate detailed OpenAPI/types as each endpoint is implemented; this inventory defines the initial surface and ownership.

## HTTP conventions

- Storefront HTML routes under `/`; staff HTML/Inertia under `/admin`; JSON storefront actions under `/store-api/v1`; signed provider events under `/webhooks/{provider}`.
- Session cookies: secure in production, HttpOnly and appropriate SameSite. State-changing browser routes enforce CSRF. Narrow exceptions are verified provider webhook routes and the dedicated signed-token one-click unsubscribe POST, which must work from mail providers without browser cookies. Preference editing, opt-in, cart restoration and all other browser actions still enforce CSRF. GET never changes consent.
- JSON success: `{ "data": ... }`; paginated lists additionally contain `meta` with `page`, `pageSize`, `totalItems`, `totalPages`. Default size 24, maximum 100. Stable secondary sort by ID; sortable/filterable fields allowlisted.
- JSON failure: `{ "error": { "code": "OUT_OF_STOCK", "message": "An item is no longer available.", "fields": {}, "requestId": "..." } }`. No raw SQL, exception, provider secret or private file path.
- 401 authentication, 403 permission, 404 absent or inaccessible private object, 409 stale revision/in-flight conflict, 422 semantic validation or idempotency payload mismatch, 429 rate limit, 503 unavailable dependency. Framework CSRF/session expiry responses are normalized for JSON clients; admin Inertia uses its supported error/redirect protocol.
- Date/time strings ISO 8601 UTC. Money object: `{ "amount": 1299, "currency": "USD", "exponent": 2 }`; numeric amounts must be safe integers and provider limits are validated separately; unit fields explicit. Public IDs opaque strings. Empty results `[]`, optional missing value `null` when defined by schema.

## Principal routes

| Method/path | Principal and input | Output / behavior |
| --- | --- | --- |
| GET `/store-api/v1/products` | Public; q/category/filter/sort/page | Published product summaries; visibility filters applied before counts |
| GET `/store-api/v1/products/{id}` | Public product ID | Public product/variant view; never private file keys |
| GET `/store-api/v1/cart` | Customer or guest session | Authoritative cart revision, lines and subtotal |
| POST `/store-api/v1/cart/lines` | variantId, desired absolute quantity, expected cart revision | Create or replace that variant's quantity; never increment implicitly; response new cart revision, 409 if stale |
| PATCH/DELETE `/store-api/v1/cart/lines/{id}` | Owned line, expected cart revision | Updated cart; 409 if stale |
| POST `/store-api/v1/checkout-quotes` | Owned cart revision, addresses, coupon, shipping method | Breakdown, eligible methods, quote ID/hash and expiry |
| POST `/store-api/v1/checkout-sessions` | quoteId, accepted revision, Idempotency-Key | Unique cart/revision order reference, attempt state, provider redirect or free-order status; a second key cannot create another payable order |
| GET `/store-api/v1/checkout-sessions/{id}` | Owning session/customer | Pending/confirmed/failed state; no other customer data |
| POST `/webhooks/stripe` | Raw signed provider payload | 2xx after durable acceptance; reject invalid signatures, retry on persistence failure |
| POST/DELETE `/store-api/v1/wishlist/items/{variantId}` | Guest/customer ownership | Idempotent save/remove |
| POST `/store-api/v1/watch-subscriptions` | Saved variant, alert type, email/consent | Verification required before notification eligibility |
| POST `/store-api/v1/comparisons` | Two to four selected product/variant IDs | Typed rows, incompatibility warnings, public availability |
| POST `/account/download-entitlements/{id}/grants` | Verified customer or verified guest-order session; Idempotency-Key | Five-minute start token and owner-only grant-status URL; allowance spent only on successful start |
| POST `/downloads/{token}/start` | Start token + authorized owner/session + CSRF; current line eligibility | Atomically consume token/allowance once, then redirect only to a protected transfer-session route; GET/prefetch never consumes |
| GET `/account/download-grants/{id}` | Owner/session authorization | Current grant/active transfer status for lost-response recovery; no second allowance consumption |
| GET `/download-transfers/{id}` | Bound owner/session; active transfer; Range optional | Protected stream/resume within one-hour session; recheck current line eligibility/revocation on each request |
| POST `/admin/orders/{id}/refunds` | Refund permission; line quantities/amounts/reason; key | Persisted refund intent, pending or confirmed provider state |
| POST `/admin/orders/{id}/shipments` | Fulfill permission; quantities/carrier/tracking; key | Shipment with atomically applied quantities/stock movement |
| POST `/admin/content/{kind}/{id}/publications` | Publish permission, revision | Atomic publish result or conflict |
| POST `/admin/template-imports` | Content-edit permission; data package | Validation report and draft import |
| POST `/admin/ai-runs` | AI-author permission; bounded prompt/context/base revision/key | 202 run ID and polling URL |
| GET `/admin/ai-runs/{id}` | Authorized staff | State/validated candidate/warnings/usage; redacted provider details |

Admin catalog, customers, discounts, shipping and settings use conventional resource routes with explicit policies and request schemas. Theme install is developer tooling, not an HTTP upload endpoint. Staff actions never consume public product models as authorization evidence.

## Idempotency contract

Required for checkout creation, payment attempts, refunds, shipments, digital grants, imports and AI runs. A client generates one key per user intent and retains it across retries. Scope = principal/session + operation. Atomically claim unique key with normalized request hash before side effects.

Also require stable intents for coupon batch/grant issuance, recovery claims, campaign activation/send intents and referral qualification/reward decisions. Financial coupon/referral dedup records follow order/reward retention rather than the ordinary draft TTL.

Same key/same body returns the recorded outcome. Same key/different body returns 422. In-flight duplicate returns 409 with a status/retry hint; long-running new work returns 202 with a status URL. Unknown external outcomes cannot be retried with a new key by background jobs. Order-linked keys survive as long as financial history; durable event dedup survives payload purging. Nonfinancial draft intent records default to 30 days and declare expiry to clients.

## Provider and domain contracts

| Owner | Interface | Input → output; errors |
| --- | --- | --- |
| catalog | `CatalogReader` | IDs + visibility context → public product/variant snapshots; not_found/unpublished |
| catalog | `InventoryAllocator` | intent reference + variant quantities → hold/commit/release/ship receipts; insufficient_stock/conflict |
| pricing | `PriceCalculator` | immutable priced line inputs + destination/coupon/time → deterministic allocations and totals; invalid_coupon/tax_coverage_missing |
| shipping | `ShippingQuoter` | destination + physical line weights/dimensions/classes + discounted physical subtotal → eligible method quotes; no_service/missing_measurement |
| checkout-orders | `PaymentGateway` | attempt/order reference + money + return URLs → checkout session/status; supports query/cancel/refund/verify-event; rejected/unavailable/unknown |
| checkout-orders | `OrderEligibilityReader` | order-line references → current paid/free-completed, refundable, held, cancelled and digital-access facts with revision; used to reject stale-event effects |
| fulfillment | `EntitlementIssuer` | paid order-line/version snapshots → unique entitlements; unpublished_asset/conflict |
| themes-content | `ThemeRegistry` | installed manifest + requested route/template → validated render definition/fallback; incompatible/missing_asset |
| themes-content | `ContentPublisher` | entity/base revision/candidate → draft or published revision; conflict/invalid_reference |
| ai-authoring | `AiAuthoringProvider` | bounded prompt + schemas + public context + budget → candidate document and usage; schema_error/rate_limited/unavailable/unknown |
| platform | `EventOutbox` | committed aggregate event → durable delivery intents and consumer receipts |
| platform | `DeliveryPreferences` | verified contact/purpose/source → versioned grant/suppression check; verification_required/suppressed |
| pricing | `PromotionIssuer` | issuer/source intent + rule/recipient/budget → stable grant; exhausted/invalid_recipient/conflict |
| marketing | `RecoveryCoordinator` | cart activity/payment facts → episode/schedule/claim; stopped/ineligible/expired |
| marketing | `OnsiteSignalReader` | public product/variant/route/time → fresh stock/offer/proof/review DTO; unavailable means omit |
| referrals | `ReferralQualifier` | verified friend + frozen attribution + financial facts → held/qualified/reversed reward intent |
| seo-hubs | `HubGenerator` | bounded rule + catalog revision → preview/membership/publication candidate; duplicate_intent/quality_failed |

Infrastructure adapters implement these contracts. The domain calls a gateway by capability; Stripe-specific names do not become order states. Pass typed snapshots instead of requiring provider modules to import consumer models.

## Event envelope

Fields: `eventId`, `type`, `version`, `occurredAt`, `aggregateType`, `aggregateId`, `aggregateRevision`, `correlationId`, `payload`. Events describe completed facts. Consumers support duplicate delivery and query authoritative state if order matters.

Initial events: `CatalogProductPublished`, `VariantPriceChanged`, `StockAvailabilityChanged`, `OrderPaid`, `OrderCancelled`, `RefundSucceeded`, `PaymentDisputed`, `ShipmentCreated`, `EntitlementRevoked`, `ContentPublished`, `ThemeActivated`. Payloads contain identifiers and minimum necessary immutable facts; customer addresses do not flow into analytics or AI events.

Add `CartActivityChanged`, `CartRestored`, `PurposeGrantChanged`, `RecipientSuppressed`, `CampaignActivated`, `CouponGrantIssued`, `ReviewPublished`, `ReferralQualified`, `ReferralRewardReversed`, `HubMembershipRefreshed` and `HubPublished`. Financial events stay authoritative; browser activity cannot assert a paid conversion.

## Marketing and SEO routes

| Method/path | Principal/input | Output / behavior |
| --- | --- | --- |
| POST `/store-api/v1/marketing/signups` | Email, explicit purpose/notice revision, bounded source; rate limit | Pending verification, no contact-existence disclosure |
| GET `/email/preferences/{token}` | Scoped opaque token | Preference UI; GET does not change consent |
| POST `/email/preferences/{token}` | Scoped token and requested purposes | Idempotent opt-out; new opt-in requires verification evidence |
| POST `/email/unsubscribe/{token}` | Signed one-click scope | Unsubscribe without login; scanner GET never mutates preferences |
| GET `/recover/{token}` | Bounded opaque token | Generic continue page, noindex/no-store; no cart contents or token consumption |
| POST `/store-api/v1/recovery-claims` | Token + session/CSRF + key | Revalidated product-only cart restoration; no customer authentication |
| GET `/store-api/v1/onsite-signals` | Public product/variant and permitted route | Fresh stock/offer/aggregate proof; timestamps/expiry, no personal payload; recipient offers resolved only from authorized session context |
| POST `/store-api/v1/product-reviews` | Verified eligible purchaser/product/rating/text | Pending moderation; ownership and duplicate check |
| GET `/r/{code}` | Public referral code, validated internal destination | Referral landing; attribution storage follows configured consent policy |
| POST `/store-api/v1/referral-claims` | Verified contact, explicit code + cart revision + key | Pricing-owned friend grant/attribution; no reward for click alone |
| GET `/account/referrals` | Verified customer | Own share links/rewards, no private friend order details |
| GET `/collections/{slug}` | Public published hub | Server-rendered hub and accurate canonical/index metadata |
| POST `/admin/marketing/campaigns/{id}/activations` | Activate permission, expected revision + key | Validated schedule or actionable readiness errors |
| POST `/admin/seo-hubs/rules/{id}/previews` | SEO-edit permission/version | Bounded candidate report; no automatic AI publication |
| POST `/admin/referrals/review-cases/{id}/decisions` | Reward-review permission, reason/revision + key | Audited decision; stable reward issuance |

Admin batch-generation, hub-publication and feed-export actions follow existing revision/idempotency/private-export rules. Delivery-feedback webhooks require provider signatures and a durable inbox. Public catalog feed URLs are opt-in and expose only public product fields. Verification inputs accept typed meta tokens, not arbitrary scripts. Recovery/referral destinations are internal and allowlisted; no open redirects.

## Installation HTTP contract

- `GET /setup`: 404 when no active CLI secret or installation is complete; otherwise an Inertia `Install` page. Locked responses contain no settings, region choices or prerequisite report.
- `POST /setup/access`: `{setup_secret: string}`. Browser validation errors redirect to setup without flashing the secret; JSON validation errors use 422. Valid access rotates the session, stores a server-only generation/expiry grant and redirects. Five attempts per minute; 429 at the limit.
- `POST /setup/settings`: `{settings: StoreDetails, version: integer}` with a valid setup session. Atomic save, identical-payload retry and optimistic version checks. Browser conflicts/expired access return actionable form errors; JSON conflicts return 409 and denied access 403. Setup CSRF/origin checks apply to both endpoints. No route issues a secret or creates an owner yet.

`StoreDetails` and choice/check response types are declared in the setup Vue page; field validation and canonical country ordering are enforced by `InstallStore` and `SetupChoices`. Secrets/provider credentials are not accepted settings fields. Initial-owner completion is an application orchestration operation added with identity, not a platform-to-identity dependency.
