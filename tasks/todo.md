# Implementation backlog

Status: secure foundation implementation started October 10, 2026. T001–T004a and T005 have local verification evidence; T004b is configured with remote execution evidence pending. Remaining tasks are planned. Prepared October 5, 2026; readiness audit October 10, 2026.

Every task includes its requirement IDs, dependencies, acceptance, verification and likely authored files. Readiness revision 0.3 adds T003a/T004a/T004b, moves foundational T091 before watchlist consumers, and retains T092–T120 before M8; follow document order and dependencies, not numeric ID alone. Paths below are **planned** and do not imply those files exist. Module test prefixes and command scripts must be established in M0. File lists are sizing estimates: split work into lettered subtasks before coding if it exceeds one focused session or approximately five authored files; record generated framework scaffolding separately. See [plan](plan.md) and [coding handoff](../docs/coding-handoff.md).

## M0: Foundation and early risk checks

### T001: Project repository boundary

- [x] Complete T001.
- **Evidence:** Dedicated mrktflow Git root and codex/foundation branch; planning baseline commit faf423c; parent repository untouched.
- **Module / requirements:** `platform` · PLAT-01.
- **Depends on:** —.
- **Acceptance:** Dedicated repository root is mrktflow; planning files preserved; parent changes untouched.
- **Verify:** Verify `git rev-parse --show-toplevel` and inspect scoped status.
- **Likely files:** `README.md`, `.gitignore`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T002: Laravel scaffold and runtime locks

- [x] Complete T002.
- **Evidence:** [Foundation verification](../docs/foundation-verification.md), October 10, 2026.
- **Module / requirements:** `platform` · PLAT-01.
- **Depends on:** T001.
- **Acceptance:** Select a project-scoped PHP 8.4 runtime, compatible locked Composer packages and reachable isolated MySQL 8.4 dev/test databases; record SELECT VERSION() without logging secrets. Laravel skeleton starts with planning documents preserved. Existing PHP 8.3 or a MySQL client alone does not satisfy this task.
- **Verify:** Run `php -v`, `composer check-platform-reqs`, `php artisan about` and scaffold smoke tests; verify database server version and separate test credentials before migrations. No global runtime replacement or unrelated database changes.
- **Likely files:** `composer.json`, `composer.lock`, `.env.example`, `config/database.php`.
- **Scope:** Mechanical framework scaffold plus ≤4 authored configuration files; inspect generated tree separately.

### T003: Vue build and quality commands

Implementation slices: (1) package/build/type configuration, (2) frontend lint/test tooling, (3) storefront mount and tested component, (4) Inertia admin mount. Verify each slice before integration; retain this parent task’s acceptance.

- [x] Complete T003.
- **Evidence:** [Foundation verification](../docs/foundation-verification.md), October 10, 2026.
- **Module / requirements:** `platform` · PLAT-01.
- **Depends on:** T002.
- **Acceptance:** Vue/TypeScript admin and storefront entrypoints build; frontend type/lint/format/unit scripts and a real smoke component test execute; selected compatible Node/tool versions are pinned. PHP, browser and CI setup are T003a/T004a/T004b.
- **Verify:** Run `npm run build`, `npm run typecheck`, `npm run lint` and `npm run test:unit -- --run`.
- **Likely files:** `package.json`, `package-lock.json`, `vite.config.ts`, `tsconfig.json`, `resources/js/app.ts`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

#### Checkpoint after T001, T002, T003

- [x] Acceptance and focused verification recorded; no zero-test pass.
- [x] Relevant build/browser flow passes, including failure, permission and replay cases.
- [x] Changes documented; pending external-provider evidence remains explicit.

Evidence: [foundation verification](../docs/foundation-verification.md). Setup/identity replay scenarios remain assigned to their later tasks; closed management paths currently deny anonymous requests.

### T003a: PHP quality and test toolchain

- [x] Complete T003a.
- **Evidence:** [Foundation verification](../docs/foundation-verification.md), October 10, 2026.
- **Module / requirements:** `platform` · PLAT-01.
- **Depends on:** T002, T003.
- **Acceptance:** Pin compatible Pest, Pint and PHPStan/Laravel tooling; configure real PHP smoke/unit tests, static analysis and non-mutating format checks. A zero-test result is never a pass.
- **Verify:** Run `php artisan test`, `vendor/bin/pint --test` and `vendor/bin/phpstan analyse --memory-limit=1G`; deliberately fail a smoke assertion and a type check to verify detection.
- **Likely files:** `composer.json`, `phpstan.neon`, `phpunit.xml`, `tests/Unit/PlatformSmokeTest.php`.
- **Scope:** Small/medium foundation slice; generated lockfile changes are recorded separately.

### T004: Test database and demo fixtures

- [x] Complete T004.
- **Evidence:** [Foundation verification](../docs/foundation-verification.md), October 10, 2026.
- **Module / requirements:** `platform` · PLAT-06.
- **Depends on:** T002, T003a.
- **Acceptance:** Distinct MySQL test database and least-privilege credentials are enforced by a fail-closed test guard. Seed only existing foundation tables and synthetic fixture values here; real physical/digital/catalog/cart seeders land with T020/T025 rather than referencing future tables.
- **Verify:** Run `php artisan test --filter=PlatformEnvironment`; verify test guard rejects a production database.
- **Likely files:** `phpunit.xml`, `tests/TestCase.php`, `database/seeders/DemoStoreSeeder.php`, `tests/Feature/PlatformEnvironmentTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T004a: Browser harness and isolated application

- [x] Complete T004a.
- **Evidence:** [Foundation verification](../docs/foundation-verification.md), October 10, 2026.
- **Module / requirements:** `platform` · PLAT-01.
- **Depends on:** T003, T004.
- **Acceptance:** Configure Playwright with a separate test application/database, web-server lifecycle and Chromium project. A real page smoke test runs; no production provider credentials or shared development data.
- **Verify:** Run `npm run test:e2e -- --project=chromium`; verify database isolation and intentional browser-test failure reporting.
- **Likely files:** `package.json`, `playwright.config.ts`, `tests/Browser/platform-smoke.spec.ts`, `tests/Browser/global-setup.ts`.
- **Scope:** Small/medium foundation slice; generated lockfile changes are recorded separately.

#### Checkpoint after T003a, T004, T004a

- [x] Acceptance and focused verification recorded; no zero-test pass.
- [x] Relevant build/browser flow passes, including failure, permission and replay cases.
- [x] Changes documented; pending external-provider evidence remains explicit.

Evidence: [foundation verification](../docs/foundation-verification.md). Setup/identity replay scenarios remain assigned to their later tasks; closed management paths currently deny anonymous requests.

### T004b: Continuous integration and quality gates

- [ ] Complete T004b.
- **Progress:** Workflow and coverage gate implemented. Hosted Quality on main ([run 38194214664](https://github.com/juan-tamayo/mrktflow/actions/runs/38194214664), 2026-10-12): PHP 272/272, then Vitest aborted on laravel-vite-plugin HMR/`CI` check. Vitest fix is on [PR #2](https://github.com/juan-tamayo/mrktflow/pull/2). Follow-up hosted Quality ([run 38348173653](https://github.com/juan-tamayo/mrktflow/actions/runs/38348173653) / [run 38348190167](https://github.com/juan-tamayo/mrktflow/actions/runs/38348190167)): PHP, coverage gate, Vitest, and production build passed; Playwright failed because `tests/Fixtures/customer-browser-mail.php` required `CURRENT_USER()` `mrktflow_browser@localhost` while disposable CI creates `'mrktflow_browser'@'%'`. Fixture now accepts the isolated browser account at any MySQL host; unit cases cover `@localhost` and `@%`. Hosted Quality after that change, required branch checks, and merge to main remain pending. See [CI evidence](../docs/continuous-integration.md).
- **Module / requirements:** `platform` · PLAT-01.
- **Depends on:** T003a, T004a.
- **Acceptance:** Add CI on PHP 8.4/MySQL 8.4 and pinned Node, using lockfile installs and fixture-only provider tests. Check formatting/static types/PHP/MySQL/Vue/build/browser smoke; configure a coverage driver and the documented domain coverage gate as domain code lands; upload diagnostics without secrets.
- **Verify:** Validate workflow configuration and run the same commands locally; once a repository host is configured, require a successful CI run. Record pending remote CI evidence rather than inventing it.
- **Likely files:** `.github/workflows/ci.yml`, `package.json`, `composer.json`, `docs/continuous-integration.md`.
- **Scope:** Small/medium foundation slice; generated lockfile changes are recorded separately.

### T005: Protected installer and settings

Implementation slices: T005a — CLI secret lifecycle, database lock/settings aggregate and prerequisite checks; T005b — protected HTTP session and setup UI; T005c — browser/replay/resume verification. Initial-owner creation and final completion remain T008.

- **Evidence October 10:** T005a–T005c implemented and locally verified: CLI secret lifecycle, protected session/Vue setup, settings retries, and real browser resume/CSRF/accessibility checks. [Installer progress](../docs/installer-progress.md). Initial owner and final completion remain T008; hosted CI evidence remains pending T004b.

- [x] Complete T005.
- **Module / requirements:** `platform` · PLAT-01, PLAT-02, PLAT-03.
- **Depends on:** T003, T004, T004b.
- **Acceptance:** Secret-protected setup creates validated store settings and a pending-owner state; replay/resume behavior defined. Identity supplies owner creation and final lock completion in T008.
- **Verify:** Run `php artisan test --filter=PlatformInstall`; manually exercise interrupted setup and invalid prerequisites.
- **Likely files:** `app/Domain/Platform/Actions/InstallStore.php`, `database/migrations/create_installation_tables.php`, `app/Http/Controllers/Admin/InstallController.php`, `resources/js/admin/pages/Install.vue`, `tests/Feature/PlatformInstallTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T006: Durable jobs and health primitives

- **Evidence October 10:** Encrypted transactional outbox, replay-safe local consumption, bounded retry/quarantine/recovery, real killed-worker restart, scheduler heartbeat/private health, request correlation and setup audit verified. Full PHP suite: 83 tests / 350 assertions; static/frontend/browser checks pass. [Evidence](../docs/outbox-progress.md), [runbook](../docs/background-work.md). Hosted CI remains pending T004b.

- [x] Complete T006.
- **Module / requirements:** `platform` · PLAT-05.
- **Depends on:** T004.
- **Acceptance:** Durable outbox and consumer receipts recover after interruption; explicitly configure database workers with timeout/retry-after 60/90 seconds and long-work workers 300/360, bounded HTTP timeouts and queue routing. Scheduler heartbeat and failed-job state are readable.
- **Verify:** Run `php artisan test --filter=PlatformOutbox`; interrupt and restart the local worker, verify recovery.
- **Likely files:** `database/migrations/create_event_tables.php`, `app/Domain/Platform/Actions/RecordEvent.php`, `app/Jobs/DispatchOutbox.php`, `routes/console.php`, `tests/Feature/PlatformOutboxTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

#### Checkpoint after T004b, T005, T006

- [ ] Acceptance and focused verification recorded; no zero-test pass.
- [ ] Relevant build/browser flow passes, including failure, permission and replay cases.
- [ ] Changes documented; pending external-provider evidence remains explicit.

### T007: Media storage boundary

- **Evidence October 10:** Private intake, safe image derivatives, queued/real-worker crash recovery, bounded cleanup and media health verified. Full PHP suite: 104 tests / 451 assertions; static/frontend/build/browser checks pass. Staff upload routes remain unmounted until T008; scanner/large-file host/remote CI evidence remain separate release work. [Evidence](../docs/media-progress.md).

- [x] Complete T007.
- **Module / requirements:** `platform` · PLAT-04.
- **Depends on:** T005.
- **Acceptance:** Public images and private files use separate storage policies and MIME/size/path validation. Test storage services with synthetic authorization context; admin upload routes remain denied/unmounted until staff policies exist in T008.
- **Verify:** Run `php artisan test --filter=PlatformMedia`; inspect anonymous requests to a private asset.
- **Likely files:** `config/filesystems.php`, `app/Domain/Platform/Actions/StoreMedia.php`, `app/Http/Controllers/Admin/MediaController.php`, `database/migrations/create_media_assets.php`, `tests/Feature/PlatformMediaTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T008: Staff session and permission foundation

- [x] Complete T008.
- **Evidence October 10:** Atomic owner/install locking, separate staff principals, role gates, versioned sessions, protected staff management, real concurrent last-owner protection and directly shared single-use invitation UI verified. Full PHP suite: 128 tests; frontend/static/build and 10 browser tests pass, including logout history-key retirement. Automatic email delivery remains T079, and privileged 2FA/re-authentication remains T013 before launch. See [identity evidence](../docs/identity-progress.md).
- **Module / requirements:** `identity` · ID-02, ID-04.
- **Depends on:** T005.
- **Acceptance:** Staff login/invitation and permission policy prevent customer privilege escalation; final-owner protection is enforced. Application setup coordination creates the initial owner and finalizes T005's persistent installation lock without a reverse platform import.
- **Verify:** Run `php artisan test --filter=IdentityStaff`; exercise owner/limited-staff admin access.
- **Likely files:** `database/migrations/create_staff_tables.php`, `app/Domain/Identity/Actions/InviteStaff.php`, `app/Policies/StaffPolicy.php`, `resources/js/admin/pages/Staff.vue`, `tests/Feature/IdentityStaffTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T009: Customer account and guest ownership

- [ ] Complete T009.
- **Progress:** Separate customer model/provider, atomic registration, versioned sessions and transaction-scoped verified identity proof implemented. Server-rendered signup/sign-in/logout and read-only account screens now work, including without JavaScript; customer/staff isolation, rate limits, nested credential handling and browser logout history checked. Verification/reset token issuance and atomic single-use consumption are now tested, including a real MySQL recovery race. Encrypted durable email intent, ID-only queue publication, bounded retries/expiry cleanup and private recovery commands are tested internally. Public verification/recovery forms and atomic signup/resend integration now work, with request limits, credential flash/trim protections, session rotation and native code fallback. Profile editing, password-confirmed email changes and encrypted saved-address management now work with version conflicts, account ownership and native browser forms. Actual guest transfer remains pending until its commerce/watchlist resources exist. See [identity progress](../docs/identity-progress.md).
- **Module / requirements:** `identity` · ID-01, ID-05.
- **Depends on:** T008.
- **Acceptance:** Registration/login/reset/verification and guest session ownership work with separate staff/customer guards. Define verified identity proof now; complete and test historical guest-order claiming later in T043/T050 when order tables exist.
- **Verify:** Run `php artisan test --filter=IdentityCustomer`; browser-test reset and session rotation.
- **Likely files:** `app/Domain/Identity/Actions/ClaimGuestIdentity.php`, `database/migrations/create_customer_tables.php`, `app/Http/Controllers/Storefront/AccountController.php`, `resources/views/system/account.blade.php`, `tests/Feature/IdentityCustomerTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

#### Checkpoint after T007, T008, T009

- [ ] Acceptance and focused verification recorded; no zero-test pass.
- [ ] Relevant build/browser flow passes, including failure, permission and replay cases.
- [ ] Changes documented; pending external-provider evidence remains explicit.

### T010: Theme rendering spike

- [x] Complete T010 locally.
- **Evidence:** Trusted default Blade product view and compiled Vue variant island share bounded public DTOs. Authenticated example preview, native no-JavaScript selection, permissions, escaping, safe-integer money display and static asset serving verified. Final local checks: 221 PHP tests (1,300 assertions), 19 frontend tests and 10 Chromium journeys; Pint/PHPStan/type/lint/format/build passed. No Node rendering service required. Registry/activation and complete storefront remain T061 onward. See [rendering findings](../docs/decisions/002-rendering-and-themes.md).
- **Module / requirements:** `themes-content` · THEME-01, WEB-02.
- **Depends on:** T003, T007.
- **Acceptance:** One Blade product view and one compiled Vue interaction consume public DTOs; no server Node process required; findings recorded.
- **Verify:** Run `npm run build` and `php artisan test --filter=ThemesContentSpike`; inspect rendered HTML with JavaScript disabled.
- **Likely files:** `themes/default/theme.json`, `themes/default/views/product.blade.php`, `themes/default/src/VariantPicker.vue`, `tests/Feature/ThemesContentSpikeTest.php`, `docs/decisions/002-rendering-and-themes.md`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T011: Payment and reservation risk spike

