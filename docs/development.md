# Development

The secure foundation is runnable. The unopened storefront homepage returns HTTP 503; unconfigured administration stays closed until protected setup finishes. A private CLI installation secret unlocks store settings and first-owner creation. After permanent installation completion, the staff workspace/invitations and separate customer signup/sign-in/account screens are available. Staff with catalog permission can save nested categories and simple physical products; published category and product pages render server-side while drafts stay private. Checkout quotes collect contact and destinations; payable orders and storefront launch remain in the ordered backlog; customer verification/recovery screens are available; editable profiles and encrypted saved addresses are available; guest-resource transfer remains in progress. Framework scaffolding comes from laravel/laravel v13.11.0; installed framework is 13.35.0.

## Runtime and installation

Use PHP 8.4, MySQL 8.4, Node 22.23.3 and npm 10.9.9. `bin/php` selects the existing Homebrew PHP 8.4 on this machine, or `MRKTFLOW_PHP` elsewhere. `bin/composer` runs Composer with that same PHP; using the globally selected Composer PHP can select the wrong version. `bin/npm` selects an ignored project Node runtime if present, otherwise the pinned runtime must be on PATH. No global runtime replacement is needed.

On a fresh checkout, create private environment files and provision the isolated databases before commands that boot the app. Copy `.env.example` to `.env` and `.env.testing.example` to `.env.testing`; never overwrite an existing app key. Generate a separate key for each fresh environment with `bin/php artisan key:generate --env=testing` or without the flag for development, after dependency installation and database setup. The test guard runs on boot, so valid database settings must precede key generation. Make environment files mode 0600.

Install lockfiles first with hooks disabled:

```sh
bin/composer install --no-scripts --no-plugins
bin/composer check-platform-reqs
bin/composer dump-autoload
bin/npm ci --ignore-scripts
```

The approved Laravel autoload/package-discovery hooks and Pest plugin are documented in [security foundation](security-foundation.md). npm lifecycle scripts remain disabled by `.npmrc`. Initial scaffold instructions for optional Boost/AI tooling were replaced with the project's own AGENTS.md; those tools are not dependencies.

## Database isolation

Provision three distinct databases and users named `mrktflow_dev`, `mrktflow_test` and `mrktflow_browser`, each with a fresh random password and privileges on only the matching database. Do not grant global permissions, roles or GRANT OPTION. Enable `partial_revokes=ON` **before granting permissions** so underscores in these names are literal rather than wildcard grants. Use MySQL 8.4. Do not use a merchant database or root account for application/test commands.

On this machine the already provisioned server lives in ignored `.runtime/mysql`, communicates through its private socket, and has networking disabled. Start it in a separate terminal with `bin/local-db start`; stop it with `bin/local-db stop`. The latter reads the private admin configuration without passing its password on the command line. `MRKTFLOW_MYSQL_BIN` selects an equivalent MySQL 8.4 executable directory on another machine. This helper never initializes or resets data; a new machine must provision its own isolated instance and random credentials. Do not copy local database data or secrets into the repository.

The PHP test guard checks environment, connection overrides, actual server version/database and account grants before any test schema operation. Cached configuration is forbidden. Tests use array mail and a synchronous default queue. The outbox interruption fixture alone runs an explicit database queue on the guarded test database; no external provider credentials are needed.

For browsers create `.env.browser` from the testing template, with database and username `mrktflow_browser`, its own password/key, `APP_ENV=testing`, `APP_URL=http://127.0.0.1:8011`, `SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`. Playwright supplies these values to a separate server and resets only that database. Do not use `--env=browser`: Laravel interprets it as an environment identity, which the guard rejects.

## Run and verify

```sh
bin/php artisan migrate
bin/npm run build
bin/php artisan serve --host=127.0.0.1 --port=8000
```

For frontend development, run `bin/npm run dev` separately. Remove the ignored `public/hot` file after stopping Vite before browser testing; browser tests require built assets. Local HTTP is permitted only for development/testing. Production requires HTTPS, debug disabled and encrypted secure sessions. Reverse-proxy trust is intentionally unconfigured until an explicit deployment topology is selected.

