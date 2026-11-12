# Module: ai-authoring

Objective: optional assisted authoring that produces reviewable validated drafts and also accepts compatible externally authored data. Depends on platform, identity, catalog, themes-content and seo-hubs. Inherits [template/AI contract](docs/template-contracts.md), [interfaces](docs/interfaces.md), and [commands](docs/coding-handoff.md).

Implementation status: AI-01 through AI-06 are implemented by T073–T078. Owners configure a store-owned secret, model, budgets and authors; page generation, product copy/schema suggestions, validation/repair caps, review/apply and outage/cancel/duplicate recovery compose through `/admin/ai/studio` and `/admin/content`. Disabled AI does not block imports. AI-07 remains proposed. Live provider smoke is opt-in and pending without `MRKTFLOW_AI_SMOKE_KEY`.

## Requirements

- **AI-01:** Owner enables/configures provider credentials, permitted model, daily/monthly budget and author permissions. Disabled AI never blocks manual authoring, imports, checkout or updates.
- **AI-02:** Support page drafts, section revisions, product copy and product-schema suggestions using selected public context and installed section/schema definitions.
- **AI-03:** Persist intent/run/base revision; reserve estimated maximum usage before dispatch. Enforce concurrent budget reservations. Record actual usage; uncertain usage retains a bounded pending estimate until reconciled.
- **AI-04:** Validate output with the same schema, reference and sanitization rules as imports. At most two budgeted repair attempts. Instructions embedded in product text/imports cannot grant access to credentials, shell, network browsing or publication tools.
- **AI-05:** Show editable draft, before/after diff, warnings, usage and mobile/desktop preview. Apply only to draft; publication uses independent permission and deliberate action. Stale base revision requires conflict resolution.
- **AI-06:** Cancellation, provider outage/rate limit/malformed response and budget exhaustion have useful status messages. Previous drafts remain intact; job replay cannot apply a change twice.
- **AI-07:** Draft hub introductions, guide sections, SEO metadata and campaign email copy from selected public data and approved templates. AI cannot fabricate reviews/activity, generate factual discount/stock values, auto-send messages or publish new prose without the existing review flow.

## Verification

Provider contract fixtures cover valid/malformed/oversized output, prompt injection in catalog text, unknown IDs, private-context leakage, concurrent budget requests, cancellation and stale edits. Run an opt-in real-provider schema smoke test with a cost cap only when credentials are supplied. Target `php artisan test --filter=AiAuthoring`; browser draft-review-publish flow.
