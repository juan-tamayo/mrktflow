# Installation

Operator runbook for a single store on the [supported host](hosting.md). Target: after infrastructure and provider credentials exist, a new operator reaches a sandbox purchase in **30 minutes** ([SPEC release acceptance](../SPEC.md)). This guide does not open the public homepage. `GET /` stays HTTP 503 until the storefront-open slice.

Use `bin/php`, `bin/composer` and `bin/npm`. Do not replace global runtimes. npm lifecycle scripts stay disabled. Composer plugins stay on the reviewed allowlist.

## Supported runtime

Confirm before copying environment files:

| Runtime | Version | Wrapper |
| --- | --- | --- |
| PHP | 8.4.x | `bin/php` or `MRKTFLOW_PHP` |
| Composer | 2.x via that PHP | `bin/composer` |
| MySQL | 8.4.x InnoDB | `bin/local-db` on the development machine, or an equivalent isolated server |
| Node / npm | 22.23.3 / 10.9.9 | `bin/npm` when compiling a source checkout |

Then:

```sh
cp .env.example .env
chmod 0600 .env
# Set DB_* to a dedicated database-only account. Never use root or a store replica for tests.
bin/composer install --no-scripts --no-plugins
bin/composer check-platform-reqs
bin/composer dump-autoload
bin/npm ci --ignore-scripts
bin/php artisan key:generate
bin/php artisan migrate --force
bin/npm run build
bin/php artisan storage:link
bin/php artisan setup:check
```

`setup:check` must PASS every required item (PHP, URL, key, database, private storage, writable directories). Queue, mail and scheduler report PENDING until workers, SMTP and cron exist; those are launch gates, not first-owner blockers. Failures name a concrete remedy and never print credentials.

Production: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://…`, `SESSION_SECURE_COOKIE=true`, reviewed SMTP (not `array`/`log`), Stripe test or live credentials as intended, and supervised workers/scheduler. Local loopback may use HTTP. Do not seed `DemoStoreSeeder` on a merchant host; production installation does not silently create sample customers.

## Secure owner setup

Setup is not a public admin registration (PLAT-02).

1. On the server, run `bin/php artisan setup:secret`. It prints a 64-character secret once. Do not put it in a URL, ticket, screenshot or git. Only a SHA-256 digest is stored. Rotation revokes previous secrets and grants. Secrets expire after 24 hours.
2. Open `/setup`. Until a valid secret exists the route is 404. Enter the secret; the grant lasts at most 15 minutes and lives in the encrypted session, never in a query string.
3. Save store settings (name, locale, timezone, currency, contact/sender, sales countries, units, five plain-text policies). Unexpected fields including provider secrets are rejected.
4. Create the first owner on the same protected form. Completion is atomic with the install lock: status `complete`, secret digest cleared, `/setup` hidden. A second HTTP owner post is forbidden.
5. Enroll staff TOTP at `/admin/two-factor`. Privileged staff cannot skip 2FA. Customers register at `/account/register`, never as staff.

Completed installations refuse `setup:secret` and `/setup`. Recovering an owner account must not reopen anonymous setup. Server policies enforce this; hiding the Vue screen is not sufficient.

## Sandbox purchase

After the owner exists, still with `GET /` returning 503:

1. Sign in at `/admin/login`, complete TOTP, configure shipping zones, tax rules and Stripe **test** credentials (`STRIPE_LIVE=false`). Array mail remains acceptable for a sandbox rehearsal; do not send real mail.
2. Create one published physical product with tracked stock and one published digital product with a ready private file pinned to its variant. Optional local fixture: coupon `FREE100` at 100% (zero-total, provider `local`) when Stripe test keys are absent.
3. As a guest (or a newly registered customer), add physical-only, digital-only, and mixed lines. Complete each checkout. Zero-total orders skip Stripe; paid sandbox orders use hosted Checkout in test mode.
4. Confirm: physical stock held/committed, digital entitlement listed for the buyer, mixed carts charge shipping/tax only on physical lines, no real charge when using `FREE100` / local completion.

Live Stripe sandbox charges require operator credentials that this repository does not ship. Record whether the rehearsal used local zero-total orders, Stripe test mode, or both.

## Private-file denial

Before treating the install as usable:

- `GET /storage/media/{id}/original` and any guessed private key return 404 for anonymous clients.
- `GET /.env` returns 404.
- Public product pages show download labels without storage keys, checksums or original filenames.
- `bin/php artisan operations:health` / `/admin/health` (owner + TOTP) show disk writability without file payloads.
- `bin/php artisan operations:install-check` confirms this runbook is present and required host checks pass, and prints no secrets.

Authorized downloads use fulfillment grants after payment, not a public file server.

## Target time

SPEC target: **30 minutes (1800 seconds)** from “infrastructure and provider credentials are already available” to a completed sandbox purchase, measured with someone other than the implementer.

`ReadInstallationRunbook` and `operations:install-check` encode that 1800-second budget. Automated `OperationsInstall` tests time a clean first-owner + private-file denial + physical/digital/mixed `FREE100` path on the isolated MySQL test database. A live 2 vCPU reference-host stopwatch with a new operator remains recorded as pending until that rehearsal happens.

## Opening the store

Do **not** open sales from this runbook.

- `GET /` must remain HTTP 503 with the closed-storefront message.
- Keep debug off in production.
- Do not enable live Stripe, real SMTP, or marketing workers until health, private-file denial, policy pages, country/tax/payment coverage and backup observation are acceptable.
- Upgrade, rollback and isolated restore guides are T085–T087; this document is clean install only.
