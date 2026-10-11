# Development

The secure foundation is runnable. The unopened storefront returns HTTP 503; unconfigured administration stays closed until protected setup finishes. A private CLI installation secret unlocks store settings and first-owner creation. After permanent installation completion, the staff workspace/invitations and separate customer signup/sign-in/account screens are available. Commerce management and storefront launch remain in the ordered backlog; customer verification/recovery screens are available; editable profiles and encrypted saved addresses are available; guest-resource transfer remains in progress. Framework scaffolding comes from laravel/laravel v13.11.0; installed framework is 13.35.0.

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

PHP and Vue runners reject zero selected tests. The browser suite has no retries and starts its own server; it never reuses a developer server. PHP migrations and browser tests must not share a test database. Local browser traces remain ignored and may contain session cookies; never publish them.

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