Run these checks before completing a task:

```sh
bin/php artisan test
bin/php vendor/bin/pint --test
bin/php vendor/bin/phpstan analyse --no-progress --memory-limit=1G
bin/npm run typecheck
bin/npm run lint
bin/npm run format:check
bin/npm run test:unit -- --run
bin/npm run build
bin/npm exec -- playwright install chromium
bin/npm run test:e2e
bin/composer audit --locked
bin/npm audit
bin/npm audit signatures
```

Finish the frontend build before starting browser verification: both use `public/build`, and rebuilding removes/replaces the manifest and assets. Do not run those operations concurrently. PHP and Vue runners reject zero selected tests. The browser suite has no retries and starts its own server; it never reuses a developer server. PHP migrations and browser tests must not share a test database. Local browser traces remain ignored and may contain session cookies; never publish them.

The local PHP runtime has no coverage driver. CI configures PCOV and checks `scripts/coverage-gate.php` after producing Clover. See [continuous integration](continuous-integration.md) for coverage and remote evidence status.

## Protected setup

After migrations and required prerequisite checks, `bin/php artisan setup:check` reports configuration needs. `bin/php artisan setup:secret` issues or rotates the 24-hour secret; enter it at `/setup`. Do not put it in a URL, publish command output or commit it. Setup access lasts at most 15 minutes. Save the store details to reach pending-owner state, then create the first owner through the protected form. That atomically completes installation and permanently closes the setup wizard. Staff can sign in at `/admin/login`; owners manage staff and directly shared invitation links at `/admin/staff`. Customer registration/sign-in are separate at `/account/register` and `/account/login`, with a private account at `/account`; these server-rendered forms work without JavaScript. Automatic invitation email, customer email verification/recovery and commerce actions remain pending in their respective milestones.

## Background work

Durable intent, consumer receipts, scheduler health and queue wrappers are implemented. See [background work](background-work.md) for lane isolation, timeout/retry settings, private diagnostics and interrupted-worker recovery. Apply migrations before starting these services. Settings saves and identity actions create minimal audit intent. Worker/scheduler services do not grant public or administrative access.

## Customer verification, recovery and credential mail

Signup queues confirmation mail atomically. Customers can resend from their account, confirm through `/account/verify`, request recovery at `/account/forgot-password`, and submit a new password through `/account/reset-password`. GET screens never consume tokens. Links fill a credential field from a fragment and remove that fragment from history; manual email codes also work without JavaScript. Only POST forms consume codes. Recovery returns a generic result and is limited by canonical email and IP; confirmation resends are limited by customer and IP. The default log mailer is deliberately refused for credentials. Configure reviewed SMTP with MAIL_MAILER=smtp, MAIL_HOST, MAIL_PORT and provider credentials in the private environment; TLS and certificate validation remain required by configuration. MAIL_URL overrides are refused. Sender name/email come from store settings, and links use APP_URL (HTTPS in production). Never include credentials in diagnostic output.

Run the identity lane independently of local outbox consumers:

```sh
bin/php artisan queue:work database-outbox --queue=identity-mail --timeout=60 --tries=1
bin/php artisan identity:mail-recover
bin/php artisan identity:mail-health
bin/php artisan identity:mail-retry <delivery-uuid>
```

The normal scheduler runs bounded recovery every minute. Configure a supervised worker and reviewed SMTP before opening the store to customers; signup can succeed while mail awaits repaired configuration. SMTP timeout is ten seconds, the delivery claim lease is 120 seconds, and queued publication is leased for 90 seconds. Worker retry is one because the durable delivery record owns bounded retry scheduling. Health reports private counts only; it is not a provider delivery guarantee. A retry requires a failed, unexpired encrypted intent and sending rechecks challenge eligibility. Tokens expire after 24 hours for verification and one hour for recovery. Expired payloads are erased by recovery, including terminal failures. Preserve the application key securely: encrypted pending deliveries depend on it.

