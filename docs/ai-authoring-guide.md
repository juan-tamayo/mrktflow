# AI authoring

Optional built-in drafts. Manual pages, template imports, checkout and updates keep working when AI is off. Publication is never an AI action.

## Turn AI on

1. Sign in as the store owner. Privileged staff must complete two-factor authentication; it cannot be skipped.
2. Open **AI authoring** at `/admin/ai`.
3. Enable built-in drafts, choose a permitted model, set daily and monthly token budgets, paste the store-owned OpenAI secret, and grant authors who already have `ai.use`.
4. The secret is encrypted and never shown again. Managers, editors and customers cannot change it.

Leave AI disabled if you only import templates or write pages by hand.

## Generate a page draft

Authorized authors open **AI studio** at `/admin/ai/studio`. Choose a page, select published products (public title and description only), and write a prompt. The store holds estimated tokens for the first provider call plus two repairs, then queues one encrypted ID-only job.

The request has no tools, no store-owned secret in the JSON body, no customer records, no prices and no unpublished products. Instructive text inside product copy cannot publish or read credentials.

## Review, apply, publish

Open the run at `/admin/ai/studio/{run}`. Review usage, the before/after diff, and desktop/mobile preview. Edit the candidate, then **Apply to draft**. Apply writes a new content draft revision only. It never updates the live pointer.

Publishing stays on **Pages, articles, and menus** (`/admin/content`) and needs `content.publish`. Content editors can apply and still cannot publish.

If someone saved the page after generation, apply is blocked until you generate from the current revision.

## Product copy and schema

On a product editor, authors can draft title/description or suggest typed schema fields. Apply never writes price, stock or SKU. Semantic-key conflicts (for example changing what `sensor.megapixels` means) stay unapplied until you choose a compatible suggestion.

## Outages, cancel and duplicates

| Outcome | What you see | What is preserved |
| --- | --- | --- |
| Cancel a queued run | “Generation cancelled.” | Previous drafts unchanged; unused hold released |
| Provider rate limit | “Provider rate limited.” | Previous drafts unchanged; estimated tokens stay pending until reconciled |
| Timeout / unavailable | “Provider timed out.” or “Provider unavailable.” | Previous drafts unchanged; no automatic retry |
| Duplicate submit of the same request intent | The original run | One reservation, one provider attempt, apply stays a no-op |

Job replay never publishes and never applies a second time.

## Import with AI off

`/admin/content/import` stages a data-only template ZIP, validates it with the same schema and sanitization rules as AI output, and applies to drafts only. You can import while built-in AI is disabled.

The public homepage stays closed (`GET /` HTTP 503) until a later launch task. Published CMS pages use `/pages/{slug}`.

## Live provider smoke

Fixture tests do not call OpenAI. A capped live smoke runs only when `MRKTFLOW_AI_SMOKE_KEY` is set in the private test environment. Missing that variable leaves remote evidence pending; do not paste production secrets into tests or CI.
