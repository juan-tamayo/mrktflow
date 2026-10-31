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

T062 implements the WEB-01/WEB-02 layout slice: every themed route (public catalog/home, account, orders, downloads, preferences, cart and checkout) renders through the one layout `themes/default/views/layout.blade.php`, whose named slots sit at the positions defined by `App\Domain\Storefront\Support\SystemSlots` — theme-filled `head`/`announcement`/`pre-main`/`content`, platform-positioned `masthead`/`footer` chrome. `ComposePage` runs on every layout render through the layout view composer: a slot assignment supplied as view data naming an unknown slot or a platform slot is refused (`SlotCompositionRefused`). The commerce actions are platform components under `resources/views/storefront/slots/` (`checkout.summary`, `checkout.actions`, `product.purchase`, `cart.line-actions`, `downloads.issue`, `download.start`, marked `data-platform-slot`) resolving outside theme namespaces, so a theme cannot replace them; public HTML is server-rendered and meaningful without JavaScript. System pages (sign-in, unsubscribe, watch verification) remain platform views on purpose. The default theme's manifest declares the layout and all page templates. Verify with `php artisan test --filter=StorefrontTheme`; the Chromium route-type spec is `tests/Browser/storefront-layout.spec.ts` (execution pending the browser harness).
