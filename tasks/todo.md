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
- **Evidence October 13:** Failing `CheckoutOrdersCartMerge` tests were written first, then `MergeCart`, login/register transfer via `CustomerSession`, cart adjustment notices, and default-theme `CartLines`/`CartIndicator` Vue enhancement. Login merges matching physical quantities clamped to allowed maximum and keeps digital quantity one; the shopper sees an adjustment notice; failed login leaves the guest cart; logout hides the account cart. Privileged staff still must pass 2FA before catalog admin. `GET /` remains 503. `CheckoutOrdersCartMerge` 2 tests; full PHP suite 370 tests / 2793 assertions; Pint; PHPStan level 8; frontend type/lint/format/build; 49 Vue tests; 10 Chromium journeys including cart indicator after add-to-cart. Composer/npm audits passed; 237 registry signatures / 95 attestations. Local PCOV coverage was not collected (no coverage driver). Hosted Quality for this branch is pending.
- **Module / requirements:** `checkout-orders` · ORDER-01, ID-05.
- **Depends on:** T025.
- **Acceptance:** Login merges matching variants by summed physical quantity clamped to allowed maximum, digital quantity one; shopper sees adjustment notice.
- **Verify:** Run `php artisan test --filter=CheckoutOrdersCartMerge` and `npm run test:unit -- --run`; test guest/login/logout behavior.
- **Likely files:** `app/Domain/CheckoutOrders/Actions/MergeCart.php`, `themes/default/src/CartLines.vue`, `themes/default/src/CartIndicator.vue`, `tests/Feature/CheckoutOrdersCartMergeTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T027: Money and deterministic price engine

- [ ] Complete T027.
- **Module / requirements:** `pricing` · PRICE-01, PRICE-04.
- **Depends on:** T016.
- **Acceptance:** Server calculation supports currency exponents, sale windows, integer allocations and immutable breakdowns with no floating point arithmetic.
- **Verify:** Run `php artisan test --filter=PricingMoney`; verify fixtures and allocation sums for odd-cent cases.
- **Likely files:** `app/Domain/Pricing/Data/Money.php`, `app/Domain/Pricing/Actions/CalculatePrice.php`, `app/Domain/Pricing/Rules/AllocateDiscount.php`, `tests/Unit/PricingMoneyTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

#### Checkpoint after T025, T026, T027

- [ ] Acceptance and focused verification recorded; no zero-test pass.
- [ ] Relevant build/browser flow passes, including failure, permission and replay cases.
- [ ] Changes documented; pending external-provider evidence remains explicit.

### T028: Coupon configuration and redemption holds

- [ ] Complete T028.
- **Module / requirements:** `pricing` · PRICE-02.
- **Depends on:** T027, T019.
- **Acceptance:** Merchant configures one-coupon rules; limits are held/committed/released atomically and reject over-redemption.
- **Verify:** Run `php artisan test --filter=PricingCoupon`; race two uses of the last redemption.
- **Likely files:** `database/migrations/create_coupon_tables.php`, `app/Domain/Pricing/Actions/ApplyCoupon.php`, `app/Domain/Pricing/Actions/HoldCoupon.php`, `resources/js/admin/pages/CouponEdit.vue`, `tests/Feature/PricingCouponTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T029: Shipping zones and rule editor

- [ ] Complete T029.
- **Module / requirements:** `shipping` · SHIP-01, SHIP-02.
- **Depends on:** T005.
- **Acceptance:** Configure deterministic destination zones, flat/free/table/pickup methods; conflicting same-priority rules rejected.
- **Verify:** Run `php artisan test --filter=ShippingRules`; preview boundary postal codes and thresholds.
- **Likely files:** `database/migrations/create_shipping_tables.php`, `app/Domain/Shipping/Actions/SaveShippingRules.php`, `resources/js/admin/pages/Shipping.vue`, `tests/Feature/ShippingRulesTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T030: Physical and mixed-cart shipping quotes

