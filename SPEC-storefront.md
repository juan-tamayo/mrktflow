# Module: storefront

Objective: a complete accessible shopping experience composed from domain contracts and installed themes. Dependencies listed in [capability map](CAPABILITY-MAP.md). Inherits [experience map](docs/experience-map.md), [template contracts](docs/template-contracts.md), and [commands](docs/coding-handoff.md).

## Requirements

- **WEB-01:** Provide homepage, category/search listing, product detail, content/article pages, cart, checkout, confirmation, customer account/orders/downloads, watchlist and comparison routes.
- **WEB-02:** Render indexable product/category/content HTML on the server. Vue handles shopping interactions; price and authorization remain server-owned. No blank client-only public catalog shell.
- **WEB-03:** Product pages expose options, availability, typed specs, physical/digital delivery expectations, gallery, save/compare and cart controls. Missing variant selection yields an actionable prompt.
- **WEB-04:** Search/filter/sort state is reflected in URLs; back navigation preserves it. Facets include only published accessible items. Sort and query limits prevent arbitrary database field access.
- **WEB-05:** Canonical metadata, Open Graph, product/breadcrumb structured data, sitemap and valid redirects match real public content; no invented reviews/ratings. Private and checkout pages use noindex and appropriate private caching.
- **WEB-06:** Default theme supports 360px through desktop widths, keyboard/screen-reader workflows, accessible errors, focus restoration and reduced motion. Currency/units/date formatting follow store settings.
- **WEB-07:** Empty/out-of-stock/unavailable/payment-pending/error states do not trap the shopper. Retried actions have explicit outcomes and cannot add duplicate orders.
- **WEB-08:** Render approved landing hubs, reviews, campaign/signup sections and bounded accessible marketing toasts; provide preferences, recovery continuation and private referral dashboard. Structured data and visible proof use real current facts; missing marketing services do not block shopping.

## Verification

Browser suite covers all reference journeys, JavaScript-disabled product discovery, network failures, mobile overflow and two-user cache isolation. Target `php artisan test --filter=Storefront` and `npm run test:e2e -- --project=chromium`. See [quality targets](docs/quality-and-operations.md).