A crash after transport acceptance can produce a duplicate email when the lease expires. Single-use challenge consumption remains authoritative; no exactly-once SMTP promise is made. A log/array transport must never be used as successful delivery in production; array is test-only.

## Customer profile and addresses

Installed customers can edit their own profile at `/account/profile` and add, edit or remove up to 20 saved addresses at `/account/addresses`. These are native forms that work without JavaScript. Changing an email requires the current password, revokes previous challenges, clears verification, increments the session version and atomically queues confirmation for the new address. Name-only edits preserve email verification. Profile and address version fields reject stale writes. Saved addresses encrypt their payload; preserve the application key securely along with backups. Deleting an address removes its payload; the audit retains only object/account IDs and the version. Postal codes and regions are optional for international addresses. This storage is not postal/carrier validation: checkout will apply current shipping-country eligibility later. Account export/deletion and backup retention remain T083.

## Theme rendering preview

Staff with `content.read` can open `/admin/theme-preview` from the workspace. This is clearly marked example data and never opens the storefront or creates a catalog product. The default theme has its Blade view under `themes/default/views` and Vue/CSS source under `themes/default/src`. The reviewed namespace is registered by ThemeServiceProvider; requests and content data do not choose template paths. Build the existing Vite entries with `bin/npm run build`; the compiled assets are served by PHP without a Node rendering service. Native variant selection also works without JavaScript.

The ProductView factory accepts only explicit bounded public fields. Description/attribute strings are escaped plain text; arbitrary HTML remains text. Variant amounts are safe integers and display formatting uses the supplied currency/exponent with integer arithmetic. No stock or checkout authority is present in the example. Executable developer themes remain trusted application code. The informational theme manifest does not yet authorize imports or activation; T061 and later content tasks own registry, compatibility, complete themes and data-only packages.

## Simple physical products

Authorized staff with `catalog.write` create a simple physical product at `/admin/products/create` and edit saved products at `/admin/products/{id}`. The list is at `/admin/products`. Each product has one default variant/SKU, a store-currency minor-unit price, and one processed public image. After the product exists, staff can save option axes and unique SKU combinations at the same edit screen (`PUT /admin/products/{id}/variants`). Duplicate SKUs or option combinations are rejected. Staff can dry-run and apply a CSV at `/admin/products/import`. Drafts are visible only in administration; `CatalogReader` public lookups treat them as unpublished. Guest and account carts accept revision-checked quantity edits; checkout is not part of this slice.

## Categories and public browse

Authorized staff manage nested categories at `/admin/categories`. Saves reject cycles, duplicate paths, and reserved route slugs. Changing a slug records a validated redirect for the old path and descendant paths. Published category pages render at `/categories/{path}` and published products at `/products/{slug}` using the default theme, including breadcrumbs and an empty-category state. Shoppers select a published variant with `?variant={id}`; missing selection prompts them to choose, unavailable combinations cannot be bought, and tracked variants with no remaining units show as out of stock. Public HTML, search/category cards, and `GET /store-api/v1/products` share the same sellability contract: physical remaining stock, digital ready files, and the merchant availability flag. Public responses omit on-hand, reserved, and low-stock quantities; staff editors still show low-stock state. Unpublished categories, draft or archived products, and unknown slugs return 404 without leaking titles. Shoppers search published products at `/search` by title or SKU, then filter by category, price, availability, and schema-defined facets. Sort and filter state lives in the URL so back navigation restores it. Drafts are omitted from results and facet counts. Numeric attributes sort numerically. Search pages are noindex. Authorized staff dry-run a catalog CSV at `/admin/products/import`, apply only valid rows, download a formula-safe export at `/admin/products/export.csv`, and archive products without deleting SKUs. Replaying the same import intent does not duplicate variants. Archived products 404 on the storefront and stay out of search. The store homepage `GET /` remains HTTP 503. Checkout quotes are available from a nonempty cart; payable orders remain a later task.

