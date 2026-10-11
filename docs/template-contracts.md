# Theme, content, and product-template contracts

Contract version: 1.0 target. T010 now implements the bounded ProductView projection, a reviewed default Blade product template and a compiled Vue variant island. The installed theme registry, manifest compatibility/activation and data-package importer are still pending; the manifest examples below remain target contracts.

## Three separate concepts

| Concept | Owns | Example |
| --- | --- | --- |
| Product schema | Typed fields, validation, filterability, comparison semantics | Camera: megapixels, mount, stabilization |
| Visual template | Layout and presentation of supplied public data | Camera detail page with technical specification table |
| Content document | Ordered section instances and their editable settings | Buying guide, homepage, category landing page |

Changing a visual template never rewrites product data. Changing a schema creates a new version and requires a migration preview. Content can reference products/categories by public ID; live price/availability comes from trusted bindings, never copied AI text.

## Trust levels and import behavior

**Merchant data package:** JSON manifest, section instances, token settings, and validated media references. May be imported in the admin. No PHP, Blade source, Vue templates, scripts, event handlers, executable URLs, arbitrary CSS, external stylesheet imports, or package installation. Rich text is an allowlisted document/HTML subset sanitized on ingestion and output. Style controls are typed tokens and allowlisted section settings.

**Developer code theme:** PHP/Blade views, ordinary HTML, CSS, Vue single-file source, and precompiled assets. Install from a reviewed local/repository release via development/deployment tooling. PHP and JavaScript execute with application/browser privileges; this is trusted application code, not a sandbox. The admin may activate an already installed compatible theme but cannot upload executable code in v1.

External AI tools can create either format. Built-in AI generates data documents or product-schema candidates. Custom executable sections are authored through the developer theme workflow, then become registered blocks usable by the editor/AI.