- [ ] Complete T030.
- **Module / requirements:** `shipping` · SHIP-03, SHIP-04, SHIP-05, SHIP-06.
- **Depends on:** T027, T029.
- **Acceptance:** Only physical lines affect charge/threshold; unsupported destinations/classes fail; digital-only skips shipping; pickup destination explicit.
- **Verify:** Run `php artisan test --filter=ShippingQuote`; compare physical/digital/mixed fixtures.
- **Likely files:** `app/Domain/Shipping/Contracts/ShippingQuoter.php`, `app/Domain/Shipping/Actions/QuoteShipping.php`, `app/Domain/Shipping/Data/ShippingQuote.php`, `tests/Unit/ShippingQuoteTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

#### Checkpoint after T028, T029, T030

- [ ] Acceptance and focused verification recorded; no zero-test pass.
- [ ] Relevant build/browser flow passes, including failure, permission and replay cases.
- [ ] Changes documented; pending external-provider evidence remains explicit.

### T031: Tax configuration and deterministic calculation

- [ ] Complete T031.
- **Module / requirements:** `pricing` · PRICE-03, PRICE-05.
- **Depends on:** T027, T030.
- **Acceptance:** Merchant tax classes/rules and price mode calculate documented line totals; ambiguous/missing coverage prevents approval.
- **Verify:** Run `php artisan test --filter=PricingTax`; validate intended launch market fixtures before real sales.
- **Likely files:** `database/migrations/create_tax_tables.php`, `app/Domain/Pricing/Actions/CalculateTax.php`, `resources/js/admin/pages/Taxes.vue`, `tests/Unit/PricingTaxTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T032: Quote revision and checkout form

- [ ] Complete T032.
- **Module / requirements:** `checkout-orders` · ORDER-02, PRICE-04.
- **Depends on:** T025, T028, T030, T031.
- **Acceptance:** Contact/billing/necessary delivery details create expiring quote; changes to cart/rules/destination invalidate acceptance.
- **Verify:** Run `php artisan test --filter=CheckoutOrdersQuote`; browser-test stale totals and address correction.
- **Likely files:** `app/Domain/CheckoutOrders/Actions/CreateQuote.php`, `app/Http/Controllers/Storefront/CheckoutController.php`, `themes/default/views/checkout.blade.php`, `themes/default/src/CheckoutForm.vue`, `tests/Feature/CheckoutOrdersQuoteTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T033: Pricing preview and cart browser gate

- [ ] Complete T033.
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

- [ ] Complete T034.
- **Module / requirements:** `checkout-orders` · ORDER-03.
- **Depends on:** T011, T032.
- **Acceptance:** Atomic intent freezes quote/line/address snapshots and holds stock/coupons. Enforce one payable order per cart revision even when competing clients use different idempotency keys; same key replays and changed payload conflicts.
- **Verify:** Run `php artisan test --filter=CheckoutOrdersIntent`; exercise parallel identical submissions and transaction rollback.
- **Likely files:** `database/migrations/create_order_tables.php`, `database/migrations/create_payment_intents.php`, `app/Domain/CheckoutOrders/Actions/CreateCheckoutIntent.php`, `tests/Feature/CheckoutOrdersIntentTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T035: Hosted payment adapter

- [ ] Complete T035.
- **Module / requirements:** `checkout-orders` · ORDER-03.
- **Depends on:** T034.
- **Acceptance:** Stable attempt creates one immediate-method hosted session outside locks; unknown timeout is persisted. Frozen quote exactly equals provider total, including odd-cent allocations; provider tax/promo/quantity/delivery changes cannot bypass the accepted quote.
- **Verify:** Run `php artisan test --filter=CheckoutOrdersGateway`; sandbox success/decline/authentication, expiry/reconciliation and physical/digital/mixed amount-mapping tests with actual test credentials. Validate provider currency/amount/line limits; fixtures alone do not complete this task.
- **Likely files:** `app/Domain/CheckoutOrders/Adapters/StripeGateway.php`, `app/Domain/CheckoutOrders/Actions/StartPayment.php`, `app/Http/Controllers/Storefront/PaymentController.php`, `tests/Contract/CheckoutOrdersGatewayTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T036: Verified durable webhook inbox

- [ ] Complete T036.
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

- [ ] Complete T037.
- **Module / requirements:** `checkout-orders` · ORDER-04.
- **Depends on:** T036.
- **Acceptance:** Verified matching success commits allocations and emits OrderPaid once; unordered events cannot downgrade success; unknown attempts queried.
- **Verify:** Run `php artisan test --filter=CheckoutOrdersReconciliation`; simulate crash after provider success and before local update.
- **Likely files:** `app/Domain/CheckoutOrders/Actions/ReconcilePayment.php`, `app/Domain/CheckoutOrders/Actions/ConfirmOrderPayment.php`, `app/Jobs/ReconcilePendingPayments.php`, `tests/Feature/CheckoutOrdersReconciliationTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T038: Expiry, cancellation and late-success recovery

