# Durable work implementation

October 10, 2026: T006 is in progress. The first slice records encrypted, immutable event intent and a snapshot of server-owned consumer bindings in the same database transaction as business changes. Repeated producer intent keys return the original event only when type, schema version and canonical JSON payload agree. Changed intent is rejected. Object/resource payloads, invalid JSON, excessive depth and payloads above 64 KiB are rejected before persistence.

Consumer classes come only from application configuration. The contract permits transactional local database effects; external delivery must use a separately persisted delivery intent. No event payload or arbitrary exception message belongs in diagnostic logs.

Evidence: five focused MySQL tests passed (16 assertions), including surrounding business rollback, encrypted storage and immutable replay. The PHP suite passed 66 tests / 267 assertions; Pint and PHPStan level 8 passed. Frontend type, lint, format, eight unit tests and production build passed. Ten real browser checks also passed. Exact-case intent keys use a binary collation; the regression first failed under the default collation. Producer publication, actual consumption/retries, interrupted-worker recovery, health and correlation propagation are subsequent slices; T006 remains incomplete.

Framework references: [Laravel encryption](https://laravel.com/framework/docs/13.x/encryption) and [Laravel queue transaction behavior](https://laravel.com/framework/docs/13.x/queues#jobs-and-database-transactions). These framework facilities do not by themselves guarantee exactly-once external delivery.

## Local consumer slice

The local consumer runner now locks the event and receipt in a consistent order, verifies encrypted payload integrity/schema, then commits the consumer's database effects and completion receipt atomically. Completed redelivery does nothing. Failure rolls back partial effects, schedules a bounded retry, and quarantines the receipt after six failures. Failure diagnostics contain only event/consumer/correlation identifiers, attempt, exception class and a fixed error code; tests check that the private fixture exception message is absent.

Evidence: ten focused outbox tests (41 assertions); full PHP suite 71 tests / 292 assertions. Pint and PHPStan passed. This slice has no HTTP/UI change. Publication and actual interrupted-worker recovery are still pending; this runner currently serves application-internal callers only.

## Database publication and worker interruption

Recording intent now tries publication after commit. If publication fails, committed intent remains recoverable and a redacted diagnostic identifies the event. The dedicated MySQL outbox queue inserts an encrypted ID-only job and a 90-second receipt lease in one transaction; overlapping publication does not create another job while that lease is active. Recovery scans bounded batches of due pending receipts. The regular database queue dispatches after commit; the dedicated long-work connection reserves for 360 seconds.

Evidence: 15 focused outbox/worker tests (63 assertions), full PHP suite 76 tests / 314 assertions, Pint and PHPStan passed. The worker rehearsal starts a real isolated-test worker, pauses a fixture consumer after its database insert but before commit, kills it with SIGKILL, verifies no committed effect, expires only that fixture's reservation/lease, republishes and restarts the worker. Exactly one audit effect and completed receipt remain; the abandoned reservation becomes one framework failed job. Fixture consumers are never registered in production configuration. This proves process-interruption recovery locally, not hosted/provider delivery or exactly-once SMTP.

Health, scheduler wiring, request correlation and worker runbook are still pending T006 work.

## T006 completion evidence

Local T006 acceptance now passes: scheduler heartbeat/recovery commands, private health and bounded failed-receipt listing, explicit failed-only retry, separate worker lane wrappers, bounded TLS-verifying HTTP client, request correlation on success/errors, and minimal setup-settings audit intent are implemented. Completed receipts cannot be reopened. Application exception diagnostics omit raw messages/trace arguments. The outbox retry delays are 10/30/120/300/900 seconds for six total application attempts.

Final local evidence: 83 PHP tests / 350 assertions passed, including killed-worker recovery, stale heartbeat, due recovery, exhausted/reopened receipt safety, correlation and private diagnostics. Pint and PHPStan level 8 passed. Frontend type/lint/format, eight unit tests and production build passed earlier in this task; no frontend source changed. Ten browser tests passed with the new middleware and setup audit integration. The real minute scheduler was run after migrations on the guarded isolated test database; the follow-up health command reported a current heartbeat and zero pending/failed work. Worker wrapper shell syntax passed.

The migration reversal test now uses Laravel's DatabaseMigrations lifecycle so it cannot leave a prior RefreshDatabase test's cached migration state pointing to dropped tables. This was reproduced as a full-suite failure and fixed without weakening the database guard.

[Background work runbook](background-work.md) contains supervisor/cron examples and recovery controls. Hosted CI/coverage remain pending T004b; deployment supervision and actual external delivery are not claimed. Owner/management access remains closed pending T008. T007 media storage is next.