## Typed product schemas

Authorized staff with `catalog.write` create versioned schemas at `/admin/schemas`. A schema has a stable key, comparison group, and typed fields (text, integer, decimal, boolean, date, or enum) with required/comparable/filterable flags. Attribute keys are globally unique in meaning: a later schema cannot reuse `sensor.megapixels` as a different type. Products may assign the current schema version and must supply valid values; invalid numeric, enum, and missing required values are rejected. Public product pages show those fields as escaped text. When products already use a schema, staff preview a new version (affected products, invalid values, comparison/filter changes) and apply it only when conversion succeeds; a failed conversion keeps the prior version and values.

## Digital products and private files

Authorized staff with `catalog.write` and `media.upload` create a digital product at `/admin/products/create`, then upload versioned private files at the product editor (`POST /admin/products/{id}/digital-assets`) and pin ready versions to a variant (`PUT /admin/products/{id}/variants/{variantId}/digital-assets`). Digital variants are untracked and quantity one. Processing or quarantined versions cannot be attached. Public product pages show download labels without storage keys, checksums, or original paths. `GET /` remains HTTP 503. `DemoStoreSeeder` can add fictional physical and digital products plus a mixed guest-account cart in local/testing only; production installation does not seed them.

## Cart ownership

Guests and signed-in customers keep separate carts until the shopper signs in or registers. Login merges the current guest cart into the account cart: matching physical variants sum and clamp to the allowed maximum, digital quantity stays one, and the shopper sees an adjustment notice. Logout invalidates the session so the account cart is no longer visible on that browser. `GET /cart` and `GET /store-api/v1/cart` return the current revision and catalog-priced lines. `POST /store-api/v1/cart/lines` replaces a variant quantity absolutely (never increments); `PATCH` and `DELETE` of a line ID require the same expected revision. Stale revisions return 409. Invalid, unpublished, out-of-stock, and unavailable variants are rejected; digital quantity is one; posted prices are ignored. Adding to cart does not reserve stock. Privileged staff still must pass 2FA before catalog admin. `GET /` remains HTTP 503.

## Money and deterministic price engine

`PriceCalculator` quotes immutable priced-line snapshots: currency-aware integer money (exponents 0–3), active UTC sale windows, optional fixed or percentage merchandise discounts, and integer largest-remainder allocations with stable line-ID ties. Line totals and per-unit discount residuals sum to the quoted discount; mixed currencies, sale prices above regular, missing sale windows, overflow, and discounts larger than the eligible subtotal are rejected. Without a tax context, tax on the quote remains zero. Cart HTML still uses catalog regular prices. Privileged staff still must pass 2FA before catalog admin. `GET /` remains HTTP 503. Run `bin/php artisan test --filter=PricingMoney`.

## Coupon configuration and redemption holds

Authorized staff with `pricing.manage` create and edit one-coupon rules at `/admin/coupons`. Each coupon has a case-normalized unique code, fixed/percentage/free-shipping mode, UTC validity window, minimum eligible subtotal, optional product and category scopes, and total/per-customer caps. `ApplyCoupon` selects eligible lines and returns a merchandise discount for `PriceCalculator`; `HoldCoupon` holds, commits, and releases usage under row locks so two last-redemption holds cannot both succeed. Replays of the same operation key return the existing receipt. Failed or released holds do not restore committed redemptions. Checkout quotes accept one shopper-entered code through `ApplyCoupon`; holds remain with T034. Privileged staff still must pass 2FA before coupon admin. `GET /` remains HTTP 503. Run `bin/php artisan test --filter=PricingCoupon`.

## Shipping zones and rate methods

Authorized staff with `shipping.manage` configure one origin, shipping classes, destination zones, and methods at `/admin/shipping`. Zones match country, optional region, and postal patterns (exact, prefix `90*`, or numeric range). Lower priority numbers win; overlapping destinations at the same priority are rejected at save. Methods are flat rate, free above a discounted physical merchandise threshold, subtotal/weight tables (min inclusive, max exclusive), or local pickup with an explicit location. Staff can preview a destination, physical subtotal, and weight without opening checkout. Privileged staff still must pass 2FA before shipping admin. `GET /` remains HTTP 503. Run `bin/php artisan test --filter=ShippingRules`.