- [ ] Complete T038.
- **Module / requirements:** `checkout-orders` · ORDER-03, ORDER-04.
- **Depends on:** T037.
- **Acceptance:** Resolve/cancel provider sessions before releasing holds; unknown status retains holds; late success re-reserves or holds order for resolution.
- **Verify:** Run `php artisan test --filter=CheckoutOrdersExpiry`; test provider outage and late success after competing stock purchase.
- **Likely files:** `app/Domain/CheckoutOrders/Actions/ExpireCheckout.php`, `app/Jobs/ExpireCheckoutSessions.php`, `routes/console.php`, `tests/Feature/CheckoutOrdersExpiryTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T039: Zero-total checkout and confirmation screen

- [ ] Complete T039.
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

- [ ] Complete T040.
- **Module / requirements:** `checkout-orders` · ORDER-05.
- **Depends on:** T037, T013.
- **Acceptance:** Permitted staff search orders and see frozen line/totals plus independent financial/fulfillment states and notes.
- **Verify:** Run `php artisan test --filter=CheckoutOrdersAdmin`; compare owner/fulfillment/content-editor access.
- **Likely files:** `app/Http/Controllers/Admin/OrderController.php`, `app/Policies/OrderPolicy.php`, `resources/js/admin/pages/Orders.vue`, `resources/js/admin/pages/OrderDetail.vue`, `tests/Feature/CheckoutOrdersAdminTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T041: Partial and full provider refunds

- [ ] Complete T041.
- **Module / requirements:** `checkout-orders` · ORDER-06.
- **Depends on:** T040.
- **Acceptance:** Refund allocation stays within remaining money/quantities. Requested unshipped quantity refunds hold fulfillment; success cancels/releases those quantities atomically, failure clears holds, unknown preserves holds. Amount-only goodwill refunds do not cancel quantities.
- **Verify:** Run `php artisan test --filter=CheckoutOrdersRefund`; race partial refunds and reconcile a timeout-after-refund.
- **Likely files:** `database/migrations/create_refunds.php`, `app/Domain/CheckoutOrders/Actions/RequestRefund.php`, `app/Domain/CheckoutOrders/Actions/ReconcileRefund.php`, `resources/js/admin/components/RefundDialog.vue`, `tests/Feature/CheckoutOrdersRefundTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T042: Order cancellation workflow

- [ ] Complete T042.
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

- [ ] Complete T043.
- **Module / requirements:** `checkout-orders` · ORDER-05, ORDER-07, OPS-01.
- **Depends on:** T009, T040.
- **Acceptance:** Own-order view and receipt reflect immutable history; event-derived receipt intent deduplicates; resend is explicit/audited.
- **Verify:** Run `php artisan test --filter=CheckoutOrdersReceipt`; deny another customer's order and inspect queued receipt.
- **Likely files:** `app/Domain/CheckoutOrders/Actions/RecordOrderEvent.php`, `app/Http/Controllers/Storefront/CustomerOrderController.php`, `resources/views/mail/order-receipt.blade.php`, `themes/default/views/account-orders.blade.php`, `tests/Feature/CheckoutOrdersReceiptTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T044: External refunds and disputes