Vue explicitly warns against treating untrusted strings as component templates; that constraint underlies the split. [Vue security guide](https://vuejs.org/guide/best-practices/security.html).

## Developer theme manifest

```json
{
  "schemaVersion": "1.0",
  "id": "mrktflow/default",
  "version": "1.0.0",
  "name": "Default",
  "requires": { "core": ">=1.0.0 <2.0.0", "themeApi": "1" },
  "templates": {
    "home": "views/home.blade.php",
    "product.default": "views/product.blade.php",
    "category.default": "views/category.blade.php",
    "page.default": "views/page.blade.php",
    "article.default": "views/article.blade.php"
  },
  "sections": ["hero", "rich-text", "product-grid", "specifications", "faq"],
  "assets": { "entry": "themes/default/src/product.ts", "style": "themes/default/src/theme.css" }
}
```

The planned installed registry stores theme ID/version/checksum/path. Theme source entries are compiled at release/deployment build time into the application Vite manifest under `public/build`; do not treat that output path as a merchant-controlled source path. All paths are normalized and constrained to the theme directory; reject traversal, symlinks escaping the root, missing files, incompatible API versions, duplicate IDs, remote executable asset entries and unknown critical manifest fields. A checksum proves integrity against a recorded value, not trustworthiness of the author.

Core owns checkout/payment/download authorization routes and system forms. Themes may supply documented visual slots and tokens around these screens, but cannot replace the authoritative commerce actions. Default fallbacks remain available when a theme omits a required view.

## Section definition and document

Each registered section specifies stable type/version, typed settings, permitted child slots, semantic landmarks, public data bindings, max repeat count, and server/Vue renderers where needed. Built-ins: hero, rich text, image/text, gallery, category grid, product grid, featured product, product specifications, comparison link, FAQ, call-to-action, and article list.

```json
{
  "schemaVersion": "1.0",
  "kind": "page",
  "title": "Choose your next camera",
  "templateKey": "page.default",
  "sections": [
    {
      "id": "intro",
      "type": "hero",
      "version": 1,
      "settings": { "heading": "Find your camera", "alignment": "center" }
    },
    {
      "id": "products",
      "type": "product-grid",
      "version": 1,
      "settings": { "heading": "Explore the collection", "limit": 8 },
      "binding": { "kind": "category", "publicId": "01JEXAMPLECATEGORY000000000" }
    }
  ]
}
```

The example category ID is illustrative; imports validate that references exist or require explicit remapping. Unknown section types and wrong settings return field-level errors. Proposed limits: 100 sections/document, nesting depth 4, 2 MB JSON, 50 MB media package, 20 MB/image, 40 megapixels/image. Limits are validated before extraction/processing; users see actionable messages.

Archive imports use a private staging directory, entry-count/uncompressed-size limits, path checks, MIME validation and atomic import only after validation. No network fetching from URLs embedded in imported content. Rejected imports leave published content unchanged. Identical checks apply to AI output.

## Marketing and hub sections

Add typed `offer-banner`, `email-signup`, `stock-signal`, `offer-countdown`, `purchase-proof`, `product-reviews`, `referral-cta` and `hub-product-grid` sections plus a shared toast outlet. Settings select approved campaign/program/hub IDs and visual tokens; facts come from eligibility-checked DTOs with timestamps/expiry. The platform enforces route exclusions, frequency caps, dismissal and privacy even for theme requests. No arbitrary stock, sales, discount or review values come from imported/AI documents. These documents cannot activate campaigns, send email or issue rewards.

Hub editorial documents reuse content revisions and publication. AI may draft hub copy/metadata and campaign copy for explicit review; rule-authorized automatic hub publication uses approved deterministic templates and never silently publishes new AI prose. See [marketing rules](marketing-rules.md).

## Product schema example

```json
{
  "schemaVersion": "1.0",
  "key": "camera",
  "version": 1,
  "label": "Camera",
  "comparisonGroup": "cameras",
  "attributes": [
    { "key": "sensor.megapixels", "type": "decimal", "unit": "MP", "required": true, "comparable": true, "filterable": true },
    { "key": "lens.mount", "type": "enum", "options": ["rf", "e", "z"], "required": true, "comparable": true, "filterable": true },
    { "key": "body.stabilized", "type": "boolean", "required": false, "comparable": true, "filterable": true }
  ],
  "defaultTemplateKey": "product.default"
}
```

Attribute semantics are global/stable; presentation labels may vary. Version changes are additive unless an explicit mapping/migration is provided. An AI-proposed new field cannot silently change the meaning of an existing key. Preview shows affected product count, invalid existing values, and comparison/filter changes before migration. Currency, stock, tax and entitlement flags are core fields outside the schema's control.

## Public data contract

Blade and Vue receive `StoreView`, `ProductView`, `CategoryView`, `ContentView`, `CartSummaryView`, and `CustomerView` with explicit whitelists. Product data includes public ID, URL, title, sanitized description, image alt text, variant choices, current formatted/raw money, availability, typed display attributes and allowed actions. It excludes supplier costs, private asset keys, credentials, staff notes and unrestricted Eloquent objects.

Render product title, description, relevant price, image, category breadcrumbs and core specifications server-side. Vue enhances variant selection, media, cart, filters, watchlist, comparison and editor previews. Include accessible non-JavaScript navigation and ordinary forms where practical. Hosted payment redirect remains a server action.

## Publication and rollback

States: draft → validated → published → archived. Saving creates a revision; preview is authenticated or uses a short-lived scoped preview token, `noindex` and private/no-store cache headers. A signed URL alone does not authorize unrelated drafts.

Publishing verifies base revision, references, required assets and compatibility, then atomically changes the published pointer and invalidates affected caches. A conflict returns 409 and preserves both editors' work. Rollback republishes an older valid revision as a new revision; it does not delete history. Theme activation previews all required route types and verifies fallback templates before switching the active pointer. Failed activation retains the last healthy theme.

## AI authoring contract

Input: operation (`draft_page`, `revise_section`, `draft_product_copy`, `suggest_product_schema`), prompt, selected entity IDs, base revision, allowed section/schema definitions, style tokens, locale and request intent ID. Only selected public catalog/content context is sent; no customer/order/address/payment/secrets context.

Output: schema-versioned candidate document, proposed changes, referenced existing assets/entities, warnings and usage metadata. Provider output is untrusted. Reject extra fields, unknown blocks, scripts, unbounded nesting, invented entity IDs and malformed values. Product claims need merchant review; AI must not invent price/stock/delivery promises as authoritative data.

For hub/marketing drafts, extend operation types with `draft_hub_copy`, `suggest_seo_metadata` and `draft_campaign_copy`; validated campaign templates are data-only and cannot alter activation state.

Flow: prompt → reserve budget → queued run → validate → show editable draft/diff → apply to draft → preview → explicit publish by permitted staff. At most two schema-repair attempts within the same reserved budget. Provider timeout/cancellation records an unknown or partial usage outcome, preserves previous drafts, and prevents unlimited automatic retries. No provider configuration is required for manual editing/imports.
