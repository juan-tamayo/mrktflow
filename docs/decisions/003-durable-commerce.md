# ADR-003: Durable commerce state on MySQL

Status: Proposed · Date: 2026-10-05.

## Context

Concurrent stock purchases, webhook replay, provider timeouts and refunds can lose money if treated as ordinary form CRUD.

## Decision

Use MySQL 8.4/InnoDB, integer currency-aware amounts, immutable order snapshots, explicit state machines, transactional stock locks, unique idempotency/event keys, an inbox/outbox and reconciliation jobs. Start with database queue drivers.

## Alternatives

PostgreSQL is a valid relational alternative but supporting two databases multiplies concurrency/migration checks. SQLite does not validate the chosen production locking behavior. Cache-only idempotency and synchronous webhooks leave crash/retention gaps. A broker is unnecessary for the first deployment profile.

## Consequences

Financial flows need more explicit records than basic CRUD. Test on MySQL; do not infer production locking correctness from in-memory SQLite tests. Redis can be added later without becoming the durable record of payments or stock.
