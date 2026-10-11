# Foundation verification — October 10, 2026

This records local evidence for T001, T002, T003, T003a, T004 and T004a. T004b has a workflow and local equivalent checks, but hosted execution remains pending. No installer, owner/customer identity, payment, fulfillment, template-import or marketing task is marked complete.

## Implemented slices

- Repository boundary: independent repository and `codex/foundation`; baseline documentation commit `faf423c`; runnable application foundation commit `9320aab`. Parent repository changes were not staged or altered.
- Generated foundation: selected Laravel v13.11.0 skeleton files, excluding scaffold documentation and optional tooling; preserved the product specifications. Framework dependencies resolve to Laravel 13.35.0. Removed the generic scaffold user identity/seeding so future staff/customer domains can implement the approved separation.
- Runtime/dependency slice: project runtime wrappers, exact PHP 8.4/Node 22.23.3/npm 10.9.9 requirements, committed Composer/npm lockfiles and restricted install hooks.
- PHP tooling slice: Pest/MySQL suite, bootstrap safety guard, Pint and PHPStan/Larastan level 8 without a baseline or rule suppressions.
- Environment slice: dedicated dev/test/browser databases, exact grant checks, migration/rollback and rejected cross-database writes; fixture-only transport settings. Existing foundation tables are infrastructure only; the seeder creates no real identities or fake future catalog records.
- HTTP security slice: production debug disabled during configuration load and provider boot, HTTPS requirement, safe encrypted session defaults, exact trusted host, CSRF/origin protection, nonce CSP and private/error response headers. Private storage serving is disabled.
- Frontend slices: strict Vue/TypeScript configuration, real component tests, server-rendered unopened-store page with a useful no-JavaScript fallback, and an Inertia administration page that exposes no management controls.
- Browser slice: isolated launcher, Chromium smoke/security checks, keyboard interaction and Axe checks at four viewport sizes. The launcher cannot use developer credentials or reuse a developer server.
- CI slice: pinned workflow, guarded disposable environment provisioning, per-domain 90% coverage gate and regression tests, public-page screenshots only; no deploy or production-provider secrets.

Framework-generated files and lockfiles are mechanical scaffolding. Authored behavior was implemented and checked in the slices above, rather than treating scaffold generation as evidence of commerce readiness.

## Local evidence

| Check | Result |
| --- | --- |
| PHP runtime / platform requirements | PHP 8.4.20; all locked package platform requirements pass using `bin/composer` |
| MySQL runtime | MySQL 8.4.5; private socket, networking disabled; three restricted accounts |
| Node/npm | 22.23.3 / 10.9.9; project archive SHA256 verified against official checksums |
| PHP behavior/security suite | 34 tests pass; production config, CSRF, HTTPS, hostile host, error redaction, private storage and database isolation |
| PHP static/format | PHPStan level 8 and Pint pass |
| Vue checks | Type/lint/format pass; 2 component tests pass, including hostile text escaping |
| Production build | Pass; separate storefront/admin entries |
| Real browser | 8 Chromium tests pass; both pages have no Axe WCAG A/AA violations at 320, 768, 1024 and 1440px |
| Native dependency audits | Composer and npm report no known vulnerabilities |
| Registry verification | 236 npm package signatures and 94 attestations verified |
| Guard/probe checks | Empty PHP/Vue selections fail; wrong PHP assertion and incompatible return type detected; initial browser launcher/heading failures detected and fixed |
| CI configuration | YAML parses; ten steps present; environment helper refuses non-CI provisioning |
| Database restart | Project start/stop helper verified; restarted server retains literal grant semantics through `--partial-revokes=ON`; PHP suite passes afterward |
| Visual review | Successful desktop screenshots inspected for both closed pages; screenshots remain ignored runtime artifacts |

Initial failing behavior tests preceded implementation. Browser testing found the router working-directory error and heading whitespace issue. Review also found the early-boot production-debug case and the global Composer PHP mismatch; the former now has a regression test and the latter is prevented by the project Composer wrapper. No security guard was relaxed to resolve these failures.

## Remaining evidence and boundaries

There is no repository remote, hosted CI run, branch protection or local coverage driver. Hosted PCOV generation/provisioning must be verified once a remote is connected. Critical commerce domains do not exist yet, so no coverage percentage is claimed for them. Provider sandboxes remain pending their own tasks.

All responses currently use conservative no-store/noindex policies while the store is closed. Public catalog/page SEO and caching need their own policies when those routes are implemented. Proxy trust is not guessed. Authentication and first-owner setup are absent; the administration stays closed until the protected installer and permission-controlled identity flows pass their acceptance.
