# Module: seo-hubs

Objective: automatically organize products into useful landing hubs and provide native technical SEO tools. Depends on platform, identity, catalog and themes-content. Inherits [hub and SEO contracts](docs/marketing-rules.md), [template contracts](docs/template-contracts.md), [commands](docs/coding-handoff.md) and [quality requirements](docs/quality-and-operations.md).

## Requirements

- **HUB-01:** Define hubs around a category, brand attribute, use case, product family or meaningful typed attribute combination. A rule previews matching published products, content intent and candidate URLs. Duplicate rule/intent fingerprints are merged or rejected; arbitrary facet combinations never automatically become indexable pages.
- **HUB-02:** Scheduled/catalog-event jobs create proposed hub pages and refresh membership in published hubs. Default: generated pages are drafts; merchants can explicitly activate an approved rule for bounded auto-publication after quality gates. AI-generated prose always requires editorial publication; automatic membership updates preserve merchant copy.
- **HUB-03:** Hubs include curated introduction, live product grid, typed comparison/specification blocks, relevant guide/FAQ content and internal links. Use `/collections/{slug}` with a unique intended search purpose; a single-product guide must add useful buying/use information instead of duplicating its product page.
- **HUB-04:** Native SEO editor and audit queue cover title/description previews, canonical choice, indexability, robots settings, sitemap eligibility, redirects, missing alt text, broken internal links, duplicate title/intent and structured-data validation. Local check status never claims Google indexing or ranking.
- **HUB-05:** Server-rendered internal links, stable pagination, canonical/sitemap alignment and lifecycle rules prevent empty/thin/stale hubs and facet crawl traps. Rebuild projections incrementally; stale rules or job failure cannot silently publish empty pages at scale.
- **HUB-06:** Product pages support accurate Product/Offer and variant relationships, plus real visible eligible ratings; hubs use CollectionPage/ItemList/BreadcrumbList rather than pretending an entire grid is a single product. Structured data mirrors rendered price/currency/availability and does not promise rich-result eligibility or display.
- **HUB-07:** Merchants can add typed site-verification meta values and export a validated catalog feed for merchant tools. Feeds include required identifiers only when known, report missing data and keep prices/stock/URLs consistent. External Search Console/Merchant Center account connection, submission and ranking ingestion are outside v1.

## Verification

Target `php artisan test --filter=SeoHubs`; browser/server-HTML and fixture validation for hub generation, duplicate rules, preview/publish, disappearing inventory, pagination, canonical tracking parameters, reviews/variants structured data, feed freshness and empty hub retirement. Use official search tooling manually where available; keep local validation distinct from provider approval. See [sources](docs/sources.md) for current reference guidance.
