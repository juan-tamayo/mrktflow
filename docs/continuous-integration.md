# Continuous integration

`.github/workflows/quality.yml` runs on pull requests, main pushes and codex branches. It has read-only repository permissions, disables persisted checkout credentials, pins action revisions and the MySQL image digest, installs lockfiles, and executes formatting, static analysis, PHP/MySQL tests, Vue tests, production build, browser/accessibility tests, vulnerability audits and registry-signature verification. It does not deploy or call live providers.

PHP 8.4, MySQL 8.4, Node 22.23.3 and bundled npm 10.9.9 are the runtime matrix. PHP uses PCOV for coverage. The pinned MySQL image can carry a later 8.4 patch than local 8.4.5; the guard enforces the 8.4 series. Action updates must be reviewed rather than silently following mutable tags.

The job creates a fresh random, masked root password for a disposable container bound only to localhost. Its provisioning helper refuses non-CI use, existing environment files and cached configuration. It creates separate development/PHP/browser databases with random database-only account credentials, writes private environment files, and removes the container on completion. These are ephemeral CI secrets; no hardcoded reusable credentials or merchant secrets are required. Never provide production credentials to this job.

## Coverage gate

The specification requires at least 90% line coverage for money, inventory, payment orchestration and entitlement authorization. `scripts/coverage-gate.php` checks Pricing, Catalog, CheckoutOrders and Fulfillment independently, including their whole domain directories to avoid filename exemptions. Missing reports or PHP files omitted from the report fail the gate. Those domain implementations do not exist in this foundation; the gate explicitly reports them as not applicable rather than claiming coverage. Threshold regression tests verify 89% fails and 90% passes. Coverage does not replace required race, replay and authorization tests.

Only public-page failure screenshots are uploaded, retained seven days. CI disables browser traces; reports/cookies/environment/log files are not uploaded. Revisit screenshot publication before browser tests contain personal information.

## Verification status — October 10, 2026

Workflow YAML parses and contains all ten intended steps. The same application quality commands passed locally; the provisioning helper's non-CI rejection was verified. PHP tests and Vue tests reject zero selected cases, an intentionally failing PHP assertion was detected, an incompatible PHP return type was detected by PHPStan, and the browser suite reported its initial failure before the launcher/heading fixes.

No remote repository is configured. The hosted runner, container provisioning, PCOV report and hosted signature verification have **not run**. A successful hosted Quality job and required branch checks remain mandatory once a repository host is connected. Branch protection cannot be configured locally. The local PHP runtime has no coverage extension; coverage percentages are not claimed from that runtime.
