# Quality, security and operations requirements

Proposed release bar, not measured results. Changes to this bar must be explicit in the spec and implementation review.

## Functional and test gates

| Layer | Required evidence |
| --- | --- |
| Domain | Deterministic money/discount/tax/refund fixtures and invariants; inventory transitions; schema/type validation |
| Integration | Real MySQL locking/concurrency; transaction rollback/outbox; auth policies; private downloads; provider/event replay |
| Provider contract | Recorded synthetic fixtures for payment and AI schemas, timeouts and unknown outcomes; sandbox payment reconciliation |
| Components | Variant selection, form errors, compare rows, editor conflicts and AI draft review |
| Browser | First owner; physical/digital/mixed purchase; guest/account merge; partial fulfillment/refund; download revocation; theme/AI publication |
| Operations | Fresh package install, N−1 upgrade, worker interruption, stale webhook recovery, backup restore with side effects disabled |

Target at least 90% line coverage in money, inventory, payment orchestration and entitlement authorization domain code; tests must assert business outcomes, boundaries and negative cases. Coverage is secondary to required race/replay/authorization scenarios. Do not create meaningless tests to reach a percentage or suppress failures. Other changes need focused tests proportionate to behavior and risk.

CI target: format checks, static analysis (PHPStan level 8 with no new baseline suppressions), TypeScript strict checks, lint, domain/integration/component suites, production build and critical browser smoke. Full cross-browser and deployment rehearsal run before a release. Dependencies are locked; security findings are triaged, and unresolved exploitable critical/high findings block release.

T003a establishes PHP test/static/format commands; T004a establishes isolated browser tests; T004b establishes CI and its coverage runtime. These tools are planned, not installed. Add each capability’s required tests as it lands, reject zero-selected-test passes, and keep merchant/provider production secrets out of CI.

## Performance targets

Measure on the documented 2 vCPU/4 GB SSD reference deployment with 10,000 products, 50,000 variants and 100,000 historical orders. Record cold/warm cache and query plans; do not report only empty-database numbers.

- At 20 catalog requests/second and 2 checkout mutations/second over 10 minutes, public read p95 application latency ≤500 ms and checkout p95 application time ≤1 second excluding measured external-provider wait. No inventory/payment correctness failures.
- Default storefront mobile target: LCP ≤2.5 s, CLS ≤0.1, INP ≤200 ms at the 75th percentile once field data exists. Before launch, run repeatable mobile lab tests on reference product/category/cart pages and label them as lab results, not field proof.
- Public product page JavaScript budget ≤200 KB gzip initially, excluding provider-hosted payment. Responsive images and dimension attributes required; avoid loading the whole admin bundle publicly.
- Queue normal-operation target: paid-order entitlement/receipt intent processed within 60 seconds; watch alerts within 5 minutes of eligible change. Surface degraded queue state instead of silently promising instant processing.
- Load and streaming tests must confirm private downloads do not exhaust PHP memory. Media imports use bounded batch work with resumable error reports.

## Accessibility

