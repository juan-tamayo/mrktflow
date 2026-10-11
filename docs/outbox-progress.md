# Durable work implementation

October 10, 2026: T006 is in progress. The first slice records encrypted, immutable event intent and a snapshot of server-owned consumer bindings in the same database transaction as business changes. Repeated producer intent keys return the original event only when type, schema version and canonical JSON payload agree. Changed intent is rejected. Object/resource payloads, invalid JSON, excessive depth and payloads above 64 KiB are rejected before persistence.

Consumer classes come only from application configuration. The contract permits transactional local database effects; external delivery must use a separately persisted delivery intent. No event payload or arbitrary exception message belongs in diagnostic logs.

Evidence: five focused MySQL tests passed (16 assertions), including surrounding business rollback, encrypted storage and immutable replay. The PHP suite passed 66 tests / 267 assertions; Pint and PHPStan level 8 passed. Frontend type, lint, format, eight unit tests and production build passed. Ten real browser checks also passed. Exact-case intent keys use a binary collation; the regression first failed under the default collation. Producer publication, actual consumption/retries, interrupted-worker recovery, health and correlation propagation are subsequent slices; T006 remains incomplete.

Framework references: [Laravel encryption](https://laravel.com/framework/docs/13.x/encryption) and [Laravel queue transaction behavior](https://laravel.com/framework/docs/13.x/queues#jobs-and-database-transactions). These framework facilities do not by themselves guarantee exactly-once external delivery.
