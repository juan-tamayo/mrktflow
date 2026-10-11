# Foundation security boundaries

Implementation begins October 10, 2026. This document records controls and their evidence; it does not certify a production-ready store.

| Boundary / asset | Abuse case | Control and test |
| --- | --- | --- |
| Test runner → database | A test resets merchant or developer data | Exact test database/account allowlist, no cached config or URL override, real grant inspection before schema operations; negative configuration tests. |
| Browser → server | Forged mutations, script injection, clickjacking | Laravel CSRF/origin verification, output escaping, CSP, frame denial, request validation; browser and HTTP negative tests. |
| Anonymous visitor → setup | Someone claims the first owner or replays setup | CLI-generated secret, digest-only storage, short session grant, install row locks and persistent completion lock; no public staff registration. |
| Shopper → administration | A customer becomes staff by registering a matching email | Separate principal tables, session guards and permission policies; deny unauthorized routes server-side. |
| Request → private files | A guessed key or generic storage route leaks downloads | Private disk outside webroot, no generic serving route; authorized delivery comes in the fulfillment milestone. |
| Dependencies → development machine | Install hooks execute unreviewed code | npm ignore-scripts policy; only reviewed Pest plugin allowed in Composer; lockfiles and native audits. |
| App/test processes → external services | Tests email real people or charge a provider | Array mail and provider fixtures; no production credentials in tests or CI. |

M0 implements these boundaries incrementally. Installation, staff/customer authentication and media actions are not considered complete until their own backlog acceptance passes.

## Local database isolation

The development MySQL 8.4 instance uses a private, ignored `.runtime/mysql` data directory and socket, with networking disabled. Development, PHP tests and browser tests have different databases and credentials; each account has grants only for its database. Environment files have mode 0600 and are not tracked. The project PHP wrapper chooses PHP 8.4 without modifying global links.

## Dependency execution policy

First installs used `--no-scripts --no-plugins` for Composer and `--ignore-scripts` for npm. Reviewed Composer hooks are Laravel autoload cleanup/package discovery and `pestphp/pest-plugin` v4.0.0, whose manager generates the local Pest plugin registry. No other Composer plugin is approved. npm lifecycle scripts stay disabled; required native tooling must work from its signed platform package or receive a specific documented review before execution.

Production secrets, customer data and live payment/AI integrations are absent from this foundation. Any later integration must retain the specification’s authorization, validation, redaction and replay rules.