## Physical and mixed-cart shipping quotes

`ShippingQuoter` quotes immutable physical-line snapshots: destination zones, include/exclude shipping classes, discounted physical merchandise subtotals, and integer gram weights. Digital-only baskets quote zero shipping without an address or method. Mixed carts ignore digital lines for charges, free-shipping thresholds, and class coverage. Unsupported destinations or mixed classes that no single method covers fail with no-service; missing weights fail weight tables without inventing grams. Local pickup skips the delivery address and uses the configured pickup location as the physical tax destination, with instructions and no stock promise. The quote freezes method/rule revision, charge, and a SHA-256 fingerprint so later rule edits do not change that snapshot. Checkout quotes collect a delivery address when physical lines need one. Privileged staff still must pass 2FA before shipping admin. `GET /` remains HTTP 503. Run `bin/php artisan test --filter=ShippingQuote`.

## Tax configuration and deterministic calculation

Authorized staff with `pricing.manage` configure tax classes, destination rules, shipping tax class, and exclusive or inclusive price mode at `/admin/taxes`. Rules match country, optional region, and postal patterns. Equal-specificity overlapping destinations are rejected at save; draft rules do not participate. Physical lines use the shipping destination and digital lines use billing. Exclusive tax is round-half-up(discounted base × n/d) per line then summed; inclusive tax is round-half-up(gross × n/(d+n)) and displayed without adding it again to the grand total. Documented USD fixtures: exclusive 2250 merchandise + 300 shipping at 10% → tax 255, total 2805; inclusive 2200 + 330 → tax 230, total 2530. Zero-rate rules must be explicit; missing or ambiguous coverage fails closed. Staff can preview physical, digital, and mixed carts without opening checkout. Checkout quotes apply `TaxContext` from billing and shipping destinations and fail closed on missing coverage. Privileged staff still must pass 2FA before tax admin. `GET /` remains HTTP 503. Run `bin/php artisan test --filter=PricingTax`.

## Pricing preview and cart empty states

Authorized staff with `pricing.manage` open `/admin/pricing-preview` and preview physical-only, digital-only, and mixed carts. The preview uses the same coupon, shipping, and tax calculation as checkout quotes, including the documented exclusive mixed-cart fixture 2500 − 250 + 300 + 255 = 2805. Currency, missing tax coverage, unsupported shipping, and rejected coupons are shown without inventing a payable total. Shoppers with an empty cart are offered continue-shopping; unavailable lines stay visible so they can be removed and checkout is withheld until every remaining line is available. Privileged staff still must pass 2FA. `GET /` remains HTTP 503. Run `bin/php artisan test --filter=PricingPreview`.

## Quote revision and checkout form

Guests and signed-in customers open `/checkout` from a nonempty cart. The form collects email, billing, only necessary shipping, an optional coupon, and policy acknowledgement; digital-only carts skip delivery. `POST /store-api/v1/checkout-quotes` and the HTML checkout POST create an encrypted 15-minute quote from `PriceCalculator`, `ShippingQuoter`, and tax. Posted client prices are ignored. The documented exclusive USD fixture is 2500 merchandise − 250 coupon + 300 shipping + 255 tax = 2805. Address correction supersedes the previous quote. Accepting records acknowledgement and the frozen fingerprint. Cart revision, catalog price, tax/shipping rule, destination, fingerprint, ownership, CSRF, and TTL mismatches return stale and require a new quote. Empty carts redirect to `/cart`. Privileged staff still must pass 2FA. `GET /` remains HTTP 503. Run `bin/php artisan test --filter=CheckoutOrdersQuote`.

## Immutable checkout intent

