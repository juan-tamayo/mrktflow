# Continuous integration

`.github/workflows/quality.yml` runs on pull requests, main pushes and codex branches. It has read-only repository permissions, disables persisted checkout credentials, pins action revisions and the MySQL image digest, installs lockfiles, and executes formatting, static analysis, PHP/MySQL tests, Vue tests, production build, browser/accessibility tests, vulnerability audits and registry-signature verification. It does not deploy or call live providers.

PHP 8.4, MySQL 8.4, Node 22.23.3 and bundled npm 10.9.9 are the runtime matrix. PHP uses PCOV for coverage. The pinned MySQL image can carry a later 8.4 patch than local 8.4.5; the guard enforces the 8.4 series. Action updates must be reviewed rather than silently following mutable tags.

The job creates a fresh random, masked root password for a disposable container bound only to localhost. Its provisioning helper refuses non-CI use, existing environment files and cached configuration. It creates separate development/PHP/browser databases with random database-only account credentials, writes private environment files, and removes the container on completion. These are ephemeral CI secrets; no hardcoded reusable credentials or merchant secrets are required. Never provide production credentials to this job.

## Coverage gate

The specification requires at least 90% line coverage for money, inventory, payment orchestration and entitlement authorization. `scripts/coverage-gate.php` checks Pricing, Catalog, CheckoutOrders and Fulfillment independently, including their whole domain directories to avoid filename exemptions. Missing reports or PHP files omitted from the report fail the gate. Pricing now has domain code; Catalog, CheckoutOrders and Fulfillment also have domain code. The gate still reports a domain as not applicable only when it has no executable statements. Threshold regression tests verify 89% fails and 90% passes. Coverage does not replace required race, replay and authorization tests. The local PHP runtime has no coverage driver, so Pricing 90% is enforced in hosted Quality with PCOV.

Only public-page failure screenshots are uploaded, retained seven days. CI disables browser traces; reports/cookies/environment/log files are not uploaded. Revisit screenshot publication before browser tests contain personal information.

## Verification status — October 13, 2026

Workflow YAML parses and contains all ten intended steps. The same application quality commands passed locally; the provisioning helper's non-CI rejection was verified. PHP tests and Vue tests reject zero selected cases, an intentionally failing PHP assertion was detected, an incompatible PHP return type was detected by PHPStan, and the browser suite reported its initial failure before the launcher/heading fixes.

Hosted Quality on `main` ran on 2026-10-12: [run 38194214664](https://github.com/juan-tamayo/mrktflow/actions/runs/38194214664). Disposable MySQL provisioning, lockfile installs, Pint/PHPStan/type/lint/format, PHP tests (272 passed, 1450 assertions) and the coverage gate succeeded. `bin/npm run test:unit -- --run` then failed at Vitest startup: laravel-vite-plugin aborts Vite’s `serve` command when `CI` is set, and Vitest resolves `vite.config.ts` with that command. Production build, Playwright, Composer/npm audit and registry signatures did not run on that job.

Local follow-up: omit `laravel-vite-plugin` when `VITEST` is set so unit tests do not start an HMR server; keep the plugin for `vite build`/`npm run dev`. A Vitest check reproduces the CI abort for a real serve and requires config resolve to succeed under `CI=true`. `CI=true bin/npm run test:unit -- --run` passed 21 tests; typecheck, lint, format and `CI=true` production build passed. `LARAVEL_BYPASS_ENV_CHECK` is not used.

PR Quality ([run 38348173653](https://github.com/juan-tamayo/mrktflow/actions/runs/38348173653), [run 38348190167](https://github.com/juan-tamayo/mrktflow/actions/runs/38348190167)): Vitest passed; Playwright failed in `owner can unlock, save and reload interrupted store setup` when filling `/account/verify`. Expected credential was the Laravel exception `Only the guarded browser fixture runtime may read in-memory mail.` The fixture compared `CURRENT_USER()` to `mrktflow_browser@localhost`; CI creates `'mrktflow_browser'@'%'`. Guard now accepts that isolated account for any MySQL host. PHP/Pint/PHPStan were not re-run in this workspace (no PHP 8.4 runtime here).

A successful hosted Quality job on the default branch and required branch checks remain mandatory. Branch protection cannot be configured from this workspace. The local PHP runtime has no coverage extension; coverage percentages are not claimed from that runtime.