- [ ] Complete T044.
- **Module / requirements:** `checkout-orders` · ORDER-04, ORDER-06.
- **Depends on:** T041, T043.
- **Acceptance:** Provider-originated refunds/disputes reconcile into history and hold/revoke workflow intents; staff cannot overwrite provider facts.
- **Verify:** Run `php artisan test --filter=CheckoutOrdersExternalEvent`; replay refund/dispute/won/lost events out of order.
- **Likely files:** `app/Domain/CheckoutOrders/Actions/ReconcileExternalAdjustment.php`, `app/Jobs/ProcessPaymentEvent.php`, `resources/js/admin/pages/OrderDetail.vue`, `tests/Feature/CheckoutOrdersExternalEventTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T045: Checkout recovery browser gate

- [ ] Complete T045.
- **Module / requirements:** `checkout-orders` · ORDER-01, ORDER-02, ORDER-03, ORDER-04, ORDER-06.
- **Depends on:** T039, T042, T043, T044.
- **Acceptance:** Physical sandbox purchase, duplicate-click, pending-return, refund and provider-outage journeys have recorded evidence.
- **Verify:** Run `php artisan test --filter=CheckoutOrders` and `npm run test:e2e -- --project=chromium`; review provider ledger versus local orders.
- **Likely files:** `tests/Browser/checkout.spec.ts`, `tests/Feature/CheckoutOrdersReplayTest.php`, `docs/commerce-rules.md`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

#### Checkpoint after T043, T044, T045

- [ ] Acceptance and focused verification recorded; no zero-test pass.
- [ ] Relevant build/browser flow passes, including failure, permission and replay cases.
- [ ] Changes documented; pending external-provider evidence remains explicit.

## M4: Physical fulfillment and digital delivery

### T046: Partial shipment creation

- [ ] Complete T046.
- **Module / requirements:** `fulfillment` · FUL-01, FUL-02.
- **Depends on:** T040, T019.
- **Acceptance:** Staff allocates only paid unshipped physical quantities; shipment and stock movement are atomic and retry-safe.
- **Verify:** Run `php artisan test --filter=FulfillmentShipment`; race two shipment submissions.
- **Likely files:** `database/migrations/create_shipment_tables.php`, `app/Domain/Fulfillment/Actions/CreateShipment.php`, `app/Http/Controllers/Admin/ShipmentController.php`, `resources/js/admin/components/ShipmentDialog.vue`, `tests/Feature/FulfillmentShipmentTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T047: Tracking and shipment notification

- [ ] Complete T047.
- **Module / requirements:** `fulfillment` · FUL-01, OPS-01.
- **Depends on:** T046, T043.
- **Acceptance:** Customer sees their tracking link and shipment quantities; invalid URLs rejected; notification intent deduplicates.
- **Verify:** Run `php artisan test --filter=FulfillmentTracking`; check malicious URL and partial shipment email.
- **Likely files:** `app/Domain/Fulfillment/Rules/TrackingUrl.php`, `resources/views/mail/shipment.blade.php`, `themes/default/views/order-status.blade.php`, `tests/Feature/FulfillmentTrackingTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T048: Returns and deliberate restock

- [ ] Complete T048.
- **Module / requirements:** `fulfillment` · FUL-03.
- **Depends on:** T046, T041.
- **Acceptance:** Staff records approval/receipt/restock separately from money; received/shipped bounds enforced; repeated restock has one effect.
- **Verify:** Run `php artisan test --filter=FulfillmentReturn`; verify refund without stock movement and explicit received restock.
- **Likely files:** `database/migrations/create_returns.php`, `app/Domain/Fulfillment/Actions/ReceiveReturn.php`, `resources/js/admin/components/ReturnDialog.vue`, `tests/Feature/FulfillmentReturnTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

#### Checkpoint after T046, T047, T048

- [ ] Acceptance and focused verification recorded; no zero-test pass.
- [ ] Relevant build/browser flow passes, including failure, permission and replay cases.
- [ ] Changes documented; pending external-provider evidence remains explicit.

### T049: Paid digital entitlement issuance

- [ ] Complete T049.
- **Module / requirements:** `fulfillment` · FUL-04.
- **Depends on:** T020, T037.
- **Acceptance:** Current eligible paid/free-completed digital lines issue one version-pinned entitlement. Replayed or delayed OrderPaid after refund/dispute cannot grant access; eligibility is durable even if refund preceded entitlement creation.
- **Verify:** Run `php artisan test --filter=FulfillmentEntitlement`; replay events for digital and mixed orders.
- **Likely files:** `database/migrations/create_entitlements.php`, `app/Domain/Fulfillment/Actions/IssueEntitlements.php`, `app/Jobs/IssueOrderDownloads.php`, `tests/Feature/FulfillmentEntitlementTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T050: Verified guest and account download area

- [ ] Complete T050.
- **Module / requirements:** `fulfillment` · FUL-04, ID-05, ORDER-07.
- **Depends on:** T049, T009.
- **Acceptance:** Guest verification links expire and are consumed only through explicit confirmation POST; scanner GET does not consume or authenticate. Verified accounts claim eligible orders; wrong identity sees no files.
- **Verify:** Run `php artisan test --filter=FulfillmentDownloadIdentity`; exercise account-link and replay attacks.
- **Likely files:** `app/Domain/Fulfillment/Actions/VerifyGuestOrder.php`, `app/Http/Controllers/Storefront/DownloadsController.php`, `themes/default/views/account-downloads.blade.php`, `resources/views/mail/download-access.blade.php`, `tests/Feature/FulfillmentDownloadIdentityTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T051: Private streaming download grants

