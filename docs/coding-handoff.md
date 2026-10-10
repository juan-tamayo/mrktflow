# Coding handoff

This package is documentation only. Do not interpret it as an instruction to start implementation until the user asks to code.

## Readiness audit — October 10, 2026

The specification is ready to start foundation work with the defaults recorded here; the local application environment is not bootstrapped. The read-only audit found PHP 8.3.31, Composer 2.8.10, Node 22.21.1 and npm 11.17.0. The plan still targets PHP 8.4 and MySQL 8.4. A MySQL 9.6 client is installed, but no server binary was on PATH and no TCP listener on port 3306 was observed; a server at another endpoint/socket remains unverified. Docker is not on PATH. Do not treat a client binary as a running compatible database. See [readiness report](readiness-audit.md).

T002 must select a project-scoped PHP 8.4 runtime and reachable isolated MySQL 8.4 dev/test databases before migrations. Do not replace global runtimes or touch another project database. Runtime provisioning is foundation work, not an unrecorded prerequisite already completed. Current PHP 8.3 can inspect documentation but does not satisfy the chosen project test matrix merely because Laravel itself supports it.

## First coding session

1. Read README, SPEC, capability map, architecture, commerce rules, marketing rules and the current backlog milestone. Inspect current files and user changes before doing anything.
2. Establish `/Users/juantamayo/Sites/mrktflow` as a dedicated repository. Git currently resolves to the parent `/Users/juantamayo/Sites`; do not stage or alter its unrelated files. If no independent repository has been established meanwhile, initialize one inside this folder, then verify `git rev-parse --show-toplevel` returns this exact project directory. Use `codex/` for work branches.
3. Resolve compatible package versions for Laravel 13/PHP 8.4 and Vue 3. Preserve all planning documents. Bootstrap into an isolated staging directory because this folder is no longer empty.
4. Work through tasks in order. Mark a task complete only with its acceptance and verification evidence. If a task exceeds one focused session or about five authored files, split it into lettered subtasks before implementation. Framework-generated scaffolding is recorded separately from authored changes.
5. Record material assumption changes in the spec/decision register. Routine implementation decisions consistent with the proposal need no repeated approval.

## Proposed bootstrap commands

**For the later coding session only.** Check PHP/Composer compatibility first. The first repository step is needed only while this folder is not already its own repository.

```sh
cd /Users/juantamayo/Sites/mrktflow
git init -b main
git rev-parse --show-toplevel
git switch -c codex/foundation
mkdir -p .bootstrap
composer create-project laravel/laravel .bootstrap/laravel "^13.0" --no-interaction --no-scripts
```

Inspect the generated tree, then copy only scaffold files that do not replace existing documentation; exclude its `.git`, README and nested staging artifacts. Perform that merge deliberately rather than running `create-project` over this nonempty directory. Add a project `.gitignore` before staging, excluding secrets, storage data, dependencies and `.bootstrap`.

Once scaffold files and package declarations are merged, configure `.env` for a dedicated local MySQL database, test database and development-only services. Never point tests at a merchant database. Generate the application key once per new environment; preserve it on upgrades.

```sh
composer install
php artisan key:generate
php artisan migrate
npm install
npm run build
```

The generated skeleton scripts and package compatibility must be inspected during bootstrap. `npm install` creates the first lockfile; subsequent reproducible installs use `npm ci`. Do not run demo seeding in production.

Before any application command, copy `.env.example` to a new untracked `.env` only if absent, configure the dedicated database endpoints and run `composer check-platform-reqs`. Laravel create-project uses `--no-scripts`, so verify the merged scaffold scripts and run the appropriate post-autoload discovery after configuration. Guard the test database by a separate database name and least-privilege credentials. Production-like migrations and tests must fail closed when that guard cannot verify the environment.

## Required command surface after foundation

These commands are target scripts to configure and verify during M0. They do not exist today. Pin exact tools and script meanings in the eventual package manifests and update this document if a command changes.

