# Experience and screen map

Product behavior specification; visual branding remains a later design task. Use a clear merchant interface with ordinary commerce language, consistent navigation, visible save status and explicit destructive-action consequences.

## Admin navigation

| Area | Screens | Required states/actions |
| --- | --- | --- |
| Home | Setup checklist, sales summary, alerts | Setup incomplete, no sales yet, payment reconciliation, failed jobs |
| Orders | List, detail, refund dialog, shipment dialog, returns | Independent paid/shipped/download badges; quantities and totals; safe retry |
| Products | List, editor, variant matrix, stock, imports | Draft validation, upload progress/failure, bulk action preview, archive impact |
| Product schemas | Schema list/editor, migration preview | Typed fields, comparison group, affected products, invalid migration data |
| Categories | Tree/list, editor, assigned layout | Cycle/path errors, preview, publish state |
| Customers | List, profile, order history, requests | Necessary profile data, permission limits, verified guest linking |
| Marketing | Campaigns, recovery flows, contacts/preferences, coupons, referrals, on-site messages, reviews | Audience preview, test/send permission, schedules/caps, stopped recovery, reward holds, real proof, moderation history |
| SEO | Landing hub rules/candidates, metadata audit, redirects, sitemap/robots preview, catalog feeds | Preview match/intent, draft or rule-approved publish, duplicate/empty warnings, crawl/index settings, feed freshness |
| Storefront | Themes, pages, articles, menus, editor | Draft/live distinction, preview, conflict recovery, version history, rollback |
| AI studio | Context selector, prompt, run status, draft comparison | Budget/disabled/provider failure, edit output, apply draft, publish separately |
| Reports | Sales, products, refunds, exports | Date/timezone, metric definitions, no-data state, queued export |
| Settings | General, payments, shipping, taxes, digital delivery, email, staff, storage | Credential masks, test status, configuration errors, impact warnings |
| System | Health, jobs, audit, version/maintenance | Last heartbeat, oldest unresolved payment, sanitized diagnostics, backup instructions |

Owners should reach a first product, theme preview and test order from the setup checklist. Do not make advanced developer concepts part of everyday product entry.

## Storefront routes

| Route shape | Purpose |
| --- | --- |
| `/` | Store home |
| `/shop`, `/search` | Browse/search/facets/sort |
| `/categories/{path}` | Category listing or category landing template |
| `/products/{slug}` | Product and variant selection |
| `/pages/{slug}`, `/journal/{slug}` | Content pages/articles |
| `/cart`, `/checkout` | Shopping basket and checkout |
| `/orders/{publicId}` | Verified guest/customer order status |
| `/account`, `/account/orders`, `/account/downloads` | Customer profile/history/files |
| `/wishlist`, `/compare` | Saved products and comparisons |
| `/collections/{slug}` | Curated/rule-generated product landing hub |
| `/recover/{token}`, `/email/preferences/{token}` | Safe cart continuation and contact preferences |
| `/r/{code}`, `/account/referrals` | Referral landing and private advocate rewards |
| `/login`, `/register`, `/password-reset` | Customer authentication |

Reserved namespaces include `admin`, `store-api`, `webhooks`, `downloads`, `account`, `checkout`, and installation/system paths. Custom content URLs cannot shadow them.

## Interaction details

- Product cards show name, meaningful image alt text, current/from price, sale comparison where truthful, and availability. Save/compare buttons have explicit accessible names and feedback. Digital items identify downloadable delivery before checkout.
- Selecting a variant updates media, price, attributes and availability from authoritative data; it does not reset the whole page. Unavailable combinations cannot be bought.
- Comparison supports 2–4 columns, sticky product names where useful, row labels, remove/replace actions and a mobile single-row scroll region with clear scroll cues. Do not rely on color alone for differences.
- Checkout steps: contact → delivery/billing → review → hosted payment → confirmation. Skip physical delivery for digital-only carts. Preserve safe entered data across recoverable errors; never preserve card data locally.
- Pending payment has a clear status and refresh mechanism; it is not presented as a failed order or an invitation to pay twice. Digital downloads display only after entitlement issuance.
- Editor shows draft/live status, undo within the session, saved revision, device preview and publication history. AI changes arrive in a review area with source entity context and an explicit apply action.
- Every form has labels, field errors and a form-level summary. On failure, keep entered values and focus the first actionable error. Long work displays progress/status and can be safely revisited.
- Private user data is absent from shared HTML caches and page source for other sessions. Empty and restricted states reveal only what the current principal may know.

## Role defaults

| Permission group | Owner | Manager | Content editor | Fulfillment |
| --- | --- | --- | --- | --- |
| Staff/secrets/system | Yes | No | No | No |
| Catalog/pricing/shipping | Yes | Yes | Content fields only if granted | Stock read |
| Orders/customers | Yes | Yes | No | Fulfillment-required fields |
| Refunds | Yes | Explicit grant | No | No |
| Theme/content/AI drafts | Yes | Yes | Yes | No |
| Publish/activate themes | Yes | Explicit grant | Explicit grant | No |
| Export customer data | Yes | Explicit grant | No | No |

Presets map to individual permission keys and are tested; they do not replace resource policies. Developer code deployment is controlled through server/repository access, not an ordinary staff role.

## Marketing journeys and permissions

Marketing workspace adds a setup checklist for sender readiness, purpose grants and program budgets. Separate permissions: `marketing.edit`, `marketing.activate`, `marketing.send`, `reviews.moderate`, `referrals.review`, `seo.edit`, `seo.publish`; owner has all, manager/editor require explicit send/publish/reward-review grants. Fulfillment staff does not receive campaign export access.

Recovery flow editor shows the timeline, eligible/blocked audience counts, example basket, incentive cap, last send and stop reason. Campaign launch shows a concrete preview/test, recipient count and schedule. Coupon editor previews stacking and total savings against fixture carts. Referral dashboard explains qualification delay without revealing friend's name/order/address.

Hub editor previews matched products, unique intent, generated versus authored sections, canonical/index state and publish mode. AI hub/campaign copy enters the existing draft review. Product reviews show verification/moderation status; customer text is not rewritten by staff.

Public toasts never steal focus and remain dismissible; show one at a time with session caps and hide on checkout/account/download routes. Preference pages offer clear purpose toggles. Recovery continuation shows current prices/unavailable items only after explicit token claim. See [marketing rules](marketing-rules.md) for full timing and eligibility.