- [ ] Complete T051.
- **Module / requirements:** `fulfillment` · FUL-05.
- **Depends on:** T050.
- **Acceptance:** Five-minute start token is consumed only by an explicit CSRF-protected POST, creating one counted transfer session bound to owner and asset for one hour. Owner-only status recovers lost responses; authorized Range/resume does not spend another grant; revoked/cross-user/expired sessions reject new streams.
- **Verify:** Run `php artisan test --filter=FulfillmentDownload`; test GET/prefetch safety, last-grant race, lost start response, stream beyond five minutes, bounded Range/resume and revoke-before-resume. Do not claim already-transferred bytes can be recalled.
- **Likely files:** `database/migrations/create_download_grants.php`, `app/Domain/Fulfillment/Actions/AuthorizeDownload.php`, `app/Http/Controllers/Storefront/DownloadStreamController.php`, `tests/Feature/FulfillmentDownloadTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

#### Checkpoint after T049, T050, T051

- [ ] Acceptance and focused verification recorded; no zero-test pass.
- [ ] Relevant build/browser flow passes, including failure, permission and replay cases.
- [ ] Changes documented; pending external-provider evidence remains explicit.

### T052: Refund and dispute access revocation

- [ ] Complete T052.
- **Module / requirements:** `fulfillment` · FUL-05.
- **Depends on:** T051, T041, T044.
- **Acceptance:** Full digital-line refund revokes access and prevents later stale paid-event issuance; unrelated physical-line or partial goodwill refunds retain eligible digital access. Disputes suspend/resolve under current authoritative order state.
- **Verify:** Run `php artisan test --filter=FulfillmentRevocation`; revoke between grant issue and transfer start.
- **Likely files:** `app/Domain/Fulfillment/Actions/RevokeEntitlements.php`, `app/Jobs/ApplyEntitlementAdjustment.php`, `tests/Feature/FulfillmentRevocationTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T053: Purchased asset updates and grant reset

- [ ] Complete T053.
- **Module / requirements:** `fulfillment` · FUL-06, CAT-06.
- **Depends on:** T051, T052.
- **Acceptance:** Staff grants a new asset version or resets limits with audit; referenced file deletion blocked; original purchase version preserved.
- **Verify:** Run `php artisan test --filter=FulfillmentAssetUpdate`; compare existing/new buyer versions.
- **Likely files:** `app/Domain/Fulfillment/Actions/GrantAssetUpdate.php`, `app/Domain/Fulfillment/Actions/ResetDownloadLimit.php`, `resources/js/admin/components/DownloadAccess.vue`, `tests/Feature/FulfillmentAssetUpdateTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T054: Mixed order fulfillment gate

- [ ] Complete T054.
- **Module / requirements:** `fulfillment` · FUL-01, FUL-04, FUL-05, WEB-07.
- **Depends on:** T047, T048, T053.
- **Acceptance:** One mixed purchase unlocks downloads while shipping stays pending; partial physical refund does not revoke unrelated digital access.
- **Verify:** Run `php artisan test --filter=Fulfillment` and `npm run test:e2e -- --project=chromium`; record mixed order and refund walkthrough.
- **Likely files:** `tests/Browser/digital-purchase.spec.ts`, `tests/Browser/mixed-purchase.spec.ts`, `tests/Browser/fulfillment.spec.ts`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

#### Checkpoint after T052, T053, T054

- [ ] Acceptance and focused verification recorded; no zero-test pass.
- [ ] Relevant build/browser flow passes, including failure, permission and replay cases.
- [ ] Changes documented; pending external-provider evidence remains explicit.

## M5: Save, watch and compare

### T091: Shared contact preferences and suppression

- [ ] Complete T091.
- **Module / requirements:** `platform` · PLAT-07, MKT-02.
- **Depends on:** T009.
- **Acceptance:** Shared verified contact purpose grants and global/transport suppression exist before watchlist/recovery consumers. GET never changes consent; dedicated signed one-click unsubscribe POST is the narrow cookie-free CSRF exception; other preference edits remain protected.
- **Verify:** Run `php artisan test --filter=MarketingPreferences`; test grant/suppression replay, signed unsubscribe and essential-mail distinction. T056/T057 verify watch integration after their tables exist.
- **Likely files:** `database/migrations/create_delivery_preferences.php`, `app/Domain/Platform/Actions/UpdateDeliveryPreference.php`, `app/Http/Controllers/Storefront/PreferencesController.php`, `themes/default/views/preferences.blade.php`, `tests/Feature/MarketingPreferencesTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T055: Guest and account saved products

