# Module: shopping-tools

Objective: help shoppers save, monitor and compare products. Depends on platform, identity and catalog. Inherits [architecture](docs/architecture.md), [data model](docs/data-model.md), and [commands](docs/coding-handoff.md).

Implementation status: SHOP-01 and SHOP-02 are implemented by T055 (2026-10-30); SHOP-03 is implemented by T056 (2026-10-30) for subscription, email verification and signed unsubscribe; SHOP-04 is implemented by T057 (2026-10-30) for stock/price event detection, deduplicated and 24-hour-capped alert intents, and delivery with a full send-time recheck; SHOP-05 and SHOP-06 are implemented by T058 (2026-10-30) for the typed comparison read model and its storefront endpoint, and by T059 (2026-10-30) for the compare tray and the accessible comparison table UI; the T060 integration gate (2026-10-30) verified SHOP-01 through SHOP-06 compose end to end — the save→login merge→compare→cart and subscribe→verify→restock→alert→product journeys pass as continuous flows, and interleaved customer/guest sessions leak no state; SHOP-07 through SHOP-11 remain proposed.

## Requirements

- **SHOP-01:** Guest wishlist persists in a server-owned anonymous session; accounts synchronize across devices. Save specific variant where selected; default variant otherwise. Merge union on login, keep newest intent and deduplicate.
- **SHOP-02:** Saved products show current price/availability and removed/unpublished state without leaking draft content. Saving alone never subscribes someone to email.
- **SHOP-03:** Separate verified opt-ins for back-in-stock and price-drop alerts, with one-click signed unsubscribe and per-store consent evidence. Back-in-stock fires on unavailable→available; price-drop compares current purchasable price with the subscription baseline.
- **SHOP-04:** Deduplicate per subscription/catalog event, cap at one alert of each type per variant/recipient/24 hours, and recheck publication, current eligibility and unsubscribe immediately before send. No notifications for transient cart-reservation releases: stock alerts use merchant receipt/restock/publication changes, with current available quantity checked.
- **SHOP-05:** Compare two to four product/variant selections; shared semantic attributes grouped consistently; missing values display “Not specified,” different values can be highlighted, and numeric units normalize before comparison.
- **SHOP-06:** Comparisons across incompatible schema groups show common basics and explain why specialized fields are omitted. Invalid/unpublished selections are removed with notice. Mobile layout, keyboard navigation and add-to-cart actions remain usable.

## Verification

Test guest/login union, duplicate subscription events, unsubscribed retries, actual price thresholds, 24-hour cap, compatible/incompatible schemas, normalized units and four-item limit. Target `php artisan test --filter=ShoppingTools`; Vue/browser watchlist and comparison flows.