Default theme/admin target [WCAG 2.2 AA](https://www.w3.org/TR/WCAG22/). Test keyboard-only navigation, focus order/traps, dialogs, error summaries, accessible names, contrast, zoom/reflow and reduced motion. Automated checks supplement manual screen-reader tests on checkout, account downloads and content editor. Third-party themes declare tested compatibility; the platform cannot certify arbitrary custom code automatically.

## Security requirements

- Threat-model privileged template code, imported archives, rich text, private files, webhooks, refunds, guest-order links and AI context. Include abuse fixtures before those features ship.
- Parameterized queries, validated types, escaped output/sanitized rich text, CSRF-protected browser mutations, authenticated object-level authorization, rate limits and session rotation.
- Externally reachable webhooks verify raw-body signatures and environment/account bindings. No generic unauthenticated webhook action dispatcher.
- File uploads use size/MIME/dimension checks, opaque storage keys, non-executable paths and quarantine status. Archive traversal/symlink/decompression-bomb protection. Distribution profile documents an optional malware scanner and whether scanning is enabled; never label an unscanned upload “scanned.”
- AI/import previews do not execute source strings. No `eval`, Vue runtime compilation of untrusted strings, arbitrary shell commands, unbounded tools or unrestricted remote URL fetch.
- Store secrets in environment/encrypted settings, redact logs and support bundles. Protect the application key and document rotation/recovery; a lost key may make encrypted settings unrecoverable.
- Default security headers and CSP compatible with compiled themes and the payment redirect. No inline executable merchant content. HTTPS required for production.
- Protect exports, downloads, previews and account pages with private/no-store caching. Audit log records actor/action/target/outcome and redacted changes; never full payment tokens or passwords.

## Diagnostics and observability

Structured logs include request/correlation ID, opaque order/attempt/job IDs, provider, outcome, latency and redacted error code. Metrics: checkout success/failure, pending/unknown payment age, stock conflicts, webhook lag/failures, queue age, entitlement failures, notification failures, AI budget usage, upload/storage failures.

Owner health page highlights an unavailable worker, missed scheduler (>3 minutes), unresolved payment (>10 minutes), repeated job failure, backup older than configured policy and near-full disk. Exact operational thresholds are configurable and documented. No telemetry leaves the installation unless explicitly enabled; core commerce never requires a vendor telemetry endpoint.

## Install and update runbook requirements

1. Verify package checksum and supported runtime; provision TLS/database/storage/worker/scheduler. Serve only Laravel's public directory. Keep debug off in production.
2. Set application environment/key and credentials, run migrations, create installation secret, complete owner setup, configure sender/provider rules and perform sandbox physical/digital/mixed orders.
3. Check private-file denial, scheduler/queue health, policy pages, country/tax/payment availability and backup freshness before opening sales.
4. Before an update, capture database and public/private asset backups and version metadata; test the package and migrations in staging. Custom code/themes are checked for compatibility.
5. Use additive/expand-contract migrations where practical. Quiesce checkout/workers when a migration requires it; keep verified provider events durably receivable or rely on tested provider retries. Never drop payment events during maintenance.
6. Deploy release, migrate, refresh caches/assets, restart workers, run smoke checks and verify delayed jobs/reconciliation. Failed deployment rolls back code only if schema-compatible; otherwise restore or forward-fix under the documented plan.

## Backup and recovery

Reference policy target: encrypted off-host database/assets backup at least daily (RPO ≤24 hours) and documented RTO ≤4 hours on the reference deployment. These are goals to measure in rehearsal, not guarantees. Faster database point-in-time recovery is an optional operator profile.

Back up application version/lockfiles, database, public media, private digital assets, custom themes, storage mappings and recoverable encryption-key material through a separate protected procedure. A database-only backup is insufficient.

Restore into an isolated environment with outbound email, payment/refund mutations and AI disabled. Compare provider transactions since the recovery point with restored local payment/order records before enabling mutations. Rebuild projections/search, check entitlements/file hashes, reconcile stock against paid orders and confirm dedup history. Do not replay money-moving jobs blindly from an old backup. Only then resume checkout/workers and record the recovery evidence.

## Marketing expansion verification

- Payment confirmation/opt-out races suppress recovery at the final dispatch check; email already accepted cannot be recalled. Duplicate episodes/jobs generate one send/coupon intent; frequency caps span episodes. Scanner GET cannot restore a cart or change preferences.
- Promotion stacking and integer discount/tax/refund allocations pass physical/digital/mixed fixtures; concurrency cannot exceed per-contact/program budgets, issue multiple first-order offers or grant duplicate referral rewards.
- Referral refund/dispute scenarios cover pre-qualification, pending reward, unused issued coupon and already-used reward; no automatic money debit or order rewrite.
- Reviews verify ownership and moderation history; low-volume/private/false proof stays absent. Fake browser conversions do not change revenue/reward facts. Countdown expiry and stock freshness survive reload, cached HTML and clock skew.
- Toasts/signup/referral/recovery flows pass keyboard, screen-reader, reduced-motion and 360px reflow checks. Combined public JavaScript still meets the existing 200 KB gzip budget; marketing must not block public rendering or payment queues.
- Hub generation is bounded and duplicate-safe. Stale catalog jobs cannot publish thin/empty pages; canonical, pagination, sitemap, visible schema and feed fixtures agree. Manual rich-result validation is recorded separately from local validation; no ranking/indexing guarantee.
- Marketing/contact exports obey ownership/retention; feedback signatures and all-purpose suppression are tested. Unconsented persistent tracking is absent. SMTP feedback limitations are shown honestly.
- Restore/replay runs with outbound campaigns and reward issuance disabled; reconcile existing sends/rewards/coupon redemptions before restarting to prevent a backlog burst or duplicate grant.

## Launch checklist

- [ ] All mandatory module criteria and reference journeys pass.
- [ ] Market/payment/tax/digital-sales requirements resolved; unsupported destinations/methods disabled.
- [ ] Correct license selected and included; dependency license inventory and security reporting route published.
- [ ] Tests/static checks/build and required browser/accessibility/performance evidence recorded.
- [ ] Fresh install, upgrade and isolated restore rehearsed; backup/worker/scheduler health demonstrated.
- [ ] Payment/refund unknown-outcome and late-webhook playbooks exercised.
- [ ] Default theme, installation guide, template developer guide and AI/import limits are accurate.
- [ ] Recovery, broadcasts, coupon/referral qualification, review/proof and hub SEO journeys pass; sender/suppression and budget policies configured.
- [ ] New hub/coupon/referral tables and revisions survive release-package upgrade/restore; marketing load respects performance budgets and payment priority.
- [ ] Maintainer/release/support ownership assigned; no unimplemented capability shown as operational.
