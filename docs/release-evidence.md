# Release evidence

Measured verification for operations rehearsals. This file records what actually ran. It does not certify a production-ready store or a public release.

## T084 clean-install rehearsal

**Date:** 12 November 2026  
**Base:** `origin/main` `81e4da1` (T083 operational diagnostics and audit views, #53)  
**Branch:** `cursor/t084-installation-runbook-46bc`

| Check | Result |
| --- | --- |
| Runbook documents | `docs/installation.md`, `docs/hosting.md`, `.env.example`, this file. Contract asserted by `ReadInstallationRunbook` / `OperationsInstall`. |
| Supported host / services / permissions | Documented in [hosting](hosting.md). PHP 8.4, MySQL 8.4, TLS, `public/` document root, database-only grants, private disk outside webroot, supervised workers/scheduler. |
| Secure owner setup | CLI secret, digest-only storage, 15-minute grant, atomic completion lock. Covered by existing `PlatformInstall` / `IdentityStaff` tests and the T084 rehearsal (`GET /setup` 404 until secret; public `/setup/owner` forbidden; completed install refuses `setup:secret`). |
| `GET /` closed storefront | HTTP 503 asserted in the T084 rehearsal. Storefront-open is out of scope. |
| Private-file denial | Anonymous `GET /storage/{opaque-key}` and `GET /.env` 404 in the T084 rehearsal. |
| Physical / digital / mixed sandbox | Isolated MySQL test database, array mail, `FREE100` local zero-total completions (provider `local`). No Stripe HTTP. No `DemoStoreSeeder`. |
| Elapsed setup time (automated) | `OperationsInstall` asserts the rehearsal finishes under the SPEC 1800-second budget. Exact wall-clock from a hosted Quality run is recorded when that workflow executes on this branch. |
| Real emails | None. `MAIL_MAILER=array` in the example file; the rehearsal uses `Mail::fake()` and `Mail::assertNothingOutgoing()`. |
| Staff 2FA | Owner is redirected to `/admin/two-factor`; privileged session helper enrolls TOTP. No bypass. |

This agent image has **no PHP 8.4 binary** and **no MySQL 8.4 daemon**, so `bin/php artisan test --filter=OperationsInstall`, Pint, PHPStan, Composer audit and Playwright did not execute here.

Frontend on pinned Node 22.23.3 / npm 10.9.9 (`npm ci --ignore-scripts`): `typecheck`, `lint`, `format:check`, Vitest **188/188**, production `build`, `npm audit` 0 vulnerabilities with lockfile signatures. Hosted Quality on the pull request is the PHP/MySQL/Pint/PHPStan/Playwright evidence path.

A local command sequence that operators should run on a machine that does have the runtimes:

```sh
bin/php artisan test --filter=OperationsInstall
bin/php artisan operations:install-check
bin/php artisan setup:check
```

Do not issue or publish a real installation secret in evidence.

## Pending evidence

- Hosted Quality (PHP 8.4, isolated MySQL 8.4, Pint, PHPStan, Vitest, production build, Playwright) on this branch.
- Live reference-host (2 vCPU / 4 GB SSD) clean install: provision TLS, PHP-FPM, MySQL, workers, scheduler, and time a **new operator** (not the implementer) through owner setup to a sandbox purchase.
- Live Stripe **test-mode** physical/digital/mixed charges. This rehearsal used local zero-total `FREE100` orders only; no payment-provider credentials were present.
- Merchant-packaged install without a Node compile step (T085).
- Upgrade/rollback (T086) and isolated backup restore (T087).
- Opening `GET /` (storefront-open slice). Not started.