- [ ] Complete T055.
- **Module / requirements:** `shopping-tools` · SHOP-01, SHOP-02.
- **Depends on:** T009, T024.
- **Acceptance:** Guest saves persist; login unions variant saves without duplicates; unpublished/archived item state is privacy-safe.
- **Verify:** Run `php artisan test --filter=ShoppingToolsWishlist`; test multiple devices and session logout.
- **Likely files:** `database/migrations/create_wishlists.php`, `app/Domain/ShoppingTools/Actions/SaveWishlistItem.php`, `app/Domain/ShoppingTools/Actions/MergeWishlist.php`, `themes/default/views/wishlist.blade.php`, `tests/Feature/ShoppingToolsWishlistTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T056: Verified watch subscriptions

- [ ] Complete T056.
- **Module / requirements:** `shopping-tools` · SHOP-03.
- **Depends on:** T055, T091.
- **Acceptance:** Stock/price alerts require separate opt-in and verified email; signed unsubscribe acts without account login; saves alone send nothing.
- **Verify:** Run `php artisan test --filter=ShoppingToolsSubscription`; replay verification and unsubscribe tokens.
- **Likely files:** `database/migrations/create_watch_subscriptions.php`, `app/Domain/ShoppingTools/Actions/SubscribeToWatch.php`, `app/Http/Controllers/Storefront/WatchController.php`, `themes/default/src/WatchOptions.vue`, `tests/Feature/ShoppingToolsSubscriptionTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

#### Checkpoint after T091, T055, T056

- [ ] Acceptance and focused verification recorded; no zero-test pass.
- [ ] Relevant build/browser flow passes, including failure, permission and replay cases.
- [ ] Changes documented; pending external-provider evidence remains explicit.

### T057: Stock and price event notifications

- [ ] Complete T057.
- **Module / requirements:** `shopping-tools` · SHOP-04, OPS-01.
- **Depends on:** T056, T006, T019.
- **Acceptance:** Eligible catalog changes trigger deduplicated/capped alerts; unsubscribe and current state rechecked; temporary reservation releases do not alert.
- **Verify:** Run `php artisan test --filter=ShoppingToolsAlert`; duplicate events and unsubscribe-before-send produce correct delivery intents.
- **Likely files:** `app/Jobs/SendWatchAlert.php`, `app/Domain/ShoppingTools/Actions/EvaluateWatchEvent.php`, `resources/views/mail/watch-alert.blade.php`, `tests/Feature/ShoppingToolsAlertTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T058: Typed comparison read model

- [ ] Complete T058.
- **Module / requirements:** `shopping-tools` · SHOP-05, SHOP-06.
- **Depends on:** T017, T024.
- **Acceptance:** Two-to-four selections normalize units and compare shared semantic attributes; incompatible groups expose only common basics.
- **Verify:** Run `php artisan test --filter=ShoppingToolsCompare`; test missing attributes, variant overrides and mixed schemas.
- **Likely files:** `app/Domain/ShoppingTools/Actions/CompareProducts.php`, `app/Domain/ShoppingTools/Data/ComparisonView.php`, `app/Http/Controllers/Storefront/ComparisonController.php`, `tests/Feature/ShoppingToolsCompareTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T059: Comparison and save UI

- [ ] Complete T059.
- **Module / requirements:** `shopping-tools` · SHOP-05, SHOP-06, WEB-06.
- **Depends on:** T055, T058.
- **Acceptance:** Product/card controls manage a compare tray; comparison supports remove/replace/differences with accessible mobile rows.
- **Verify:** Run `npm run test:unit -- --run` and `npm run build`; keyboard-test four products at 360px.
- **Likely files:** `themes/default/src/ProductActions.vue`, `themes/default/src/CompareTray.vue`, `themes/default/src/ComparisonTable.vue`, `themes/default/views/compare.blade.php`, `tests/Browser/comparison.spec.ts`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

