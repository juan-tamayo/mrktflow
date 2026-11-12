# Hosting

Supported reference-host profile for a single-store MRKTFlow installation. This is the operator contract for [OPS-05](../SPEC-operations.md), [PLAT-01](../SPEC-platform.md) and the performance baseline in [quality and operations](quality-and-operations.md). It is not a proven capacity claim.

## Supported host

- Linux VPS or container host that the store owner controls. Shared hosting is supported only when it meets every runtime, process, TLS and permission requirement below. There is no compatibility promise for arbitrary PHP shared hosting.
- PHP 8.4 with PDO MySQL, OpenSSL, mbstring, intl, fileinfo, GD, zip and DOM. Use the project wrappers (`bin/php`, `bin/composer`, `bin/npm`) so a global 8.3 runtime is never selected by accident.
- MySQL 8.4 with InnoDB and `utf8mb4`. One dedicated application database. Database-only accounts: no `GLOBAL` grants, no `GRANT OPTION`, and `partial_revokes=ON` before creating users whose names contain underscores.
- TLS termination in front of the application. Production `APP_URL` is `https://` with no credentials, query string or fragment. Loopback HTTP is allowed only for local development and tests.
- The web server serves **only** Laravel's `public/` directory. Application source, `.env`, `storage/`, `vendor/` and `bootstrap/cache` stay outside the document root.
- Persistent local disks: public image derivatives under `storage/app/public` (linked as `/storage` after `php artisan storage:link`) and private originals under `storage/app/private`. The private disk must remain outside the web root with generic file serving disabled (`FILESYSTEM_DISK=local`, `serve` false).
- Supervised queue workers and a once-per-minute scheduler. Configuration alone is not evidence that those processes are healthy; owners confirm `/admin/health` and `operations:health` after first boot.
- Outbound HTTPS for Stripe webhooks/Checkout and a reviewed SMTP sender before opening sales. Tests and this runbook use the array mailer only. Do not send real mail while rehearsing.

Unsupported in this profile: Windows as the production application host, PHP 8.3/8.5, MySQL 5.7/8.0/9 as the store database, SQLite, Redis-required topologies, multi-tenant SaaS, and serving the repository root as the web root.

## Services

| Service | Requirement |
| --- | --- |
| PHP-FPM / `php artisan serve` | PHP 8.4; production uses PHP-FPM or an equivalent process manager, not `artisan serve`. |
| Web server | nginx or equivalent; TLS; document root `public/`; deny `.env` and `storage/app/private`. |
| MySQL 8.4 | Isolated store database; least-privilege user; InnoDB. |
| Queue worker (interactive) | `bin/php artisan queue:work database --queue=payments,default,mail --tries=3 --timeout=60` with retry-after 90 seconds. |
| Queue worker (outbox / mail) | `bin/php artisan queue:work database-outbox --queue=identity-mail,transactional-mail,marketing --timeout=60 --tries=1`. |
| Queue worker (long work) | `bin/php artisan queue:work database_long --queue=ai,media,hubs,marketing --tries=3 --timeout=300` with retry-after 360 seconds. |
| Scheduler | `bin/php artisan schedule:run` every minute (production cron or `schedule:work` only on a development host). |
| Node 22.23.x | Required to compile assets on a source checkout (`bin/npm ci --ignore-scripts` and `bin/npm run build`). A later packaged release (T085) is expected to ship compiled assets so a merchant host does not need Node. |
| Stripe | Test-mode Checkout credentials for sandbox purchases. Live keys are an explicit later switch (`STRIPE_LIVE=false` until then). |
| SMTP | Reviewed SMTP with certificate validation before customer mail. `MAIL_MAILER=array` discards mail and is the committed example default; `log` is refused for credentials. |

Do not run developer Vite (`bin/npm run dev`) on the reference host. Browser and production checks use built assets in `public/build`.

## Permissions

- Application Unix user owns `storage/` and `bootstrap/cache` and can write views, cache, logs, public derivatives and private originals. The web worker user is that same application user or a tightly grouped equivalent; it must not be root.
- `.env` mode `0600`, untracked, never copied into images or git. `APP_KEY` is generated once per environment and preserved across upgrades; a lost key makes encrypted settings, setup grants and stored addresses unrecoverable.
- Database user: `GRANT` only on the store schema. Tests use separate `mrktflow_test` / `mrktflow_browser` databases and must never point at the store schema. Do not bypass `TestDatabaseGuard` or substitute SQLite.
- Private files: no generic `/storage/...` route for the local private disk; digital originals use opaque keys. Anonymous `GET` of those keys must 404. Authorized download is fulfillment, not a public file server.
- Staff administration is server-authorized. UI hiding is not an access control. First-owner setup requires a CLI installation secret (PLAT-02). Staff 2FA (TOTP) cannot be bypassed.
- Reverse-proxy trust is only for the documented single ingress. Production forces `APP_DEBUG=false`, encrypted sessions and secure cookies.

## Reference machine

Suggested starting test machine from [architecture](architecture.md): **2 vCPU, 4 GB RAM, SSD**, Linux, with the services above. Performance targets in [quality and operations](quality-and-operations.md) are measured on this class of host with a large catalog; an empty-database install rehearsal does not satisfy those targets.

Use this host for:

1. Clean install and first-owner setup.
2. Physical, digital and mixed sandbox purchases (test-mode Stripe or local zero-total `FREE100` fixtures while live credentials are absent).
3. Private-file denial checks.
4. Scheduler/worker health after processes are supervised.

Upgrade, rollback, backup restore and a merchant-timed 30-minute stopwatch on this VPS remain later operations tasks (T085–T087) when those rehearsals run.