- [x] Complete T011 locally; live provider sandbox evidence remains T035.
- **Implementation slices:** T011a defines frozen payment/refund intents and bound observation contracts; T011b demonstrates isolated MySQL locking/rollback and records provider reconciliation limits. Neither slice introduces a live provider adapter or production order/catalog schema.
- **Evidence October 10:** 13 gateway contract tests and an independent-connection MySQL last-unit/rollback test executed. Full PHP suite: 245 tests / 1382 assertions; Pint, PHPStan level 8, frontend type/lint/format/build, 19 frontend tests and 10 Chromium journeys pass. Coverage gate fixture now includes implemented critical-domain files and proves every domain independently retains the 90% threshold; measured coverage/hosted CI remains T004b. No provider sandbox call or SDK added. [Contracts and limits](../docs/commerce-rules.md#paymentreservation-spike-t011).
- **Module / requirements:** `checkout-orders` · ORDER-03, ORDER-04, CAT-05.
- **Depends on:** T006.
- **Acceptance:** Using isolated synthetic records, document payment session expiry/cancel/retrieve/refund and unknown-outcome contracts; demonstrate MySQL row locking without depending on future catalog/order tables. Run real provider sandbox checks only with test credentials.
- **Verify:** Run `php artisan test --filter=CheckoutOrdersGateway` and `php artisan test --filter=CheckoutOrdersConcurrency` with at least one executed test each. Missing test credentials leave sandbox evidence pending; independent M1 work may continue, but T035 sandbox acceptance remains blocked on actual evidence.
- **Likely files:** `app/Domain/CheckoutOrders/Contracts/PaymentGateway.php`, `tests/Contract/CheckoutOrdersGatewayTest.php`, `tests/Feature/CheckoutOrdersConcurrencyTest.php`, `docs/commerce-rules.md`, `docs/decisions/003-durable-commerce.md`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T012: AI provider/schema risk spike

- [x] Complete T012 locally; exact-model live provider evidence remains T074.
- **Implementation slices:** T012a adds a bounded page-draft schema shared at the themes/content boundary, a public-context request, provider/result contract and provider-shaped fixture tests (five authored files). T012b records provider/model options, failure/budget/privacy limits and future importer/live-adapter acceptance; no content tables or live generation are introduced by this spike. Other authoring operations remain T074–T078.
- **Evidence October 10:** 27 AI contract fixtures / 78 assertions cover bounded public context, strict data sections/references, prompt injection without capabilities, refusals/incomplete/tool calls, malformed/unbound usage and JSON object/list fidelity. Full PHP suite: 272 tests / 1473 assertions; Pint, PHPStan level 8, frontend type/lint/format/build, 19 frontend tests and 10 Chromium journeys pass. Independent model review found one object/list mismatch, corrected with failing-then-passing fixtures and confirmed resolved. Composer/npm vulnerability audits and registry signatures pass (236 signatures / 94 attestations). No provider request, SDK, enabled key or publication action. [Schema and limits](../docs/template-contracts.md#implemented-t012-page-draft-spike), [options evaluated](../docs/open-decisions.md#d08-t012-providerschema-findings-october-10-2026).
- **Module / requirements:** `ai-authoring` · AI-01, AI-04.
- **Depends on:** T010.
- **Acceptance:** Define a bounded AI authoring adapter/schema and validate provider-shaped fixtures without future content tables or private data. Evaluate compatible provider/model options; live provider selection/usage evidence requires test credentials.
- **Verify:** Run `php artisan test --filter=AiAuthoringContract` with at least one executed test. Record provider smoke only if credentials exist; keep missing live evidence pending for T074 rather than blocking unrelated catalog work.
- **Likely files:** `app/Domain/AiAuthoring/Contracts/AiAuthoringProvider.php`, `tests/Contract/AiAuthoringContractTest.php`, `docs/open-decisions.md`, `docs/template-contracts.md`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

#### Checkpoint after T010, T011, T012

- [x] Local spike acceptance and focused verification recorded; no zero-test pass.
- [x] Relevant built theme/browser flows and local failure/permission/replay guards pass; live payment/AI replay and integration acceptance remain T035/T074.
- [x] Changes documented; pending external-provider evidence remains explicit.

## M1: Catalog and merchant/product foundation

### T013: Privileged staff two-factor authentication

- [x] Complete T013 locally; QR provisioning, optional 2FA for non-privileged roles, and refund/credential reauth screens remain later work.
- **Evidence October 13:** Owners and staff with settings, integrations, refunds, or publish permission must enroll a SHA-1/6-digit/30s TOTP before the workspace. Secrets and recovery hashes are encrypted and bound to the staff UUID; recovery codes are single-use and wipe the authenticator so a lost device must re-enroll. Staff invitation and access mutations require a 15-minute password+code reauthentication. RFC 6238 vectors, replay, ciphertext swap, expired pending enrollment, expired reauth, CSRF, and customer isolation are covered. Full PHP suite: 278 tests / 1581 assertions; Pint; PHPStan level 8; frontend type/lint/format/build; 21 Vue tests; 10 Chromium journeys including enrollment, login challenge, and lost-device recovery. Vitest CI skip and browser-mail `mrktflow_browser@%` account check landed on main via #2 (`ff1c93c`); this branch is rebased on that. Hosted Quality for the rebased 2FA PR is pending. [Identity evidence](../docs/identity-progress.md).
- **Implementation slices:** T013a verifies a maintained OTP library, bounded authenticator profile and standard vectors; T013b adds encrypted, account-bound enrollment and transactionally single-use TOTP/recovery state; T013c connects enrollment/challenge/login enforcement and scoped fresh reauthentication to native/admin flows. Local slices and browser/lost-device/expiry checks passed; QR codes and later refund/credential screens are remaining gaps.
- **Module / requirements:** `identity` · ID-03.
- **Depends on:** T008.
- **Acceptance:** Privileged staff require 2FA; recovery codes are single-use; sensitive actions require reauthentication.
- **Verify:** Run `php artisan test --filter=IdentityTwoFactor`; manually verify lost-device recovery and expired reauth.
- **Likely files:** `app/Domain/Identity/Actions/ManageTwoFactor.php`, `app/Http/Controllers/Admin/TwoFactorController.php`, `resources/js/admin/pages/TwoFactor.vue`, `tests/Feature/IdentityTwoFactorTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T014: Create a simple physical product

- [x] Complete T014 locally; hosted Quality CI evidence was not collected from this agent.
- **Evidence October 13:** Failing `CatalogProduct` tests were written first, then product tables, `SaveProduct`, `CatalogReader`, staff product admin, and public/draft isolation. Rebased onto `main` at `3d10e35` (T013). Privileged staff still must pass 2FA before catalog admin; product routes sit behind `RequireStaffSession` with no 2FA bypass. Hosted Quality for the rebased PR is pending. No cart/checkout. `GET /` remains 503.
- **Module / requirements:** `catalog` · CAT-01, CAT-02.
- **Depends on:** T007, T008.
- **Acceptance:** Merchant saves/publishes a simple physical product with one variant/SKU, price and image; drafts remain private.
- **Verify:** Run `php artisan test --filter=CatalogProduct` and `npm run build`; inspect public versus draft response.
- **Likely files:** `database/migrations/create_product_tables.php`, `app/Domain/Catalog/Actions/SaveProduct.php`, `app/Http/Controllers/Admin/ProductController.php`, `resources/js/admin/pages/ProductEdit.vue`, `tests/Feature/CatalogProductTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T015: Categories and public product browse

- [x] Complete T015 locally; hosted Quality CI evidence was not collected from this agent.
- **Evidence October 13:** Failing `CatalogCategory` tests were written first, then category tables, `SaveCategory`, public `CatalogReader` browse, staff category admin, theme category/product pages, and slug redirects. Category trees reject cycles, path collisions, and reserved slugs. Published category and product pages render server-side with breadcrumbs; drafts, missing products, and unpublished categories stay 404. Privileged staff still must pass 2FA before catalog admin. No cart/checkout. `GET /` remains 503. Full PHP suite: 299 tests / 1777 assertions; Pint; PHPStan level 8; frontend type/lint/format/build; 29 Vue tests; 10 Chromium journeys including empty category, breadcrumbs, missing product, and closed homepage. Composer/npm audits and 237 registry signatures passed. Hosted Quality for this branch is pending.
- **Module / requirements:** `catalog` · CAT-03, WEB-01, WEB-03.
- **Depends on:** T010, T014.
- **Acceptance:** Category tree rejects cycles/path collisions; published product/category pages render server-side with basic navigation.
- **Verify:** Run `php artisan test --filter=CatalogCategory`; browser-check breadcrumbs, missing product and empty category.
- **Likely files:** `database/migrations/create_categories.php`, `app/Domain/Catalog/Actions/SaveCategory.php`, `app/Http/Controllers/Storefront/CatalogController.php`, `themes/default/views/category.blade.php`, `tests/Feature/CatalogCategoryTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

#### Checkpoint after T013, T014, T015

- [ ] Acceptance and focused verification recorded; no zero-test pass.
- [ ] Relevant build/browser flow passes, including failure, permission and replay cases.
- [ ] Changes documented; pending external-provider evidence remains explicit.

### T016: Variants and option combinations

- [x] Complete T016 locally; hosted Quality CI evidence was not collected from this agent.
- **Evidence October 13:** Failing `CatalogVariant` tests were written first, then variant option tables, `SaveVariants`, staff `VariantEditor`, public `CatalogReader` variant snapshots, and live product selection. Unique SKUs and option combinations are rejected; shoppers select a variant and see current price/media, with missing selection and unavailable combinations prompted. Privileged staff still must pass 2FA before catalog admin. No cart/checkout. `GET /` remains 503. Full PHP suite: 304 tests / 1828 assertions; Pint; PHPStan level 8; frontend type/lint/format/build; 31 Vue tests; 10 Chromium journeys including variant save and public selection. Composer/npm audits and 237 registry signatures passed. Hosted Quality for this branch is pending.
- **Module / requirements:** `catalog` · CAT-02, WEB-03.
- **Depends on:** T014.
- **Acceptance:** Unique SKU/option combinations validated; shopper selects variant and sees correct current price/media/availability.
- **Verify:** Run `php artisan test --filter=CatalogVariant` and `npm run test:unit -- --run`; verify unavailable combinations.
- **Likely files:** `app/Domain/Catalog/Actions/SaveVariants.php`, `app/Http/Requests/SaveVariantsRequest.php`, `resources/js/admin/components/VariantEditor.vue`, `themes/default/src/VariantPicker.vue`, `tests/Feature/CatalogVariantTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T017: Typed product schema editor

- [x] Complete T017 locally; hosted Quality CI evidence was not collected from this agent.
- **Evidence October 13:** Failing `CatalogSchema` tests were written first, then schema/attribute tables, `SaveProductSchema`, staff schema editor, product assignment, typed `attribute_values`, and public specification display. Invalid numeric, enum, extra, and missing required values are rejected. Semantic attribute keys cannot change type. Privileged staff still must pass 2FA before catalog admin. No cart/checkout. `GET /` remains 503. Hosted Quality on `ff9a3ab` failed the Catalog coverage gate at 86.28%. Additional `CatalogSchema` tests cover unused-field updates, assigned-schema field lock, string/integer/date/false boolean storage, schema clear, duplicate keys, units on text, unknown schema, float decimals, and HTTP patch/409. Full PHP suite after that: 316 tests / 1948 assertions; Pint; PHPStan; local PCOV Catalog 93.50% (gate 90%). Frontend type/lint/format/build, 36 Vue tests, and 10 Chromium journeys were recorded on the first T017 commit. Composer/npm audits passed then; 237 registry signatures / 95 attestations. Hosted Quality after the coverage tests is pending.
- **Module / requirements:** `catalog` · CAT-04.
- **Depends on:** T014.
- **Acceptance:** Create a versioned schema with typed fields, enum choices and comparison semantics; product values validate against it.
- **Verify:** Run `php artisan test --filter=CatalogSchema`; test invalid numeric, enum and required values.
- **Likely files:** `database/migrations/create_schema_tables.php`, `app/Domain/Catalog/Actions/SaveProductSchema.php`, `resources/js/admin/pages/ProductSchema.vue`, `resources/js/admin/components/AttributeFields.vue`, `tests/Feature/CatalogSchemaTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T018: Schema migration preview

- [x] Complete T018 locally; hosted Quality CI evidence was not collected from this agent.
- **Evidence October 13:** Failing `CatalogSchemaMigration` tests were written first, then `PreviewSchemaMigration`, `ApplySchemaMigration`, staff `SchemaMigration` preview/apply, and assigned-schema versioning. An additive optional field previews affected products and comparison/filter changes, then creates a new schema version while keeping prior values. Incompatible type changes and required fields that invalidate products are rejected; the prior version, pointer, and values stay intact. Privileged staff still must pass 2FA before catalog admin. No cart/checkout. `GET /` remains 503. Full PHP suite: 324 tests / 2026 assertions; Pint; PHPStan level 8; frontend type/lint/format/build; 39 Vue tests; 10 Chromium journeys including schema migration preview/apply. Composer/npm audits passed; 237 registry signatures / 95 attestations. Local PCOV Catalog coverage was not collected (no coverage driver). Hosted Quality for this branch is pending.
- **Module / requirements:** `catalog` · CAT-04.
- **Depends on:** T017.
- **Acceptance:** New schema version previews affected products and invalid values; failed conversion leaves prior values/version intact.
- **Verify:** Run `php artisan test --filter=CatalogSchemaMigration`; migrate an additive field and reject an incompatible type change.
- **Likely files:** `app/Domain/Catalog/Actions/PreviewSchemaMigration.php`, `app/Domain/Catalog/Actions/ApplySchemaMigration.php`, `resources/js/admin/components/SchemaMigration.vue`, `tests/Feature/CatalogSchemaMigrationTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

#### Checkpoint after T016, T017, T018

- [ ] Acceptance and focused verification recorded; no zero-test pass.
- [ ] Relevant build/browser flow passes, including failure, permission and replay cases.
- [ ] Changes documented; pending external-provider evidence remains explicit.

### T019: Stock balance and reservation contract

- [x] Complete T019 locally; hosted Quality CI evidence was not collected from this agent.
- **Evidence October 13:** Failing `CatalogInventory` tests were written first, then inventory tables, `InventoryAllocator`/`AllocateInventory`, `AdjustStock`, and staff `StockEditor`. Tracked allocations store original/shipped/released/remaining quantities; partial ship/cancel conserves `original = shipped + released + remaining`. Hold/commit/release/ship and ledger writes are atomic and idempotent; adjustments cannot undercut reserved units. Parallel last-unit holds on isolated MySQL 8.4 produced exactly one winner. Privileged staff still must pass 2FA before catalog admin. No cart/checkout. `GET /` remains 503. Full PHP suite: 332 tests / 2128 assertions; Pint; PHPStan level 8; frontend type/lint/format/build; 40 Vue tests; 10 Chromium journeys including tracked stock and a low-stock indicator. Composer/npm audits passed; 237 registry signatures / 95 attestations. Local PCOV Catalog coverage was not collected (no coverage driver). Hosted Quality for this branch is pending.
- **Module / requirements:** `catalog` · CAT-05.
- **Depends on:** T011, T016.
- **Acceptance:** Tracked allocations store original/shipped/released/remaining quantities; partial shipment/cancel conserves their sum. Hold/commit/release/ship and ledger writes are atomic/idempotent; adjustments cannot undercut allocations.
- **Verify:** Run `php artisan test --filter=CatalogInventory` on MySQL; parallel last-unit holds yield exactly one winner.
- **Likely files:** `database/migrations/create_inventory_tables.php`, `app/Domain/Catalog/Contracts/InventoryAllocator.php`, `app/Domain/Catalog/Actions/AllocateInventory.php`, `app/Domain/Catalog/Actions/AdjustStock.php`, `tests/Feature/CatalogInventoryTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T020: Digital product and private asset versions

- [x] Complete T020 locally; hosted Quality CI evidence was not collected from this agent.
- **Evidence October 13:** Failing `CatalogDigitalAsset` tests were written first, then digital asset tables, `VersionDigitalAsset`, staff `DigitalAssets`, public download labels without storage keys, and `DemoStoreSeeder` (refused in production; not called from `DatabaseSeeder`). Digital variants are untracked and quantity one; processing/quarantined versions cannot be attached. Privileged staff still must pass 2FA before catalog admin. No cart/checkout. `GET /` remains 503. Full PHP suite: 339 tests / 2255 assertions; Pint; PHPStan level 8; frontend type/lint/format/build; 41 Vue tests; 10 Chromium journeys including digital upload/attach and a public page that omits original keys. Composer/npm audits passed; 237 registry signatures / 95 attestations. Local PCOV Catalog coverage was not collected (no coverage driver). Hosted Quality for this branch is pending.
- **Module / requirements:** `catalog` · CAT-06, CAT-02, PLAT-06.
- **Depends on:** T007, T016.
- **Acceptance:** Digital variants attach versioned private files; quantity one/processing states enforced and public views omit keys. Now that catalog tables exist, seed fictional physical/digital products without production data or automatic production seeding.
- **Verify:** Run `php artisan test --filter=CatalogDigitalAsset`; upload/replace a large file and verify memory/privacy behavior.
- **Likely files:** `database/migrations/create_digital_assets.php`, `app/Domain/Catalog/Actions/VersionDigitalAsset.php`, `resources/js/admin/components/DigitalAssets.vue`, `app/Http/Resources/ProductResource.php`, `tests/Feature/CatalogDigitalAssetTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T021: Catalog search and typed filters

- [x] Complete T021 locally; hosted Quality CI evidence was not collected from this agent.
- **Evidence October 13:** Failing `CatalogSearch` tests were written first, then `SearchCatalog`, storefront `SearchController`, default-theme search page, and `CatalogFilters`. Shoppers search published titles/SKUs at `/search` and filter by category, price, availability, and schema-defined facets. Drafts are omitted from results and facet values/counts. Numeric attributes sort numerically; unsafe sort fields and oversized queries are ignored. Pagination and filter state live in the URL so back navigation restores them. Privileged staff still must pass 2FA before catalog admin. No cart/checkout. `GET /` remains 503. Full PHP suite: 345 tests / 2325 assertions; Pint; PHPStan level 8; frontend type/lint/format/build; 43 Vue tests; 10 Chromium journeys including search query back-navigation. Composer/npm audits passed; 237 registry signatures / 95 attestations. Local PCOV Catalog coverage was not collected (no coverage driver). Hosted Quality for this branch is pending.
- **Module / requirements:** `catalog` · CAT-08, WEB-04.
- **Depends on:** T015, T017.
- **Acceptance:** Search title/SKU, category, price and typed facets with stable pagination; drafts excluded from values/counts.
- **Verify:** Run `php artisan test --filter=CatalogSearch`; verify numeric sorting and back-navigation query state.
- **Likely files:** `app/Domain/Catalog/Actions/SearchCatalog.php`, `app/Http/Controllers/Storefront/SearchController.php`, `themes/default/views/search.blade.php`, `themes/default/src/CatalogFilters.vue`, `tests/Feature/CatalogSearchTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

#### Checkpoint after T019, T020, T021

- [ ] Acceptance and focused verification recorded; no zero-test pass.
- [ ] Relevant build/browser flow passes, including failure, permission and replay cases.
- [ ] Changes documented; pending external-provider evidence remains explicit.

### T022: CSV dry-run import

- [x] Complete T022 locally; hosted Quality CI evidence was not collected from this agent.
- **Evidence October 13:** Failing `CatalogImportPreview` tests were written first, then `product_import_files`, `PreviewProductImport`, `ValidateProductImport`, staff `ProductImport`, and formula-safe error CSV download. Uploaded CSVs map columns, classify create vs update-by-SKU, and report mixed-validity rows without writing products. Duplicate SKUs, spreadsheet formulas, invalid schema/category values, and unsafe files are rejected. Privileged staff still must pass 2FA before catalog admin. No cart/checkout. `GET /` remains 503. Full PHP suite: 352 tests / 2432 assertions; Pint; PHPStan level 8; frontend type/lint/format/build; 44 Vue tests; 10 Chromium journeys including CSV dry-run. Composer/npm audits passed; 237 registry signatures / 95 attestations. Local PCOV Catalog coverage was not collected (no coverage driver). Hosted Quality for this branch is pending.
- **Module / requirements:** `catalog` · CAT-07.
- **Depends on:** T016, T017.
- **Acceptance:** Uploaded CSV maps columns and reports row-specific errors without writes; duplicate SKU/update intent and formula risks handled.
- **Verify:** Run `php artisan test --filter=CatalogImportPreview`; inspect mixed-validity row report.
- **Likely files:** `app/Domain/Catalog/Actions/PreviewProductImport.php`, `app/Jobs/ValidateProductImport.php`, `resources/js/admin/pages/ProductImport.vue`, `tests/Feature/CatalogImportPreviewTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T023: CSV apply, export and archive

- [x] Complete T023 locally; hosted Quality CI evidence was not collected from this agent.
- **Evidence October 13:** Failing `CatalogImportApply` tests were written first, then `ApplyProductImport`, `ExportProducts`, `ArchiveProduct`, apply/export/archive routes, and staff import/index/edit UI. Valid CSV rows apply with a stable `catalog:import:apply:{importId}` intent and per-row created/updated/skipped results; replaying the same import does not duplicate variants. Formula-safe catalog export includes archived SKUs. Archive keeps variants/SKUs and hides the product from public pages and search. Privileged staff still must pass 2FA before catalog admin. No cart/checkout. `GET /` remains 503. Full PHP suite: 359 tests / 2549 assertions; Pint; PHPStan level 8; frontend type/lint/format/build; 44 Vue tests; 10 Chromium journeys including apply, export, and archive. Composer/npm audits passed; 237 registry signatures / 95 attestations. Local PCOV Catalog coverage was not collected (no coverage driver). Hosted Quality for this branch is pending.
- **Module / requirements:** `catalog` · CAT-01, CAT-07.
- **Depends on:** T022.
- **Acceptance:** Apply validated rows with stable import intent and per-row results; replay creates no duplicates; archive preserves order references.
- **Verify:** Run `php artisan test --filter=CatalogImportApply`; repeat an import and open formula-safe export.
- **Likely files:** `app/Domain/Catalog/Actions/ApplyProductImport.php`, `app/Domain/Catalog/Actions/ExportProducts.php`, `app/Domain/Catalog/Actions/ArchiveProduct.php`, `tests/Feature/CatalogImportApplyTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T024: Catalog availability and public contracts

- [x] Complete T024 locally; hosted Quality CI evidence was not collected from this agent.
- **Evidence October 13:** Failing `CatalogReadModel` tests were written first, then `VariantAvailability`, catalog `ProductView`, `ProductResource`, and `GET /store-api/v1/products`. Public HTML/JSON/search/category cards share sellability: tracked remaining stock, digital ready files, and the merchant availability flag. Staff snapshots keep on-hand/reserved/low-stock; public responses omit those quantities. Archived and missing products return 404 without leaking titles. Privileged staff still must pass 2FA before catalog admin. No cart/checkout. `GET /` remains 503. Full PHP suite: 363 tests / 2662 assertions; Pint; PHPStan level 8; frontend type/lint/format/build; 45 Vue tests; 10 Chromium journeys including public JSON, mobile product viewport, and archive 404. Composer/npm audits passed; 237 registry signatures / 95 attestations. Local PCOV Catalog coverage was not collected (no coverage driver). Hosted Quality for this branch is pending.
- **Module / requirements:** `catalog` · CAT-05, CAT-08, WEB-07.
- **Depends on:** T019, T020, T021.
- **Acceptance:** Public DTOs consistently expose physical/digital availability and low-stock admin state; archived/missing products degrade cleanly.
- **Verify:** Run `php artisan test --filter=CatalogReadModel` and `npm run build`; browse fixture catalog on mobile.
- **Likely files:** `app/Domain/Catalog/Contracts/CatalogReader.php`, `app/Domain/Catalog/Data/ProductView.php`, `app/Http/Resources/ProductResource.php`, `themes/default/views/product.blade.php`, `tests/Feature/CatalogReadModelTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

#### Checkpoint after T022, T023, T024

- [x] Acceptance and focused verification recorded; no zero-test pass. T022/T023/T024 each have failing-first tests and local evidence; hosted Quality for this branch is pending.
- [x] Relevant build/browser flow passes, including failure, permission and replay cases. Chromium catalog journey covers import apply/export/archive, public JSON, mobile product viewport, missing/archived 404, and `GET /` 503.
- [x] Changes documented in `docs/development.md`; pending external-provider evidence remains explicit. Live payment/AI replay remain T035/T074.

## M2: Cart, price, shipping and tax quotes

### T025: Cart ownership and line mutations

- [x] Complete T025 locally; hosted Quality CI evidence was not collected from this agent.
- **Evidence October 13:** Failing `CheckoutOrdersCart` tests were written first, then cart tables, `ReadCart`/`UpdateCart`, storefront HTML/JSON cart routes, native add-to-cart/cart forms, and a mixed physical+digital `DemoStoreSeeder` fixture. Guest and account carts are separate; quantity replaces are revision-checked; stale writes return 409; other sessions cannot mutate a line; invalid/unavailable variants and digital quantity above one fail; posted prices are ignored. Privileged staff still must pass 2FA before catalog admin. Adding to cart does not reserve stock or open checkout. `GET /` remains 503. Full PHP suite: 368 tests / 2756 assertions; Pint; PHPStan level 8; frontend type/lint/format/build; 45 Vue tests; 10 Chromium journeys including add-to-cart of physical and digital lines. Composer/npm audits passed; 237 registry signatures / 95 attestations. Local PCOV CheckoutOrders coverage was not collected (no coverage driver). Hosted Quality for this branch is pending.
- **Module / requirements:** `checkout-orders` · ORDER-01, PLAT-06.
- **Depends on:** T009, T024.
- **Acceptance:** Guest/account carts support revision-checked quantity edits; invalid/unavailable variants fail and supplied prices are ignored. Add the real mixed-cart fixture now that cart and catalog migrations exist.
- **Verify:** Run `php artisan test --filter=CheckoutOrdersCart`; verify cross-session denial and stale mutation.
- **Likely files:** `database/migrations/create_cart_tables.php`, `app/Domain/CheckoutOrders/Actions/UpdateCart.php`, `app/Http/Controllers/Storefront/CartController.php`, `themes/default/views/cart.blade.php`, `tests/Feature/CheckoutOrdersCartTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T026: Cart merge and shopper interaction

- [x] Complete T026 locally; hosted Quality CI evidence was not collected from this agent.
- **Evidence October 13:** Failing `CheckoutOrdersCartMerge` tests were written first, then `MergeCart`, login/register transfer via `CustomerSession`, cart adjustment notices, and default-theme `CartLines`/`CartIndicator` Vue enhancement. Login merges matching physical quantities clamped to allowed maximum and keeps digital quantity one; the shopper sees an adjustment notice; failed login leaves the guest cart; logout hides the account cart. Privileged staff still must pass 2FA before catalog admin. `GET /` remains 503. Hosted Quality on `c6e3aa6` failed the CheckoutOrders 90% coverage gate at 89.20% after PHP tests passed; additional merge-path tests plus dead-branch cleanup raised local CheckoutOrders coverage to 93.42%. `CheckoutOrdersCartMerge` 3 tests; earlier full PHP suite 370 tests / 2793 assertions; Pint; PHPStan level 8; frontend type/lint/format/build; 49 Vue tests; 10 Chromium journeys including cart indicator after add-to-cart. Composer/npm audits passed; 237 registry signatures / 95 attestations. Hosted Quality for the coverage follow-up is pending.
- **Module / requirements:** `checkout-orders` · ORDER-01, ID-05.
- **Depends on:** T025.
- **Acceptance:** Login merges matching variants by summed physical quantity clamped to allowed maximum, digital quantity one; shopper sees adjustment notice.
- **Verify:** Run `php artisan test --filter=CheckoutOrdersCartMerge` and `npm run test:unit -- --run`; test guest/login/logout behavior.
- **Likely files:** `app/Domain/CheckoutOrders/Actions/MergeCart.php`, `themes/default/src/CartLines.vue`, `themes/default/src/CartIndicator.vue`, `tests/Feature/CheckoutOrdersCartMergeTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T027: Money and deterministic price engine

- [x] Complete T027 locally; hosted Quality CI evidence was not collected from this agent.
- **Evidence October 13:** Failing `PricingMoney` tests were written first, then `Money`, `CalculatePrice`, `AllocateDiscount`, and the `PriceCalculator` binding. Server quotes use currency exponents 0/2/3, UTC sale windows, integer `bcmath` rationals, largest-remainder odd-cent allocations, per-unit residuals, and immutable SHA-256 fingerprints. Mixed currencies, sale-above-regular, missing windows, overflow, and discounts larger than the eligible subtotal are rejected. Tax remains zero until T031; coupon tables and checkout quote persistence remain later. Privileged staff still must pass 2FA before catalog admin. Cart still displays catalog regular prices. `GET /` remains 503. `PricingMoney` 6 tests / 60 assertions. Full PHP suite: 377 tests / 2871 assertions; Pint; PHPStan level 8; frontend type/lint/format/build; 49 Vue tests; 10 Chromium journeys including cart add and `GET /` 503. Composer/npm audits passed; 237 registry signatures / 95 attestations. Local PCOV Pricing coverage was not collected (no coverage driver). Hosted Quality for this branch is pending.
- **Module / requirements:** `pricing` · PRICE-01, PRICE-04.
- **Depends on:** T016.
- **Acceptance:** Server calculation supports currency exponents, sale windows, integer allocations and immutable breakdowns with no floating point arithmetic.
- **Verify:** Run `php artisan test --filter=PricingMoney`; verify fixtures and allocation sums for odd-cent cases.
- **Likely files:** `app/Domain/Pricing/Data/Money.php`, `app/Domain/Pricing/Actions/CalculatePrice.php`, `app/Domain/Pricing/Rules/AllocateDiscount.php`, `tests/Unit/PricingMoneyTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

#### Checkpoint after T025, T026, T027

- [x] Acceptance and focused verification recorded; no zero-test pass. T025/T026/T027 each have failing-first tests and local evidence; hosted Quality for this branch is pending.
- [x] Relevant build/browser flow passes, including failure, permission and replay cases. Chromium journeys cover add-to-cart, cart indicator, privileged 2FA setup, and `GET /` 503. PricingMoney covers overflow, sale-window, odd-cent, and coupon-over-subtotal failures.
- [x] Changes documented in `docs/development.md` and `docs/commerce-rules.md`; pending external-provider evidence remains explicit. Live payment/AI replay remain T035/T074. Coupon holds remain T028.

### T028: Coupon configuration and redemption holds

- [x] Complete T028 locally; hosted Quality failed on 3fcfad0 at Pricing 86.67% coverage (need 90%). Extra coupon tests added; re-run Quality pending.
- **Evidence October 13:** Failing `PricingCoupon` tests were written first, then coupon tables, `SaveCoupon`/`ApplyCoupon`/`HoldCoupon`, and admin `/admin/coupons` screens. Staff with `pricing.manage` save one-coupon rules (fixed/percentage/free-shipping, UTC windows, catalog scopes, total and per-customer caps). Apply selects eligible lines for `PriceCalculator`; hold/commit/release is idempotent under row locks; two MySQL workers racing the last redemption yield one hold and one exhausted. Privileged staff still must pass 2FA before coupon admin. Shoppers cannot enter a code at cart/checkout in this slice. `GET /` remains 503. Hosted Quality on 3fcfad0 (`38430192250`) failed `coverage-gate.php`: Pricing 86.67%. Additional `PricingCoupon` cases now cover paused/exhausted/min-spend/free-shipping/product scopes, stale edits, hold conflicts, and admin list/PATCH. `PricingCoupon` 8 tests / 166 assertions locally after the coverage tests. Pint and PHPStan passed on the follow-up. Local PCOV still unavailable. Privileged 2FA and `GET /` 503 unchanged. Hosted Quality after this push is pending; merge only on green.
- **Module / requirements:** `pricing` · PRICE-02.
- **Depends on:** T027, T019.
- **Acceptance:** Merchant configures one-coupon rules; limits are held/committed/released atomically and reject over-redemption.
- **Verify:** Run `php artisan test --filter=PricingCoupon`; race two uses of the last redemption.
- **Likely files:** `database/migrations/create_coupon_tables.php`, `app/Domain/Pricing/Actions/ApplyCoupon.php`, `app/Domain/Pricing/Actions/HoldCoupon.php`, `resources/js/admin/pages/CouponEdit.vue`, `tests/Feature/PricingCouponTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T029: Shipping zones and rule editor

- [x] Complete T029 locally; hosted Quality CI evidence was not collected from this agent.
- **Evidence October 13:** Failing `ShippingRules` tests were written first, then shipping tables, `SaveShippingRules`/`ReadShippingRules`/`PreviewShipping`, destination overlap matching, and admin `/admin/shipping`. Staff with `shipping.manage` save one origin, the built-in `general` class plus extra classes, prioritized zones, and flat/free/table/pickup methods. Same-priority overlapping country/region/postal patterns are rejected. Preview uses physical subtotal and grams at postal and free/table boundaries; weight tables refuse missing grams. Privileged staff still must pass 2FA before shipping admin. Checkout still does not quote a cart. `GET /` remains 503. `ShippingRules` 7 tests / 97 assertions. Full PHP suite: 392 tests / 3134 assertions; Pint; PHPStan level 8; frontend type/lint/format/build; 54 Vue tests; 10 Chromium journeys including origin/zone save and destination preview. Composer/npm audits passed; 237 registry signatures / 95 attestations. Local PCOV Shipping coverage was not collected (no coverage driver; Shipping is not in the 90% domain gate). Hosted Quality for this branch is pending.
- **Module / requirements:** `shipping` · SHIP-01, SHIP-02.
- **Depends on:** T005.
- **Acceptance:** Configure deterministic destination zones, flat/free/table/pickup methods; conflicting same-priority rules rejected.
- **Verify:** Run `php artisan test --filter=ShippingRules`; preview boundary postal codes and thresholds.
- **Likely files:** `database/migrations/create_shipping_tables.php`, `app/Domain/Shipping/Actions/SaveShippingRules.php`, `resources/js/admin/pages/Shipping.vue`, `tests/Feature/ShippingRulesTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T030: Physical and mixed-cart shipping quotes

- [x] Complete T030 locally; hosted Quality CI evidence was not collected from this agent.
- **Evidence October 13:** Failing `ShippingQuote` tests were written first, then `ShippingQuoter`/`QuoteShipping` with immutable physical-line snapshots. Digital-only quotes zero without an address or method. Mixed carts ignore digital lines for charges, free-shipping thresholds, and class coverage. Unsupported destinations and mixed classes that no single method covers fail with no-service; missing grams fail weight tables without inventing a weight. Pickup skips delivery address, uses the configured location as tax destination, and does not promise stock. Frozen revision/charge/fingerprint stay on the first quote after a later rule edit. Privileged staff still must pass 2FA before shipping admin. Checkout still does not collect a shopper address. `GET /` remains 503. `ShippingQuote` 7 tests / 66 assertions. Full PHP suite: 399 tests / 3197 assertions; Pint; PHPStan level 8; frontend type/lint/format/build; 54 Vue tests; 10 Chromium journeys including `GET /` 503. Composer/npm audits passed; 237 registry signatures / 95 attestations. Local PCOV Shipping coverage was not collected (no coverage driver; Shipping is not in the 90% domain gate). Hosted Quality for this branch is pending.
- **Module / requirements:** `shipping` · SHIP-03, SHIP-04, SHIP-05, SHIP-06.
- **Depends on:** T027, T029.
- **Acceptance:** Only physical lines affect charge/threshold; unsupported destinations/classes fail; digital-only skips shipping; pickup destination explicit.
- **Verify:** Run `php artisan test --filter=ShippingQuote`; compare physical/digital/mixed fixtures.
- **Likely files:** `app/Domain/Shipping/Contracts/ShippingQuoter.php`, `app/Domain/Shipping/Actions/QuoteShipping.php`, `app/Domain/Shipping/Data/ShippingQuote.php`, `tests/Unit/ShippingQuoteTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

#### Checkpoint after T028, T029, T030

- [x] Acceptance and focused verification recorded; no zero-test pass. T028/T029/T030 each have failing-first tests and local evidence; hosted Quality for this branch is pending.
- [x] Relevant build/browser flow passes, including failure, permission and replay cases. Chromium journeys cover coupon/shipping admin, privileged 2FA setup, and `GET /` 503. ShippingQuote covers digital-only, mixed-cart threshold, unsupported destination/class, missing weight, pickup tax destination, and frozen revision after rule edits.
- [x] Changes documented in `docs/development.md`; pending external-provider evidence remains explicit. Live payment/AI replay remain T035/T074. Checkout quote collection remains T032.

### T031: Tax configuration and deterministic calculation

- [x] Complete T031 locally; hosted Quality CI evidence was not collected from this agent.
- **Evidence October 13:** Failing `PricingTax` tests were written first, then `SaveTaxRules`/`CalculateTax`/`PreviewTax` with exclusive/inclusive line rounding. Documented USD fixtures match: exclusive 2250 + 300 shipping at 10% → tax 255 / total 2805; inclusive 2200 + 330 → tax 230 / total 2530. Physical uses shipping destination and digital uses billing; shipping has its own tax class. Equal-specificity overlapping destinations (including prefix vs exact, overlapping ranges, and nested prefixes) are rejected at save; draft duplicates do not conflict. Zero-rate rules persist explicitly; missing or ambiguous coverage fails closed. Privileged staff still must pass 2FA before tax admin; customers and non-pricing roles are denied. Checkout still does not collect a shopper address or freeze a payable quote. `GET /` remains 503. `PricingTax` 8 tests / 130 assertions. Full PHP suite: 407 tests / 3324 assertions; Pint; PHPStan level 8; frontend type/lint/format/build; 56 Vue tests; 10 Chromium journeys including taxes save/preview and `GET /` 503. Composer/npm audits passed; 237 registry signatures / 95 attestations. Local PCOV Pricing coverage was not collected (no coverage driver). Hosted Quality for this branch is pending.
- **Module / requirements:** `pricing` · PRICE-03, PRICE-05.
- **Depends on:** T027, T030.
- **Acceptance:** Merchant tax classes/rules and price mode calculate documented line totals; ambiguous/missing coverage prevents approval.
- **Verify:** Run `php artisan test --filter=PricingTax`; validate intended launch market fixtures before real sales.
- **Likely files:** `database/migrations/create_tax_tables.php`, `app/Domain/Pricing/Actions/CalculateTax.php`, `resources/js/admin/pages/Taxes.vue`, `tests/Unit/PricingTaxTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T032: Quote revision and checkout form

- [x] Complete T032 locally; hosted Quality CI evidence was not collected from this agent.
- **Evidence October 13:** Failing `CheckoutOrdersQuote` tests were written first, then `CreateQuote`/`AcceptQuote`, encrypted `checkout_quotes` persistence, `POST /store-api/v1/checkout-quotes`, and HTML `/checkout`. Guests and accounts enter email, billing, only necessary shipping, an optional coupon, and policy acknowledgement; digital-only carts skip delivery. Quotes expire in 15 minutes and freeze PriceCalculator + ShippingQuoter + tax itemization (exclusive fixture 2500 merchandise − 250 coupon + 300 shipping + 255 tax = 2805). Accepting does not create an order or payment (T034). Cart revision, catalog price, tax/shipping rule, destination, fingerprint, ownership, CSRF, and TTL mismatches invalidate acceptance and require a new quote. Privileged staff still must pass 2FA. `GET /` remains 503. `CheckoutOrdersQuote` 5 tests / 75 assertions. Full PHP suite: 412 tests / 3405 assertions; Pint; PHPStan level 8; frontend type/lint/format/build; 57 Vue tests; 10 Chromium journeys including checkout address correction, stale totals after a cart-line remove, and `GET /` 503. Composer/npm audits passed; 237 registry signatures / 95 attestations. Local PCOV coverage was not collected (no coverage driver). Hosted Quality for this branch is pending.
- **Module / requirements:** `checkout-orders` · ORDER-02, PRICE-04.
- **Depends on:** T025, T028, T030, T031.
- **Acceptance:** Contact/billing/necessary delivery details create expiring quote; changes to cart/rules/destination invalidate acceptance.
- **Verify:** Run `php artisan test --filter=CheckoutOrdersQuote`; browser-test stale totals and address correction.
- **Likely files:** `app/Domain/CheckoutOrders/Actions/CreateQuote.php`, `app/Http/Controllers/Storefront/CheckoutController.php`, `themes/default/views/checkout.blade.php`, `themes/default/src/CheckoutForm.vue`, `tests/Feature/CheckoutOrdersQuoteTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T033: Pricing preview and cart browser gate

- [x] Complete T033 locally; hosted Quality CI evidence was not collected from this agent.
- **Evidence October 13:** Failing `PricingPreview` tests were written first, then `PreviewPricing` plus `/admin/pricing-preview`. Admin mixed-cart preview matches the storefront quote itemization for the documented exclusive fixture (2500 merchandise − 250 coupon + 300 shipping + 255 tax = 2805) and the same currency formatting. Physical-only, digital-only, missing tax coverage, unsupported shipping, and rejected coupons return understandable failures without inventing a payable total. Empty carts offer continue-shopping; unavailable lines stay removable and withhold checkout. Privileged staff still must pass 2FA. `GET /` remains 503. `PricingPreview` 6 tests / 126 assertions. Full PHP suite: 418 tests / 3527 assertions; Pint; PHPStan level 8; frontend type/lint/format/build; Vue unit tests; 11 Chromium journeys including mixed-cart checkout vs admin preview totals. Composer/npm audits passed. Local PCOV was not collected. Hosted Quality on `d6d1dfe` timed out at the 15-minute job cap; the workflow budget is 25 minutes. Quality on `d4628df` ([run 38467702574](https://github.com/juan-tamayo/mrktflow/actions/runs/38467702574/job/115538628690)) failed the owner-setup Chromium overflow check at 320px because Coupons, Taxes, and Pricing preview sat on one unwrapped row. Those links now wrap (`tool-links` + `minmax(0, 1fr)`). Local Vue AdminHome tests, ESLint, Prettier, and vue-tsc passed after the wrap; Docker was unavailable here so the Chromium overflow re-check is hosted Quality. Merge only after that run is green.
- **Module / requirements:** `pricing` · PRICE-05, WEB-07.
- **Depends on:** T032.
- **Acceptance:** Admin preview and storefront quote show identical itemized totals; currency, rule and failure states are understandable.
- **Verify:** Run `php artisan test --filter=PricingPreview` and `npm run test:e2e -- --project=chromium`; record mixed-cart totals.
- **Likely files:** `resources/js/admin/pages/PricingPreview.vue`, `app/Http/Controllers/Admin/PricingPreviewController.php`, `tests/Feature/PricingPreviewTest.php`, `tests/Browser/cart.spec.ts`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

#### Checkpoint after T031, T032, T033

- [ ] Acceptance and focused verification recorded; no zero-test pass.
- [ ] Relevant build/browser flow passes, including failure, permission and replay cases.
- [ ] Changes documented; pending external-provider evidence remains explicit.

## M3: Payment and order lifecycle

### T034: Immutable order and idempotency claim

- [x] Complete T034 locally; hosted Quality CI evidence was not collected from this agent.
- **Evidence October 13:** Failing `CheckoutOrdersIntent` tests were written first, then `CreateCheckoutIntent` plus `POST/GET /store-api/v1/checkout-sessions`. An accepted quote freezes order/line/address snapshots, holds tracked stock and coupon usage, and creates a `pending` order with a `created` payment attempt. Same idempotency key replays; a changed payload returns 422; a second key for the same cart revision returns the existing intent. A competing shopper cannot create a second payable order when the last unit is held (failed freeze rolls back). Parallel MySQL workers with different keys produced one order. Zero-total digital carts complete locally (`confirmed` / `succeeded`, provider `local`) without Stripe. Privileged staff still must pass 2FA. `GET /` remains 503. Hosted Quality on `fb01b0c` passed 425 PHP tests then failed the CheckoutOrders coverage gate at 89.88% (minimum 90%). Extra intent tests cover unaccepted/expired/stale/malformed claims, missing Idempotency-Key, and HTTP 422 payload mismatch (`CheckoutOrdersIntent` 9 tests / 99 assertions). Merge only after a green Quality run. Hosted payment remains T035.
- **Module / requirements:** `checkout-orders` · ORDER-03.
- **Depends on:** T011, T032.
- **Acceptance:** Atomic intent freezes quote/line/address snapshots and holds stock/coupons. Enforce one payable order per cart revision even when competing clients use different idempotency keys; same key replays and changed payload conflicts.
- **Verify:** Run `php artisan test --filter=CheckoutOrdersIntent`; exercise parallel identical submissions and transaction rollback.
- **Likely files:** `database/migrations/create_order_tables.php`, `database/migrations/create_payment_intents.php`, `app/Domain/CheckoutOrders/Actions/CreateCheckoutIntent.php`, `tests/Feature/CheckoutOrdersIntentTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T035: Hosted payment adapter

- [x] Complete T035 locally; live Stripe sandbox charges were not executed (no test credentials in this environment).
- **Evidence October 13:** Failing `CheckoutOrdersGateway` adapter/payment tests were written first, then `StripeGateway`, `StartPayment`, and `POST /store-api/v1/checkout-sessions/{id}/payment`. Frozen exclusive/inclusive/odd-cent lines map to quantity-one Stripe amounts that sum to the quote; promotion codes, automatic tax, adjustable quantities, and shipping-address collection are disabled. Unsafe hosted URLs, amount mismatches, 400s, and delayed methods stay `unknown`. Timeouts persist `unknown` without releasing stock; a retry with the same idempotency key recovers the session. Return/cancel URLs only show status and cannot mark an order paid. Zero-total digital orders skip Stripe. Privileged staff still must pass 2FA. `GET /` remains 503. Live provider sandbox evidence remains pending. Merge only after a green Quality run.
- **Module / requirements:** `checkout-orders` · ORDER-03.
- **Depends on:** T034.
- **Acceptance:** Stable attempt creates one immediate-method hosted session outside locks; unknown timeout is persisted. Frozen quote exactly equals provider total, including odd-cent allocations; provider tax/promo/quantity/delivery changes cannot bypass the accepted quote.
- **Verify:** Run `php artisan test --filter=CheckoutOrdersGateway`; sandbox success/decline/authentication, expiry/reconciliation and physical/digital/mixed amount-mapping tests with actual test credentials. Validate provider currency/amount/line limits; fixtures alone do not complete this task. Live sandbox remains pending.
- **Likely files:** `app/Domain/CheckoutOrders/Adapters/StripeGateway.php`, `app/Domain/CheckoutOrders/Actions/StartPayment.php`, `app/Http/Controllers/Storefront/PaymentController.php`, `tests/Contract/CheckoutOrdersGatewayTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T036: Verified durable webhook inbox

- [x] Complete T036 locally; live Stripe webhook delivery was not executed (no webhook endpoint credentials in this environment).
- **Evidence October 13:** Failing `CheckoutOrdersWebhook` tests were written first, then `provider_events`, `AcceptProviderEvent`, CSRF-exempt `POST /webhooks/stripe`, and ID-only encrypted `ProcessPaymentEvent` on the payments queue. Raw Stripe-Signature HMAC, timestamp window, configured account, and livemode/environment are verified before insert. Replay of the same signed event returns 204 with one row; wrong signature/environment/account returns 400 and stores nothing. Persistence failure (missing table) returns 503 so the provider can retry. Inbox does not mark orders paid. Privileged staff still must pass 2FA. `GET /` remains 503. `CheckoutOrdersWebhook` 6 tests / 46 assertions. Full PHP suite: 442 tests / 3760 assertions before the extra job/oversize case (now 6 webhook tests); Pint; PHPStan level 8; frontend type/lint/format/build; 62 Vue tests; Chromium `platform-smoke` 8 passed (closed storefront 503). Composer audit clean; npm 0 vulnerabilities; 237 registry signatures / 95 attestations. Local PCOV CheckoutOrders coverage was not collected (no coverage driver). Hosted Quality for this branch is pending. Merge only after a green Quality run.
- **Module / requirements:** `checkout-orders` · ORDER-04.
- **Depends on:** T035, T006.
- **Acceptance:** Validate raw signature/account/environment; persist deduplicated event before 2xx; persistence failure remains retryable.
- **Verify:** Run `php artisan test --filter=CheckoutOrdersWebhook`; replay valid event and reject wrong signature/environment.
- **Likely files:** `database/migrations/create_provider_events.php`, `app/Http/Controllers/Webhooks/StripeController.php`, `app/Jobs/ProcessPaymentEvent.php`, `routes/webhooks.php`, `tests/Feature/CheckoutOrdersWebhookTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

#### Checkpoint after T034, T035, T036

- [ ] Acceptance and focused verification recorded; no zero-test pass.
- [ ] Relevant build/browser flow passes, including failure, permission and replay cases.
- [ ] Changes documented; pending external-provider evidence remains explicit.

### T037: Payment confirmation and reconciliation

- [x] Complete T037 locally; live Stripe capture reconciliation was not executed (no test credentials in this environment).
- **Evidence October 14:** Failing `CheckoutOrdersReconciliation` tests were written first, then `payments`, `ConfirmOrderPayment`, `ReconcilePayment`, `ProcessPaymentEvent` confirmation, and bounded `payments:reconcile`. Matching provider success commits stock/coupon holds, records one capture, confirms the order, and emits `checkout.order.paid` once. Duplicate jobs and later expired events cannot downgrade success. Unknown attempts are queried; a retrieve-paid-before-local-commit crash recovers on reconcile. Mismatched amount stays unpaid; return URLs cannot mark paid. Privileged staff still must pass 2FA. `GET /` remains 503. `CheckoutOrdersReconciliation` 4 tests / 43 assertions; `CheckoutOrdersWebhook` 6/46. Full PHP suite after Quality-path tighten: 449 tests / 3829 assertions in 92s without coverage; Pint. Hosted Quality on `24f04a7` ([run 38491549507](https://github.com/juan-tamayo/mrktflow/actions/runs/38491549507)) was cancelled at the 25-minute job budget during Tests and production build. Feature tests now migrate once and truncate (restoring installation, staff-security, and builtin class rows), PCOV is limited to `app/Domain`, PHPUnit is compact, and Playwright skips `--with-deps`. Hosted Quality for the tightened HEAD is pending. Merge only after a green Quality run.
- **Module / requirements:** `checkout-orders` · ORDER-04.
- **Depends on:** T036.
- **Acceptance:** Verified matching success commits allocations and emits OrderPaid once; unordered events cannot downgrade success; unknown attempts queried.
- **Verify:** Run `php artisan test --filter=CheckoutOrdersReconciliation`; simulate crash after provider success and before local update.
- **Likely files:** `app/Domain/CheckoutOrders/Actions/ReconcilePayment.php`, `app/Domain/CheckoutOrders/Actions/ConfirmOrderPayment.php`, `app/Jobs/ReconcilePendingPayments.php`, `tests/Feature/CheckoutOrdersReconciliationTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T038: Expiry, cancellation and late-success recovery

- [x] Complete T038 locally; live Stripe expiry/late-success races were not executed (no test credentials in this environment).
- **Evidence October 16:** Failing `CheckoutOrdersExpiry` tests were written first, then `ExpireCheckout`, `ExpireCheckoutSessions`, `payments:expire`, inventory/coupon `rehold`, and `ConfirmOrderPayment` late-success recovery. Due open sessions are retrieved then cancelled before stock/coupon release; provider outage retains holds. Late matching success after expiry re-reserves when stock remains, otherwise records the capture, marks the order `on_hold`, and writes `audit:order-hold:{orderId}`. Privileged staff still must pass 2FA. `GET /` remains 503. `CheckoutOrdersExpiry` 6 tests / 45 assertions; `CheckoutOrders` 65/613. Full PHP suite: 455 tests / 3857 assertions; Pint; PHPStan level 8; frontend type/lint/format/build; 62 Vue tests; Chromium `platform-smoke` 8 passed (closed storefront 503). Composer audit clean; npm 0 vulnerabilities; 237 registry signatures / 95 attestations. Live Stripe expiry evidence remains pending. Hosted Quality for this branch is pending. Merge only after a green Quality run on the current HEAD.
- **Module / requirements:** `checkout-orders` · ORDER-03, ORDER-04.
- **Depends on:** T037.
- **Acceptance:** Resolve/cancel provider sessions before releasing holds; unknown status retains holds; late success re-reserves or holds order for resolution.
- **Verify:** Run `php artisan test --filter=CheckoutOrdersExpiry`; test provider outage and late success after competing stock purchase.
- **Likely files:** `app/Domain/CheckoutOrders/Actions/ExpireCheckout.php`, `app/Jobs/ExpireCheckoutSessions.php`, `routes/console.php`, `tests/Feature/CheckoutOrdersExpiryTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T039: Zero-total checkout and confirmation screen

- [x] Complete T039 locally; live Stripe sandbox charges were not executed (no test credentials in this environment).
- **Evidence October 16:** Failing `CheckoutOrdersFree` tests were written first, then `CompleteFreeOrder`, local `payments` provider, `GET /checkout/orders/{orderId}`, and `order-status`. Zero-total orders skip Stripe, commit holds, record a local zero capture (`local_{attemptId}`), and emit `checkout.order.paid` once; replays are idempotent. Forged success query parameters and payment return URLs cannot mark paid. Pending confirmation HTML refreshes until the order is confirmed; unowned IDs 404. Privileged staff still must pass 2FA. `GET /` remains 503. `CheckoutOrdersFree` 3 tests / 67 assertions; `CheckoutOrders` 68/680. Full PHP suite: 458 tests / 3923 assertions; Pint; PHPStan level 8; frontend type/lint/format/build; 62 Vue tests; Chromium e2e 11 passed (closed storefront 503). Composer audit clean; npm 0 vulnerabilities; 237 registry signatures / 95 attestations. Live Stripe evidence remains pending. Hosted Quality for this branch is pending. Merge only after a green Quality run on the current HEAD.
- **Module / requirements:** `checkout-orders` · ORDER-03, WEB-07.
- **Depends on:** T037.
- **Acceptance:** Free orders use the same idempotent confirmation pipeline without gateway; return URL alone cannot mark paid; pending UI recovers.
- **Verify:** Run `php artisan test --filter=CheckoutOrdersFree` and `npm run build`; navigate directly to forged success URL.
- **Likely files:** `app/Domain/CheckoutOrders/Actions/CompleteFreeOrder.php`, `app/Http/Controllers/Storefront/OrderStatusController.php`, `themes/default/views/order-status.blade.php`, `tests/Feature/CheckoutOrdersFreeTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

#### Checkpoint after T037, T038, T039

- [ ] Acceptance and focused verification recorded; no zero-test pass.
- [ ] Relevant build/browser flow passes, including failure, permission and replay cases.
- [ ] Changes documented; pending external-provider evidence remains explicit.

### T040: Admin order list and detail

- [x] Complete T040 locally; live Stripe was not executed (no test credentials in this environment).
- **Evidence October 16:** Failing `CheckoutOrdersAdmin` tests were written first, then `financial_status`/`fulfillment_status`/`download_status` on `orders`, `order_notes`, `ReadAdminOrders`/`AddOrderNote`, `/admin/orders` list/detail, and workspace navigation for `orders.read`. Search matches number/email/date. Detail shows frozen lines/totals and independent states (physical unpaid remains unfulfilled; paid digital is entitled with fulfillment `not_required`). Notes audit omits body. Owner and fulfillment may read; content editors and customers are denied. Privileged staff still must pass 2FA. `GET /` remains 503. `CheckoutOrdersAdmin` 3 tests / 108 assertions. Full PHP suite: 461 tests / 4031 assertions; Pint; PHPStan level 8; frontend type/lint/format/build; 64 Vue tests; 11 Chromium journeys (platform smoke treats `/admin/orders` as a real staff route). Composer audit clean; npm 0 vulnerabilities; 237 registry signatures / 95 attestations. Local PCOV CheckoutOrders coverage was not collected (no coverage driver). Live Stripe was not executed. Hosted Quality for this branch is pending. Merge only after a green Quality run.
- **Module / requirements:** `checkout-orders` · ORDER-05.
- **Depends on:** T037, T013.
- **Acceptance:** Permitted staff search orders and see frozen line/totals plus independent financial/fulfillment states and notes.
- **Verify:** Run `php artisan test --filter=CheckoutOrdersAdmin`; compare owner/fulfillment/content-editor access.
- **Likely files:** `app/Http/Controllers/Admin/OrderController.php`, `app/Policies/OrderPolicy.php`, `resources/js/admin/pages/Orders.vue`, `resources/js/admin/pages/OrderDetail.vue`, `tests/Feature/CheckoutOrdersAdminTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T041: Partial and full provider refunds

- [x] Complete T041 locally; live Stripe refunds were not executed (no test credentials in this environment).
- **Evidence October 16:** Failing `CheckoutOrdersRefund` tests were written first, then `refunds`/`refund_lines`, line hold/cancel counters, `RequestRefund`/`ReconcileRefund`/`ApplyRefundOutcome`, Stripe retrieve-by-capture list, `/admin/orders/{id}/refunds`, and `RefundDialog`. Quantity refunds claim remaining unit money (including line tax) and hold unshipped qty; success cancels/releases those units atomically; failure clears holds; unknown keeps holds until reconcile, including timeout-after-refund via `payments:reconcile-refunds`. Amount-only goodwill does not cancel quantities. Concurrent last-unit refunds serialize. Privileged staff still must pass 2FA and reauthenticate. `GET /` remains 503. `CheckoutOrdersRefund` 4 tests / 66 assertions. Full PHP: 465 tests / 4101 assertions; Pint; PHPStan level 8; typecheck; lint; format:check; 65 Vue tests; production build; Chromium e2e 11 passed; Composer audit clean; npm 0 vulnerabilities; 237 registry signatures / 95 attestations. Live Stripe refunds were not executed (no test credentials). Local PCOV was not collected. Hosted Quality for this branch is pending. Merge only after a green Quality run.
- **Module / requirements:** `checkout-orders` · ORDER-06.
- **Depends on:** T040.
- **Acceptance:** Refund allocation stays within remaining money/quantities. Requested unshipped quantity refunds hold fulfillment; success cancels/releases those quantities atomically, failure clears holds, unknown preserves holds. Amount-only goodwill refunds do not cancel quantities.
- **Verify:** Run `php artisan test --filter=CheckoutOrdersRefund`; race partial refunds and reconcile a timeout-after-refund.
- **Likely files:** `database/migrations/create_refunds.php`, `app/Domain/CheckoutOrders/Actions/RequestRefund.php`, `app/Domain/CheckoutOrders/Actions/ReconcileRefund.php`, `resources/js/admin/components/RefundDialog.vue`, `tests/Feature/CheckoutOrdersRefundTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T042: Order cancellation workflow

- [x] Complete T042 locally; live Stripe sandbox refunds were not executed (no test credentials in this environment).
- **Evidence October 16:** Failing `CheckoutOrdersCancellation` tests were written first, then `CancelOrder`, cancellation refund kind, `OrderCancellationController`, `CancelOrderDialog`, `checkout.order.cancelled`, and `download_status` `revoked`. Paid unshipped cancellation refunds remaining capture, releases unshipped stock once, leaves coupon redemptions committed, and emits `order:cancelled:{orderId}` once; replays and a second key do not double-refund. Partially shipped orders are refused for the return path. Zero-total orders cancel without a provider refund. Privileged staff still must pass 2FA. `GET /` remains 503. `CheckoutOrdersCancellation` 3 tests / 53 assertions; `CheckoutOrders` 78/907. Full PHP suite: 468 tests / 4160 assertions; Pint; PHPStan level 8; frontend type/lint/format/build; 66 Vue tests; Chromium e2e 11 passed (closed storefront 503). Composer audit clean; npm 0 vulnerabilities; 237 registry signatures / 95 attestations. Live Stripe refunds were not executed. Hosted Quality for this branch is pending. Merge only after a green Quality run on the current HEAD.
- **Module / requirements:** `checkout-orders` · ORDER-06.
- **Depends on:** T038, T041.
- **Acceptance:** Cancellation resolves payment/refund, releases only eligible allocations and emits a durable fact; shipped lines require return path.
- **Verify:** Run `php artisan test --filter=CheckoutOrdersCancellation`; verify repeated requests and partial-shipment restriction.
- **Likely files:** `app/Domain/CheckoutOrders/Actions/CancelOrder.php`, `app/Http/Controllers/Admin/OrderCancellationController.php`, `resources/js/admin/components/CancelOrderDialog.vue`, `tests/Feature/CheckoutOrdersCancellationTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

#### Checkpoint after T040, T041, T042

- [ ] Acceptance and focused verification recorded; no zero-test pass.
- [ ] Relevant build/browser flow passes, including failure, permission and replay cases.
- [ ] Changes documented; pending external-provider evidence remains explicit.

### T043: Order timeline, receipts and customer view

- [x] Complete T043 locally; hosted Quality for this branch is pending.
- **Evidence October 16:** Failing `CheckoutOrdersReceipt` tests were written first, then `order_events` / `order_receipt_intents`, `RecordOrderEvent`, paid-event receipt queueing, `ResendOrderReceipt`, customer `/account/orders` views, and admin timeline/resend. Own-order HTML shows frozen lines/totals; another customer UUID 404s. The paid consumer records timeline and one `receipt:paid:{orderId}` intent; redelivery does not duplicate. Staff resend is explicit, idempotent, and audited without SMTP. Privileged staff still must pass 2FA. `GET /` remains 503. `CheckoutOrdersReceipt` 2 tests / 71 assertions; `CheckoutOrders` 80/983. Full PHP suite: 470 tests / 4234 assertions; Pint; PHPStan level 8; frontend type/lint/format/build; 67 Vue tests; Chromium e2e 11 passed (closed storefront 503). Composer audit clean; npm 0 vulnerabilities; 237 registry signatures / 95 attestations. Live SMTP and live Stripe were not executed. Hosted Quality for this branch is pending. Merge only after a green Quality run on the current HEAD.
- **Module / requirements:** `checkout-orders` · ORDER-05, ORDER-07, OPS-01.
- **Depends on:** T009, T040.
- **Acceptance:** Own-order view and receipt reflect immutable history; event-derived receipt intent deduplicates; resend is explicit/audited.
- **Verify:** Run `php artisan test --filter=CheckoutOrdersReceipt`; deny another customer's order and inspect queued receipt.
- **Likely files:** `app/Domain/CheckoutOrders/Actions/RecordOrderEvent.php`, `app/Http/Controllers/Storefront/CustomerOrderController.php`, `resources/views/mail/order-receipt.blade.php`, `themes/default/views/account-orders.blade.php`, `tests/Feature/CheckoutOrdersReceiptTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T044: External refunds and disputes

- [x] Complete T044 locally; live Stripe refunds/disputes were not executed (no test credentials in this environment).
- **Evidence October 16:** Split into T044a–T044c before coding (actual scope exceeded the sizing rule). Failing `CheckoutOrdersExternalEvent` tests were written first, then migrations 000044/000045 (`refunds.origin` with nullable `staff_id`, refund kind `external`, `order_disputes`, `orders.dispute_status`, `download_status` `suspended`, new timeline types), `ExternalRefundObservation`/`DisputeObservation`, `ReconcileExternalAdjustment`, inbox routing in `ProcessPaymentEvent`, read-only provider facts in admin order detail, and the staff-only scope of `payments:reconcile-refunds`. Provider events bind by the payment-intent capture reference; currency mismatches, over-capture amounts and unbindable observations record nothing. Observations of a staff refund (provider refund ID or `metadata.refund_id`) reconcile that row through `ApplyRefundOutcome` instead of duplicating it; any other succeeded provider refund becomes an immutable provider fact that projects `financial_status`, appends one deduplicated timeline entry and consumes the same refundable bounds as a staff refund (a staff refund against a fully provider-refunded order is refused). A recorded dispute holds a confirmed order and suspends entitled downloads; a won dispute restores only the hold/suspension that dispute caused and only while access stays eligible; a lost dispute writes one provider debit fact, revokes downloads and projects financial status. Closed-before-created, created-after-closed and duplicate deliveries never regress or double-apply. Privileged staff still must pass 2FA. `GET /` remains 503. `CheckoutOrdersExternalEvent` 6 tests / 90 assertions; `CheckoutOrders` 86/1192. Full PHP suite: 481 tests / 4527 assertions; Pint; PHPStan level 8; frontend type/lint/format; 68 Vue tests; production build. Live Stripe refund/dispute events were not executed (no test credentials). Local PCOV was not collected; Chromium journeys were not run in this environment (no combined PHP/Node browser harness on the VPS test stack). Hosted Quality for this branch is pending. Merge only after a green Quality run on the current HEAD.
- **Module / requirements:** `checkout-orders` · ORDER-04, ORDER-06.
- **Depends on:** T041, T043.
- **Acceptance:** Provider-originated refunds/disputes reconcile into history and hold/revoke workflow intents; staff cannot overwrite provider facts.
- **Verify:** Run `php artisan test --filter=CheckoutOrdersExternalEvent`; replay refund/dispute/won/lost events out of order.
- **Likely files:** `app/Domain/CheckoutOrders/Actions/ReconcileExternalAdjustment.php`, `app/Jobs/ProcessPaymentEvent.php`, `resources/js/admin/pages/OrderDetail.vue`, `tests/Feature/CheckoutOrdersExternalEventTest.php`.
- **Scope:** Split into T044a–T044c below before coding (actual scope exceeds the sizing rule); parent acceptance is unchanged and completes only when all three subtasks are green.

#### T044a: External provider refund reconciliation

- [x] Complete T044a locally; live Stripe refunds were not executed (no test credentials in this environment).
- **Evidence October 16:** Migration 000044, `ExternalRefundObservation`, the refund path of `ReconcileExternalAdjustment`, and `ProcessPaymentEvent` routing for `refund.created`/`refund.updated`/`charge.refunded`; staff-refund observations reconcile through `ApplyRefundOutcome`, and `ReconcilePendingRefunds` now only reconciles `origin = staff` rows. Covered by `CheckoutOrdersExternalEvent` tests 1–3 (provider refund lifecycle and bounds, staff observation without duplication, mismatched/unbindable no-ops).
- **Acceptance:** A signed refund observation that matches no local refund creates one immutable `origin = provider` refund fact bound to the payment by its payment-intent reference; currency/amount mismatches and unbindable observations record nothing. Succeeded external refunds project `financial_status` and append one deduplicated timeline event. Observations of a staff-initiated refund (provider refund ID or `metadata.refund_id`) reconcile that row through `ApplyRefundOutcome` instead of creating a second fact. Provider facts consume refundable bounds, so staff refunds cannot exceed or overwrite them. Duplicate and out-of-order deliveries never double-apply.
- **Verify:** Run `php artisan test --filter=CheckoutOrdersExternalEvent`; replay `refund.created`/`refund.updated`/`charge.refunded` out of order.
- **Likely files:** `database/migrations/2026_10_16_000044_external_refund_facts.php`, `app/Domain/CheckoutOrders/Data/ExternalRefundObservation.php`, `app/Domain/CheckoutOrders/Actions/ReconcileExternalAdjustment.php`, `app/Jobs/ProcessPaymentEvent.php`, `tests/Feature/CheckoutOrdersExternalEventTest.php`.

#### T044b: Provider dispute lifecycle reconciliation

- [x] Complete T044b locally; live Stripe disputes were not executed (no test credentials in this environment).
- **Evidence October 16:** Migration 000045 (`order_disputes`, `orders.dispute_status`, `download_status` `suspended`, dispute timeline types), `DisputeObservation`, and the dispute path of `ReconcileExternalAdjustment`: open holds/suspends with hold provenance on the dispute row, won restores only that hold/suspension while access is eligible, lost writes one provider debit and revokes; terminal facts are monotonic under out-of-order replay. Covered by `CheckoutOrdersExternalEvent` tests 4–6 (won restore, lost closed-before-created, no download restore after a full provider refund).
- **Acceptance:** A recorded dispute opens onto `orders.dispute_status`, holds a confirmed order (`on_hold`, provenance recorded on the dispute row), suspends entitled downloads and appends timeline history. A won dispute restores only the hold/suspension the dispute itself caused and only while access is still eligible. A lost dispute records one provider debit fact inside refundable bounds, revokes downloads and projects financial status. Terminal dispute facts never regress: `closed` before `created`, `created` after `closed`, and duplicate deliveries are all no-ops after the first application.
- **Verify:** Run `php artisan test --filter=CheckoutOrdersExternalEvent`; replay dispute opened/won/lost events out of order.
- **Likely files:** `database/migrations/2026_10_16_000045_order_disputes.php`, `app/Domain/CheckoutOrders/Data/DisputeObservation.php`, `app/Domain/CheckoutOrders/Actions/ReconcileExternalAdjustment.php`, `app/Jobs/ProcessPaymentEvent.php`, `tests/Feature/CheckoutOrdersExternalEventTest.php`.

#### T044c: Admin visibility and documentation for provider facts

- [x] Complete T044c locally.
- **Evidence October 16:** `ReadAdminOrders` detail exposes refund `origin`, `dispute_status` and the dispute facts list; `OrderDetail.vue` renders refund origin, the dispute state and a read-only disputes section, with unit coverage for both the empty and provider-fact states (68 Vue tests; type/lint/format/build pass). `SPEC-checkout-orders.md` verification and the `docs/development.md` external refunds and disputes section record the behavior and the pending live-provider evidence.
- **Acceptance:** Admin order detail exposes refund origin, the order dispute status and dispute facts as read-only provider history alongside the timeline; no staff mutation path can edit them. Module spec and development docs record the T044 behavior and pending live-provider evidence.
- **Verify:** Run `php artisan test --filter=CheckoutOrdersExternalEvent` and the frontend type/lint/unit/build checks.
- **Likely files:** `app/Domain/CheckoutOrders/Actions/ReadAdminOrders.php`, `resources/js/admin/pages/OrderDetail.vue`, `SPEC-checkout-orders.md`, `docs/development.md`.

### T045: Checkout recovery browser gate

- [ ] Complete T045.
- **Module / requirements:** `checkout-orders` · ORDER-01, ORDER-02, ORDER-03, ORDER-04, ORDER-06.
- **Depends on:** T039, T042, T043, T044.
- **Acceptance:** Physical sandbox purchase, duplicate-click, pending-return, refund and provider-outage journeys have recorded evidence.
- **Verify:** Run `php artisan test --filter=CheckoutOrders` and `npm run test:e2e -- --project=chromium`; review provider ledger versus local orders.
- **Likely files:** `tests/Browser/checkout.spec.ts`, `tests/Feature/CheckoutOrdersReplayTest.php`, `docs/commerce-rules.md`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

#### Checkpoint after T043, T044, T045

- [x] Acceptance and focused verification recorded; no zero-test pass.
- [ ] Relevant build/browser flow passes, including failure, permission and replay cases.
- [x] Changes documented; pending external-provider evidence remains explicit.

## M4: Physical fulfillment and digital delivery

### T046: Partial shipment creation

- [x] Complete T046 locally.
- **Evidence October 16:** Split into T046a–T046c before coding (actual scope exceeded the sizing rule). Failing `FulfillmentShipment` tests were written first, then migration 000046 (`shipments`/`shipment_lines`, `fulfillment_status` widened with `partially_fulfilled`/`fulfilled`, `order-shipment` idempotency operation, `shipment_created` timeline type), `CreateShipment` in the new `fulfillment` domain module, `POST /admin/orders/{id}/shipments`, admin detail `shipped_qty`/`shippable_quantity`/shipment history, and `ShipmentDialog`. Staff allocate only paid unshipped physical quantities within `quantity − shipped − cancelled − held`, so refund-held quantities cannot ship; one transaction inserts the shipment facts, increments `shipped_qty`, consumes tracked stock through the allocator `ship` mutation (untracked lines move no stock), projects `fulfillment_status`, records one deduplicated `shipment_created` timeline event — the durable notification intent for this slice — and writes a staff audit. Replaying the same key returns the existing shipment without moving stock twice; the same key with a changed payload conflicts; two racing submissions for the same remaining quantities ship once. Privileged staff still must pass 2FA. `GET /` remains 503. `FulfillmentShipment` 7 tests / 155 assertions. Full PHP suite: 488 tests / 4908 assertions; Pint; PHPStan level 8; frontend type/lint/format; 70 Vue tests; production build. Live Stripe is not involved in this slice. Local PCOV was not collected; Chromium journeys were not run in this environment (no combined PHP/Node browser harness on the VPS test stack). Hosted Quality for this branch is pending. Merge only after a green Quality run on the current HEAD.
- **Module / requirements:** `fulfillment` · FUL-01, FUL-02.
- **Depends on:** T040, T019.
- **Acceptance:** Staff allocates only paid unshipped physical quantities; shipment and stock movement are atomic and retry-safe.
- **Verify:** Run `php artisan test --filter=FulfillmentShipment`; race two shipment submissions.
- **Likely files:** `database/migrations/create_shipment_tables.php`, `app/Domain/Fulfillment/Actions/CreateShipment.php`, `app/Http/Controllers/Admin/ShipmentController.php`, `resources/js/admin/components/ShipmentDialog.vue`, `tests/Feature/FulfillmentShipmentTest.php`.
- **Scope:** Split into T046a–T046c before coding (schema/domain, admin HTTP/visibility, dialog/docs), preserving this parent acceptance.

#### T046a: Shipment persistence and atomic creation action

- [x] Complete T046a locally.
- **Evidence October 16:** Migration 000046 and `CreateShipment` with the staff idempotency claim (`order-shipment`), order/line row locks, the allocator `ship` mutation inside the same transaction, the `fulfillment_status` projection, the deduplicated `shipment_created` timeline event and the `audit:order-shipment:{shipmentId}` audit. Covered by `FulfillmentShipment` tests 1–6 (partial→full shipment with stock/reservation/balance assertions, untracked and mixed carts without invented movements, overshipment/digital/unpaid/unauthorized rejections persisting nothing, key replay and changed-payload conflict, refund-held bounds, and the two-process race shipping the remaining quantity once).
- **Acceptance:** Migration 000046 creates `shipments`/`shipment_lines`, widens `orders.fulfillment_status` to include `partially_fulfilled`/`fulfilled`, admits the `order-shipment` idempotency operation and the `shipment_created` order-event type. `CreateShipment` (permission `fulfillment.manage`) claims a staff idempotency record, locks the order and its lines, and accepts only physical lines on a confirmed, captured order within `quantity − shipped − cancelled − held`; it inserts the shipment and lines, increments `shipped_qty`/`eligibility_revision`, consumes tracked stock through the allocator `ship` mutation under the same transaction, projects `fulfillment_status` from physical line quantities, records one deduplicated `shipment_created` timeline event and a staff audit. A replayed key returns the existing shipment without moving stock twice; the same key with a changed payload conflicts. Two racing submissions for the same remaining quantities ship once.
- **Verify:** Run `php artisan test --filter=FulfillmentShipment`; race two shipment submissions.
- **Likely files:** `database/migrations/2026_10_16_000046_create_shipment_tables.php`, `app/Domain/Fulfillment/Actions/CreateShipment.php`, `app/Domain/Fulfillment/Exceptions/FulfillmentIdempotencyConflict.php`, `app/Domain/CheckoutOrders/Actions/RecordOrderEvent.php`, `tests/Feature/FulfillmentShipmentTest.php`, `tests/Fixtures/order-shipment-race.php`.

#### T046b: Admin shipment endpoint and order visibility

- [x] Complete T046b locally.
- **Evidence October 16:** `ShipmentController` (`fulfillment.manage` plus fresh staff 2FA, validated carrier/tracking/line quantities and a stable idempotency key, redirect with `order_shipment_saved`), the `POST /admin/orders/{orderId}/shipments` route, `ReadAdminOrders` line `shipped_qty`/`shippable_quantity` plus the `shipments` history, and the `canShip`/`shipmentSaved` detail props. Covered by the `FulfillmentShipment` HTTP test (2FA reauthentication redirect, successful create, same-key HTTP replay without a second shipment, content-editor 403) and the `CheckoutOrders` regression groups inside the full suite.
- **Acceptance:** `POST /admin/orders/{orderId}/shipments` requires `fulfillment.manage` and fresh staff 2FA, validates carrier/tracking/line quantities and a stable idempotency key, and redirects back to the order. Admin order detail exposes per-line `shipped_qty`/`shippable_quantity` plus the shipment history (carrier, tracking, quantities) and a `canShip` flag for the detail page.
- **Verify:** Run `php artisan test --filter=FulfillmentShipment` and `php artisan test --filter=CheckoutOrdersAdmin`.
- **Likely files:** `app/Http/Controllers/Admin/ShipmentController.php`, `routes/web.php`, `app/Domain/CheckoutOrders/Actions/ReadAdminOrders.php`, `app/Http/Controllers/Admin/OrderController.php`.

#### T046c: Shipment dialog and documentation

- [x] Complete T046c locally.
- **Evidence October 16:** `ShipmentDialog` (per-line quantities bounded by `shippable_quantity`, optional carrier/tracking number/tracking URL, fresh idempotency key per submit) and the read-only shipments section plus `shipped` counts on `OrderDetail.vue`, with unit coverage for the dialog and the shipment detail state (70 Vue tests; type/lint/format/build pass). The `SPEC-fulfillment.md` implementation status and the `docs/development.md` shipments section record the T046 behavior; tracking URL policy, customer tracking views and shipment email remain T047.
- **Acceptance:** `ShipmentDialog` on the order detail page lets permitted staff enter per-line quantities bounded by `shippable_quantity` plus optional carrier/tracking fields and posts with a fresh idempotency key; recorded shipments render read-only in the detail history. `SPEC-fulfillment.md` and `docs/development.md` record the T046 behavior; tracking URL policy, customer tracking views and shipment email remain T047.
- **Verify:** Run the frontend type/lint/format/unit/build checks and `php artisan test --filter=FulfillmentShipment`.
- **Likely files:** `resources/js/admin/components/ShipmentDialog.vue`, `resources/js/admin/components/ShipmentDialog.test.ts`, `resources/js/admin/pages/OrderDetail.vue`, `resources/js/admin/pages/OrderDetail.test.ts`, `SPEC-fulfillment.md`, `docs/development.md`.

### T047: Tracking and shipment notification

- [x] Complete T047.
- **Module / requirements:** `fulfillment` · FUL-01, OPS-01.
- **Depends on:** T046, T043.
- **Acceptance:** Customer sees their tracking link and shipment quantities; invalid URLs rejected; notification intent deduplicates.
- **Verify:** Run `php artisan test --filter=FulfillmentTracking`; check malicious URL and partial shipment email.
- **Likely files:** `app/Domain/Fulfillment/Rules/TrackingUrl.php`, `resources/views/mail/shipment.blade.php`, `themes/default/views/order-status.blade.php`, `tests/Feature/FulfillmentTrackingTest.php`.
- **Scope:** Split into T047a–T047c before coding (URL policy/enforcement, notification intent/email, customer view/docs), preserving this parent acceptance.

#### T047a: Tracking URL policy and enforcement

- [x] Complete T047a.
- **Acceptance:** A `TrackingUrl` rule accepts only absolute http/https URLs on a dotted public hostname: no other scheme (including `javascript:`/`data:`), no scheme-relative URLs, no embedded credentials (userinfo), no whitespace/control/backslash/quote characters and no IP-literal or single-label hosts. `CreateShipment` rejects an invalid tracking URL and persists nothing; the admin shipment endpoint validates the same rule. A valid carrier URL is stored unchanged.
- **Verify:** Run `php artisan test --filter=FulfillmentTracking`; submit malicious URLs at the action and HTTP layers.
- **Likely files:** `app/Domain/Fulfillment/Rules/TrackingUrl.php`, `app/Domain/Fulfillment/Actions/CreateShipment.php`, `app/Http/Controllers/Admin/ShipmentController.php`, `tests/Feature/FulfillmentTrackingTest.php`.
- **Evidence October 16:** Failing `FulfillmentTracking` tests were written first (6 failed before implementation), then the `TrackingUrl` rule: absolute http/https only on a dotted public hostname; non-http(s) schemes (`javascript:`, `data:`, `ftp:`), scheme-relative URLs, embedded credentials (userinfo), whitespace/control/backslash/quote characters, IP-literal and single-label hosts, over-length values and zero/out-of-range ports are rejected — the port range is checked against the raw authority text because `parse_url` reports port 0 verbatim and fails outright above 65535. `CreateShipment` enforces the rule inside the shipment transaction and `ShipmentController` validates the same rule, so an invalid URL persists nothing; a valid carrier URL is stored unchanged.

#### T047b: Shipment notification intent and email

- [x] Complete T047b.
- **Acceptance:** Migration 000047 creates `shipment_notification_intents` (one deduplicated `event` intent per shipment, mirroring `order_receipt_intents`) and admits the `shipment_notification_queued` timeline type. `CreateShipment` queues the intent in the same transaction via `QueueShipmentNotification`; replayed creations and repeated queue calls return the existing intent and record no second timeline event. The `mail.shipment` view renders the order number, carrier/tracking and only the quantities in that shipment (partial shipments show partial quantities), with the tracking link taken from the validated URL.
- **Verify:** Run `php artisan test --filter=FulfillmentTracking`; render the partial-shipment email and replay a shipment creation.
- **Likely files:** `database/migrations/2026_10_16_000047_create_shipment_notification_intents.php`, `app/Domain/Fulfillment/Actions/QueueShipmentNotification.php`, `app/Domain/Fulfillment/Actions/CreateShipment.php`, `app/Domain/CheckoutOrders/Actions/RecordOrderEvent.php`, `resources/views/mail/shipment.blade.php`, `tests/Feature/FulfillmentTrackingTest.php`.
- **Evidence October 16:** Migration 000047 creates `shipment_notification_intents` (unique `(shipment_id, template)`, `intent_key` `shipment:{shipmentId}`, kind `event`, template `shipment`, status `queued`, mirroring `order_receipt_intents`) and widens the `order_events` type check for `shipment_notification_queued`. `QueueShipmentNotification` runs inside `CreateShipment`'s transaction; a replayed creation and repeated queue calls return the existing intent with no second row and no second timeline event. `resources/views/mail/shipment.blade.php` renders the order number, carrier/tracking and only that shipment's line quantities — the partial-shipment email test ships 2 of 5 and asserts the unshipped line never renders. This slice queues intent only; no SMTP is sent.

#### T047c: Customer tracking view and documentation

- [x] Complete T047c.
- **Acceptance:** `ReadCustomerOrders` detail exposes the order's shipments (carrier, tracking number, per-shipment line titles/SKUs/quantities, created date) and the account order page renders a tracking link per shipment. Stored URLs are re-validated at read time, so a pre-policy (T046-era) malicious value is never exposed or rendered as a link. `SPEC-fulfillment.md` and `docs/development.md` record the T047 behavior; the post-checkout `order-status` page intentionally shows no tracking because no shipment can exist at confirmation time.
- **Verify:** Run `php artisan test --filter=FulfillmentTracking` and `php artisan test --filter=CheckoutOrders`; open the customer order page for own and foreign orders.
- **Likely files:** `app/Domain/CheckoutOrders/Actions/ReadCustomerOrders.php`, `themes/default/views/account-order.blade.php`, `SPEC-fulfillment.md`, `docs/development.md`, `tests/Feature/FulfillmentTrackingTest.php`.
- **Evidence October 16:** `ReadCustomerOrders` detail gains `shipments` (carrier, tracking number, created date, per-shipment line titles/SKUs/quantities) with the stored tracking URL re-validated at read time — a pre-policy T046-era `javascript:` value written directly to the database is exposed as null and never rendered as a link — and `themes/default/views/account-order.blade.php` renders a Shipments section whose tracking link opens with `rel="noopener"`. The `SPEC-fulfillment.md` implementation status and the `docs/development.md` shipment tracking section record the T047 behavior; `themes/default/views/order-status.blade.php` is intentionally unchanged because no shipment can exist at checkout confirmation time. Two pre-existing T046 style issues surfaced by Pint (`routes/web.php` import order for `ShipmentController`, a missing blank line in `CreateShipment::consumeStock`) were fixed in passing. Verification: `FulfillmentTracking` 6 tests / 129 assertions; `CheckoutOrders` 86 tests / 1131 assertions standalone (an earlier group run overlapped a second test container against the shared test database and failed one `CheckoutOrdersAdmin` test with a UniqueConstraintViolationException; the solo re-run and both full-suite runs pass it); full PHP suite 494 tests / 5037 assertions (assertion totals drift between runs — the T046 race test asserts inside a polling loop; the pristine b59b2cd tree re-measured 142 assertions for `FulfillmentShipment` against the 155 recorded, with identical pass counts); Pint 427 files PASS; PHPStan level 8 clean; frontend typecheck/lint/format/build pass with 70 Vue tests. Live SMTP was not executed (no credentials). Hosted Quality for this branch is pending. Chromium journeys were not run (no combined PHP/Node browser harness on the VPS test stack).

### T048: Returns and deliberate restock

- [x] Complete T048 locally.
- **Evidence October 16:** Split into T048a–T048d before coding (actual scope exceeded the sizing rule). Failing `FulfillmentReturns` tests were written first, then migration 000048 (`order_returns`/`return_lines` with per-line stage bounds as checks, the `order-return`/`order-return-approve`/`order-return-receive`/`order-return-restock` idempotency operations, the `return_requested`/`return_approved`/`return_received`/`return_restocked` timeline types, the allocator `restock` movement/action), four step actions (`RecordReturn`/`ApproveReturn`/`ReceiveReturn`/`RestockReturn` over a shared `BaseReturnAction`), the allocator `restock` mutation, `ReturnController` and routes, the admin return summary with refund linkage, and `ReturnDialog`. Staff record request (explicit reason; quantities within `shipped − committed` per line, where a return commits its requested quantities while `requested`, its approved quantities once approved, and only what it received once rejected), approval, receipt and restock as separate idempotent steps; receipt never moves stock or money, a refund never restocks, and restock returns tracked units to `on_hand` exactly once through the allocator `restock` mutation keyed by the staff submission (untracked lines record quantities without inventing a balance). Returns record no money: the detail summary links the refund facts (`refund_lines` joined to `refunds`) recorded against each returned line from the T041/T044 machinery, and detail lines expose `returned_qty`/`restocked_qty`/`returnable_quantity`. Replaying any step key returns the existing record without a second effect; the same key with a changed payload conflicts; a duplicate restock request was inspected and moves stock once. Privileged staff still must pass 2FA. `GET /` remains 503. `FulfillmentReturns` 7 tests / 148 assertions; standalone serial runs: `CheckoutOrders` 86 / 1150, `FulfillmentShipment` 7 / 147, `FulfillmentTracking` 6 / 129, `CatalogInventory` 8 / 125. Full PHP suite: 501 tests / 4916 assertions (assertion totals drift between runs — the T046 race test asserts inside a polling loop; pass counts are stable at the 494 baseline + 7). Pint PASS (435 files); PHPStan level 8 no errors (run with `APP_ENV=testing` so Larastan boots the testing environment); frontend type/lint/format; 73 Vue tests; production build. Live Stripe is not involved in this slice. Local PCOV was not collected; Chromium journeys were not run in this environment (no combined PHP/Node browser harness on the VPS test stack). Hosted Quality for this branch is pending. Merge only after a green Quality run on the current HEAD.
- **Module / requirements:** `fulfillment` · FUL-03.
- **Depends on:** T046, T041.
- **Acceptance:** Restock happens only through deliberate action; return summary and refund linkage visible. Staff records approval/receipt/restock separately from money; received/shipped bounds enforced; repeated restock has one effect.
- **Verify:** Run `php artisan test --filter=FulfillmentReturns`; inspect duplicate return request; verify refund without stock movement and explicit received restock.
- **Likely files:** `database/migrations/create_returns.php`, `app/Domain/Fulfillment/Actions/ReceiveReturn.php`, `app/Http/Controllers/Admin/ReturnController.php`, `resources/js/admin/components/ReturnDialog.vue`, `tests/Feature/FulfillmentReturnsTest.php`.
- **Scope:** Split into T048a–T048d before coding (schema/request/approval, receipt/restock, admin HTTP/visibility, dialog/docs), preserving this parent acceptance.

#### T048a: Return schema, request and approval actions

- [x] Complete T048a locally.
- **Evidence October 16:** Migration 000048, `RecordReturn` and `ApproveReturn` with the committed-quantity bound and per-step idempotency claims. Covered by `FulfillmentReturns` tests 1–4 (lifecycle request/approval stages, shipped/stage bounds and unauthorized/unpaid rejections persisting nothing, partial approval limiting later requests with a full rejection freeing quantities, and duplicate-request replay/conflict).
- **Acceptance:** Migration 000048 creates `order_returns`/`return_lines` (per-line requested/approved/received/restocked quantities with stage bounds as checks), admits the `order-return`/`order-return-approve`/`order-return-receive`/`order-return-restock` idempotency operations, the `return_requested`/`return_approved`/`return_received`/`return_restocked` timeline types and the allocator `restock` movement/action. `RecordReturn` (permission `fulfillment.manage`) claims a staff idempotency record, locks the order and its lines, and accepts only physical lines on a confirmed/on_hold/completed order within `shipped − received − outstanding requested/approved` per line, recording the return as `requested` with an explicit reason, one deduplicated timeline event and a staff audit. `ApproveReturn` acts only on a `requested` return, records per-line approved quantities within requested (unposted lines approve zero), and moves the return to `approved`, or `rejected` when every line approves zero, freeing its quantities. A replayed key returns the existing record; the same key with a changed payload conflicts.
- **Verify:** Run `php artisan test --filter=FulfillmentReturns`.
- **Likely files:** `database/migrations/2026_10_16_000048_create_returns.php`, `app/Domain/Fulfillment/Actions/RecordReturn.php`, `app/Domain/Fulfillment/Actions/ApproveReturn.php`, `app/Domain/CheckoutOrders/Actions/RecordOrderEvent.php`, `tests/Feature/FulfillmentReturnsTest.php`.

#### T048b: Return receipt and deliberate restock

- [x] Complete T048b locally.
- **Evidence October 16:** `ReceiveReturn` (partial receipts project `partially_received` → `received`; no stock or money movement) and `RestockReturn` over the new allocator `restock` mutation (contract + `AllocateInventory`, `restock` stock movement with `on_hand` delta only, operation key derived from the staff submission). Covered by `FulfillmentReturns` tests 1, 4, 5 and 6: stock moves only at restock, a replayed restock moves it once, a staff refund leaves `on_hand` untouched, and untracked lines restock as records without a balance or movement.
- **Acceptance:** `ReceiveReturn` acts only on an `approved`/`partially_received` return, records per-line received quantities within `approved − received`, moves the return to `received` when every approved unit is received (else `partially_received`), and never moves stock or money. `RestockReturn` acts only on a return with received units, records per-line restocked quantities within `received − restocked`, and returns tracked units to `on_hand` exactly once through a new allocator `restock` mutation keyed by the staff submission (untracked lines move no stock); receiving alone never restocks, and a refund never restocks. Each step claims its own staff idempotency operation, records one timeline event per accepted call and a staff audit; replays return the current record without a second effect and changed payloads conflict.
- **Verify:** Run `php artisan test --filter=FulfillmentReturns`; verify refund without stock movement and explicit received restock.
- **Likely files:** `app/Domain/Fulfillment/Actions/ReceiveReturn.php`, `app/Domain/Fulfillment/Actions/RestockReturn.php`, `app/Domain/Catalog/Contracts/InventoryAllocator.php`, `app/Domain/Catalog/Actions/AllocateInventory.php`, `tests/Feature/FulfillmentReturnsTest.php`.

#### T048c: Admin return endpoints, order visibility and refund linkage

- [x] Complete T048c locally.
- **Evidence October 16:** `ReturnController` (create/approve/receive/restock, `fulfillment.manage` plus fresh staff 2FA, stable idempotency key, redirect with `order_return_saved`), the four routes, `ReadAdminOrders` per-line `returned_qty`/`restocked_qty`/`returnable_quantity` plus the `returns` summary whose lines link the `refund_lines`/`refunds` facts for that order line, and the `canReturn`/`returnSaved` detail props. Covered by the `FulfillmentReturns` HTTP test (2FA reauthentication redirect, the four steps over HTTP, content-editor 403) and the detail assertions in tests 1, 5 and 6; `CheckoutOrders` group green standalone.
- **Acceptance:** `POST /admin/orders/{orderId}/returns` plus `/returns/{returnId}/approve|receive|restock` require `fulfillment.manage` and fresh staff 2FA, validate reason/line quantities and a stable idempotency key, and redirect back to the order. Admin order detail exposes per-line `returned_qty`/`restocked_qty`/`returnable_quantity` plus the return summary: each return's status, reason and per-line requested/approved/received/restocked quantities, with refund linkage — the refund facts (`refund_lines` joined to `refunds`: id, kind, origin, status, quantity, amount) recorded against each returned order line — read from the T041/T044 refund machinery; returns themselves record no money. The detail page gains a `canReturn` flag.
- **Verify:** Run `php artisan test --filter=FulfillmentReturns` and `php artisan test --filter=CheckoutOrdersAdmin`.
- **Likely files:** `app/Http/Controllers/Admin/ReturnController.php`, `routes/web.php`, `app/Domain/CheckoutOrders/Actions/ReadAdminOrders.php`, `app/Http/Controllers/Admin/OrderController.php`.

#### T048d: Return dialog and documentation

- [x] Complete T048d locally.
- **Evidence October 16:** `ReturnDialog` (create/approve/receive/restock modes, per-step quantity bounds, required reason on create, fresh idempotency key per submit) and the read-only returns section on `OrderDetail.vue` (stage quantities plus linked refund facts, step dialogs gated by return status), with unit coverage for the dialog's create/restock modes and the detail returns state (73 Vue tests; type/lint/format/build pass). The `SPEC-fulfillment.md` implementation status and the `docs/development.md` returns section record the T048 behavior; digital entitlements/downloads remain T049+.
- **Acceptance:** `ReturnDialog` on the order detail page lets permitted staff open a return (per-line quantities bounded by `returnable_quantity`, required reason, fresh idempotency key per submit) and, per recorded return, submit the approve/receive/restock steps with quantities bounded by that step's remaining quantities; recorded returns render read-only with their stage quantities and linked refunds. `SPEC-fulfillment.md` and `docs/development.md` record the T048 behavior; digital entitlements/downloads remain T049+.
- **Verify:** Run the frontend type/lint/format/unit/build checks and `php artisan test --filter=FulfillmentReturns`.
- **Likely files:** `resources/js/admin/components/ReturnDialog.vue`, `resources/js/admin/components/ReturnDialog.test.ts`, `resources/js/admin/pages/OrderDetail.vue`, `resources/js/admin/pages/OrderDetail.test.ts`, `SPEC-fulfillment.md`, `docs/development.md`.

#### Checkpoint after T046, T047, T048

- [x] Acceptance and focused verification recorded; no zero-test pass. T046/T047/T048 each have failing-first tests and local evidence (`FulfillmentShipment` 7, `FulfillmentTracking` 6, `FulfillmentReturns` 7); full PHP suite 501 tests; hosted Quality for these branches is pending.
- [x] Relevant build/browser flow passes, including failure, permission and replay cases. Admin shipment/tracking/return flows are covered over HTTP with permission and fresh-2FA gates, idempotent replays and changed-payload conflicts; Chromium journeys were not run in this environment (no combined PHP/Node browser harness on the VPS test stack).
- [x] Changes documented in `SPEC-fulfillment.md` and `docs/development.md`; pending external-provider evidence remains explicit. Live Stripe shipment/return evidence is not applicable (no provider calls in these slices); hosted CI remains pending.

### T049: Paid digital entitlement issuance

- [x] Complete T049.
- **Module / requirements:** `fulfillment` · FUL-04.
- **Depends on:** T020, T037.
- **Acceptance:** Current eligible paid/free-completed digital lines issue one version-pinned entitlement. Replayed or delayed OrderPaid after refund/dispute cannot grant access; eligibility is durable even if refund preceded entitlement creation.
- **Verify:** Run `php artisan test --filter=FulfillmentEntitlement`; replay events for digital and mixed orders.
- **Evidence October 16:** Split into T049a–T049b before coding (actual scope exceeded the sizing rule). Failing `FulfillmentEntitlement` tests were written first (8 failed), then migration `000049` (`entitlements`: one row per order line under a unique constraint, version pinned from the line's frozen `digital_asset_version_id`, grantee snapshot `customer_id` — null for guest orders, lifecycle status check `active`/`suspended`/`revoked`/`expired`, `entitlements_issued` added to the order-events type check), `IssueEntitlements` in the fulfillment module and an independent `fulfillment.entitlements` outbox consumer on `checkout.order.paid`. The paid event is a trigger only: issuance requires a recorded `payments` fact (captured provider payment or the local zero-total completion row — placement alone never grants) and rechecks current authoritative eligibility under order/line row locks, so eligible paid/free-completed digital lines issue `active` while fully refunded lines, cancelled orders, whole-order refunds and dispute-lost orders record a durable `revoked` tombstone instead of access, open-dispute/download-suspended lines record `suspended`, and amount-only goodwill refunds retain access. Replays return the existing rows (a revoked row is never silently reactivated), a later asset version never moves the pin, and a `suspended` `download_status` is never un-suspended by issuance; one deduplicated `entitlements_issued` timeline entry is recorded per paid event that creates rows. `FulfillmentEntitlement` 8 tests / 58 assertions; affected groups CheckoutOrders 86, Fulfillment 33, PlatformOutbox 19. Full PHP suite: 509 tests / 4986 assertions; Pint; PHPStan level 8 (with `--memory-limit=1G` — the container's 256M default crashed a PHPStan worker before analysis); frontend type/lint/format; 73 Vue tests; production build. Live Stripe sandbox purchase inspection remains pending (no credentials); issuance is evidenced over the local capture and free-completion paths with synthetic fixtures. Chromium journeys were not run in this environment. Hosted Quality for this branch is pending. Merge only after a green Quality run on the current HEAD.
- **Likely files:** `database/migrations/create_entitlements.php`, `app/Domain/Fulfillment/Actions/IssueEntitlements.php`, `app/Jobs/IssueOrderDownloads.php`, `tests/Feature/FulfillmentEntitlementTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.
- **Split (2026-10-30, before coding):** Actual scope exceeds the sizing rule (migration, issuance action, outbox consumer wiring, timeline type, feature tests, docs), so T049 is split into T049a–T049b below; parent acceptance above is preserved and is checked only when both subtasks carry evidence.

#### T049a: Entitlements persistence and issuance action

- [x] Complete T049a.
- **Evidence October 16:** Migration `000049` creates `entitlements` as specified and `IssueEntitlements::issueForOrder` performs the locked eligibility recheck (payment fact required; per-line resolution active/suspended/revoked as in the parent evidence). Covered by the `FulfillmentEntitlement` cases for paid, free-completed, unpaid (no payment fact → throws, no rows), fully-refunded-before-issuance, goodwill-retained, dispute-open and dispute-lost orders.
- **Acceptance:** Migration `000049` creates `entitlements`: one row per order line (unique), version pinned from the line's frozen `digital_asset_version_id`, grantee snapshot from the order (nullable `customer_id` for guests), lifecycle status `active` / `suspended` / `revoked` / `expired`. `IssueEntitlements` rechecks current authoritative eligibility under order/line row locks and never trusts the event alone: it requires a recorded payment fact (captured payment or the local zero-total completion row); eligible paid/free-completed digital lines issue `active`; fully refunded lines, cancelled orders, whole-order refunds and dispute-lost orders record a durable `revoked` tombstone instead of access; open-dispute / download-suspended lines record `suspended`. Re-issuance returns existing rows unchanged (revoked is never silently reactivated).
- **Likely files:** `database/migrations/2026_10_16_000049_create_entitlements.php`, `app/Domain/Fulfillment/Actions/IssueEntitlements.php`.

#### T049b: Paid-event consumer wiring, timeline and documentation

- [x] Complete T049b.
- **Evidence October 16:** `IssueOrderEntitlements` consumer registered as `fulfillment.entitlements` beside `checkout.order-paid` in `config/outbox.php` (its own consumer receipt per paid event); `entitlements_issued` added to the `RecordOrderEvent` whitelist and the `order_events` type check. Redelivery, forced receipt replay and direct re-issuance all leave exactly one entitlement and one timeline entry (asserted in the paid-line test). `SPEC-fulfillment.md` implementation status and the `docs/development.md` entitlements section record the behavior; the live-Stripe sandbox purchase inspection stays listed as pending evidence.
- **Acceptance:** An independent outbox consumer (`fulfillment.entitlements`) on `checkout.order.paid` issues through T049a and records one deduplicated `entitlements_issued` order-timeline entry per paid event; consumer redelivery and receipt replays create no duplicate entitlements or timeline entries. Issuance never un-suspends a `suspended` `download_status`. `FulfillmentEntitlement` feature tests cover paid, free-completed, mixed, physical-only, unpaid, refund-before-issuance, dispute-open-before-issuance, dispute-lost-before-issuance and replay cases. `SPEC-fulfillment.md` implementation status and the `docs/development.md` entitlements section record the behavior; live Stripe sandbox purchase inspection remains pending evidence.
- **Likely files:** `app/Domain/Fulfillment/Consumers/IssueOrderEntitlements.php`, `config/outbox.php`, `app/Domain/CheckoutOrders/Actions/RecordOrderEvent.php`, `tests/Feature/FulfillmentEntitlementTest.php`, `SPEC-fulfillment.md`, `docs/development.md`.

### T050: Verified guest and account download area

- [x] Complete T050.
- **Module / requirements:** `fulfillment` · FUL-04, ID-05, ORDER-07.
- **Depends on:** T049, T009.
- **Acceptance:** Guest verification links expire and are consumed only through explicit confirmation POST; scanner GET does not consume or authenticate. Verified accounts claim eligible orders; wrong identity sees no files.
- **Evidence October 16:** Split into T050a–T050b before coding (actual scope exceeded the sizing rule; the T009 historical guest-order claiming T050 depends on did not exist — only the `ProveCustomerIdentity` proof — so claiming was built as T050b rather than stubbed). Failing `FulfillmentDownloadIdentity` tests were written first (7 failed), then migration `000050` (`guest_order_verifications` digest-only tokens, `download_access_intents`, `download_access_requested` timeline type), `VerifyGuestOrder` (issue/consume), the scanner-safe guest confirmation flow, `ReadDownloads`, `ClaimGuestOrders`, the account and guest download areas and the `download-access` mail view. Guest tokens expire in 24 hours, are single-use and are consumed only by the explicit CSRF-protected confirmation POST, which rotates the session and grants access scoped to one order; scanner GETs, replays, expired tokens and pre-claim tokens for claimed orders all fail closed. Both areas expose only `active` entitlements with a `ready` pinned version; suspended/revoked rows show no files and wrong identities see nothing. Verified claiming re-proves identity in-transaction and atomically moves guest orders (customer_id set, guest hash cleared, entitlement grantee repointed, guest tokens revoked); unverified same-email registrations and token possession alone claim nothing. `FulfillmentDownloadIdentity` 7 tests / 118 assertions; affected groups CheckoutOrders 86, Fulfillment 40, IdentityCustomer 82. Full PHP suite: 516 tests / 5083 assertions; Pint (445 files); PHPStan level 8 (APP_ENV=testing, `--memory-limit=1G`); frontend type/lint/format; 73 Vue tests; production build. Live Stripe sandbox purchase inspection remains pending (no credentials); Chromium journeys were not run in this environment. Hosted Quality for this branch is pending. Merge only after a green Quality run on the current HEAD.
- **Verify:** Run `php artisan test --filter=FulfillmentDownloadIdentity`; exercise account-link and replay attacks.
- **Likely files:** `app/Domain/Fulfillment/Actions/VerifyGuestOrder.php`, `app/Http/Controllers/Storefront/DownloadsController.php`, `themes/default/views/account-downloads.blade.php`, `resources/views/mail/download-access.blade.php`, `tests/Feature/FulfillmentDownloadIdentityTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.
- **Split (2026-10-30, before coding):** Actual scope exceeds the sizing rule (migration, guest token issue/consume, mail view, access controller flow, download read model, historical guest-order claiming, theme views, feature tests, docs), so T050 is split into T050a–T050b below; parent acceptance above is preserved and is checked only when both subtasks carry evidence. T009 finding: the verified identity proof (`ProveCustomerIdentity`) exists, but historical guest-order claiming was never built — orders still carry `guest_token_hash` XOR `customer_id` and no claim action exists — so the minimal claiming slice T050 needs is built as T050b (`ClaimGuestOrders`) rather than stubbed.

#### T050a: Guest download verification tokens and scanner-safe confirmation

- [x] Complete T050a.
- **Evidence October 16:** Migration `000050` creates `guest_order_verifications` (SHA-256 token digest only, email digest, 24-hour expiry, consumed/revoked timestamps) and `download_access_intents` (one `queued` `download-access` intent per request) and adds `download_access_requested` to the order-events type check and `RecordOrderEvent` whitelist. `VerifyGuestOrder::requestAccess` returns null generically for unknown orders, account-owned orders and email mismatches; `consume` locks order-then-token, rechecks guest ownership, email digest, expiry and unconsumed state, consumes atomically and audits. `DownloadsController` renders the access form on GET without any mutation, and the confirmation POST validates the 64-hex code, consumes, regenerates the session and stores a 24-hour grant scoped to the one order. Covered by the `FulfillmentDownloadIdentity` issue/scanner/replay/expiry cases, including the mail view rendering (order number, fragment link, code, no storage terms) with `Mail::assertNothingOutgoing`.
- **Acceptance:** A guest requests download access for one guest order by order ID + the order's email; mismatches return the same generic outcome and issue nothing. Issuance stores only a SHA-256 token digest (24-hour expiry, reissue revokes prior tokens) and queues one deduplicated-per-request `download-access` mail intent; the `mail.download-access` view carries the order number, a fragment link and the code without any storage key. Scanner/prefetch GETs (access page, downloads URL) never consume the token, authenticate or mutate state; only the explicit CSRF-protected confirmation POST consumes it atomically, rotates the session and grants access scoped to that one order. Consumed, expired, revoked and wrong-order tokens fail without granting access; replay after consumption fails.
- **Likely files:** `database/migrations/2026_10_16_000050_create_guest_download_access.php`, `app/Domain/Fulfillment/Actions/VerifyGuestOrder.php`, `app/Http/Controllers/Storefront/DownloadsController.php`, `resources/views/system/guest-download-access.blade.php`, `resources/views/mail/download-access.blade.php`, `tests/Feature/FulfillmentDownloadIdentityTest.php`.

#### T050b: Download area and verified account claiming of guest orders

- [x] Complete T050b.
- **Evidence October 16:** `ReadDownloads` lists only `active` entitlements whose pinned version is `ready` (title/SKU, asset label, version, bytes — no storage key) keyed strictly by `orders.customer_id` for accounts or by the single granted order for guests; suspended (dispute-opened) and revoked (fully refunded) fixtures render an area with no files, and a stranger's account area is empty. `ClaimGuestOrders` re-proves identity via `ProveCustomerIdentity` inside its transaction and claims canonical-email guest orders atomically (order owner flip, entitlement grantee repoint, guest-token revocation, per-order audit); the `FulfillmentDownloadIdentity` claim case walks an unverified same-email account being refused (403 + action-level `AuthorizationException`), a verified wrong-email account claiming zero, verification unlocking the claim, replay claiming zero, and the pre-claim guest token dying with the claim. The account-link case proves a guest token confirmed inside another customer's session grants only the scoped guest view and never the account area. `SPEC-fulfillment.md` implementation status and the `docs/development.md` download-areas section record the behavior; streaming grants remain T051.
- **Acceptance:** The download area lists only `active` entitlements whose pinned asset version is `ready`, showing line title/SKU, asset label, version and size — never a storage key — for the signed-in customer's own orders (`/account/downloads`) or for the single guest order a verified session grant covers (`/orders/{orderId}/downloads`, 404 without a live grant). Suspended/revoked/expired entitlements show no files; another identity's orders return nothing/404 without leaking titles. A verified account claims eligible historical guest orders (matching canonical email) through an explicit POST: claiming atomically sets `orders.customer_id`, clears `guest_token_hash` and repoints unclaimed entitlements' grantee snapshot; unverified accounts and wrong-email accounts claim nothing. `SPEC-fulfillment.md` implementation status and the `docs/development.md` downloads section record the behavior; streaming grants remain T051.
- **Likely files:** `app/Domain/Fulfillment/Actions/ClaimGuestOrders.php`, `app/Domain/Fulfillment/Actions/ReadDownloads.php`, `app/Http/Controllers/Storefront/DownloadsController.php`, `themes/default/views/account-downloads.blade.php`, `tests/Feature/FulfillmentDownloadIdentityTest.php`, `SPEC-fulfillment.md`, `docs/development.md`.

### T051: Private streaming download grants

- [x] Complete T051.
- **Module / requirements:** `fulfillment` · FUL-05.
- **Depends on:** T050.
- **Evidence October 16:** Split into T051a–T051b before coding (actual scope exceeded the sizing rule). Failing `FulfillmentDownload` tests were written first, then migration `000051` (entitlement `download_limit`/`downloads_used` allowance counters, `download_grants` digest-only start tokens, `download_transfers` sessions with single-stream lease fields, `download_started` timeline type), `AuthorizeDownload`, `DownloadStreamController`, the grant status view and the download-area allowance display. Issuing a grant stores only a SHA-256 digest of a 256-bit token (five-minute window, bound to entitlement, order and principal) and spends nothing; the explicit CSRF-protected start POST consumes it under the entitlement row lock after rechecking principal, token, current entitlement/version/order eligibility and the allowance — two racing worker processes for the last allowance produced exactly one `started` and one `denied`, five transfers total. The owner-only grant status recovers a lost start response without re-spending, replays create nothing, and transfers outlive the five-minute token window but not their one-hour absolute expiry (410 after). Streams serve the pinned version's bytes from the private disk with bounded single-Range support (206/Content-Range, suffix and open-ended ranges, end clamping, 416 for unsatisfiable, malformed/multi-range ignored), hold a single-stream lease (409 against a live foreign lease, released on completion), and recheck principal, transfer state and current entitlement eligibility on every request — revoked/suspended entitlements and revoked transfers return 403, cross-principal requests 404, and resume spends no further allowance. Guest transfers stream only inside the verified single-order session scope and are invisible under the account routes. `FulfillmentDownload` filter 15 tests / 286 assertions (8 new + 7 T050 identity); affected groups Fulfillment 48, CheckoutOrders 86, IdentityCustomer 82. Full PHP suite: 524 tests / 5240 assertions; Pint (451 files); PHPStan level 8 (APP_ENV=testing, `--memory-limit=1G`); frontend type/lint/format; 73 Vue tests; production build. Live Stripe is not involved in this slice. Chromium journeys were not run in this environment. Hosted Quality for this branch is pending. Merge only after a green Quality run on the current HEAD.
- **Acceptance:** Five-minute start token is consumed only by an explicit CSRF-protected POST, creating one counted transfer session bound to owner and asset for one hour. Owner-only status recovers lost responses; authorized Range/resume does not spend another grant; revoked/cross-user/expired sessions reject new streams.
- **Verify:** Run `php artisan test --filter=FulfillmentDownload`; test GET/prefetch safety, last-grant race, lost start response, stream beyond five minutes, bounded Range/resume and revoke-before-resume. Do not claim already-transferred bytes can be recalled.
- **Likely files:** `database/migrations/create_download_grants.php`, `app/Domain/Fulfillment/Actions/AuthorizeDownload.php`, `app/Http/Controllers/Storefront/DownloadStreamController.php`, `tests/Feature/FulfillmentDownloadTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.
- **Split (2026-10-30, before coding):** Actual scope exceeds the sizing rule (migration, authorization action, streaming controller with Range/lease handling, routes, two theme views, race fixture, feature tests, docs), so T051 is split into T051a–T051b below; parent acceptance above is preserved and is checked only when both subtasks carry evidence.

#### T051a: Download grant persistence and authorization action

- [x] Complete T051a.
- **Evidence October 16:** Migration `000051` and `AuthorizeDownload` landed as accepted: digest-only five-minute tokens bound to entitlement/order/principal, issue spends nothing, start consumes under the entitlement row lock with principal/eligibility/allowance rechecks (worker-process race: one `started`, one `denied`, counter ends at the limit), owner-only status recovers the transfer, and replayed/expired/cross-principal starts fail closed spending nothing. Covered by the `FulfillmentDownload` issue/start/race/exhaustion cases.
- **Acceptance:** Migration `000051` adds per-entitlement allowance counters (`download_limit` default five, `downloads_used`), `download_grants` (digest-only five-minute start tokens bound to entitlement, order and principal) and `download_transfers` (one transfer per grant, owner + pinned-version binding, one-hour absolute expiry, single-stream lease fields, active/revoked/expired state), plus the `download_started` order-timeline type. `AuthorizeDownload` issues tokens without spending an allowance, consumes a token only through the explicit start call — rechecking principal, current entitlement/version eligibility and the allowance under the entitlement row lock, so concurrent starts for the last allowance create exactly one transfer — and exposes owner-only grant status that recovers a consumed grant's transfer without re-spending. Replayed, expired, cross-principal and ineligible starts fail closed and spend nothing.
- **Verify:** Run `php artisan test --filter=FulfillmentDownload`; the last-allowance race runs two independent worker processes against the isolated MySQL test database.

#### T051b: Streaming delivery and download-area wiring

- [x] Complete T051b.
- **Evidence October 16:** `DownloadStreamController`, the eight account/guest routes, the `download-grant` status view and the area allowance form landed as accepted: POST-only issue/start, GET status never consumes, bounded Range streaming with lease and per-request rechecks (revoked/suspended 403, cross-principal 404, expired 410, foreign live lease 409), stream alive ten minutes after start and gone after the hour, guest scope enforced end-to-end. `SPEC-fulfillment.md` implementation status and the `docs/development.md` download-grants section record the behavior; transferred bytes are never claimed recallable.
- **Acceptance:** `DownloadStreamController` mounts scanner-safe routes for both areas (account and guest-order scope): issue and start are POST-only, the owner-only grant status page recovers a lost start response, and the stream route serves the pinned version's bytes from the private disk with bounded single-Range support (206/Content-Range, 416 for unsatisfiable, malformed ignored), a single-stream lease, and a per-request recheck of principal, transfer state/expiry and current entitlement eligibility — revoked/suspended entitlements, revoked or expired transfers and cross-principal requests reject new streams, while resume within the hour spends no further allowance. The download areas show the remaining allowance and the issue form. `SPEC-fulfillment.md` implementation status and the `docs/development.md` download-grants section record the behavior; already-transferred bytes are never claimed recallable.

#### Checkpoint after T049, T050, T051

- [x] Acceptance and focused verification recorded; no zero-test pass. T049 (8 entitlement tests), T050 (7 download-identity tests) and T051 (8 download-grant tests) each have failing-first tests and local evidence; the `FulfillmentDownload` filter runs 15 tests / 286 assertions and the full PHP suite is 524 tests / 5240 assertions.
- [x] Relevant build/browser flow passes, including failure, permission and replay cases. Server-side flows cover paid/free issuance tombstones, scanner-safe guest confirmation, claiming, token replay/expiry, the last-allowance start race, lost-response recovery, Range/resume, lease contention, revoke-before-resume and cross-principal denial; Chromium journeys were not run in this environment, consistent with T049–T050.
- [x] Changes documented in `SPEC-fulfillment.md` and `docs/development.md`; pending external-provider evidence remains explicit. Live Stripe sandbox purchase inspection remains pending (no credentials); hosted Quality for this branch is pending.

### T052: Refund and dispute access revocation

- [x] Complete T052.
- **Module / requirements:** `fulfillment` · FUL-05.
- **Depends on:** T051, T041, T044.
- **Evidence October 16:** Split into T052a–T052b before coding (actual scope exceeded the sizing rule; the synchronous in-transaction sync was chosen over the queued `ApplyEntitlementAdjustment` job in the likely-files list, for the atomicity reason recorded in the split note below). Failing `FulfillmentRevocation` tests were written first (8 failed, 1 passed — the retention guard), then migration `000052` (order-event types `entitlements_revoked` / `entitlements_suspended` / `entitlements_restored`), `RevokeEntitlements::syncForOrder`, and hooks in `ApplyRefundOutcome` (succeeded staff refunds), `CancelOrder::complete` (cancellations completing without a refund outcome) and `ReconcileExternalAdjustment` (provider-refund success; dispute opened/won/lost effects). The sync locks the order, its lines and its entitlements and recomputes each non-terminal entitlement from the current authoritative facts: a cancelled order, a lost dispute, a whole-order refunded financial state, a revoked download projection or the entitlement's own line at `refunded_qty >= quantity` revokes — also revoking the entitlement's unconsumed grants and active transfers; an open dispute or a suspended download projection suspends (grants and transfers left in place, since T051 start/stream rechecks reject on entitlement status); otherwise the line is eligible, and a suspended row restores only when the recomputation says eligible again. Each transition kind writes one timeline entry (counts + reasons) sourced from the triggering fact plus one platform audit; a no-change sync writes nothing, so replays and redeliveries are silent. `FulfillmentRevocation` 9 tests / 88 assertions (full staff line refund with grant/transfer kill and stale paid-event replay, two-digital-line precision, physical-line + goodwill retention, provider partial/full refunds + redelivery, dispute suspend/restore/replay, dispute lost opened-first and observed-closed, dispute won after an intervening full refund restoring nothing, HTTP revoke-between-grant-issue-and-start, free-order cancellation); affected groups Fulfillment 57 / 944 assertions, CheckoutOrders 86 / 1135 assertions. Full PHP suite: 533 tests / 5360 assertions; Pint (454 files); PHPStan level 8 (APP_ENV=testing, `--memory-limit=1G`); frontend type/lint/format; 73 Vue tests; production build. Live Stripe refunds/disputes were not executed (no credentials); provider facts are exercised through the reconciliation actions with synthetic observations, as in T044. Chromium journeys were not run in this environment. Hosted Quality for this branch is pending. Merge only after a green Quality run on the current HEAD.
- **Acceptance:** Full digital-line refund revokes access and prevents later stale paid-event issuance; unrelated physical-line or partial goodwill refunds retain eligible digital access. Disputes suspend/resolve under current authoritative order state.
- **Verify:** Run `php artisan test --filter=FulfillmentRevocation`; revoke between grant issue and transfer start.
- **Likely files:** `app/Domain/Fulfillment/Actions/RevokeEntitlements.php`, `app/Jobs/ApplyEntitlementAdjustment.php`, `tests/Feature/FulfillmentRevocationTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.
- **Split (2026-10-30, before coding):** Actual scope exceeds the sizing rule (timeline-type migration, the revocation sync action, four money-path wirings across two modules, feature tests, docs), so T052 is split into T052a–T052b below; parent acceptance above is preserved and is checked only when both subtasks carry evidence. Design decision recorded here: revocation is a synchronous, in-transaction recomputation (`RevokeEntitlements::syncForOrder`) invoked from the money paths themselves — the same composition `CancelOrder::complete` already uses inside `ApplyRefundOutcome` — rather than a queued `ApplyEntitlementAdjustment` job, because access must change atomically with the refund/dispute fact that causes it; a queued adjustment could observe pre-commit state or land after a download start has already rechecked the stale entitlement. The sync recomputes every issued entitlement from the current authoritative facts (order status/financial/download projections, per-line `refunded_qty`, dispute rows) under row locks, so staff refunds, provider-observed refunds and dispute transitions all converge on one rule set and replays/redeliveries are no-ops.

#### T052a: Entitlement revocation sync action and timeline types

- [x] Complete T052a.
- **Evidence October 16:** Migration `000052` and the `RecordOrderEvent` whitelist carry the three transition types; `RevokeEntitlements::syncForOrder` performs the locked recomputation exactly as accepted (revoked precedence, terminal rows untouched, grant/transfer revocation only on the revoked transition, one timeline entry + audit per kind that actually occurred, nothing recorded on a no-op sync). Covered by the direct-sync assertions in the `FulfillmentRevocation` full-refund and dispute cases, including the replayed-sync no-op.
- **Acceptance:** Migration `000052` adds `entitlements_revoked` / `entitlements_suspended` / `entitlements_restored` to the `order_events` type check and the `RecordOrderEvent` whitelist. `RevokeEntitlements::syncForOrder` locks the order, its lines and its entitlements and recomputes each non-terminal entitlement's state from current authoritative facts with revoked precedence: order cancelled, a lost dispute, whole-order refunded financial state, a `revoked` download projection, or the entitlement's own line fully refunded (`refunded_qty >= quantity`) targets `revoked`; an open dispute or a `suspended` download projection targets `suspended`; otherwise `active`. `revoked`/`expired` rows never move. A transition to `revoked` also revokes the entitlement's unconsumed download grants (`revoked_at` set, digest cleared) and its `active` transfers (status `revoked`, lease cleared), so new streams reject; suspension leaves grants/transfers in place because T051 start/stream rechecks reject on entitlement status and a restore re-enables them. A `suspended` row returns to `active` only when the recomputation says eligible (the suspending dispute resolved won and no revoking fact landed meanwhile). Each transition kind that actually occurs records one order-timeline entry (counts + reasons) and one platform audit; a sync that changes nothing records nothing, so replays are silent no-ops. Already-transferred bytes are never claimed recallable.
- **Likely files:** `database/migrations/2026_10_16_000052_entitlement_revocation_events.php`, `app/Domain/Fulfillment/Actions/RevokeEntitlements.php`, `app/Domain/CheckoutOrders/Actions/RecordOrderEvent.php`, `tests/Feature/FulfillmentRevocationTest.php`.

#### T052b: Money-path wiring, end-to-end verification and documentation

- [x] Complete T052b.
- **Evidence October 16:** Hooks landed in `ApplyRefundOutcome` (after the refund commit/projection and any cancellation completion, inside the same transaction), `CancelOrder::complete` (after the cancellation facts) and `ReconcileExternalAdjustment` (`refundSucceededEffects`, `disputeOpenedEffects`, `disputeWonEffects`, `disputeLostEffects`). The nine `FulfillmentRevocation` cases prove the matrix end-to-end through the real staff, provider and HTTP paths named in the acceptance; `SPEC-fulfillment.md` implementation status and the `docs/development.md` revocation section record the behavior; live Stripe remains unexecuted.
- **Acceptance:** The sync runs inside the same transaction wherever the authoritative facts change: `ApplyRefundOutcome` on a succeeded refund (staff quantity/goodwill/cancellation refunds and provider observations of staff refunds), `CancelOrder::complete` (cancellations without a refund outcome, e.g. zero-total), `ReconcileExternalAdjustment` provider-refund success effects and the dispute opened/won/lost effects. End-to-end coverage proves: a full staff refund of a digital line revokes that line only (a second digital line and a download already started behave per the transfer rules); revocation between grant issue and transfer start rejects the start; a physical-line refund and an amount-only goodwill refund retain digital access; a full provider refund revokes while a partial retains; dispute open suspends issued access, won restores it, lost revokes it, and won after an intervening full refund restores nothing. `SPEC-fulfillment.md` implementation status and the `docs/development.md` revocation section record the behavior; live Stripe remains unexecuted.
- **Likely files:** `app/Domain/CheckoutOrders/Actions/ApplyRefundOutcome.php`, `app/Domain/CheckoutOrders/Actions/CancelOrder.php`, `app/Domain/CheckoutOrders/Actions/ReconcileExternalAdjustment.php`, `tests/Feature/FulfillmentRevocationTest.php`, `SPEC-fulfillment.md`, `docs/development.md`.

### T053: Purchased asset updates and grant reset

- [x] Complete T053.
- **Evidence October 16:** T053a–T053c complete with evidence below. Verification on the VPS Docker test stack (isolated `mrktflow_test` database; live demo untouched): focused `FulfillmentAssetUpdate` 7 passed (133 assertions); groups Fulfillment 64, CheckoutOrders 86, PlatformMedia 21 and Catalog 82 passed; full PHP suite 540 passed (5,484 assertions; baseline 533 + 7 new); Pint PASS (460 files); PHPStan level 8 no errors; frontend typecheck, lint and format checks pass, unit 77 passed (38 files; baseline 73 + 4 new) and the production build succeeds. Hosted CI and Chromium journeys were not run in this environment, as in T044–T052; live Stripe is not involved in this slice.
- **Module / requirements:** `fulfillment` · FUL-06, CAT-06.
- **Depends on:** T051, T052.
- **Acceptance:** Staff grants a new asset version or resets limits with audit; referenced file deletion blocked; original purchase version preserved.
- **Verify:** Run `php artisan test --filter=FulfillmentAssetUpdate`; compare existing/new buyer versions.
- **Likely files:** `app/Domain/Fulfillment/Actions/GrantAssetUpdate.php`, `app/Domain/Fulfillment/Actions/ResetDownloadLimit.php`, `resources/js/admin/components/DownloadAccess.vue`, `tests/Feature/FulfillmentAssetUpdateTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.
- **Split (2026-10-30, before coding):** Actual scope exceeds the sizing rule (timeline/idempotency migration, two staff actions, the media-cleanup deletion guard, admin read model + controller + routes, the Vue component, feature tests, docs), so T053 is split into T053a–T053c below; parent acceptance above is preserved and is checked only when all three subtasks carry evidence. Design decisions recorded here: (1) an asset update moves only the one entitlement's `digital_asset_version_id` pin to a strictly newer `ready` version of the same digital asset — the order line's frozen purchase pin, the version history and every other buyer's entitlement stay untouched, in-flight transfers keep the version they were bound to at start (T051), and new starts read the entitlement's current pin; terminal (`revoked`/`expired`) entitlements reject both staff actions so a deliberate grant can never resurrect access T052 took away. (2) A limit reset zeroes `downloads_used` and optionally replaces `download_limit` inside the existing 1..1000 check; a reset that would change nothing is a validation error, not a silent success. (3) Referenced-file deletion: no staff media-deletion endpoint exists and row deletion is already `restrictOnDelete`; the one application path that deletes private originals is `CleanMedia`'s purge of staging/failed drafts, so the application-layer refusal lives there — a media asset referenced by any `digital_asset_versions` row is never purged, however purge-eligible it otherwise looks.

#### T053a: Staff entitlement actions — asset update grant and download limit reset

- [x] Complete T053a.
- **Evidence October 16:** Migration `2026_10_16_000053_entitlement_staff_updates` extends the `order_events` type check and the `idempotency_records` operation check (its `down()` restores the 000052/000048 lists) and `RecordOrderEvent` whitelists the two new types. `GrantAssetUpdate` and `ResetDownloadLimit` share `BaseEntitlementAction` (permission, row locks, staff idempotency claim, entitlement view). The `FulfillmentAssetUpdate` grant, target-rejection, revoked-entitlement and reset cases prove: the pin moves for the granted entitlement only, with the from/to timeline payload and one platform audit; a same-key replay returns the view and a changed payload conflicts; same-version, downgrade, quarantined and foreign-asset targets and a missing reason reject; staff without `fulfillment.manage` fail authorization; a revoked entitlement refuses both actions; a reset restores a spent allowance recording previous limit/used, a no-change reset and out-of-bounds limits reject, and the buyer can spend again afterwards.
- **Acceptance:** Migration `000053` adds `entitlement_update_granted` / `entitlement_limit_reset` to the `order_events` type check and the `RecordOrderEvent` whitelist, and `entitlement-update` / `entitlement-limit-reset` to the `idempotency_records` operation check. `GrantAssetUpdate` (staff `fulfillment.manage`, staff idempotency claim) locks the order and entitlement and moves that entitlement's pin to a strictly newer `ready` version of the same digital asset, recording the from/to version ids and numbers, the required reason, one `entitlement_update_granted` timeline entry and one platform audit; a same-key replay returns the entitlement view and a changed payload conflicts. `ResetDownloadLimit` zeroes `downloads_used` and optionally replaces `download_limit` (1..1000), recording previous/new values, the required reason, one `entitlement_limit_reset` timeline entry and one platform audit, with the same idempotency contract. Both reject `revoked`/`expired` entitlements, never change entitlement status, order projections or the order line's frozen pin, and a no-change reset is a validation error.
- **Likely files:** `database/migrations/2026_10_16_000053_entitlement_staff_updates.php`, `app/Domain/Fulfillment/Actions/BaseEntitlementAction.php`, `app/Domain/Fulfillment/Actions/GrantAssetUpdate.php`, `app/Domain/Fulfillment/Actions/ResetDownloadLimit.php`, `app/Domain/CheckoutOrders/Actions/RecordOrderEvent.php`, `tests/Feature/FulfillmentAssetUpdateTest.php`.

#### T053b: Referenced-file deletion guard

- [x] Complete T053b.
- **Evidence October 16:** `CleanMedia`'s purge branch now skips any media asset referenced by a `digital_asset_versions` row (the row still advances `cleanup_checked_at`, so it is re-examined on the normal cadence). The `FulfillmentAssetUpdate` cleanup case forces a referenced media record purge-eligible (failed status, timestamps 25 hours old) and proves its private file and row survive `CleanMedia::clean()` while an unreferenced staging draft control purges in the same run; the `PlatformMedia` group (21 tests) stays green.
- **Acceptance:** `CleanMedia` never purges the private original of a media asset referenced by any `digital_asset_versions` row, even when the record is otherwise purge-eligible (staging/failed and older than the cutoff): the file remains on the private disk and `purged_at` stays null. Unreferenced staging/failed drafts keep purging exactly as before. Covered by the `FulfillmentAssetUpdate` referenced-file case alongside an unreferenced control purge.
- **Likely files:** `app/Domain/Platform/Actions/CleanMedia.php`, `tests/Feature/FulfillmentAssetUpdateTest.php`.

#### T053c: Admin download-access surface, end-to-end verification and documentation

- [x] Complete T053c.
- **Evidence October 16:** `ReadAdminOrders::detail` exposes the `downloads` read model; `EntitlementController` mirrors `ReturnController` (the `fulfillment.manage` gate, fresh staff 2FA, idempotency key from header or field) on the two throttled entitlement routes; `OrderDetail.vue` renders `DownloadAccess.vue` behind `canManageDownloads` with a `downloadSaved` note, and the existing `OrderDetail.test.ts` fixtures gained the `downloads` prop. The `FulfillmentAssetUpdate` HTTP case proves a stale 2FA session redirects with no mutation, fresh-session Inertia props carry the pinned and purchase versions plus grantable newer versions, a missing reason returns session errors, both POSTs succeed, and a content editor receives 403. The two-buyer streaming case proves the granted buyer streams the new version's bytes while the ungranted buyer still streams the original purchase version's bytes, with the v1 version row, its media file and both order lines' frozen pins intact. `DownloadAccess.test.ts` adds 4 component tests. `SPEC-fulfillment.md` implementation status and the `docs/development.md` download-access section record the behavior; live Stripe is not involved in this slice.
- **Acceptance:** The admin order detail exposes a `downloads` read model per entitlement (line title/SKU, asset label, pinned version number and id, the order line's frozen purchase version, status, `download_limit` / `downloads_used` / remaining, and the grantable strictly-newer `ready` versions of the same asset). `EntitlementController` serves `POST /admin/orders/{orderId}/entitlements/{entitlementId}/update` and `POST /admin/orders/{orderId}/entitlements/{entitlementId}/reset-limit` behind the `fulfillment.manage` gate and fresh staff 2FA, mirroring `ReturnController`. `DownloadAccess.vue` renders the section with per-entitlement update/reset forms (reason required, fresh idempotency key per submit) inside `OrderDetail.vue`, covered by `DownloadAccess.test.ts`. End-to-end coverage compares two buyers of the same asset: the granted buyer streams the new version while the ungranted buyer still streams the original purchase version, whose file and version row remain intact. `SPEC-fulfillment.md` implementation status and the `docs/development.md` download-access section record the behavior; live Stripe remains unexecuted.
- **Likely files:** `app/Domain/CheckoutOrders/Actions/ReadAdminOrders.php`, `app/Http/Controllers/Admin/EntitlementController.php`, `app/Http/Controllers/Admin/OrderController.php`, `routes/web.php`, `resources/js/admin/components/DownloadAccess.vue`, `resources/js/admin/components/DownloadAccess.test.ts`, `resources/js/admin/pages/OrderDetail.vue`, `SPEC-fulfillment.md`, `docs/development.md`.

### T054: Mixed order fulfillment gate

- [x] Complete T054.
- **Evidence October 16:** No split was needed: the slice is verification plus browser-spec authoring and required no production change — the gate already composes. New `tests/Feature/FulfillmentMixedOrderTest.php` walks one customer purchase of two tracked physical units plus one digital line end to end (faked provider capture, paid-event consumption): the entitlement issues `active` and version-pinned with `download_status` `entitled` while `fulfillment_status` stays `unfulfilled` and zero shipments exist; the account downloads area lists the file and a full grant/start/stream round-trip serves its bytes in that state; a partial shipment moves only the physical projection to `partially_fulfilled`. The refund walk refunds one of the two physical units through the staff refund path: the order projects `partially_refunded` / `unfulfilled` / `entitled`, the physical line's `refunded_qty` is 1 and the digital line's is 0, no `entitlements_revoked` or `entitlements_suspended` timeline entries appear, the guide still lists and streams (allowance spent normally), and shipping the remaining unit completes the physical half (`fulfilled`) with the digital half untouched; `ReadCustomerOrders::detail` keeps the three projections separate at every step. Verification on the VPS Docker test stack (isolated `mrktflow_test` database; live demo untouched), all runs serial: focused `FulfillmentMixedOrder` 2 passed (72 assertions); `Fulfillment` group 66 passed (1,160 assertions); full PHP suite 542 passed (5,550 assertions; baseline 540 + 2 new); Pint PASS (461 files); PHPStan level 8 no errors; frontend typecheck, lint and format checks pass, 77 Vue unit tests passed (38 files) and the production build succeeds. The three browser journeys are authored against the real routes and UI text with fixture preconditions recorded in-file (`tests/Browser/digital-purchase.spec.ts`, `mixed-purchase.spec.ts`, `fulfillment.spec.ts`); their Chromium execution remains pending — there is no combined PHP/Node Chromium harness in this environment, as recorded since T044. Live Stripe is not involved (payment and refund facts use the local capture and faked provider paths, as in T049–T053). Hosted CI pending (branch not pushed). This closes M4.
- **Module / requirements:** `fulfillment` · FUL-01, FUL-04, FUL-05, WEB-07.
- **Depends on:** T047, T048, T053.
- **Acceptance:** One mixed purchase unlocks downloads while shipping stays pending; partial physical refund does not revoke unrelated digital access.
- **Verify:** Run `php artisan test --filter=Fulfillment` and `npm run test:e2e -- --project=chromium`; record mixed order and refund walkthrough.
- **Likely files:** `tests/Browser/digital-purchase.spec.ts`, `tests/Browser/mixed-purchase.spec.ts`, `tests/Browser/fulfillment.spec.ts`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

#### Checkpoint after T052, T053, T054

- [x] Acceptance and focused verification recorded; no zero-test pass.
- [x] Relevant build/browser flow passes, including failure, permission and replay cases.
- [x] Changes documented; pending external-provider evidence remains explicit.

## M5: Save, watch and compare

### T091: Shared contact preferences and suppression

- [x] Complete T091.
- **Evidence (October 16):** Implemented as T091a–T091c below on `feat/t091-contact-preferences`. Shared verified contact purpose grants and global/transport suppression now exist ahead of the watchlist/recovery consumers; GET never changes consent; the signed one-click unsubscribe POST is the only cookie-free CSRF exception; every other preference edit stays behind the customer session plus an identity re-proof. Focused suite 10 passed / 95 assertions; affected groups Platform 109, IdentityCustomer 82, Fulfillment 66 and CheckoutOrders 86 passed; full local PHP suite 552 passed / 5,649 assertions (542 baseline + 10 new); Pint PASS (467 files), PHPStan level 8 clean and the frontend battery (typecheck, lint, format, 77 unit tests, build) passed in the pinned Node container. The CSRF exception is verified by wiring (the `preventRequestForgery` exclusion list) and by the suite's cookie-free POSTs — PHPUnit bypasses CSRF middleware, so no live 419 contrast exists in local evidence. No campaign sending, watchlist or recovery consumer was built; live mail/SMS providers are not involved.
- **Split note:** Actual scope exceeds the sizing rule (schema, consent actions, a shared policy, a signed-token surface, a public endpoint pair, an account surface and docs), so T091 is split into T091a–T091c before coding; the parent acceptance is preserved. Design decisions recorded with the split: purposes are the four named in `docs/marketing-rules.md` (`cart_recovery`, `newsletter`, `stock_alert`, `price_alert`); tables follow `docs/data-model.md` (`delivery_contacts`, `purpose_grants`, `delivery_suppressions`); consent transitions are current-state rows carrying full provenance (source, notice revision, confirmation evidence, granted/revoked timestamps) plus one platform audit per real transition, and replays of a transition already in effect are silent no-ops. A global suppression with reason `complaint` or `hard_bounce` is never cleared by a grant; an `opt_out`/`manual` global suppression is cleared only by the contact's own deliberate grant through a protected verified channel (preference center or double opt-in), never by an import/replay source. The one-click token is a purpose-scoped HMAC-signed payload (no server-side token store); applying it only ever revokes, so replays cannot re-enroll. Essential templates are classified in the policy (`order-receipt`, `shipment`, `download-access`, `customer-challenge`) and are never blocked by marketing suppression.
- **Module / requirements:** `platform` · PLAT-07, MKT-02.
- **Depends on:** T009.
- **Acceptance:** Shared verified contact purpose grants and global/transport suppression exist before watchlist/recovery consumers. GET never changes consent; dedicated signed one-click unsubscribe POST is the narrow cookie-free CSRF exception; other preference edits remain protected.
- **Verify:** Run `php artisan test --filter=MarketingPreferences`; test grant/suppression replay, signed unsubscribe and essential-mail distinction. T056/T057 verify watch integration after their tables exist.
- **Likely files:** `database/migrations/create_delivery_preferences.php`, `app/Domain/Platform/Actions/UpdateDeliveryPreference.php`, `app/Http/Controllers/Storefront/PreferencesController.php`, `themes/default/views/preferences.blade.php`, `tests/Feature/MarketingPreferencesTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

#### T091a: Delivery preference schema, consent actions and shared policy

- [x] Complete T091a.
- **Evidence (October 16):** Migration `2026_10_16_000054_create_delivery_preferences.php` creates `delivery_contacts` (case-sensitive unique normalized email, nullable customer link, verification timestamp), `purpose_grants` (one current-state row per contact+purpose carrying source, notice revision `2026-10-v1`, evidence, granted/revoked timestamps and revoking source) and `delivery_suppressions` (global/email/sms scope; `opt_out`/`complaint`/`hard_bounce`/`manual` reason; cleared provenance). `UpdateDeliveryPreference` is the only writer: grants refuse unverified contacts, every real transition writes one platform audit, and replays change nothing twice (proven by audit-count and timestamp-stability assertions). `DeliveryPolicy` is the single consult point — marketing requires a verified contact, an active grant for the purpose and no active global/transport suppression; `essentialAllowed` classifies `order-receipt`, `shipment`, `download-access` and `customer-challenge` as never blockable. `complaint`/`hard_bounce` suppressions are never cleared by a grant; an `opt_out`/`manual` global suppression clears only on a deliberate protected-channel grant. Red run first: 4 failed (missing schema/actions); then green, 4 passed / 40 assertions in the focused filter.
- Migration `000054` (`delivery_contacts`, `purpose_grants`, `delivery_suppressions`), `UpdateDeliveryPreference` (ensure/verify/grant/revoke/suppress/feedback transitions, all idempotent with provenance + platform audit) and `DeliveryPolicy` (the single consult point: purpose grant + verification + global/transport suppression for marketing; essential classification never blocked). Tests: grant requires a verified contact, checkout capture alone is not an opt-in, replay no-ops, transport vs global suppression reach, complaint suppression survives a non-protected grant source.

#### T091b: Signed one-click unsubscribe (the narrow CSRF exception)

- [x] Complete T091b.
- **Evidence (October 16):** `UnsubscribeTokens` issues stateless purpose-scoped HMAC payloads (no token store; the signature binds contact + purpose, so a token cannot be retargeted). `GET /preferences/unsubscribe` only renders the confirmation form (scanner/prefetch safe — grant rows proven unchanged after GETs); `POST /preferences/unsubscribe` is the single entry in the `preventRequestForgery` exception list, needs no session or CSRF token, accepts the token in the body or the URL query (the provider one-click shape, `List-Unsubscribe=One-Click`), revokes only its own purpose, and replays return the same 200 done page with no second change. Tampered payloads, bad signatures and retargeted purposes return 404 with state intact, and a valid-token positive control keeps the fail-closed test from passing vacuously. Two bugs were caught by the tests en route: the HTTP tests initially omitted the installed-store fixture (`RequireInstalledStore` 404s every route pre-install), and the tamper test could have passed vacuously without its positive control. Red run first: 3 failed; then green in the combined focused suite.
- `UnsubscribeTokens` (purpose-scoped HMAC payloads), public GET landing (renders a confirmation form, mutates nothing) and POST (the single CSRF-exempt storefront route, cookie-free, signature-gated, idempotent) in `PreferencesController`, plus the system unsubscribe view. Tests: scanner GET changes nothing, POST without session/CSRF revokes only the token's purpose, replay is a harmless 200, tampered/foreign-purpose tokens fail closed.

#### T091c: Account preference center and documentation

- [x] Complete T091c.
- **Evidence (October 16):** `GET`/`POST /account/preferences` sit inside the customer-session group; the GET renders state read-only (contact, grant and suppression rows proven unchanged across reads, with no lazy creation), and the POST re-proves identity inside the edit transaction (`ProveCustomerIdentity`, the T050 pattern), so a customer manages only the contact bound to their own verified email and an unverified customer is refused with 403. Toggles grant/revoke per purpose with `preference_center` provenance; stop-all revokes every purpose and records a global `opt_out` suppression, and a later deliberate toggle-on in the center clears it (the policy re-allows). End-to-end essential-mail proof: a globally suppressed guest contact still queues a `download_access_intents` row through `VerifyGuestOrder::requestAccess` while `marketingAllowed` stays false. Docs: PLAT-07 status paragraph in `SPEC-platform.md` and the contact-preferences section in `docs/development.md`.
- Protected account surface (`GET`/`POST /account/preferences` behind the customer session, identity re-proved in the update transaction): purpose toggles, all-marketing stop (revokes all grants + global `opt_out` suppression), deliberate re-grant clearing only an `opt_out` suppression, suppression state shown honestly. Theme view `preferences.blade.php`; SPEC-platform status note and `docs/development.md` section. Tests: verified customer manages only their own contact, unverified customer is refused, GETs never mutate, essential mail distinction end-to-end (a globally suppressed guest still receives download-access mail intent).

### T055: Guest and account saved products

- [x] Complete T055.
- **Module / requirements:** `shopping-tools` · SHOP-01, SHOP-02.
- **Depends on:** T009, T024.
- **Acceptance:** Guest saves persist; login unions variant saves without duplicates; unpublished/archived item state is privacy-safe.
- **Verify:** Run `php artisan test --filter=ShoppingToolsWishlist`; test multiple devices and session logout.
- **Likely files:** `database/migrations/create_wishlists.php`, `app/Domain/ShoppingTools/Actions/SaveWishlistItem.php`, `app/Domain/ShoppingTools/Actions/MergeWishlist.php`, `themes/default/views/wishlist.blade.php`, `tests/Feature/ShoppingToolsWishlistTest.php`.
- **Scope:** Split into T055a–T055d before coding (actual scope exceeds the sizing rule).
  - [x] **T055a — Persistence + owner + save/remove actions.** Migration `000055` (`wishlists` one row per owner — unique `customer_id` XOR unique `guest_token_hash` with the carts-style DB check; `wishlist_items` unique per (wishlist, variant), variant FK `restrictOnDelete`), `ShoppingTools\Support\WishlistOwner` (mirrors `CartOwner`: customer via guard + session version, else the session `guest_identity` token hashed SHA-256), `SaveWishlistItem` (save is an idempotent set insert that refreshes the saved timestamp; only a listed variant — published product + listed variant — can be saved; remove is scoped to the owner's own list and fails closed for foreign item ids).
  - [x] **T055b — Read model with privacy-safe state.** `ReadWishlist` returns current price/availability for published products (SHOP-02); a saved item whose product is draft or archived (or whose variant is no longer listed) degrades to a `removed` placeholder carrying only the item id and saved date — no title, SKU, price, image or slug may leak.
  - [x] **T055c — Login merge.** `MergeWishlist` unions guest saves into the account list keyed by variant (duplicates collapse to one row keeping the newest saved timestamp), consumes the guest list, and is a no-op on repeat login; hooked into `CustomerSession::establish` beside `MergeCart`.
  - [x] **T055d — Storefront surface + docs.** `WishlistController` (HTML + store-api JSON, mirroring `CartController`), routes (`/wishlist`, `/wishlist/items`, `/store-api/v1/wishlist*`), `themes/default/views/wishlist.blade.php`, a save form on the product page, and the development.md implementation note. Saving alone creates no delivery contact, purpose grant or subscription (SHOP-02/T056 boundary).
- **Evidence (October 16):** Implemented as T055a–T055d above on `feat/t055-wishlist`. One wishlist per owner (migration `000055`: unique `customer_id` XOR unique `guest_token_hash` behind the carts-style DB owner check; items unique per (wishlist, variant) with an explicit `saved_at`). Guest persistence is concrete, not cross-device: saves are server-side rows keyed by the SHA-256 hash of the session `guest_identity` token, so they survive across requests while the browser session cookie survives and are never enumerable, but a pure guest has no second-device list — cross-device sync is an account property, reached by a guest only through the login merge. The merge unions by variant (a duplicate collapses to the account row keeping the newest `saved_at`), consumes the guest list so a later sign-in on the same browser inherits nothing, and repeats on later logins as a no-op; logout deletes nothing and the next session resolves to a fresh empty guest scope. The read model always shows current price/availability for listed entries; an unpublished, archived or unlisted entry degrades to a placeholder carrying only its id, state and saved date — the whole snapshot is asserted free of the draft's title, SKU, slug and formatted price — and republishing restores the full entry. Saving creates no delivery contact, purpose grant or suppression row (SHOP-02). Red run first: 10 failed (missing ShoppingTools classes); focused suite then 10 passed / 102 assertions; affected groups CheckoutOrders + IdentityCustomer + Catalog 251 passed; full local PHP suite 562 passed / 5,758 assertions (552 baseline + 10 new); Pint PASS (475 files); PHPStan level 8 clean after one typing fix in the digital-ready lookup (generic `object` rows are now read via `get_object_vars`), re-verified by the focused suite; the frontend battery (typecheck, lint, format check, 77 unit tests, build) passed in the pinned Node container. Multi-device and logout behavior is proven at the action layer (fresh request/session scopes) and end-to-end over HTTP in the focused suite; no browser-harness run exists for this surface. One pre-coding correction is recorded honestly: two test assertions initially matched raw cent amounts as substrings, which can appear inside UUIDs, and were rewritten to assert formatted money before the first run. No watch subscriptions, alert evaluation or recovery sends were built (T056+). Hosted CI evidence remains pending, as for every local branch.

### T056: Verified watch subscriptions

- [x] Complete T056.
- **Evidence (October 16):** Implemented as T056a–T056b below on `feat/t056-watch-subscriptions`. Stock/price alerts now require a separate per-type opt-in and a verified email: consent is written only through `UpdateDeliveryPreference` (`stock` → `stock_alert`, `price` → `price_alert`), an unverified subscribe creates pending rows and grants nothing, the digest-only verification challenge (24-hour, single-use, re-issue revokes) is the double opt-in that verifies, grants, activates and snapshots price baselines, and the shared signed unsubscribe acts without any login and makes a watch non-alertable through `DeliveryPolicy`. Saving a wishlist item alone creates no contact, grant, subscription or intent. Focused suite 15 passed / 153 assertions; affected groups ShoppingTools 25 passed / 255 assertions and MarketingPreferences 10 passed / 95 assertions; full PHP suite 577 passed / 5,905 assertions (562 baseline + 15 new); Pint PASS (481 files), PHPStan level 8 clean, and the frontend battery (typecheck, lint, format, 82 unit tests — 77 baseline + 5 new — and build) passed in the pinned Node container; a final-tree rebuild re-ran ThemesContent (11 passed) and ShoppingTools (25 passed) after the last entry-point changes. Red run first: 15 failed on the tests-only snapshot. Bugs caught during verification: the new HTTP tests initially double-installed the store fixture (`catalogInstalledOwner` also runs inside `cartCatalog`), the subscribe validator rejected padded emails the consent layer normalizes (normalization now precedes validation), and the new theme files needed the codebase's indexed-access assertion idiom, a separate `watch.ts` Vite entry (one component per file) and Prettier formatting. No event detection or sending was built (T057); no live mail provider is involved — verification is a queued intent only, the raw token is returned to the caller layer and never rendered into an HTTP response. Chromium journeys were not run (no harness in this environment), as with T091/T055. Hosted Quality for this branch is pending.
- **Module / requirements:** `shopping-tools` · SHOP-03.
- **Depends on:** T055, T091.
- **Acceptance:** Stock/price alerts require separate opt-in and verified email; signed unsubscribe acts without account login; saves alone send nothing.
- **Verify:** Run `php artisan test --filter=ShoppingToolsSubscription`; replay verification and unsubscribe tokens.
- **Likely files:** `database/migrations/create_watch_subscriptions.php`, `app/Domain/ShoppingTools/Actions/SubscribeToWatch.php`, `app/Http/Controllers/Storefront/WatchController.php`, `themes/default/src/WatchOptions.vue`, `tests/Feature/ShoppingToolsSubscriptionTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.
- **Split (2026-10-30, before coding):** Actual scope exceeds the sizing rule (migration, subscribe/verify/cancel domain actions, a privacy-safe read model, storefront controller + routes, a scanner-safe verification confirm flow, a theme Vue component and feature tests), so T056 is split into T056a–T056b below; the parent acceptance is preserved and is checked only when both subtasks carry evidence. Design decisions recorded with the split: alert types map one-to-one to T091 purposes (`stock` → `stock_alert`, `price` → `price_alert`) and consent is written ONLY through `UpdateDeliveryPreference` (the sole writer) — a watch subscription row is the per-variant expression of an opt-in, never a parallel consent store. Verification reuses the platform's digest-only challenge idiom (`VerifyGuestOrder`/`CustomerChallengeStore` shape: SHA-256 digest only, email digest binding, 24-hour expiry, single-use consume that nulls the digest and revokes siblings, queued mail intent, no SMTP in this slice), keyed to the delivery contact rather than a customer account so guests can verify. Subscribing while unverified creates `pending` subscriptions and grants nothing; the emailed confirmation is a double opt-in — confirming marks the contact verified and grants the pending purposes with source `double_opt_in` (a protected channel in T091's terms). An already-verified contact subscribing in-session grants with source `watch_options` (deliberately unprotected, so it cannot clear an `opt_out`/`manual` suppression — suppression keeps winning at `DeliveryPolicy`, the single consult point). A signed-in customer whose account email is verified has the contact marked verified with `customer_account` evidence, mirroring the preference center. A price subscription snapshots the variant's purchasable price (amount/currency/exponent) as its baseline when it becomes active (SHOP-03 compares against the subscription baseline in T057); a stock subscription carries no baseline. The signed unsubscribe is T091's purpose-scoped token pair, not a new surface: revoking a purpose leaves subscription rows in place but makes them non-alertable, and `DeliveryPolicy` is re-consulted at read (and, in T057, immediately before send per SHOP-04). Event detection and sending are T057, out of scope.

#### T056a: Watch subscription schema, consent-writing subscribe action and verification challenges

- [x] Complete T056a.
- **Evidence (October 16):** Migration `000056` creates `watch_subscriptions` (unique (contact, variant, type); status `pending`/`active`/`cancelled`; all-or-null baseline triple), `watch_verifications` (digest-only challenges bound to the contact's email digest) and `watch_verification_intents` (one queued `watch-verification` intent per issue). `SubscribeToWatch` shares the wishlist's listed-variant privacy rule and generic failure, upserts per (contact, variant, type), activates immediately only for verified contacts (in-session grants carry the deliberately unprotected `watch_options` provenance; a verified-account customer marks the contact verified with `customer_account` evidence) and cancels per-variant without touching the purpose grant. `VerifyWatchEmail` issues/consumes in the `VerifyGuestOrder` idiom — consume locks the contact then the challenge, marks the contact verified through `UpdateDeliveryPreference`, grants the pending purposes as `double_opt_in`, activates the rows and snapshots price baselines in the same transaction; replayed, tampered, revoked and expired tokens all fail closed with the same 404 and change no fact (grant timestamps and audit counts proven stable across a replay). `ReadWatchSubscriptions` keeps lifecycle and `DeliveryPolicy`-computed alertability separate and degrades unpublished entries to the wishlist's placeholder without leaking title, SKU, slug, price or baseline. Focused domain evidence sits inside the combined 15 passed / 153 assertions: separate per-type opt-ins, suppression surviving a double opt-in, saves creating nothing.
- Migration `000056` (`watch_subscriptions`, `watch_verifications`, `watch_verification_intents`), `SubscribeToWatch` (subscribe/cancel; listed-variant privacy check shared with the wishlist; pending vs active by verification state; consent only via `UpdateDeliveryPreference`), `VerifyWatchEmail` (issue/consume in the digest-only idiom; consume marks verified, grants pending purposes as `double_opt_in`, activates rows and snapshots price baselines atomically; replays fail closed and change nothing) and `ReadWatchSubscriptions` (per-contact read model with `DeliveryPolicy`-computed alertability and the wishlist's privacy degradation for unpublished items). Tests: separate per-type opt-ins, unverified subscribe grants nothing and queues one verification intent, verify replay is a no-op, tampered/expired tokens fail closed, suppression wins over an active subscription, saves alone create no contact/grant/subscription/intent.

#### T056b: Storefront watch surface (controller, scanner-safe verify flow, theme component)

- [x] Complete T056b.
- **Evidence (October 16):** `WatchController` serves `POST /watch/subscriptions` plus the `/store-api/v1/watch` mirrors (subscribe, owner-only cancel, the signed-in customer's list); the verify pair sits in the installed-store group beside the unsubscribe pair — the GET renders only the confirmation form (after GETs the subscription is proven still `pending` and the challenge unconsumed), the POST consumes exactly once and its replay 404s, as do unknown tokens. A guest subscribing over HTTP receives only the status and read model (no token key, no 64-hex string in the response) with one queued intent and zero grants; a foreign signed-in customer cancelling another contact's subscription gets a 404 and the row stays active; the shared signed unsubscribe POST, sent with no login at all, revokes the watch purpose and the read model reports the subscription non-alertable while its row stays `active`. The product page gains a native "Set alerts" form beside the save form, progressively enhanced by `WatchOptions.vue` from its own `watch.ts` Vite entry (`watchData.ts` parser rejects malformed datasets and leaves the native form in place); 5 new Vue unit tests sit inside the 82 passed.
- `WatchController` + routes (`POST /watch/subscriptions`, `POST /watch/subscriptions/{id}/cancel` for the signed-in owner, `GET /watch/verify` rendering only a confirmation form, `POST /watch/verify` consuming — the GET never consumes, mirroring the download-access scanner rule; `/store-api/v1/watch` mirrors), the system watch-verification view, `WatchOptions.vue` on the product page (progressive enhancement over a native form, per-variant alert toggles + email) with its data parser and unit tests. Tests: scanner GET changes nothing, confirm POST verifies once and replaying it 404s, signed unsubscribe POST without any login revokes the purpose and makes the subscription non-alertable, a foreign customer cannot cancel another contact's subscription.

#### Checkpoint after T091, T055, T056

- [x] Acceptance and focused verification recorded; no zero-test pass. T091 (10 preference tests), T055 (10 wishlist tests) and T056 (15 subscription tests) each have failing-first tests and local evidence; the full PHP suite is 577 tests / 5,905 assertions and the frontend battery passes (82 unit tests); hosted Quality for these branches is pending.
- [x] Relevant build/browser flow passes, including failure, permission and replay cases. Server-side flows cover grant/suppression replays, scanner-safe GETs, signed unsubscribe without login, verification token replay/tamper/expiry, guest-scope isolation, login merge unions, foreign-owner denials and suppression winning over live grants; Chromium journeys were not run in this environment (no combined PHP/Node browser harness on the VPS test stack), consistent with the M4 checkpoints.
- [x] Changes documented; pending external-provider evidence remains explicit. T091 is documented in `SPEC-platform.md`, T055/T056 in `SPEC-shopping-tools.md`, and all three in `docs/development.md`; no live mail/SMS provider evidence is involved (sends are queued intents at this layer) and live Stripe evidence remains pending on credentials, as recorded in the earlier blocks.

### T057: Stock and price event notifications

- [x] Complete T057.
- **Evidence (October 16):** Implemented as T057a–T057b below on `feat/t057-watch-alerts`. Eligible catalog changes trigger deduplicated, 24-hour-capped watch alert intents, and delivery rechecks subscription, consent policy, listing and condition against current state immediately before sending; temporary reservation holds/commits/releases never alert. Focused suite 15 passed / 77 assertions; affected groups Catalog 83, Platform 109, CheckoutOrders 86, Fulfillment 66, IdentityCustomer 82 and ShoppingTools 40 passed; full local PHP suite 592 passed / 5,957 assertions (577 baseline + 15 new) on the final tree; Pint PASS (487 files), PHPStan level 8 clean and the frontend battery (typecheck, lint, format, 82 unit tests, build) passed in the pinned Node container. Delivery is verified at the intent layer against the array transport with real rendered messages — no live mail provider is involved. Bugs caught during verification: the first test draft asserted on the whole queue, which the platform's own outbox publications also use, so assertions were scoped to `SendWatchAlert`; the delivery test's first draft used `Mail::fake`, whose send returns null in this Laravel version and is indistinguishable from a refused send, so the suite follows the identity mail tests and inspects the array transport instead; the mail template's body key `message` collided with the `$message` variable Laravel injects into mail views (an `htmlspecialchars` TypeError at render) and was renamed to `bodyText`; and PHPStan rejected property access through `object`-typed helper parameters, so the condition checks read row values via `get_object_vars`, the `QueueShipmentNotification` idiom. No Chromium/browser run (no harness in this environment), consistent with the M4/M5 checkpoints; hosted CI evidence remains outstanding, as with all prior slices.
- **Module / requirements:** `shopping-tools` · SHOP-04, OPS-01.
- **Depends on:** T056, T006, T019.
- **Acceptance:** Eligible catalog changes trigger deduplicated/capped alerts; unsubscribe and current state rechecked; temporary reservation releases do not alert.
- **Verify:** Run `php artisan test --filter=ShoppingToolsAlert`; duplicate events and unsubscribe-before-send produce correct delivery intents.
- **Likely files:** `app/Jobs/SendWatchAlert.php`, `app/Domain/ShoppingTools/Actions/EvaluateWatchEvent.php`, `resources/views/mail/watch-alert.blade.php`, `tests/Feature/ShoppingToolsAlertTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.
- **Split note:** Actual scope exceeds the sizing rule (intent schema, an evaluation action, hooks in three merchant write paths, a delivery action, a queued job, a mailable + template and docs), so T057 is split into T057a–T057b before coding; the parent acceptance is preserved. Design decisions recorded with the split: the cap is exactly SHOP-04's — one alert of each type per variant/recipient/24 hours — enforced per subscription (a subscription is unique per contact+variant+type) as "no intent for the subscription in `queued`/`sending`/`sent` within the last 24 hours"; `cancelled`/`failed` intents never consume the cap, so a suppression-cancelled or transport-failed alert does not silence a later legitimate one. Dedupe is per subscription+catalog event: the intent key is `watch-alert:{subscriptionId}:{eventKey}`, where the event key names the merchant operation (`adjust:{operation_key}`, `variants:{productId}:{version}`, `product:{productId}:{version}`), and the unique constraint makes replaying one operation a no-op. Stock evaluation is only ever invoked on an availability-entering merchant change (SHOP-04: receipt/restock/publication): `AdjustStock` computes the committed-position transition (available = on_hand − reserved moving from ≤ 0 to > 0 while tracking stays on), `SaveProduct` invokes it when a save takes the product into `published`, and `SaveVariants` when a variant's listed flag flips on. The checkout reservation lifecycle (`AllocateInventory` hold/commit/release/ship) gets no hook at all, so a transient reservation release can never trigger an evaluation; eligibility always reads the committed position, never the transient one. `AllocateInventory::restock` is the one exception in that class: it is the deliberate staff act (T048) that returns units to `on_hand`, so it is hooked with the same committed-position transition rule, keyed `restock:{operation_key}`. Price evaluation compares the variant's current purchasable price with the subscription's activation baseline and fires only on a strict drop with identical currency and exponent — a rise, a currency change or an exponent change is never a drop. Every send rechecks immediately before the mail leaves: subscription still `active`, `DeliveryPolicy` still allows the purpose for the contact, the variant still listed, and the triggering condition still true against current state; any failed recheck cancels the intent with a visible reason instead of sending. Delivery is a durable intent (`watch_alert_intents`, the T043/T047 idiom) plus a queued job; the mail renders from current catalog state at send time, never from event-time snapshots.

#### T057a: Alert intent schema, evaluation and merchant-change triggers

- [x] Complete T057a.
- **Evidence (October 16):** Migration `2026_10_16_000057_create_watch_alert_intents.php` creates `watch_alert_intents` in the T043/T047 idiom (subscription/contact/variant links, template `watch-alert`, kind `event`, recipient snapshot, unique intent key, catalog event key, status `queued|sending|sent|cancelled|failed`, error code, sent timestamp). `EvaluateWatchEvent` creates an intent only for an `active` subscription on a still-listed variant whose contact passes `DeliveryPolicy` and whose condition holds against current state; it dedupes on the unique intent key `watch-alert:{subscriptionId}:{eventKey}`, enforces the SHOP-04 cap per subscription over `queued`/`sending`/`sent` intents inside 24 hours (`cancelled`/`failed` never consume it), records one platform audit per intent and dispatches `SendWatchAlert` after commit. Hooks: `AdjustStock` fires stock only when the committed position (on_hand − reserved) moves from none to some while tracking stays on (an untracked variant was already sellable, so enabling tracking with stock is not a transition); `AllocateInventory::restock` fires under the same transition rule while hold/commit/release/ship stay unhooked — proven silent by test, including a merchant restock under a full reservation alerting from the committed position; `SaveVariants` fires price on every save and stock only on a listed-flag flip; `SaveProduct` fires both on saves landing in `published`, stock only when the save enters publication. The price condition is a strict drop against the subscription's activation baseline with identical currency and exponent — a rise, a currency change or an exponent change creates nothing. Red run first: the whole class failed pre-implementation; the focused suite then went 10/15 → 14/15 → 15/15 as the verification bugs in the parent evidence were fixed.
- Migration `000057` (`watch_alert_intents`: subscription/contact/variant links, template `watch-alert`, kind `event`, recipient snapshot, unique intent key, catalog event key, status `queued|sending|sent|cancelled|failed`, error code, sent timestamp) and `EvaluateWatchEvent` (per-variant stock/price evaluation against current state: listed check, `DeliveryPolicy` consult, condition check, SHOP-04 24-hour cap, per-event dedupe, one platform audit per created intent, `SendWatchAlert` dispatched after commit). Trigger hooks: `AdjustStock` (restock transition only), `SaveVariants` (price on every save; stock only on a listed-flag flip), `SaveProduct` (both on saves landing in `published`; stock only when the save enters publication), `AllocateInventory::restock` (transition only; hold/commit/release/ship stay unhooked). Tests: restock creates one intent per eligible subscription, replayed operation dedupes, reservation hold/release creates nothing, ineligible subscriptions (pending/cancelled/unconsented/suppressed/unlisted) create nothing.

#### T057b: Send-time recheck, delivery job and mail

- [x] Complete T057b.
- **Evidence (October 16):** `DeliverWatchAlert` claims a queued intent under row locks and rechecks, in order, subscription still `active`, `DeliveryPolicy` still allowing the purpose for the contact, variant still listed, and the triggering condition still true against current state; any failed recheck finishes the intent `cancelled` with the reason as its error code (`subscription_inactive`, `delivery_not_allowed`, `no_longer_listed`, `condition_no_longer_met`) and sends nothing — each case proven by a dedicated test that changes the state between event and send (unsubscribe, provider suppression, unpublish, price/stock reversal, subscription cancel). The claim/finish discipline mirrors `DeliverCustomerChallengeMail` (claim in a transaction, network send outside it, finish in a transaction; running inside a transaction throws). The mail renders from state read in the claim — a further price drop between event and send is the price the recipient sees — and `WatchAlertMessage` + `resources/views/mail/watch-alert.blade.php` carry the current title/price, the product link and the signed purpose-scoped unsubscribe link (token round-trip asserted against `UnsubscribeTokens`). A successful send finishes `sent` with `sent_at`; a transport failure finishes `failed`/`transport_failed` and an unready store mail configuration finishes `failed`/`mail_configuration_unavailable`, both visible per OPS-01. `SendWatchAlert` is the thin queued job (`tries = 1`, encrypted) mirroring `SendCustomerChallengeMail`. Docs: SHOP-04 status line in `SPEC-shopping-tools.md` and the watch-alert paragraph in `docs/development.md`.
- `DeliverWatchAlert` (claim the queued intent, recheck subscription/policy/listing/condition against current state, render from current state, send, finish `sent`; a failed recheck finishes `cancelled` with its reason; transport failure finishes `failed` visibly), thin `SendWatchAlert` job (`tries = 1`, mirroring `SendCustomerChallengeMail`), `WatchAlertMessage` mailable and `resources/views/mail/watch-alert.blade.php` (current title/price, product link, signed purpose unsubscribe link). SPEC-shopping-tools status line and `docs/development.md` watch-alert paragraph. Tests: happy-path send marks the intent sent with mail rendered from current state; unsubscribe-before-send, suppression-before-send, unpublish-before-send, condition-reversal-before-send and subscription-cancel-before-send each cancel with no mail; the 24-hour cap blocks a second alert and lifts after 24 hours; a price rise and a currency/exponent change never alert.

### T058: Typed comparison read model

- [x] Complete T058.
- **Evidence (October 16):** Implemented as T058a–T058b below on `feat/t058-comparison`. The typed comparison read model resolves variant or product selections (a product resolves to its default listed variant, duplicates collapse to one column), removes invalid/unpublished/unlisted selections with a generic fact-free notice, compares the platform basics plus the union of comparable semantic attributes across compatible comparison groups with explicit "Not specified" absences, normalizes numeric values through a typed unit registry before deciding `differs`, and degrades to basics only with a stated reason across incompatible groups. Focused suite 9 passed / 130 assertions (red run first: all 9 failed pre-implementation); affected groups Catalog 83, Platform 109, CheckoutOrders 86, Fulfillment 66, IdentityCustomer 82 and ShoppingTools 49 passed; full local PHP suite 601 passed / 6,162 assertions (592 baseline + 9 new) on the final tree; Pint PASS (492 files), PHPStan level 8 clean and the frontend battery (typecheck, lint, format, 82 unit tests, build) passed in the pinned Node container. Bugs caught during verification: PHPStan rejected property access through the `object`-typed row parameter in the column seed and the typed-value reader, so both read rows via `get_object_vars` with per-field type narrowing (the `QueueShipmentNotification` idiom), and it rejected the defensive `is_array` checks under a too-precise input docblock, so the action's input contract is honestly typed `list<mixed>`; Pint required the routes import in alphabetical order and one docblock alignment space in the unit registry. No Chromium/browser run (no harness in this environment), consistent with the M4/M5 checkpoints; the comparison UI itself is T059. Hosted CI evidence remains outstanding, as with all prior slices.
- **Module / requirements:** `shopping-tools` · SHOP-05, SHOP-06.
- **Depends on:** T017, T024.
- **Acceptance:** Two-to-four selections normalize units and compare shared semantic attributes; incompatible groups expose only common basics.
- **Verify:** Run `php artisan test --filter=ShoppingToolsCompare`; test missing attributes, variant overrides and mixed schemas.
- **Likely files:** `app/Domain/ShoppingTools/Actions/CompareProducts.php`, `app/Domain/ShoppingTools/Data/ComparisonView.php`, `app/Http/Controllers/Storefront/ComparisonController.php`, `tests/Feature/ShoppingToolsCompareTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.
- **Split note:** Actual scope exceeds the sizing rule (a typed unit-conversion registry, the comparison action, the view DTO, a storefront controller with routes, a minimal theme view and docs), so T058 is split into T058a–T058b before coding; the parent acceptance is preserved. Design decisions recorded with the split: a selection names a variant, or a product resolved to its default listed variant exactly as SHOP-01 saves resolve; the same variant selected twice is one column. Semantic identity is the T017 global `attribute_definitions.semantic_key`: one key has exactly one type, unit and comparison meaning platform-wide (`ProductSchemaWriter` refuses to redefine a key with a different type or unit, "a different type or comparison meaning"), and schema versions only select and label definitions, so a shared row is a `comparable` definition present in the union of the selections' schema versions — never a name match across different keys. A value a product never stored, or an attribute its schema does not define, is an explicit absence ("Not specified"), never a zero or an empty string. Numeric comparison normalizes before comparing (SHOP-05): each numeric cell is converted through the unit registry to its dimension's canonical base unit (mass g, volume mL, length mm) and `differs` is decided on the normalized values; units outside the registry keep their own unit as the normal form, so an unconvertible value degrades honestly instead of comparing wrong. Compatibility is the schema versions' `comparison_group`: selections spanning more than one group (a schema-less product counts as its own null group) expose only the platform basics every product has — price, availability, SKU, type — with `specialized_attributes_omitted` and a plain reason (SHOP-06). Invalid, unpublished or unlisted selections are removed with a generic notice that carries no catalog facts; if removals leave fewer than two columns the comparison is refused with the same generic wording, so the surface cannot probe unpublished content. Variant selections use the selected variant's own price, SKU, availability and option label; typed attributes are product-level in the T017 model (`attribute_values` has no variant scope), so there is no variant attribute override to apply — the override surface is the variant's own basics.

#### T058a: Unit registry, comparison action and view model

- [x] Complete T058a.
- **Evidence (October 16):** `UnitConversions` is the typed registry — mass (base g; mg/kg/oz/lb factors), volume (base mL; L) and length (base mm; cm/m/in/ft) — converting decimal strings arithmetically and returning null for unknown units or cross-dimension requests, so an unconvertible value keeps its own unit as its normal form. `CompareProducts` resolves each selection to a listed variant of a published product (a product names its default listed variant, the wishlist resolution), dedupes by variant, collects generic removal notices, and refuses when fewer than two columns remain. Columns carry the selected variant's own money (via `CartMoney`), SKU, option label (`VariantOptions`), type and `VariantAvailability` state; rows are the basics (price, availability, SKU, type) plus, when every selection shares one `comparison_group`, the union of `comparable` definitions across the selections' schema versions ordered by semantic key, labelled from the first schema that defines the attribute. Cells hold the typed value read from the matching `attribute_values` slot; a missing value or an undefined attribute is `specified: false` with display "Not specified". Numeric cells also carry `normalized_value`/`normalized_unit` from the registry (a kg-defined mass normalizes to grams), and `differs` is decided on normalized values, so 24.20 MP equals 24.2 MP and 1.50 kg equals 1.5 kg. Across groups the view is basics-only with `specialized_attributes_omitted` and the omission reason, and the encoded view provably contains no unpublished title, SKU, slug or attribute value. `ComparisonView` validates the snapshot in the `ProductView` idiom and rejects rows whose cells do not align with the columns. Tests: registry conversions and refusals, shared attributes with normalization and absences, two-schema union in one group, incompatible groups, variant basics over the product default, unlisted-variant removal, one/five/zero-item and malformed rejections, four-item acceptance, draft/unknown removal without leaks.
- `UnitConversions` registry (mass/volume/length dimensions with canonical base units and exact typed factors; unknown or cross-dimension conversions return null), `CompareProducts` (selection resolution with generic removal notices, basics rows, compatible-group attribute union with explicit absences, normalized `differs`), `ComparisonView` DTO (validated snapshot in the `ProductView` idiom). Tests: two-to-four bounds, dedupe, unit normalization across systems, missing attributes and missing values as "Not specified", variant basics overriding the product default, shared attributes across two schemas in one group, non-comparable attributes excluded, incompatible groups exposing basics only, unpublished/invalid selections removed without leaking facts.

#### T058b: Comparison endpoint, minimal view and docs

- [x] Complete T058b.
- **Evidence (October 16):** `ComparisonController` serves `GET /compare` (HTML) and `GET /store-api/v1/compare` (JSON) from `items`/`products` query lists behind the installation-complete guard; validation failures return the wishlist-style 422 `VALIDATION` envelope for JSON and render the page with the generic message for HTML, and every response is `no-store, private`. The minimal `compare.blade.php` renders removal notices, the omission reason when specialized attributes are omitted, column titles with variant labels, and one table row per read-model row with differing rows marked — the tray, remove/replace controls and accessible table are T059. SPEC-shopping-tools records SHOP-05/SHOP-06 as implemented by T058 for the read model and endpoint; docs/development.md gains the comparison paragraph. Tests: the JSON surface carries selections, rows, normalized values and removal notices; the HTML surface shows titles, labels and "Not specified" and omits specialized fields for a mixed-group comparison; a one-item request fails 422 with the generic envelope; a draft selection is removed with its notice and none of its facts appear in the response body.
- `ComparisonController` (`GET /compare`, `GET /store-api/v1/compare`, selections from `items`/`products` query lists, wishlist-style validation envelope), minimal `compare.blade.php` rendering columns, rows, absences and removal notices (full tray/table UI is T059), SPEC-shopping-tools status line and docs/development.md paragraph. Tests: JSON and HTML surfaces carry the read model, invalid counts fail with the generic envelope, removal notices render without catalog facts.

### T059: Comparison and save UI

- [x] Complete T059.
- **Evidence (October 16):** Implemented as T059a–T059b below on `feat/t059-compare-ui`. Product/card controls manage a compare tray that persists in a first-party session cookie (the cookie-scoped guest-session scope that keys the guest cart and wishlist), and the comparison table supports remove/replace/differences-only on T058's normalized read model with stacked, column-labelled rows at mobile widths; every state change is announced through `role="status"` regions. Verification: 41 new Vue unit tests (compareData 24, ProductActions 5, CompareTray 5, ComparisonTable 7) and one new ShoppingToolsCompare PHP test; the frontend battery (typecheck, lint, format, 123 unit tests — 82 baseline + 41 new — and build) passed in the pinned Node container, and the full PHP suite passed 602 / 6,116 assertions (601 baseline + 1 new) with the ShoppingTools group at 50 passed / 476 assertions, Pint PASS (492 files) and PHPStan level 8 clean on the VPS test stack. Red runs first: the T059a test files failed unimplemented, the T059b parse/table suites failed unimplemented, and the PHP candidates test failed on the missing cart revision before the controller change. Bugs caught during verification: the first remove-column test read candidate option text inside header cells, so the assertion now reads the column title links; the new tests needed explicitly typed fetcher mocks under `noUncheckedIndexedAccess`; the theme's one-component-per-entry rule split the compare entry into `compareTray.ts` / `compareActions.ts` / `comparisonTable.ts`; and separate bundles meant separate store instances, so cookie-backed stores now live-sync through a window event with the cookie as source of truth, covered by a dedicated store test. The Chromium journey (`tests/Browser/comparison.spec.ts`, four products at 360px, keyboard only) is authored with its fixture preconditions in-file but was not executed — no combined PHP/Node browser harness exists in this environment, consistent with the M4/M5 checkpoints. Hosted Quality for this branch is pending.
- **Module / requirements:** `shopping-tools` · SHOP-05, SHOP-06, WEB-06.
- **Depends on:** T055, T058.
- **Acceptance:** Product/card controls manage a compare tray; comparison supports remove/replace/differences with accessible mobile rows.
- **Verify:** Run `npm run test:unit -- --run` and `npm run build`; keyboard-test four products at 360px.
- **Likely files:** `themes/default/src/ProductActions.vue`, `themes/default/src/CompareTray.vue`, `themes/default/src/ComparisonTable.vue`, `themes/default/views/compare.blade.php`, `tests/Browser/comparison.spec.ts`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.
- **Split (2026-10-30, before coding):** Actual scope exceeds the sizing rule (a shared tray store with persistence, three Vue components, a new Vite entry wired into five theme surfaces, a controller-supplied replace-candidate list, the accessible table rework and a Chromium spec), so T059 is split into T059a–T059b below; the parent acceptance is preserved and is checked only when both subtasks carry evidence. Design decisions recorded with the split: the comparison identity is the variant, exactly as T058 resolves it — a product/card control names the product and the read model resolves its default listed variant, a product-page control names the selected variant (its own variant select, defaulted to the server-selected variant, mirroring `WatchOptions`); a tray holds at most one entry per product, so adding a variant replaces a product entry for the same product and vice versa, and adding a fifth product is refused with an announced message — the oldest entry is never dropped silently. The tray persists in a first-party session cookie holding only the selections (product id, optional variant id, title, variant label): the same cookie-scoped durable scope as the anonymous guest session that keys the guest cart and wishlist, so no second store and no account state is invented, and the cookie is the source of truth every page load re-reads. Each compare widget mounts from its own Vite entry (the theme's one-component-per-entry idiom); the widget store instances share the cookie as the source of truth, and live cookie-backed stores stay in step through a window change event, so no widget waits for a page load to reflect another's change. The comparison table consumes T058's read model as served: the differences toggle filters on the model's own `differs` flag (decided server-side on normalized values, never re-derived from display strings), remove/replace re-fetch `GET /store-api/v1/compare` with the remaining variant ids and fall back to a full navigation to `/compare` when the fetch fails, and a removal that leaves one column renders the add-another prompt instead of fetching a comparison the model refuses. Replace candidates and the cart revision are supplied by `ComparisonController` for the HTML surface only (published products resolved to their default listed variant, the `CatalogController` cart-snapshot idiom), so each column keeps a usable add-to-cart form (SHOP-06). At the theme's 760px breakpoint the table's rows become stacked blocks whose cells carry their column title as a visible label — no horizontal scrolling and no truncated values at 360px. State changes are announced through `role="status"` regions, the theme's established idiom.

#### T059a: Compare tray store, product/card controls and tray component

- [x] Complete T059a.
- **Evidence (October 16):** `compareData.ts` tray state (strict session-cookie payload validation, add/remove with the one-entry-per-product and four-product cap rules, tray/fetch URL builders, live cross-bundle sync) with 19 failing-first unit tests, plus `ProductActions` (5 tests: card toggle, server-selected variant, choose-a-variant gate, card-added state on the product page, fifth-product refusal) and `CompareTray` (5 tests: empty state, one-more gate, compare link URL, remove with announcement, variant labels). Outlets: product page (variant-aware, listed variants only), category/search/home cards, and the masthead tray outlet; one Vite entry per component (`compareTray.ts`, `compareActions.ts`) registered in `vite.config.ts`; tray/control styles in `theme.css`.
- `compareData.ts` tray state (parse/serialize the session-cookie payload, add/remove with the one-entry-per-product and four-product cap rules, tray and fetch URL builders) with failing-first unit tests; `ProductActions.vue` (product-page variant-aware toggle and product-card toggle), `CompareTray.vue` (entries, per-entry remove, count, compare link gated at two entries, status announcements), the shared `compare.ts` entry registered in `vite.config.ts`, outlets on the product, category, search and home surfaces plus the masthead tray outlet, and the tray/card-control styles in `theme.css`.

#### T059b: Comparison table UI, replace candidates and browser spec

- [x] Complete T059b.
- **Evidence (October 16):** `parseComparison` whole-payload validation (rows must align with columns) and `visibleComparisonRows` trusting the model's normalized `differs` flag (5 tests, including the "1.5 kg" vs "1500 g" equal-displays case), plus `ComparisonTable` (7 tests: rendering/absences/add-to-cart gating, differences toggle, remove with tray sync, remove-to-one prompt without a fetch, replace, fetch-failure navigation fallback, `data-column` cell labels). `ComparisonController` supplies replace candidates (published products resolved to their default listed variant; the draft never appears, one candidate per product) and the cart revision to the HTML surface only — focused ShoppingToolsCompare 10 passed / 144 assertions. `compare.blade.php` mounts the table over the server-rendered fallback table, which carries the same stacked-row CSS; SPEC-shopping-tools and docs/development.md updated. The Chromium journey is authored, execution pending the absent harness.
- `parseComparison` validation and the differences row filter in `compareData.ts` with failing-first unit tests; `ComparisonTable.vue` (remove/replace/differences with in-place re-fetch, focus handling and announcements, per-column add-to-cart, stacked mobile rows); `ComparisonController` supplies replace candidates and the cart revision to the HTML surface with PHP coverage in `ShoppingToolsCompareTest`; `compare.blade.php` mounts the table over the server-rendered fallback table; the Chromium journey in `tests/Browser/comparison.spec.ts` (four products at 360px, keyboard only — authored, execution pending the absent harness); SPEC-shopping-tools and docs/development.md updated.

#### Checkpoint after T057, T058, T059

- [x] Acceptance and focused verification recorded; no zero-test pass. T057 (15 alert tests), T058 (9 compare tests) and T059 (41 new Vue unit tests plus 1 PHP candidates test) each have failing-first tests and local evidence; the full PHP suite is 602 tests / 6,116 assertions and the frontend battery passes (123 unit tests, typecheck, lint, format and build).
- [x] Relevant build/browser flow passes, including failure, permission and replay cases. Server and unit flows cover alert dedupe/cap/recheck, normalized comparison with absence and removal honesty, the tray cap refusal and one-entry-per-product replacement, malformed cookie/payload rejection, remove-to-one, fetch-failure navigation fallback and unpublished selections never reaching the candidate list; Chromium journeys were not run in this environment (no combined PHP/Node browser harness on the VPS test stack), consistent with the M4 checkpoints — the comparison journey is authored in `tests/Browser/comparison.spec.ts` with its fixture preconditions recorded.
- [x] Changes documented; pending external-provider evidence remains explicit. T057–T059 are documented in `SPEC-shopping-tools.md` and `docs/development.md`; no external provider is involved in this block (alert sends remain queued intents) and live Stripe evidence remains pending on credentials, as recorded in the earlier blocks.

### T060: Shopping tools integration gate

- [x] Complete T060.
- **Module / requirements:** `shopping-tools` · SHOP-01, SHOP-02, SHOP-03, SHOP-04, SHOP-05, SHOP-06.
- **Depends on:** T057, T059.
- **Acceptance:** Save→login merge→compare→cart and stock-alert→product journeys work; no customer state leaks between sessions.
- **Verify:** Run `php artisan test --filter=ShoppingTools` and `npm run test:e2e -- --project=chromium`.
- **Likely files:** `tests/Browser/wishlist.spec.ts`, `tests/Browser/comparison.spec.ts`, `tests/Feature/ShoppingToolsIsolationTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.
- **Evidence (October 16):** Gate slice on `feat/t060-integration-gate`; no split was needed and no production change was required — the M5 slices compose as built and no composition bug was found. New `tests/Feature/ShoppingToolsGateTest.php` walks both acceptance journeys end to end over the real controllers and merchant write paths: (1) a guest saves two camera variants over HTTP, the compare read model agrees with the saved selections, login unions the guest list with the account's existing save by variant with no duplicate, the signed-in comparison page supplies the cart revision, both compared variants reach the cart (the table's HTML form add plus a JSON add) with the subtotal matching the compared prices, and the saves are not consumed; (2) a guest subscribes to a stock alert unverified (a restock in that state creates no intent and pushes no job), the scanner-safe verify GET changes nothing, the confirm POST activates the watch, a committed restock then fires exactly one intent (replaying the merchant operation dedupes), delivery renders the current price, and the mailed product link lands on the product in its current listed state. New `tests/Feature/ShoppingToolsIsolationTest.php` interleaves two customers and a guest across the wishlist and watch surfaces — foreign saves invisible, a foreign watch cancel 404s, logout exposes no account state, a second browser's guest token resolves to its own empty scope and owner hints in request input are ignored — and unpublishes a product mid-journey to show every surface degrading consistently from the same event: wishlist placeholder without variant id, title, SKU or price; product page 404; compare refusal through the generic validation envelope; the queued alert cancelled at send with `no_longer_listed`; and republication restoring the save in full. The wishlist Chromium journey is authored in `tests/Browser/wishlist.spec.ts` with fixture preconditions in-file; `tests/Browser/comparison.spec.ts` was reviewed and its preconditions already align, so it is unchanged. Verification on the VPS Docker test stack (isolated `mrktflow_test` database; live demo untouched), all runs serial: focused ShoppingToolsGate 2 passed / 62 assertions and ShoppingToolsIsolation 2 passed / 57 assertions; `--filter=ShoppingTools` 54 passed / 595 assertions; full PHP suite 606 passed / 6,244 assertions (602 baseline + 4 new); Pint PASS (494 files); PHPStan level 8 no errors; the frontend battery (typecheck, lint, format, 123 unit tests and build) passed in the pinned Node container. The gate journeys passed on their first focused run against the composed system — that pass is the gate evidence. The two defects verification caught were both in the new test code, recorded rather than hidden: the isolation test first looked the privacy placeholder up by the variant id the placeholder deliberately omits, and the save journey compared sorted UUID lists against unsorted creation order, a coin flip that passed focused and failed both full-suite runs until both sides were sorted; the final full-suite run is green on the fixed tree. Chromium journeys were not executed — no combined PHP/Node browser harness exists in this environment, consistent with the M4/M5 checkpoints. No live mail provider is involved (delivery verified at the intent layer against the array transport, as in T057). Hosted CI pending (branch not pushed). This closes M5.

#### Checkpoint after T060

- [x] Acceptance and focused verification recorded; no zero-test pass. T060 adds 4 end-to-end gate tests (ShoppingToolsGate 2 passed / 62 assertions, ShoppingToolsIsolation 2 passed / 57 assertions) on top of the slices' own suites; the ShoppingTools filter is 54 passed / 595 assertions and the full PHP suite is 606 tests / 6,244 assertions.
- [x] Relevant build/browser flow passes, including failure, permission and replay cases. Server flows cover the two acceptance journeys in sequence, interleaved-session isolation with foreign-owner denials, logout exposure, replayed merchant operations deduping to one alert intent, mid-journey unpublish degrading all four surfaces consistently, and scanner-safe verification inside the alert journey; Chromium journeys were not run in this environment (no combined PHP/Node browser harness on the VPS test stack), consistent with the M4 checkpoints — the wishlist journey is authored in `tests/Browser/wishlist.spec.ts` and the comparison journey in `tests/Browser/comparison.spec.ts`, each with fixture preconditions recorded.
- [x] Changes documented; pending external-provider evidence remains explicit. T060 is documented in `SPEC-shopping-tools.md` and `docs/development.md`; no external provider is involved in this gate (alert sends remain queued intents verified against the array transport) and hosted CI evidence remains outstanding, as with all prior slices.

## M6: Themes, page composition and template imports

### T061: Theme registry and compatibility checks

- [x] Complete T061.
- **Implementation slices:** T061a adds the platform version source (`PlatformVersion`: core `1.0.0`, theme API `1`) with bounded semver-range evaluation used by both compatibility checks. T061b adds the installed-theme registry (migration `000058`, `ThemeRegistry` contract, `ThemeRegistration` read model, `RegisterTheme` action): manifest structure, confined-path, missing-file, duplicate-ID and version validation recorded as an explicit `valid`/`invalid` status with reasons before any activation exists; the default theme's manifest gains the documented `requires.core` range so it exercises both checks. Neither slice adds activation (T068).
- **Evidence:** Focused `ThemesContentRegistry`: 25 passed / 96 assertions (red first: all 25 failed pre-implementation), covering range evaluation, the default theme registering `valid` with its manifest checksum, idempotent re-registration, duplicate-ID refusal from a second directory, traversal/absolute/backslash/cross-theme/remote path datasets, a symlink escaping the theme directory, missing template/entry/style files named in the recorded reasons, incompatible themeApi/core/unparsable/missing requirements, unknown fields and bad schema/theme versions, structurally unusable manifests throwing before any row is recorded, and a fixed manifest revalidating back to `valid`. Group filters: ThemesContent 36 passed / 129 assertions, Platform 109 passed / 463 assertions. Full PHP suite: 631 passed / 6,335 assertions (606 baseline + 25 new). Pint PASS (500 files), PHPStan level 8 no errors, frontend typecheck/lint/format/unit (123 passed)/build green. Two verification bugs were in the new code, not the platform: PHPStan regex-offset narrowing in `PlatformVersion` (fixed by relying on isset semantics, no suppressions) and a test-harness `$GLOBALS` cleanup bug in the registry test's afterEach. The checkpoint after T061/T062/T063 stays open: it also covers T062 and T063, which are not built yet. Hosted CI evidence remains outstanding, as with all prior slices; no browser flow is involved in this slice.
- **Module / requirements:** `themes-content` · THEME-01.
- **Depends on:** T010, T024.
- **Acceptance:** Installed manifests validate API/core versions and confined paths; missing assets/incompatible templates fail before activation.
- **Verify:** Run `php artisan test --filter=ThemesContentRegistry`; test traversal, missing asset and incompatible manifest.
- **Likely files:** `app/Domain/ThemesContent/Actions/RegisterTheme.php`, `app/Domain/ThemesContent/Contracts/ThemeRegistry.php`, `database/migrations/create_theme_tables.php`, `tests/Feature/ThemesContentRegistryTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T062: Default storefront layout and system slots

- [x] Complete T062.
- **Module / requirements:** `storefront` · WEB-01, WEB-02, WEB-03, THEME-01.
- **Depends on:** T054, T060, T061.
- **Acceptance:** Default theme covers public/account/order routes and core checkout slots; public HTML is meaningful without JS; themes cannot replace commerce actions.
- **Verify:** Run `npm run build` and `php artisan test --filter=StorefrontTheme`; browser-check all route types.
- **Likely files:** `themes/default/views/layout.blade.php`, `themes/default/views/home.blade.php`, `themes/default/src/theme.css`, `app/Domain/Storefront/Actions/ComposePage.php`, `tests/Feature/StorefrontThemeTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.
- **Evidence:** Focused `StorefrontTheme`: 12 passed / 254 assertions (red first: all 12 failed pre-implementation), covering the slot contract for all 15 page keys (template existence included), platform reservation of the six commerce slots, assignment refusal at unit level and at render time through the layout composer, every public/account/order route type rendering through the one layout with `data-layout`/`data-page` and chrome slot markers, no-JS server HTML (product title + server-owned price + working add-to-cart form in the raw response), cart line actions from the platform component, the checkout summary/actions slots inside the real quote flow (accepting swaps the same actions slot to the pay form), platform rendering despite hostile slot files planted at both plausible override paths in the theme directory, system pages staying system-owned, and the paid-order → downloads → grant journey with the issue/start slots. Group filters: Storefront 25 passed, ThemesContent 36 passed, CheckoutOrders 86 passed, ShoppingTools 54 passed, Fulfillment 66 passed. Full PHP suite: 643 passed / 6,741 assertions (631 baseline + 12 new). Pint PASS (505 files), PHPStan level 8 no errors, frontend typecheck/lint/format/unit (123 passed)/build green. The checkpoint after T061/T062/T063 stays open: it also covers T063, which is not built yet. Hosted CI evidence remains outstanding, as with all prior slices; the Chromium route-type spec (`tests/Browser/storefront-layout.spec.ts`, incl. a JavaScript-disabled purchase path) is authored with fixture preconditions but not executed — no harness exists in this environment.

#### T062a: Demo storefront shell (homepage + shared chrome) — done 2026-10-27

- [x] Complete T062a (a shell slice of T062 for the public demo deployment; T062 itself stays open — no layout/slot system was built).
- **What shipped:** `HomeController` serves a real homepage at `/` once installation completes (pre-install the root keeps the 503 closed page); shared masthead/footer partials wrap the nine existing theme views; `ThemeServiceProvider` view composer supplies store name + published categories; Casa Norte demo layer in `theme.css` with self-hosted General Sans (the app CSP blocks CDN styles/fonts); `DemoTiendaSeeder` seeds the fictional demo catalog.
- **Deployment fixes proven by the demo:** GD in the deployment image needs `--with-jpeg --with-webp` or JPEG media processing fails (suite fixtures only use PNG); `SecurityHeaders` must run after `TrustProxies` or every proxied production request 400s (ordering regression test in `StorefrontHomepageTest`); the `identity-mail` queue lane must run or customer challenge mail never leaves.
- **Evidence:** full PHP suite 475 passed (4,459 assertions) incl. 5 new homepage tests; Pint and PHPStan clean; frontend type/lint/format/unit/build green; live at https://tienda.tamayo.co — guest cart → quote (coupon NORTE10, MX shipping, IVA-inclusive tax) → checkout session verified to the cent; payment start stops with "Hosted payment is not configured" until Stripe test keys are provided.

#### T062b: Layout and slot contract foundation + public pages

- [x] Complete T062b.
- **What it builds:** `app/Domain/Storefront` opens with the slot contract (`Support/SystemSlots`), the composition gate (`Actions/ComposePage` + `Data/ComposedPage` + `Exceptions/SlotCompositionRefused`) and `themes/default/views/layout.blade.php` — one layout rendering every themed page with platform-positioned chrome and named slots (`head`, `announcement`, `pre-main`, `content` theme-filled; `masthead`/`footer` platform-positioned). Public pages migrate onto it (home, category, search, product, compare, wishlist); the product purchase panel (add-to-cart / save / watch forms) is extracted to the platform component `storefront.slots.product-purchase`, outside the theme namespace so a theme cannot shadow it. The layout view composer in `ThemeServiceProvider` runs `ComposePage` for the page's key on every render: slot assignments supplied as view data naming a platform slot or an unknown slot are refused, per the template-contracts rule that core owns commerce actions and themes supply visual slots around them (developer theme code itself remains trusted, reviewed application code — the gate governs slot assignments and component resolution, not theme PHP).
- **Verify:** `php artisan test --filter=StorefrontTheme` (public coverage, contract + refusal, hostile theme-side slot file, no-JS server HTML).

#### T062c: Commerce, account and order pages on the layout

- [x] Complete T062c.
- **What it builds:** cart, checkout, payment status, order status, account orders/order/downloads/preferences and the download grant page migrate onto the T062b layout; the remaining commerce actions are extracted to platform components (`storefront.slots.cart-line-actions`, `checkout-summary`, `checkout-actions`, `download-issue`, `download-start`) rendered at the contract positions with `data-platform-slot` markers; a Chromium spec covers all route types with fixture preconditions (execution pending the absent local harness, as with prior slices).
- **Verify:** `php artisan test --filter=StorefrontTheme` (checkout slot rendering inside the real quote flow, cart/account/order coverage) plus the full suite as the M2/M3 regression net.

### T063: Typed section registry

- [x] Complete T063.
- **Evidence:** Focused `ThemesContentSection`: 21 passed / 94 assertions (red first: all 21 failed pre-implementation), covering the registry declaring exactly the twelve built-in blocks with typed schemas, a full document validating every built-in sample (defaults applied, bindings resolved to public summaries), unknown block/field/version rejects, constraint violations naming the section, field and problem, draft and nonexistent binding references refused with one indistinguishable message, duplicate ids/references and per-page-limit overruns, rendering only validated data (escaped) through the sections view, invalid payloads never rendering, composition into theme slots with platform/unknown slots refused through `ComposePage`, and the default theme manifest declaring the registered types and re-registering `valid`. Group filters: ThemesContent 57 passed / 223 assertions, Storefront 25 passed / 356 assertions, Catalog 84 passed / 1,142 assertions. Full PHP suite: 664 passed / 6,718 assertions (643 baseline + 21 new) on the final tree. Pint PASS (511 files), PHPStan level 8 no errors, frontend typecheck/lint/format/unit (123 passed)/build green. The checkpoint after T061/T062/T063 is checked below: acceptance and focused verification are recorded for all three slices and the build flows pass including failure and replay cases (invalid manifests recorded, slot assignments refused, section payloads rejected, registration idempotent); the browser-flow evidence remains T062's authored-but-unexecuted Chromium specs — no harness exists in this environment — and section rendering is proven at server-render level, recorded here rather than claimed as executed. Hosted CI evidence remains outstanding, as with all prior slices. Disk note: the VPS root disk hit 100% three times during this slice's builds; each time `docker builder prune -f` (cache only, ~1.3–1.6 GB) restored headroom and no live container or volume was touched.
- **Module / requirements:** `themes-content` · THEME-02.
- **Depends on:** T061.
- **Acceptance:** Built-in blocks define settings/bindings/limits and render only validated data; unknown blocks/fields reject with useful errors.
- **Verify:** Run `php artisan test --filter=ThemesContentSection`; test all built-in schema samples.
- **Likely files:** `app/Domain/ThemesContent/Actions/ValidateSections.php`, `themes/default/sections/definitions.json`, `themes/default/views/sections.blade.php`, `tests/Contract/ThemesContentSectionTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.
- **Split (2026-10-30, sizing rule):** T063a — definitions + registry + validator; T063b — rendering + slot composition. Parent acceptance preserved across both.

#### T063a: Section definitions, registry and validator

- [x] Complete T063a.
- **Evidence:** The registry, definitions and validator halves of the focused evidence above: all twelve definitions load strictly (unknown definition/field keys fail loudly), every built-in sample validates, and the rejection datasets (unknown block, unknown settings/instance field, wrong version, range/type/length/markup violations, unsafe paths, missing required settings, wrong binding kind, draft/nonexistent references, duplicates, repeat limits) each fail with errors naming the section, field and problem. Bindings resolve only through `CatalogReader` public lookups — for a category binding by public ID the validator consults `staffCategory` solely to gate on the published status and takes all rendered data from the public snapshot, so a draft and a nonexistent category produce the same refusal and no draft data reaches output.
- **What it builds:** `themes/default/sections/definitions.json` declares the twelve built-in blocks from the template contracts (hero, rich-text, image-text, gallery, category-grid, product-grid, featured-product, specifications, comparison-link, faq, call-to-action, article-list), each with typed settings fields (text/integer/boolean/enum/path/object/list with length, range, option and item constraints, required flags and defaults), a binding spec (allowed kinds — product, products, category, categories — required flag and item cap) and limits (max per page). `SectionRegistry` parses the definitions file strictly (unknown definition/field keys and malformed constraints fail loudly). `ValidateSections` validates a section-instance list against the registry: exact instance keys, bounded distinct ids, version match, per-type repeat limits and the 100-section document cap, unknown block types and unknown settings fields rejected, every constraint checked with errors naming the section, field and problem (all errors collected, thrown as `SectionValidationFailed`). Bindings resolve only through the platform catalog read model (`CatalogReader` public lookups): a draft or nonexistent product/category reference fails with one message that does not distinguish the two, and validated output carries the resolved public summaries (never raw models). No persistence: section payloads are validated data only until the editor (T064) owns documents.

#### T063b: Section rendering and slot composition

- [x] Complete T063b.
- **Evidence:** The rendering and composition half of the focused evidence above: a hero + category-bound product grid render through `default-theme::sections` with validated, escaped data (heading entities encoded, product title and formatted price from the resolved binding), an invalid payload throws before any render, sections compose into the `announcement` theme slot with `ComposePage` recording the sections view, and assignment to `checkout.actions` (platform) or an unknown slot throws `SlotCompositionRefused`. `themes/default/theme.json` now declares the twelve section types and re-registers `valid` (asserted in the focused suite). `article-list` renders its heading with an explicit empty state until article records land in T065.
- **What it builds:** `RenderSections` validates a section list through `ValidateSections`, then composes it into a page through T062's `ComposePage` by assigning the sections view (`default-theme::sections`) to a named theme slot — an assignment naming a platform slot or an unknown slot fails composition exactly like any other assignment (`SlotCompositionRefused`). `themes/default/views/sections.blade.php` renders each validated section from its validated settings and resolved binding data only (escaped output, semantic landmarks per block); `themes/default/theme.json` declares the twelve section types so the T061 registry records them. `article-list` renders its heading with an empty state — article records arrive in T065; no other block invents data it cannot bind.

#### Checkpoint after T061, T062, T063

- [x] Acceptance and focused verification recorded; no zero-test pass.
- [x] Relevant build/browser flow passes, including failure, permission and replay cases.
- [x] Changes documented; pending external-provider evidence remains explicit.

### T064: Section editor and preview controls

- [x] Complete T064.
- **Module / requirements:** `themes-content` · THEME-02, WEB-06.
- **Depends on:** T063.
- **Acceptance:** Editors add/reorder/configure allowed blocks and tokens with keyboard controls and mobile/desktop draft preview.
- **Verify:** Run `npm run test:unit -- --run` and `npm run build`; keyboard-test reorder and validation recovery.
- **Likely files:** `resources/js/admin/pages/ContentEditor.vue`, `resources/js/admin/components/SectionList.vue`, `resources/js/admin/components/SectionSettings.vue`, `resources/js/admin/components/DevicePreview.vue`, `tests/Browser/content-editor.spec.ts`.
- **Scope:** Split into T064a/T064b before coding (2026-10-30): the slice spans draft persistence, JSON endpoints and a four-component Vue editor — more than the ~5-file sizing rule. The parent acceptance is preserved across both subtasks. (Branch naming follows the chain's `feat/` convention, not `codex/`.)

#### T064a: Draft persistence, save/preview endpoints and structured validation errors

- **Builds:** Migration `000059` creates `content_drafts` — one working draft per (page, slot): uuid id, `page_key`, `slot`, the validated section payload as JSON, an optimistic-lock `version`, `updated_by`, timestamps; unique (page_key, slot). Editable targets are the platform's pages (`SystemSlots::pages()`) and their theme slots that can hold sections (`announcement`, `content`) — platform slots and unknown pages/slots are refused before validation. `SaveSectionDraft` runs the payload through T063's `ValidateSections` first: an invalid payload persists nothing; a valid save stores the normalized payload (registry defaults applied, binding `resolved` summaries stripped — references only), increments `version`, refuses a stale expected version with a conflict carrying the current version, and writes one platform audit. `ContentEditorController` serves the Inertia editor page (`ContentEditor`) with the serialized registry definitions and the initial draft, a JSON draft read, a JSON save (`content.write` gate; read/preview under `content.read`) and a JSON preview that renders the submitted draft through T063's `RenderSections` (validation failures return the same structured errors; preview persists nothing). A save rejected by `SectionValidationFailed` returns errors grouped per section (keyed by section id, or document position when the id itself is invalid) plus a document-level list, so the editor can recover without losing work.
- **Verify:** `php artisan test --filter=ThemesContentEditor` — authorization (guest/customer denied, no-content staff 403, content_editor saves), valid save + normalization + version increments, stale-version conflict, invalid payload persists nothing and returns grouped errors, unknown page/platform slot refused, preview renders real section markup and persists nothing, draft isolation per (page, slot).
- **Evidence (2026-10-30):** 8 tests / 91 assertions pass (red first: all 8 failed with 404s before the endpoints existed). ThemesContent group 65 passed; full PHP suite 672 passed / 6,849 assertions on the slice tree (the two post-suite PHPStan decode-shape fixes were re-verified by the focused suite, Pint and PHPStan). Pint PASS (519 files), PHPStan level 8 no errors.

#### T064b: Editor UI — add/reorder/configure with keyboard controls and device preview

- **Builds:** `resources/js/admin/pages/ContentEditor.vue` orchestrates the editor (page/slot picker, working copy, dirty state, save with validation recovery — a 422 keeps the working copy intact, shows grouped errors on the offending sections and in a summary, and a corrected re-save succeeds; preview refresh renders the server fragment). `SectionList.vue` lists sections with native move up/down/remove/configure controls: fully keyboard-operable, focus follows the moved section, removal moves focus to a neighbour (or the add control when the list empties), and every change is announced in a `role="status"` region. Adding a block offers only registry types, disabling types at their per-page repeat limit; new sections get a unique bounded id and defaults derived from the field definitions. `SectionSettings.vue` generates the settings form from the registry's typed field definitions (text/integer/boolean/enum/path/object/list, recursively) plus the binding editor (kind from the definition's allowed kinds, one `publicId` or a `publicIds` list editor) — no per-block hardcoded forms. `DevicePreview.vue` shows the server-rendered fragment in an iframe with desktop/mobile width controls. Pure list/default/error helpers live in `resources/js/admin/section-editor.ts`. The Chromium spec `tests/Browser/content-editor.spec.ts` is authored with fixture preconditions in-file (execution remains pending — no harness in this environment, the established pattern).
- **Verify:** `npm run test:unit -- --run` (SectionList reorder + focus + announcements, SectionSettings generation from definitions incl. list/object/binding editors, DevicePreview width toggle, ContentEditor add/save-failure-recovery flow with mocked fetch) and `npm run build`.
- **Evidence (2026-10-30):** 23 new unit tests pass (6 helper, 7 SectionList — reorder by button and Alt + arrows, focus follows the moved section, removal focus, limit disabling; 4 SectionSettings — generated text/enum/object/list controls and binding editor; 3 DevicePreview; 3 ContentEditor — add → 422 keeps the working copy and lists the problem on the section → fix → save succeeds, preview renders into the frame, read-only hides saving). Full frontend suite 48 files / 146 tests pass; typecheck 0 errors, lint and Prettier clean, production build OK. `tests/Browser/content-editor.spec.ts` is authored with fixture preconditions in-file and has NOT been executed (no Chromium harness in this environment). Two real bugs were caught by the tests during the slice: an untouched optional object (hero CTA) was initially sent to the server to fail validation — the payload builder now omits deep-empty optional objects/lists — and `structuredClone` refused the reactive Inertia props, replaced with a JSON deep clone (payloads are JSON-safe by construction).

### T065: Pages, articles and navigation

- [ ] Complete T065.
- **Module / requirements:** `themes-content` · THEME-02, THEME-05.
- **Depends on:** T064.
- **Acceptance:** Create page/article/menu records with validated paths/links; no collision with reserved routes or category tree.
- **Verify:** Run `php artisan test --filter=ThemesContentPage`; test path collision, invalid menu URL and empty article list.
- **Likely files:** `database/migrations/create_content_tables.php`, `app/Domain/ThemesContent/Actions/SaveContent.php`, `app/Http/Controllers/Admin/ContentController.php`, `resources/js/admin/pages/Navigation.vue`, `tests/Feature/ThemesContentPageTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T066: Product and category visual assignments

- [ ] Complete T066.
- **Module / requirements:** `themes-content` · THEME-02, CAT-04.
- **Depends on:** T065, T018.
- **Acceptance:** Assign visual templates independent of product schemas; category landing pages bind live products without copying prices.
- **Verify:** Run `php artisan test --filter=ThemesContentAssignment`; switch layout without altering product attributes or order totals.
- **Likely files:** `app/Domain/ThemesContent/Actions/AssignLayout.php`, `resources/js/admin/components/TemplatePicker.vue`, `app/Domain/Storefront/Actions/ComposePage.php`, `tests/Feature/ThemesContentAssignmentTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

#### Checkpoint after T064, T065, T066

- [ ] Acceptance and focused verification recorded; no zero-test pass.
- [ ] Relevant build/browser flow passes, including failure, permission and replay cases.
- [ ] Changes documented; pending external-provider evidence remains explicit.

### T067: Revisions and explicit publication

- [ ] Complete T067.
- **Module / requirements:** `themes-content` · THEME-03.
- **Depends on:** T065.
- **Acceptance:** Saves preserve drafts; publish atomically switches validated revision with optimistic locking; preview is authorized/noindex/no-store.
- **Verify:** Run `php artisan test --filter=ThemesContentPublish`; concurrent editors preserve both changes and live content remains stable.
- **Likely files:** `database/migrations/create_content_revisions.php`, `app/Domain/ThemesContent/Actions/PublishContent.php`, `app/Http/Controllers/Admin/PreviewController.php`, `resources/js/admin/components/PublishPanel.vue`, `tests/Feature/ThemesContentPublishTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T068: Content rollback and theme activation

- [ ] Complete T068.
- **Module / requirements:** `themes-content` · THEME-03, THEME-06.
- **Depends on:** T067, T066.
- **Acceptance:** Rollback creates a new publication revision; activation validates route coverage and missing-section mapping; failure retains active theme.
- **Verify:** Run `php artisan test --filter=ThemesContentActivation`; activate incompatible theme and restore prior published revision.
- **Likely files:** `app/Domain/ThemesContent/Actions/ActivateTheme.php`, `app/Domain/ThemesContent/Actions/RestoreRevision.php`, `resources/js/admin/pages/Themes.vue`, `tests/Feature/ThemesContentActivationTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T069: Safe merchant template import

- [ ] Complete T069.
- **Module / requirements:** `themes-content` · THEME-04.
- **Depends on:** T063, T067.
- **Acceptance:** Stage JSON/media package, enforce archive/size/MIME/type rules, map references and import only into drafts; PHP/Vue/script payloads rejected.
- **Verify:** Run `php artisan test --filter=ThemesContentImport`; malicious zip, unknown blocks and repeated import leave live content unchanged.
- **Likely files:** `app/Domain/ThemesContent/Actions/ValidateImport.php`, `app/Domain/ThemesContent/Actions/ApplyImport.php`, `resources/js/admin/pages/TemplateImport.vue`, `tests/Feature/ThemesContentImportTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

#### Checkpoint after T067, T068, T069

- [ ] Acceptance and focused verification recorded; no zero-test pass.
- [ ] Relevant build/browser flow passes, including failure, permission and replay cases.
- [ ] Changes documented; pending external-provider evidence remains explicit.

### T070: SEO, redirects and public cache rules

- [ ] Complete T070.
- **Module / requirements:** `storefront` · WEB-04, WEB-05, WEB-07, THEME-05.
- **Depends on:** T068, T062.
- **Acceptance:** Metadata/sitemap/structured data reflect live content; slug redirects avoid loops; private and preview pages never enter shared cache.
- **Verify:** Run `php artisan test --filter=StorefrontSeo`; inspect product HTML, sitemap and two-user cached responses.
- **Likely files:** `app/Domain/Storefront/Actions/BuildMetadata.php`, `app/Http/Controllers/Storefront/SitemapController.php`, `app/Domain/ThemesContent/Actions/SaveRedirect.php`, `themes/default/views/metadata.blade.php`, `tests/Feature/StorefrontSeoTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T071: Developer theme tooling and guide

- [ ] Complete T071.
- **Module / requirements:** `themes-content` · THEME-01, THEME-04.
- **Depends on:** T068, T069.
- **Acceptance:** Document/package a reviewed custom Blade/CSS/Vue template; tooling validates/builds it without editing commerce core; trust level explicit.
- **Verify:** Run `npm run build` and `php artisan test --filter=ThemesContentDeveloper`; install fixture theme in a clean dev store.
- **Likely files:** `app/Console/Commands/ValidateTheme.php`, `themes/example/theme.json`, `themes/example/views/product.blade.php`, `docs/theme-development.md`, `tests/Feature/ThemesContentDeveloperTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T072: Content and theme end-to-end gate

- [ ] Complete T072.
- **Module / requirements:** `themes-content` · THEME-01, THEME-02, THEME-03, THEME-04, THEME-05, THEME-06.
- **Depends on:** T070, T071.
- **Acceptance:** Import→edit→preview→publish→rollback and developer-theme→custom-product/category journeys pass with physical/digital checkout intact.
- **Verify:** Run `php artisan test --filter=ThemesContent` and `npm run test:e2e -- --project=chromium`.
- **Likely files:** `tests/Browser/content-publish.spec.ts`, `tests/Browser/theme-activation.spec.ts`, `docs/template-contracts.md`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

#### Checkpoint after T070, T071, T072

- [ ] Acceptance and focused verification recorded; no zero-test pass.
- [ ] Relevant build/browser flow passes, including failure, permission and replay cases.
- [ ] Changes documented; pending external-provider evidence remains explicit.

## M7: Built-in AI drafting and review

### T073: AI configuration and concurrent budget holds

- [ ] Complete T073.
- **Module / requirements:** `ai-authoring` · AI-01, AI-03.
- **Depends on:** T012, T013, T072.
- **Acceptance:** Owner-only secret configuration and author permissions work; concurrent requests cannot exceed configured reserved budget; disabled mode remains usable.
- **Verify:** Run `php artisan test --filter=AiAuthoringBudget`; race two requests at remaining budget limit.
- **Likely files:** `database/migrations/create_ai_tables.php`, `app/Domain/AiAuthoring/Actions/ReserveUsage.php`, `resources/js/admin/pages/AiSettings.vue`, `tests/Feature/AiAuthoringBudgetTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T074: Queued bounded generation adapter

- [ ] Complete T074.
- **Module / requirements:** `ai-authoring` · AI-02, AI-03, AI-06.
- **Depends on:** T073.
- **Acceptance:** One provider receives only selected public context and schema; queued run records usage/status; timeout/cancel has bounded retry behavior.
- **Verify:** Run `php artisan test --filter=AiAuthoringRun`; inspect request fixtures for customer/secrets exclusion.
- **Likely files:** `app/Domain/AiAuthoring/Adapters/SelectedProvider.php`, `app/Jobs/GenerateAuthoringDraft.php`, `app/Domain/AiAuthoring/Actions/BuildPublicContext.php`, `tests/Contract/AiAuthoringRunTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T075: AI output validation and repair limits

- [ ] Complete T075.
- **Module / requirements:** `ai-authoring` · AI-04.
- **Depends on:** T074, T069.
- **Acceptance:** Reuse import validators; unknown IDs/code/malformed output rejected; at most two repairs consume same budget reservation.
- **Verify:** Run `php artisan test --filter=AiAuthoringValidation`; inject malicious instructions and code into catalog context/output.
- **Likely files:** `app/Domain/AiAuthoring/Actions/ValidateCandidate.php`, `app/Domain/AiAuthoring/Actions/RepairCandidate.php`, `tests/Feature/AiAuthoringValidationTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

#### Checkpoint after T073, T074, T075

- [ ] Acceptance and focused verification recorded; no zero-test pass.
- [ ] Relevant build/browser flow passes, including failure, permission and replay cases.
- [ ] Changes documented; pending external-provider evidence remains explicit.

### T076: Draft diff, preview and apply

- [ ] Complete T076.
- **Module / requirements:** `ai-authoring` · AI-05.
- **Depends on:** T075, T067.
- **Acceptance:** Show editable changes and usage; apply targets a draft at matching revision; publish remains a separate permission/action.
- **Verify:** Run `php artisan test --filter=AiAuthoringApply` and `npm run build`; test stale revision and editor without publish permission.
- **Likely files:** `app/Domain/AiAuthoring/Actions/ApplyDraft.php`, `resources/js/admin/pages/AiStudio.vue`, `resources/js/admin/components/DraftDiff.vue`, `tests/Feature/AiAuthoringApplyTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T077: Product copy and schema suggestions

- [ ] Complete T077.
- **Module / requirements:** `ai-authoring` · AI-02, CAT-04.
- **Depends on:** T076, T018.
- **Acceptance:** AI can propose typed product schema/copy; merchant reviews semantic-key conflicts and migrations; price/stock remain authoritative core fields.
- **Verify:** Run `php artisan test --filter=AiAuthoringProduct`; apply a camera schema suggestion with an existing-key conflict.
- **Likely files:** `app/Domain/AiAuthoring/Actions/DraftProductContent.php`, `app/Domain/AiAuthoring/Actions/SuggestProductSchema.php`, `resources/js/admin/components/ProductAiPanel.vue`, `tests/Feature/AiAuthoringProductTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T078: AI authoring and outage integration gate

- [ ] Complete T078.
- **Module / requirements:** `ai-authoring` · AI-01, AI-02, AI-03, AI-04, AI-05, AI-06.
- **Depends on:** T077.
- **Acceptance:** Page generation→review→publish works; cancelled/failed/duplicate runs preserve drafts and budget bounds; manual import works with AI off.
- **Verify:** Run `php artisan test --filter=AiAuthoring` and `npm run test:e2e -- --project=chromium`; capped provider smoke if configured.
- **Likely files:** `tests/Browser/ai-authoring.spec.ts`, `tests/Feature/AiAuthoringRecoveryTest.php`, `docs/ai-authoring-guide.md`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

#### Checkpoint after T076, T077, T078

- [ ] Acceptance and focused verification recorded; no zero-test pass.
- [ ] Relevant build/browser flow passes, including failure, permission and replay cases.
- [ ] Changes documented; pending external-provider evidence remains explicit.

## M9: Native campaigns, recovery and conversion tools

### T092: Campaign configuration and audience preview

- [ ] Complete T092.
- **Module / requirements:** `marketing` · MKT-01, MKT-10.
- **Depends on:** T091, T064.
- **Acceptance:** Merchant drafts versioned campaigns, filters verified opt-in audiences and previews schedules/caps; activate/send permissions are distinct.
- **Verify:** Run `php artisan test --filter=MarketingCampaign`; compare draft/editor/sender access and paused schedule behavior.
- **Likely files:** `database/migrations/create_campaign_tables.php`, `app/Domain/Marketing/Actions/PreviewAudience.php`, `app/Http/Controllers/Admin/CampaignController.php`, `resources/js/admin/pages/CampaignEdit.vue`, `tests/Feature/MarketingCampaignTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T093: Coupon batches and stable recipient grants

- [ ] Complete T093.
- **Module / requirements:** `pricing` · PRICE-06, MKT-05.
- **Depends on:** T028, T092.
- **Acceptance:** Reusable codes, bounded batches and recipient grants issue through stable source intents; code collision/replay never duplicates benefits.
- **Verify:** Run `php artisan test --filter=PricingCouponGrant`; repeat batch/import intent and deny wrong-recipient redemption.
- **Likely files:** `database/migrations/create_promotion_grants.php`, `app/Domain/Pricing/Actions/IssuePromotionGrant.php`, `app/Jobs/GenerateCouponBatch.php`, `resources/js/admin/pages/CouponCampaign.vue`, `tests/Feature/PricingCouponGrantTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T094: Automatic offers and deterministic stacking

- [ ] Complete T094.
- **Module / requirements:** `pricing` · PRICE-07.
- **Depends on:** T027, T031, T093.
- **Acceptance:** One entered code plus at most one automatic offer obey best-total/tie-break and mutual-stacking rules; show applied/rejected reasons.
- **Verify:** Run `php artisan test --filter=PricingPromotionStack`; assert odd-cent inclusive/exclusive tax totals and sale-price treatment.
- **Likely files:** `app/Domain/Pricing/Actions/SelectPromotions.php`, `app/Domain/Pricing/Rules/AllocateDiscount.php`, `themes/default/src/CheckoutForm.vue`, `tests/Unit/PricingPromotionStackTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

#### Checkpoint after T092, T093, T094

- [ ] Acceptance and focused verification recorded; no zero-test pass.
- [ ] Relevant build/browser flow passes, including failure, permission and replay cases.
- [ ] Changes documented; pending external-provider evidence remains explicit.

### T095: Promotion budgets and first-customer claims

- [ ] Complete T095.
- **Module / requirements:** `pricing` · PRICE-08, PRICE-06.
- **Depends on:** T034, T041, T094.
- **Acceptance:** Budget/recipient/first-order eligibility is atomically held with checkout; refunds and campaign pause preserve frozen allocations and provider outcomes.
- **Verify:** Run `php artisan test --filter=PricingPromotionBudget` on MySQL; race last budget and simultaneous first orders.
- **Likely files:** `app/Domain/Pricing/Actions/HoldPromotionBudget.php`, `app/Domain/CheckoutOrders/Actions/CreateCheckoutIntent.php`, `app/Domain/CheckoutOrders/Actions/RequestRefund.php`, `tests/Feature/PricingPromotionBudgetTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T096: Abandonment episodes and cancellation

- [ ] Complete T096.
- **Module / requirements:** `marketing` · MKT-03.
- **Depends on:** T037, T038, T091.
- **Acceptance:** Meaningful shopper changes create versioned episodes; paid/resumed/suppressed/in-flight payment states cancel or defer eligibility; bots do not refresh inactivity.
- **Verify:** Run `php artisan test --filter=MarketingAbandonment`; cover unknown payment, resumed activity and multiple carts per contact.
- **Likely files:** `database/migrations/create_recovery_episodes.php`, `app/Domain/Marketing/Actions/ObserveCartActivity.php`, `app/Jobs/EvaluateAbandonedCarts.php`, `tests/Feature/MarketingAbandonmentTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T097: Recovery sequence and bounded incentives

- [ ] Complete T097.
- **Module / requirements:** `marketing` · MKT-03, MKT-05.
- **Depends on:** T095, T096.
- **Acceptance:** Configured steps obey cross-episode contact caps; final dispatch rechecks purchase/consent; one send/grant intent per episode step.
- **Verify:** Run `php artisan test --filter=MarketingRecoverySend`; replay jobs, pause flow and race payment/opt-out at dispatch.
- **Likely files:** `app/Domain/Marketing/Actions/ScheduleRecovery.php`, `app/Jobs/SendRecoveryStep.php`, `resources/views/mail/cart-recovery.blade.php`, `resources/js/admin/pages/RecoveryFlow.vue`, `tests/Feature/MarketingRecoverySendTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

#### Checkpoint after T095, T096, T097

- [ ] Acceptance and focused verification recorded; no zero-test pass.
- [ ] Relevant build/browser flow passes, including failure, permission and replay cases.
- [ ] Changes documented; pending external-provider evidence remains explicit.

### T098: Scanner-safe recovery cart restoration

- [ ] Complete T098.
- **Module / requirements:** `marketing` · MKT-04.
- **Depends on:** T025, T097.
- **Acceptance:** GET token landing reveals no cart/address; explicit POST restores current eligible items with lineage, no login/payment-session reuse; restricted coupon needs identity.
- **Verify:** Run `php artisan test --filter=MarketingRestore`; test forwarded/expired/replayed token, changed stock/price and email-prefetch GET.
- **Likely files:** `database/migrations/create_recovery_tokens.php`, `app/Domain/Marketing/Actions/ClaimRecoveryCart.php`, `app/Http/Controllers/Storefront/RecoveryController.php`, `themes/default/views/recover.blade.php`, `tests/Feature/MarketingRestoreTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T099: Scheduled broadcast and delivery feedback

- [ ] Complete T099.
- **Module / requirements:** `marketing` · MKT-02, MKT-10.
- **Depends on:** T092, T097.
- **Acceptance:** Opt-in campaigns send bounded batches with per-send suppression and one-click unsubscribe. Plain SMTP uses visible manual suppression and reports unavailable automated feedback; a selected feedback-capable adapter must verify signatures/dedup and pass its own contract tests before webhook use.
- **Verify:** Run `php artisan test --filter=MarketingBroadcast`; inject bounce/complaint duplicates and stop a campaign mid-batch.
- **Likely files:** `app/Jobs/SendCampaignBatch.php`, `app/Http/Controllers/Webhooks/DeliveryFeedbackController.php`, `resources/views/mail/campaign.blade.php`, `resources/js/admin/pages/CampaignDelivery.vue`, `tests/Feature/MarketingBroadcastTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T100: Truthful low-stock and expiry signals

- [ ] Complete T100.
- **Module / requirements:** `marketing` · MKT-06.
- **Depends on:** T019, T094.
- **Acceptance:** Physical tracked-stock signals expire after freshness TTL; actual offer expiry never resets across reloads; digital/untracked scarcity is hidden.
- **Verify:** Run `php artisan test --filter=MarketingUrgency`; use frozen time, stale cache, stock changes and expired grants.
- **Likely files:** `app/Domain/Marketing/Actions/ReadUrgencySignal.php`, `app/Domain/Marketing/Data/UrgencyView.php`, `app/Http/Controllers/Storefront/SignalController.php`, `tests/Feature/MarketingUrgencyTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

#### Checkpoint after T098, T099, T100

- [ ] Acceptance and focused verification recorded; no zero-test pass.
- [ ] Relevant build/browser flow passes, including failure, permission and replay cases.
- [ ] Changes documented; pending external-provider evidence remains explicit.

### T101: Aggregate purchase social proof

- [ ] Complete T101.
- **Module / requirements:** `marketing` · MKT-07.
- **Depends on:** T037, T044.
- **Acceptance:** Windowed net eligible purchase counts omit private data and low-volume groups; refund/cancellation events rebuild aggregates idempotently.
- **Verify:** Run `php artisan test --filter=MarketingProof`; exclude fake browser/test/unpaid events and fully refunded orders.
- **Likely files:** `database/migrations/create_proof_aggregates.php`, `app/Jobs/RefreshPurchaseProof.php`, `app/Domain/Marketing/Actions/ReadPurchaseProof.php`, `tests/Feature/MarketingProofTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T102: Verified-purchase reviews and moderation

- [ ] Complete T102.
- **Module / requirements:** `marketing` · MKT-07, MKT-08.
- **Depends on:** T043, T050.
- **Acceptance:** Verified purchaser submits one editable review/product; moderation reason/history preserved; authentic low ratings/refunded purchases follow policy.
- **Verify:** Run `php artisan test --filter=MarketingReviews`; deny wrong purchaser and inspect edit/refund/moderation history.
- **Likely files:** `database/migrations/create_product_reviews.php`, `app/Domain/Marketing/Actions/SubmitProductReview.php`, `app/Domain/Marketing/Actions/ModerateReview.php`, `resources/js/admin/pages/Reviews.vue`, `tests/Feature/MarketingReviewsTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T103: Accessible marketing sections and toasts

- [ ] Complete T103.
- **Module / requirements:** `storefront` · MKT-09, WEB-08, THEME-07.
- **Depends on:** T063, T100, T101, T102.
- **Acceptance:** Theme sections render approved facts/offers/signup/reviews; one toast/session caps/dismissal and private-route exclusions survive navigation.
- **Verify:** Run `npm run test:unit -- --run`, `npm run build` and `npm run test:e2e -- --project=chromium`; keyboard/reduced-motion/360px check.
- **Likely files:** `themes/default/sections/definitions.json`, `themes/default/src/MarketingToast.vue`, `themes/default/src/MarketingSections.vue`, `tests/Browser/marketing-widgets.spec.ts`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

#### Checkpoint after T101, T102, T103

- [ ] Acceptance and focused verification recorded; no zero-test pass.
- [ ] Relevant build/browser flow passes, including failure, permission and replay cases.
- [ ] Changes documented; pending external-provider evidence remains explicit.

### T104: First-party attribution capture

- [ ] Complete T104.
- **Module / requirements:** `marketing` · MKT-10.
- **Depends on:** T098, T099.
- **Acceptance:** Consent-eligible UTM/touch events and explicit recovery restores freeze order attribution; browser conversions cannot assert revenue; tokens never enter logs.
- **Verify:** Run `php artisan test --filter=MarketingAttribution`; test no-consent, direct-visit precedence, seven-day boundary and overlapping recovery.
- **Likely files:** `database/migrations/create_marketing_attributions.php`, `app/Domain/Marketing/Actions/RecordCampaignTouch.php`, `app/Domain/CheckoutOrders/Actions/CreateCheckoutIntent.php`, `tests/Feature/MarketingAttributionTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T105: Campaign and recovery integration gate

- [ ] Complete T105.
- **Module / requirements:** `marketing` · MKT-01, MKT-02, MKT-03, MKT-04, MKT-05, MKT-06, MKT-07, MKT-08, MKT-09, MKT-10.
- **Depends on:** T103, T104.
- **Acceptance:** Recovery→restore→coupon→paid order stops later sends; broadcasts/preferences/proof/reviews pass real browser journeys and abuse cases.
- **Verify:** Run `php artisan test --filter=Marketing` and `npm run test:e2e -- --project=chromium`; record email acceptance-race limitation and fresh signal behavior.
- **Likely files:** `tests/Browser/cart-recovery.spec.ts`, `tests/Browser/marketing-preferences.spec.ts`, `tests/Browser/product-reviews.spec.ts`, `docs/marketing-operator-guide.md`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

#### Checkpoint after T104, T105

- [ ] Acceptance and focused verification recorded; no zero-test pass.
- [ ] Relevant build/browser flow passes, including failure, permission and replay cases.
- [ ] Changes documented; pending external-provider evidence remains explicit.

## M10: Product hubs, referrals and growth reporting

### T106: Hub rule preview and duplicate-intent guard

- [ ] Complete T106.
- **Module / requirements:** `seo-hubs` · HUB-01.
- **Depends on:** T021, T066, T105.
- **Acceptance:** Merchant previews finite product groups/candidate URLs with normalized intent fingerprint; duplicate or unbounded facet rules rejected.
- **Verify:** Run `php artisan test --filter=SeoHubsRules`; test category/brand/use-case and duplicate predicate permutations.
- **Likely files:** `database/migrations/create_hub_rules.php`, `app/Domain/SeoHubs/Actions/PreviewHubRule.php`, `resources/js/admin/pages/HubRules.vue`, `tests/Feature/SeoHubsRulesTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T107: Bounded hub creation and membership refresh

- [ ] Complete T107.
- **Module / requirements:** `seo-hubs` · HUB-02, HUB-03, HUB-05.
- **Depends on:** T106, T067.
- **Acceptance:** Draft creation is bounded; approved-rule auto-publication honors quality gate; live product refresh preserves authored copy, URLs and revision history.
- **Verify:** Run `php artisan test --filter=SeoHubsGeneration`; replay generation and simulate missing products/job failure.
- **Likely files:** `app/Jobs/GenerateProductHubs.php`, `app/Domain/SeoHubs/Actions/RefreshHubMembership.php`, `app/Http/Controllers/Storefront/HubController.php`, `themes/default/views/hub.blade.php`, `tests/Feature/SeoHubsGenerationTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T108: SEO audit and hub lifecycle controls

- [ ] Complete T108.
- **Module / requirements:** `seo-hubs` · HUB-04, HUB-05.
- **Depends on:** T107, T070.
- **Acceptance:** Audit metadata/intent/links/alt/canonical/index state; empty/retired hubs, pagination/facets/tracking URLs follow explicit lifecycle policy.
- **Verify:** Run `php artisan test --filter=SeoHubsAudit`; inspect HTML/sitemap for page 2, duplicate hub, empty group and tracking parameters.
- **Likely files:** `app/Domain/SeoHubs/Actions/AuditSeo.php`, `app/Domain/SeoHubs/Actions/ResolveIndexability.php`, `resources/js/admin/pages/SeoAudit.vue`, `tests/Feature/SeoHubsAuditTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

#### Checkpoint after T106, T107, T108

- [ ] Acceptance and focused verification recorded; no zero-test pass.
- [ ] Relevant build/browser flow passes, including failure, permission and replay cases.
- [ ] Changes documented; pending external-provider evidence remains explicit.

### T109: Product, variant and review structured data

- [ ] Complete T109.
- **Module / requirements:** `seo-hubs` · HUB-06, WEB-08.
- **Depends on:** T102, T108.
- **Acceptance:** Visible product/offer/variant/review facts generate matching markup; hubs use collection/list semantics; no rating without eligible published reviews.
- **Verify:** Run `php artisan test --filter=SeoHubsStructuredData`; validate fixtures and record manual rich-result tool findings without ranking claims.
- **Likely files:** `app/Domain/SeoHubs/Actions/BuildStructuredData.php`, `themes/default/views/metadata.blade.php`, `themes/default/views/product.blade.php`, `tests/Feature/SeoHubsStructuredDataTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T110: Catalog feed and site-verification tools

- [ ] Complete T110.
- **Module / requirements:** `seo-hubs` · HUB-07.
- **Depends on:** T109.
- **Acceptance:** Typed verification tokens and bounded feed export expose only public catalog data; price/stock/version timestamp consistent; missing identifiers reported.
- **Verify:** Run `php artisan test --filter=SeoHubsFeed`; test invalid meta token, stale price, malformed XML/CSV and missing GTIN.
- **Likely files:** `app/Jobs/BuildCatalogFeed.php`, `app/Domain/SeoHubs/Actions/ValidateFeedProduct.php`, `resources/js/admin/pages/SeoIntegrations.vue`, `tests/Feature/SeoHubsFeedTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T111: AI hub and campaign copy drafts

- [ ] Complete T111.
- **Module / requirements:** `ai-authoring` · AI-07.
- **Depends on:** T078, T092, T108.
- **Acceptance:** AI drafts selected public hub/email copy and metadata for review; cannot fabricate proof/discount facts or publish/send through output.
- **Verify:** Run `php artisan test --filter=AiAuthoringMarketing`; review stale base revision, invented rating and private-context prompts.
- **Likely files:** `app/Domain/AiAuthoring/Actions/DraftMarketingContent.php`, `resources/js/admin/components/MarketingAiDraft.vue`, `tests/Feature/AiAuthoringMarketingTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

#### Checkpoint after T109, T110, T111

- [ ] Acceptance and focused verification recorded; no zero-test pass.
- [ ] Relevant build/browser flow passes, including failure, permission and replay cases.
- [ ] Changes documented; pending external-provider evidence remains explicit.

### T112: Referral program and advocate enrollment

- [ ] Complete T112.
- **Module / requirements:** `referrals` · REF-01, REF-02.
- **Depends on:** T009, T093, T105.
- **Acceptance:** Owner versions program rules/limits; verified customer enrolls and receives share code; copy controls do not send unsolicited invitations.
- **Verify:** Run `php artisan test --filter=ReferralsEnrollment`; verify permission, terms version and opaque-code uniqueness.
- **Likely files:** `database/migrations/create_referral_programs.php`, `app/Domain/Referrals/Actions/EnrollAdvocate.php`, `resources/js/admin/pages/ReferralProgram.vue`, `themes/default/views/referrals.blade.php`, `tests/Feature/ReferralsEnrollmentTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T113: Referral attribution and friend offer

- [ ] Complete T113.
- **Module / requirements:** `referrals` · REF-03, REF-04, REF-06.
- **Depends on:** T112, T095.
- **Acceptance:** First eligible touch/explicit code precedence and verified first-customer guard freeze on order; one advocate/claim; no open redirect or self-referral.
- **Verify:** Run `php artisan test --filter=ReferralsClaim`; race guest/account first orders and test expiry/consent/household cases.
- **Likely files:** `database/migrations/create_referral_claims.php`, `app/Domain/Referrals/Actions/ClaimReferral.php`, `app/Http/Controllers/Storefront/ReferralController.php`, `tests/Feature/ReferralsClaimTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T114: Paid-order qualification and reward hold

- [ ] Complete T114.
- **Module / requirements:** `referrals` · REF-04, REF-05.
- **Depends on:** T113, T046, T044.
- **Acceptance:** Net qualifying merchandise and later payment/physical-fulfillment hold control reward; unknown refunds/disputes defer release; repeat events are harmless.
- **Verify:** Run `php artisan test --filter=ReferralsQualification`; physical/digital/mixed orders and time/refund boundary fixtures.
- **Likely files:** `app/Domain/Referrals/Actions/QualifyReferral.php`, `app/Jobs/EvaluateReferralRewards.php`, `tests/Feature/ReferralsQualificationTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

#### Checkpoint after T112, T113, T114

- [ ] Acceptance and focused verification recorded; no zero-test pass.
- [ ] Relevant build/browser flow passes, including failure, permission and replay cases.
- [ ] Changes documented; pending external-provider evidence remains explicit.

### T115: Atomic reward grant and program caps

- [ ] Complete T115.
- **Module / requirements:** `referrals` · REF-05, REF-06.
- **Depends on:** T114.
- **Acceptance:** After eligibility recheck, exactly one pricing-owned advocate coupon is issued by stable intent; parallel releases cannot exceed caps/budget.
- **Verify:** Run `php artisan test --filter=ReferralsReward` on MySQL; crash/replay coupon issuance and race final program slot.
- **Likely files:** `database/migrations/create_referral_rewards.php`, `app/Domain/Referrals/Actions/ReleaseReferralReward.php`, `app/Domain/Pricing/Actions/IssuePromotionGrant.php`, `tests/Feature/ReferralsRewardTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T116: Refund reversal and abuse review

- [ ] Complete T116.
- **Module / requirements:** `referrals` · REF-05, REF-06.
- **Depends on:** T115.
- **Acceptance:** Late adverse events revoke unused reward or create review for used/unknown-payment grant; staff reasons audited; no payment debit/history rewrite.
- **Verify:** Run `php artisan test --filter=ReferralsReversal`; refund before/after reward redemption and resolve legitimate shared-household case.
- **Likely files:** `app/Domain/Referrals/Actions/ReconcileReward.php`, `app/Domain/Referrals/Actions/ReviewReferralCase.php`, `resources/js/admin/pages/ReferralReview.vue`, `tests/Feature/ReferralsReversalTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T117: Private referral dashboard and metrics

- [ ] Complete T117.
- **Module / requirements:** `referrals` · REF-02, REF-07, WEB-08.
- **Depends on:** T116.
- **Acceptance:** Advocate sees own reward states without friend's identity/order; staff sees qualification/redemption/net-sales metrics and audited adjustments.
- **Verify:** Run `php artisan test --filter=ReferralsDashboard`; verify cross-customer denial and refund-adjusted counts.
- **Likely files:** `app/Domain/Referrals/Actions/BuildReferralSummary.php`, `app/Http/Controllers/Storefront/ReferralAccountController.php`, `themes/default/views/referrals.blade.php`, `resources/js/admin/pages/ReferralReport.vue`, `tests/Feature/ReferralsDashboardTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

#### Checkpoint after T115, T116, T117

- [ ] Acceptance and focused verification recorded; no zero-test pass.
- [ ] Relevant build/browser flow passes, including failure, permission and replay cases.
- [ ] Changes documented; pending external-provider evidence remains explicit.

### T118: Unified marketing funnel and recovery reporting

- [ ] Complete T118.
- **Module / requirements:** `operations` · MKT-10, OPS-08.
- **Depends on:** T104, T110, T117.
- **Acceptance:** Report accepted/delivered/click/paid/refund facts distinctly; campaign/recovery/referral overlap never inflates unique orders; diagnostics show lag/freshness.
- **Verify:** Run `php artisan test --filter=OperationsMarketing`; reconcile same order across three attribution dimensions and suppressed/unknown mail.
- **Likely files:** `app/Domain/Operations/Actions/BuildMarketingReport.php`, `resources/js/admin/pages/MarketingReport.vue`, `app/Domain/Operations/Actions/InspectMarketingHealth.php`, `tests/Feature/OperationsMarketingTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T119: Hub and referral end-to-end journeys

- [ ] Complete T119.
- **Module / requirements:** `seo-hubs` · HUB-01, HUB-02, HUB-03, HUB-04, HUB-05, HUB-06, HUB-07, REF-01, REF-02, REF-03, REF-04, REF-05, REF-06, REF-07.
- **Depends on:** T111, T118.
- **Acceptance:** Hub generate→review→publish→refresh and referral share→friend purchase→hold→reward→refund reversal work with canonical/private states intact.
- **Verify:** Run `php artisan test --filter=SeoHubs`, `php artisan test --filter=Referrals` and `npm run test:e2e -- --project=chromium`.
- **Likely files:** `tests/Browser/product-hubs.spec.ts`, `tests/Browser/referral-program.spec.ts`, `docs/marketing-operator-guide.md`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T120: Integrated marketing release evidence

- [ ] Complete T120.
- **Module / requirements:** `operations` · PLAT-07, PRICE-06, PRICE-07, PRICE-08, MKT-09, HUB-05, REF-05, AI-07, OPS-08.
- **Depends on:** T119, T105.
- **Acceptance:** Expanded browser/abuse/performance scenarios recorded; upgrade/restore fixtures include preferences, rewards and hubs; release package scope updated before M8 gates.
- **Verify:** Run focused marketing/pricing/referral/SEO suites and `npm run build`; prepare fixtures for final T085–T090 install/restore/load rehearsals.
- **Likely files:** `tests/Feature/OperationsMarketingRecoveryTest.php`, `tests/Browser/marketing-accessibility.spec.ts`, `docs/release-evidence.md`, `docs/quality-and-operations.md`, `docs/marketing-operator-guide.md`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

#### Checkpoint after T118, T119, T120

- [ ] Acceptance and focused verification recorded; no zero-test pass.
- [ ] Relevant build/browser flow passes, including failure, permission and replay cases.
- [ ] Changes documented; pending external-provider evidence remains explicit.

## M8: Merchant operations and release readiness

### T079: Transactional email configuration and failures

- [ ] Complete T079.
- **Module / requirements:** `operations` · OPS-01, PLAT-05.
- **Depends on:** T043, T047, T057, T078, T120.
- **Acceptance:** Transactional and marketing templates honor separate suppression/purpose policies and have preview/test sender, deduplicated delivery intent and visible failure/retry; no exactly-once SMTP claim.
- **Verify:** Run `php artisan test --filter=OperationsMail`; simulate transport outage then recovery and unsubscribe-before-retry.
- **Likely files:** `app/Domain/Operations/Actions/DeliverNotification.php`, `resources/js/admin/pages/EmailSettings.vue`, `resources/js/admin/pages/NotificationFailures.vue`, `tests/Feature/OperationsMailTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T080: Sales and refund reporting

- [ ] Complete T080.
- **Module / requirements:** `operations` · OPS-02.
- **Depends on:** T041, T044.
- **Acceptance:** Paid/test/unpaid inclusion and date/timezone rules are explicit; dashboard figures reconcile to captured/refunded fixtures.
- **Verify:** Run `php artisan test --filter=OperationsReports`; compare gross/discount/refund/net/tax/shipping/AOV against ledger fixture.
- **Likely files:** `app/Domain/Operations/Actions/BuildSalesReport.php`, `app/Http/Controllers/Admin/ReportsController.php`, `resources/js/admin/pages/Reports.vue`, `tests/Feature/OperationsReportsTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T081: Permission-gated private exports

- [ ] Complete T081.
- **Module / requirements:** `operations` · OPS-03.
- **Depends on:** T080, T023.
- **Acceptance:** Customer/order/product exports are audited, formula-safe and privately downloadable with expiry; other staff/customers cannot access them.
- **Verify:** Run `php artisan test --filter=OperationsExport`; test cross-user access, expiry and spreadsheet-formula cells.
- **Likely files:** `app/Jobs/ExportStoreData.php`, `app/Http/Controllers/Admin/ExportController.php`, `resources/js/admin/pages/Exports.vue`, `tests/Feature/OperationsExportTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

#### Checkpoint after T079, T080, T081

- [ ] Acceptance and focused verification recorded; no zero-test pass.
- [ ] Relevant build/browser flow passes, including failure, permission and replay cases.
- [ ] Changes documented; pending external-provider evidence remains explicit.

### T082: Account data and retention workflow

- [ ] Complete T082.
- **Module / requirements:** `identity` · ID-06.
- **Depends on:** T081, T050.
- **Acceptance:** Export/deletion request verifies identity; eligible profile data anonymized under configured policy; retained order/dedup records remain valid.
- **Verify:** Run `php artisan test --filter=IdentityPrivacy`; verify historical receipts, revoked sessions and retained payment reconciliation.
- **Likely files:** `app/Domain/Identity/Actions/ProcessPrivacyRequest.php`, `resources/js/admin/pages/PrivacyRequests.vue`, `app/Jobs/PruneExpiredData.php`, `tests/Feature/IdentityPrivacyTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T083: Operational diagnostics and audit views

- [ ] Complete T083.
- **Module / requirements:** `operations` · OPS-04, PLAT-05.
- **Depends on:** T079, T080.
- **Acceptance:** Owner sees worker/scheduler lag, unknown payments, disk/backups and redacted job errors; support export excludes private payloads.
- **Verify:** Run `php artisan test --filter=OperationsHealth`; stop worker/scheduler and inspect actionable warnings.
- **Likely files:** `app/Domain/Operations/Actions/InspectHealth.php`, `app/Http/Controllers/Admin/HealthController.php`, `resources/js/admin/pages/SystemHealth.vue`, `resources/js/admin/pages/AuditLog.vue`, `tests/Feature/OperationsHealthTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T084: Reference-host installation runbook

- [ ] Complete T084.
- **Module / requirements:** `operations` · OPS-05, PLAT-01, PLAT-02.
- **Depends on:** T083.
- **Acceptance:** Document supported host/services/permissions and secure owner setup; a new operator completes sandbox purchase within target time.
- **Verify:** Run clean-install rehearsal; record runtime, elapsed setup time, private-file denial and physical/digital/mixed test evidence.
- **Likely files:** `docs/installation.md`, `docs/hosting.md`, `.env.example`, `docs/release-evidence.md`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

#### Checkpoint after T082, T083, T084

- [ ] Acceptance and focused verification recorded; no zero-test pass.
- [ ] Relevant build/browser flow passes, including failure, permission and replay cases.
- [ ] Changes documented; pending external-provider evidence remains explicit.

### T085: Reproducible open-source release package

- [ ] Complete T085.
- **Module / requirements:** `operations` · OPS-05, OPS-07.
- **Depends on:** T084, T120.
- **Acceptance:** Package includes the expanded marketing/referral/hub modules, compiled assets/production dependencies/checksums; chosen license/dependency inventory/changelog/security policy included before public release.
- **Verify:** Run `composer install --no-dev --prefer-dist --optimize-autoloader` and `npm ci` / `npm run build` in isolated packaging checkout; verify archive secrets exclusion.
- **Likely files:** `build/release.sh`, `LICENSE`, `CHANGELOG.md`, `SECURITY.md`, `CONTRIBUTING.md`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T086: Upgrade and rollback rehearsal

- [ ] Complete T086.
- **Module / requirements:** `operations` · OPS-05.
- **Depends on:** T085, T068.
- **Acceptance:** N−1 fixture upgrades without losing orders/assets/themes; failed deployment follows schema-compatible rollback or documented restore path.
- **Verify:** Run rehearsal with queued provider events and custom theme; compare pre/post data and verify no lost payment intent.
- **Likely files:** `docs/upgrading.md`, `docs/rollback.md`, `tests/Feature/OperationsUpgradeTest.php`, `docs/release-evidence.md`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T087: Backup, isolated restore and reconciliation

- [ ] Complete T087.
- **Module / requirements:** `operations` · OPS-06.
- **Depends on:** T086, T082.
- **Acceptance:** Restore database/files/key procedure with outbound side effects disabled; reconcile post-backup provider transactions before reopening; measure RPO/RTO.
- **Verify:** Perform isolated recovery rehearsal; verify checksums, entitlements, stock and pending jobs without issuing charges/emails/rewards; reconcile suppression, recovery sends and already-issued coupons before enabling marketing workers.
- **Likely files:** `docs/backup-restore.md`, `docs/payment-reconciliation.md`, `tests/Feature/OperationsRecoveryTest.php`, `docs/release-evidence.md`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

#### Checkpoint after T085, T086, T087

- [ ] Acceptance and focused verification recorded; no zero-test pass.
- [ ] Relevant build/browser flow passes, including failure, permission and replay cases.
- [ ] Changes documented; pending external-provider evidence remains explicit.

### T088: Security and accessibility release checks

- [ ] Complete T088.
- **Module / requirements:** `storefront` · WEB-06, ID-04, FUL-05, THEME-04, AI-04.
- **Depends on:** T078, T082, T087.
- **Acceptance:** Critical abuse/authorization tests pass; keyboard/screen-reader/mobile flows and default theme meet documented accessibility gate.
- **Verify:** Run `php artisan test`, `vendor/bin/phpstan analyse --memory-limit=1G`, `npm run typecheck` and `npm run test:e2e -- --project=chromium`; record manual review.
- **Likely files:** `tests/Browser/accessibility.spec.ts`, `tests/Feature/SecurityBoundariesTest.php`, `docs/security-review.md`, `docs/release-evidence.md`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T089: Performance, capacity and failure-load checks

- [ ] Complete T089.
- **Module / requirements:** `operations` · OPS-04, WEB-06, WEB-07.
- **Depends on:** T088.
- **Acceptance:** Reference catalog/load with marketing widgets, bulk mail and hub refresh enabled meets documented targets; unknown-payment/queue failure behavior remains correct under load; large downloads stay memory-bounded.
- **Verify:** Run documented reproducible load scenario and full production build; record p95, dataset, hardware, cache state and lab/field distinction.
- **Likely files:** `tests/Performance/commerce.js`, `database/seeders/PerformanceSeeder.php`, `docs/performance.md`, `docs/release-evidence.md`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T090: Release acceptance and complete handoff

- [ ] Complete T090.
- **Module / requirements:** `operations` · OPS-05, OPS-07.
- **Depends on:** T089, T120.
- **Acceptance:** All expanded commerce, marketing, referral and SEO requirements mapped to passing evidence; open license/market/provider/governance choices resolved; installation/theme/AI docs accurate.
- **Verify:** Run final quality/build suite and every launch checklist item in docs/quality-and-operations.md; leave release incomplete if any mandatory evidence fails.
- **Likely files:** `README.md`, `tasks/todo.md`, `docs/release-evidence.md`, `docs/open-decisions.md`, `docs/quality-and-operations.md`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.
#### Checkpoint after T088, T089, T090

- [ ] Acceptance and focused verification recorded; no zero-test pass.
- [ ] Relevant build/browser flow passes, including failure, permission and replay cases.
- [ ] Changes documented; pending external-provider evidence remains explicit.


## Execution evidence

Add dated evidence beneath completed tasks: focused test result, manual journey, environment and unresolved limitations. Never mark provider smoke tests complete without credentials and actual results; mocks prove local behavior only. The first public release requires every mandatory criterion, including M4–M10; M3 alone is not the promised product.