#### Checkpoint after T057, T058, T059

- [ ] Acceptance and focused verification recorded; no zero-test pass.
- [ ] Relevant build/browser flow passes, including failure, permission and replay cases.
- [ ] Changes documented; pending external-provider evidence remains explicit.

### T060: Shopping tools integration gate

- [ ] Complete T060.
- **Module / requirements:** `shopping-tools` · SHOP-01, SHOP-02, SHOP-03, SHOP-04, SHOP-05, SHOP-06.
- **Depends on:** T057, T059.
- **Acceptance:** Save→login merge→compare→cart and stock-alert→product journeys work; no customer state leaks between sessions.
- **Verify:** Run `php artisan test --filter=ShoppingTools` and `npm run test:e2e -- --project=chromium`.
- **Likely files:** `tests/Browser/wishlist.spec.ts`, `tests/Browser/comparison.spec.ts`, `tests/Feature/ShoppingToolsIsolationTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

#### Checkpoint after T060

- [ ] Acceptance and focused verification recorded; no zero-test pass.
- [ ] Relevant build/browser flow passes, including failure, permission and replay cases.
- [ ] Changes documented; pending external-provider evidence remains explicit.

## M6: Themes, page composition and template imports

### T061: Theme registry and compatibility checks

- [ ] Complete T061.
- **Module / requirements:** `themes-content` · THEME-01.
- **Depends on:** T010, T024.
- **Acceptance:** Installed manifests validate API/core versions and confined paths; missing assets/incompatible templates fail before activation.
- **Verify:** Run `php artisan test --filter=ThemesContentRegistry`; test traversal, missing asset and incompatible manifest.
- **Likely files:** `app/Domain/ThemesContent/Actions/RegisterTheme.php`, `app/Domain/ThemesContent/Contracts/ThemeRegistry.php`, `database/migrations/create_theme_tables.php`, `tests/Feature/ThemesContentRegistryTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T062: Default storefront layout and system slots

- [ ] Complete T062.
- **Module / requirements:** `storefront` · WEB-01, WEB-02, WEB-03, THEME-01.
- **Depends on:** T054, T060, T061.
- **Acceptance:** Default theme covers public/account/order routes and core checkout slots; public HTML is meaningful without JS; themes cannot replace commerce actions.
- **Verify:** Run `npm run build` and `php artisan test --filter=StorefrontTheme`; browser-check all route types.
- **Likely files:** `themes/default/views/layout.blade.php`, `themes/default/views/home.blade.php`, `themes/default/src/theme.css`, `app/Domain/Storefront/Actions/ComposePage.php`, `tests/Feature/StorefrontThemeTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

### T063: Typed section registry

- [ ] Complete T063.
- **Module / requirements:** `themes-content` · THEME-02.
- **Depends on:** T061.
- **Acceptance:** Built-in blocks define settings/bindings/limits and render only validated data; unknown blocks/fields reject with useful errors.
- **Verify:** Run `php artisan test --filter=ThemesContentSection`; test all built-in schema samples.
- **Likely files:** `app/Domain/ThemesContent/Actions/ValidateSections.php`, `themes/default/sections/definitions.json`, `themes/default/views/sections.blade.php`, `tests/Contract/ThemesContentSectionTest.php`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

#### Checkpoint after T061, T062, T063

- [ ] Acceptance and focused verification recorded; no zero-test pass.
- [ ] Relevant build/browser flow passes, including failure, permission and replay cases.
- [ ] Changes documented; pending external-provider evidence remains explicit.

### T064: Section editor and preview controls

- [ ] Complete T064.
- **Module / requirements:** `themes-content` · THEME-02, WEB-06.
- **Depends on:** T063.
- **Acceptance:** Editors add/reorder/configure allowed blocks and tokens with keyboard controls and mobile/desktop draft preview.
- **Verify:** Run `npm run test:unit -- --run` and `npm run build`; keyboard-test reorder and validation recovery.
- **Likely files:** `resources/js/admin/pages/ContentEditor.vue`, `resources/js/admin/components/SectionList.vue`, `resources/js/admin/components/SectionSettings.vue`, `resources/js/admin/components/DevicePreview.vue`, `tests/Browser/content-editor.spec.ts`.
- **Scope:** Small/medium planned slice; split if actual scope exceeds the sizing rule.

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