`POST /store-api/v1/checkout-sessions` with an `Idempotency-Key` claims one payable order per cart revision from an accepted quote. The transaction freezes quote, line, and address snapshots, holds tracked stock and coupon usage, and creates a `pending` order with a `created` payment attempt. The same key replays; a changed payload returns 422; a second key returns the existing intent and cannot create another payable order. Failed holds roll back the order. Zero-total digital orders complete locally (`confirmed` / `succeeded`, provider `local`) without a Stripe charge. Privileged staff still must pass 2FA. `GET /` remains HTTP 503. Run `bin/php artisan test --filter=CheckoutOrdersIntent`.

## Hosted payment adapter

`POST /store-api/v1/checkout-sessions/{id}/payment` creates one immediate-method Stripe Checkout Session outside database locks after the frozen attempt and outbound hash are persisted. Line amounts are quantity-one allocations that must sum to the frozen grand total; promotion codes, automatic tax, adjustable quantities, and destination changes are disabled. Timeouts and unbound/mismatched responses persist `unknown` and keep stock/coupon holds. Return and cancel URLs only display status and cannot mark an order paid. Zero-total orders skip Stripe. HTTP fixtures cover success, open/authentication, expiry, decline-as-still-open, unsafe hosted URLs, amount mismatches, and idempotent recovery after timeout. Live Stripe sandbox charges were not executed in this environment (no test credentials). Privileged staff still must pass 2FA. `GET /` remains HTTP 503. Run `bin/php artisan test --filter=CheckoutOrdersGateway`.

## Verified durable webhook inbox

`POST /webhooks/stripe` is CSRF-exempt and verifies the raw Stripe-Signature HMAC, timestamp window, configured account, and livemode/environment before inserting a unique encrypted `provider_events` row. A replay of the same signed event returns 2xx without a second row. Invalid signatures or environments return 400 and store nothing. Persistence failure returns 503 so the provider can retry. The inbox does not mark an order paid by itself. `ProcessPaymentEvent` is an ID-only encrypted job on the payments queue that queries the provider session and confirms only a matching capture. Privileged staff still must pass 2FA. `GET /` remains HTTP 503. Run `bin/php artisan test --filter=CheckoutOrdersWebhook`.

## Payment confirmation and reconciliation

Verified matching success commits stock and coupon holds, records one `payments` capture, confirms the order, and emits `checkout.order.paid` once (`intent_key` `order:paid:{orderId}`). Duplicate inbox jobs and later expired/unpaid observations cannot downgrade that success. Unknown attempts are queried through `ReconcilePayment` and a bounded `payments:reconcile` scheduler job. A crash after the provider reports capture and before the local commit is recovered by the same query path; return URLs still cannot mark an order paid. Amount, currency, account, and live/test mismatches stay unpaid. Live Stripe capture evidence remains pending. Privileged staff still must pass 2FA. `GET /` remains HTTP 503. Run `bin/php artisan test --filter=CheckoutOrdersReconciliation`.

## Checkout expiry and late-success recovery

Due `created`/`awaiting_payment` attempts are expired through `ExpireCheckout` and a bounded `payments:expire` scheduler job. Open provider sessions are retrieved then cancelled with Stripe `expire` before stock or coupon holds are released; already-expired unpaid sessions may release. Unknown or failed provider responses keep holds and leave the attempt payable for later reconcile. After a verified unpaid expiry, late matching success re-reserves and commits holds when stock remains, or records the capture, marks the order `on_hold`, and writes a staff audit (`audit:order-hold:{orderId}`, reason `late-success-stock`) when a competing purchase already took the last unit. Live Stripe expiry races remain pending. Privileged staff still must pass 2FA. `GET /` remains HTTP 503. Run `bin/php artisan test --filter=CheckoutOrdersExpiry`.

## Zero-total checkout and confirmation