| Purpose | Command | Required behavior |
| --- | --- | --- |
| PHP server | `php artisan serve --host=127.0.0.1 --port=8000` | Development only |
| Asset development | `npm run dev` | Vite development server |
| Worker | `php artisan queue:work database --queue=payments,default,mail --tries=3 --timeout=60` | Set this connection retry-after to 90 seconds; reserve capacity for payments/mail |
| Long-work worker | `php artisan queue:work database_long --queue=ai,media,hubs,marketing --tries=3 --timeout=300` | Define database_long with retry-after 360 seconds and explicit job routing; separate supervised worker |
| Scheduler | `php artisan schedule:work` | Development scheduling; production invokes schedule:run each minute |
| PHP tests | `php artisan test` | Pest suite against dedicated MySQL test database where required |
| Focused module tests | `php artisan test --filter=Catalog` | Test class/description naming includes module prefix |
| Unit coverage | `php artisan test --coverage` | Supported coverage extension configured in CI |
| PHP format check | `vendor/bin/pint --test` | No mutation |
| PHP static checks | `vendor/bin/phpstan analyse --memory-limit=1G` | Level 8 application analysis |
| Vue tests | `npm run test:unit -- --run` | Vitest once |
| Type check | `npm run typecheck` | vue-tsc --noEmit |
| Frontend lint | `npm run lint` | ESLint check, no auto-fix in CI |
| Frontend format | `npm run format:check` | Prettier check |
| Build | `npm run build` | Admin/storefront/default-theme assets |
| Browser tests | `npm run test:e2e -- --project=chromium` | Playwright critical suite; separate test application/DB |

Worker timeout must remain below queue retry-after/visibility settings. T006 configures the explicit 60/90 and 300/360 timeout/retry-after pairs, timeout-bounded HTTP requests and connection/queue routing; test worker death/retry without simultaneous duplicate execution. Split jobs that exceed those limits. The default local command is not a production process-manager configuration.

T003a owns PHP/Pest/static/format command setup; T004a owns the browser harness; T004b owns provider-neutral CI using PHP 8.4/MySQL 8.4 and a pinned compatible Node version. CI must fail on zero selected tests, invalid test database, lint/type errors and build failures. It uses fixtures/fakes for external providers and never requires merchant production secrets.

Custom installer/theme/health/export commands will be designed and documented in their tasks, not represented as existing framework commands here.

## Dependency checkpoints

T004 creates only the test-database guard and fixture primitives; real catalog/cart seeders land with their migrations in T020/T025. T009 tests customer identity primitives; historical guest-order claims are completed with T043/T050. M0 provider spikes use isolated synthetic fixtures rather than depending on future order/catalog tables. Missing provider test credentials leave only their real-provider evidence pending: catalog/theme work can proceed on contract fixtures, while T035 live sandbox acceptance and T074 provider smoke remain gated on actual test evidence. Never mark those external checks passed from a fake response.

## Marketing expansion execution order

Task IDs remain stable after the scope expansion. T091 supplies shared contact preferences in M5 before watchlist subscriptions; T092–T120 remain in M9/M10. M9/M10 add native campaigns, coupon/referral engines and SEO hubs; execute them before the final M8 release gates T085–T090. Follow listed dependencies and document order, not numeric task ID alone. No marketing task is optional for the first public release. Bulk-mail, feedback, reward and hub jobs need isolated bounded queues; development fixtures must never email real contacts.

## Done for a coding task

Acceptance demonstrated, focused checks pass, relevant full build/smoke passes, failure/retry/authorization paths considered, docs/contracts updated and task evidence recorded. Never tick a task merely because files were created. M0 is complete only after a local framework application runs; the current documentation package does not satisfy that milestone.

## Suggested future start request

“Start MRKTFlow milestone M0 using the specification and tasks/todo.md. Keep the project in its own repository, preserve the planning documents, and implement only the foundation and early validation tasks.”
