# Module: themes-content

Objective: extensible design and everyday content editing without coupling presentation to commerce internals. Depends on platform, identity and catalog. Inherits [template contracts](docs/template-contracts.md), [architecture](docs/architecture.md), and [commands](docs/coding-handoff.md).

## Requirements

- **THEME-01:** Ship a complete responsive default theme and a documented developer-theme format supporting PHP/Blade, HTML, CSS and compiled Vue. Compatibility and asset checks run before activation.
- **THEME-02:** Page/article/menu editor supports typed sections, ordering, visibility and style tokens. Merchant can assign templates to pages, categories and products, with fallback defaults.
- **THEME-03:** Separate draft/published revisions, authenticated previews, optimistic editor locking, explicit publish and rollback. Editing drafts does not alter live pages.
- **THEME-04:** Admin imports data-only template packages with staging, validation, media/reference mapping and a reviewable report. No raw executable source upload in admin. Developer code packages use the separate trusted installation workflow.
- **THEME-05:** Navigation links, slugs, reserved paths, metadata, canonical URLs and redirect loops are validated. Public sitemap excludes drafts, private pages, cart, checkout and account routes.
- **THEME-06:** Theme activation is reversible and preserves content. Detect unavailable section types/template keys and require a mapping or fallback; never discard incompatible settings silently.
- **THEME-07:** Typed theme extension slots include offer banners, signup forms, stock/countdown signals, purchase aggregates, verified reviews, referral calls to action and hub product groups. Core supplies eligibility/facts; themes receive no contact/order data or authority to invent stock, prices or ratings.

## Verification

Test malicious archives/HTML/URLs, wrong manifest versions, unavailable sections, conflicting edits, failed activation, rollback and draft cache leakage. Target `php artisan test --filter=ThemesContent`; browser editor/publish/rollback flow. Success: add a category guide and custom product presentation without editing core commerce code.
