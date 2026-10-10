# Module: catalog

Objective: reusable product data, searchable categories and reliable availability for physical and digital goods. Depends on platform and identity. Inherits [engineering conventions](docs/architecture.md), [commands](docs/coding-handoff.md), and [commerce invariants](docs/commerce-rules.md).

## Requirements

- **CAT-01:** Create/edit/duplicate/archive products; draft and published states; title, slug, rich description, type, images/alt text, category membership, SEO fields and template assignment. Drafts never leak into public search/counts.
- **CAT-02:** Every product has a default variant or explicit variants. SKU and option combination are unique. Physical variants support tracking, weight, dimensions and shipping class; digital variants bind private asset versions and max quantity one per order.
- **CAT-03:** Nested categories have unique paths, menus/breadcrumbs and optional custom landing layouts. Prevent cycles and reserved-route conflicts. Slug changes create validated redirects.
- **CAT-04:** Typed product schemas define required fields, enum choices, comparison group and filterable/comparable flags. Version schemas and preview migrations; keep old product values until migration succeeds.
- **CAT-05:** Catalog owns inventory balances, reservation lifecycle and movement ledger. No negative tracked availability or adjustment below reserved stock. Low-stock indicators and thresholds are available in admin.
- **CAT-06:** Digital files upload privately with configurable limits, checksums, processing/quarantine state and version history. Default maximum 500 MB per file; streaming upload/limits must be verified on the reference host. Never expose keys in public product views.
- **CAT-07:** CSV import provides sample columns, field mapping, dry-run validation, per-row error export and explicit create/update-by-SKU behavior. Re-running the same successful import does not duplicate variants. Export neutralizes spreadsheet formula injection.
- **CAT-08:** Search supports title/SKU and categories, price, availability and schema-defined facets. Stable pagination; numeric fields sort numerically; archived variants remain in historical orders.

## Contracts and verification

Provides `CatalogReader` and `InventoryAllocator` in [interfaces](docs/interfaces.md). Test concurrent last-unit holds, schema migration rollback, invalid option duplicates, digital-file privacy and partial CSV failures. Target `php artisan test --filter=Catalog`; focused MySQL concurrency suite is mandatory. Success: a merchant creates camera/apparel/download schemas and sells their variants without modifying core code.
