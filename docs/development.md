# Development

The secure foundation is runnable. It intentionally returns HTTP 503 for the unopened storefront and administration; there is no owner, public signup or commerce management yet. Protected store settings setup is available after issuing a private CLI installation secret; owner completion follows in the identity milestone. Follow the ordered backlog for those features. Framework scaffolding comes from laravel/laravel v13.11.0; installed framework is 13.35.0.

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

The PHP test guard checks environment, connection overrides, actual server version/database and account grants before any test schema operation. Cached configuration is forbidden. Tests use array mail and synchronous jobs; no external provider credentials are needed.

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

After migrations and required prerequisite checks, `bin/php artisan setup:check` reports configuration needs. `bin/php artisan setup:secret` issues or rotates the 24-hour secret; enter it at `/setup`. Do not put it in a URL, publish command output or commit it. Setup access lasts at most 15 minutes. Save the store details to reach pending-owner state; owner creation and final lock completion are the next identity milestone. Administrative commerce actions remain closed.
