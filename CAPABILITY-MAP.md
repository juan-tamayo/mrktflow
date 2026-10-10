# Capability map

Status: proposed module boundaries, prepared with the complete specification at the user's request. Module IDs are stable. Each module inherits the engineering contract in [SPEC.md](SPEC.md) and [architecture](docs/architecture.md).

Dependencies mean direct application-code/service consumption. Events do not permit reverse imports; consumers own their listeners. The commerce orchestration module calls provider modules and never requires storefront rendering.

| Module ID | Responsibility | Depends on | Specification |
| --- | --- | --- | --- |
| `platform` | Installation, settings, media/storage primitives, jobs, audit/event infrastructure | — | [Spec](SPEC-platform.md) |
| `identity` | Staff/customer accounts, permissions, sessions, recovery | platform | [Spec](SPEC-identity.md) |
| `catalog` | Products, typed attributes, variants, inventory, private digital asset definitions | platform, identity | [Spec](SPEC-catalog.md) |
| `pricing` | Prices, discounts, tax calculation from cart snapshots | platform, catalog | [Spec](SPEC-pricing.md) |
| `shipping` | Zones, methods, eligibility and quotes from physical-line snapshots | platform | [Spec](SPEC-shipping.md) |
| `themes-content` | Trusted theme registry, sections, pages, articles, menus, publication | platform, identity, catalog | [Spec](SPEC-themes-content.md) |
| `checkout-orders` | Carts, checkout orchestration, payment adapter, order/refund lifecycle | platform, identity, catalog, pricing, shipping | [Spec](SPEC-checkout-orders.md) |
| `fulfillment` | Shipments, returns, digital entitlements and downloads | platform, identity, catalog, checkout-orders | [Spec](SPEC-fulfillment.md) |
| `shopping-tools` | Watchlists, alert subscriptions, comparison | platform, identity, catalog | [Spec](SPEC-shopping-tools.md) |
| `marketing` | Campaigns, recovery flows, urgency/social proof, verified reviews and audience workflows | platform, identity, catalog, pricing, checkout-orders | [Spec](SPEC-marketing.md) |
| `referrals` | Advocate enrollment, referral attribution, qualification and coupon rewards | platform, identity, pricing, checkout-orders | [Spec](SPEC-referrals.md) |
| `seo-hubs` | Rule-generated product hubs, technical SEO checks and catalog feeds | platform, identity, catalog, themes-content | [Spec](SPEC-seo-hubs.md) |
| `storefront` | Public/customer routes, read models, theme composition, shopping and marketing interaction | themes-content, catalog, pricing, shipping, checkout-orders, fulfillment, shopping-tools, identity, marketing, referrals, seo-hubs | [Spec](SPEC-storefront.md) |
| `ai-authoring` | Provider adapter, bounded content/hub draft generation, validation, author review | platform, identity, catalog, themes-content, seo-hubs | [Spec](SPEC-ai-authoring.md) |
| `operations` | Reporting, delivery, exports, diagnostics, update/backup/restore | platform, identity, catalog, checkout-orders, fulfillment, shopping-tools, themes-content, ai-authoring, marketing, referrals, seo-hubs | [Spec](SPEC-operations.md) |

Topological order: platform → identity → catalog → pricing/shipping/themes-content → checkout-orders/shopping-tools/seo-hubs → fulfillment/marketing/referrals → storefront/ai-authoring → operations.

This is a dependency order, not a requirement to finish every backend before building screens. [The implementation plan](tasks/plan.md) uses vertical slices: a small default storefront and admin surface accompany each capability. Infrastructure health and email transport start early in platform; richer operational views arrive later.

## Boundary rules

- Catalog owns stock mutations. Checkout requests reservations/commit/release through the catalog contract; it does not directly update stock tables.
- Shipping quotes have no dependency on an order. Fulfillment stores completed shipments and consumes shipping-method snapshots already on the order.
- Pricing calculates; checkout snapshots and orchestrates; fulfillment grants access and ships. None uses theme code to determine commerce behavior.
- Templates receive explicit public data objects, never database models with lazy-loaded secrets or payment mutation services.
- AI creates candidate documents; themes-content and catalog enforce their own validation and save/publish rules.
- Reporting reads committed facts and projections. Sales operations never depend on reporting availability.
- Platform provides the protected setup state and settings; identity provides first-owner creation. The application composition layer coordinates them and finalizes the install lock. Platform does not import identity models, avoiding an installation dependency cycle.
- Marketing/referrals request bounded coupon grants from pricing and consume order facts. Pricing and checkout never import those consumer modules; source IDs and rule snapshots cross the boundary as data.
- Platform owns shared contact-purpose/transport suppression primitives. Marketing and shopping-tools manage their specific subscriptions through that contract; the storefront preference center composes their views without creating mutual imports.
- SEO-hubs calls the existing content publisher; templates bind product groups at render time. AI can request hub drafts through the hub contract, but publication remains with the content publisher and its permissions.