Zero-total orders skip Stripe and use `CompleteFreeOrder`: the same hold-commit and `checkout.order.paid` pipeline as a captured payment, with a local zero-amount `payments` row (`provider` `local`, capture `local_{attemptId}`). Replays are idempotent. Forged success query parameters and payment return URLs only display status. `GET /checkout/orders/{orderId}` is owner-scoped, shows pending with a refresh link until the order is confirmed, then a confirmation screen. HTML pay for a free quote redirects there. Privileged staff still must pass 2FA. `GET /` remains HTTP 503. Live provider charges were not executed. Run `bin/php artisan test --filter=CheckoutOrdersFree`.

## Admin order list and detail

Staff with `orders.read` search `/admin/orders` by order number, customer email, and UTC date range, then open `/admin/orders/{id}`. The detail shows frozen lines and totals plus independent order, financial, fulfillment, and download states. Physical unpaid orders stay `unfulfilled` when financial is `unpaid`; paid digital-only orders stay `not_required` for shipment while downloads become `entitled`. Internal notes persist with a staff audit that omits note body. Owner and fulfillment staff can read; content editors receive 403. Privileged staff still must pass 2FA. `GET /` remains HTTP 503. Run `bin/php artisan test --filter=CheckoutOrdersAdmin`.

## Partial and full provider refunds

Staff with `orders.refund` submit `POST /admin/orders/{id}/refunds` with an idempotency key. Quantity refunds compute remaining unit merchandise and line tax from frozen allocations, optionally add remaining shipping, hold unshipped quantities immediately, then call Stripe with `refund:{refund UUID}`. Success cancels those quantities and releases matching committed stock in one transaction; definite failure clears the hold; unknown/timeout keeps the hold until `ReconcileRefund` / `payments:reconcile-refunds` observes the same capture. Amount-only goodwill refunds spend remaining captured money without cancelling units. Concurrent claims cannot over-refund remaining money or quantity. Zero-total and unpaid orders are not refundable. Privileged staff still must pass 2FA. `GET /` remains HTTP 503. Live Stripe refunds were not executed. Run `bin/php artisan test --filter=CheckoutOrdersRefund`.

## Order cancellation workflow

Staff with `orders.manage` submit `POST /admin/orders/{id}/cancellation` with an idempotency key and reason. Paid unshipped orders claim remaining captured money as a `cancellation` refund, hold remaining quantities, then refund outside locks; success cancels those quantities, releases committed unshipped stock once, marks the order `cancelled`, revokes undelivered digital eligibility (`download_status` `revoked`), and emits `checkout.order.cancelled` once (`intent_key` `order:cancelled:{orderId}`). Replays of the same key and later keys after success do not create another refund or event. Coupon redemptions stay committed. Any shipped quantity refuses cancellation and requires the return path. Zero-total orders skip Stripe, still cancel remaining quantities, and emit the same fact. Privileged staff still must pass 2FA. `GET /` remains HTTP 503. Live Stripe refunds were not executed. Run `bin/php artisan test --filter=CheckoutOrdersCancellation`.

## Payment/reservation contract spike

No payment credentials are needed for the local spike. Run `bin/php artisan test --filter=CheckoutOrdersGateway` for bound payment/refund observation rules, and `bin/php artisan test --filter=CheckoutOrdersConcurrency` for separate MySQL worker connections competing for one synthetic stock unit and transactional rollback. The concurrency fixture verifies the restricted PHP test database, creates only temporary test tables and cleans them up; it does not add merchant migrations or permit testing against a store database. Webhook authenticity remains T036. See [commerce contracts and limitations](commerce-rules.md#paymentreservation-spike-t011).

## AI authoring contract spike

Run `bin/php artisan test --filter=AiAuthoringContract` for the bounded public-context request, shared data-only page schema and Responses-shaped success/refusal/incomplete/malformed/tool-call fixtures. No key, SDK or provider request is needed. The schema is a prototype with no page persistence, renderer, importer, budget service or live adapter; it cannot publish content. [Schema and remaining acceptance](template-contracts.md#implemented-t012-page-draft-spike), [provider/model findings](open-decisions.md#d08-t012-providerschema-findings-october-10-2026). T074 requires a deliberate capped provider smoke before enablement.
