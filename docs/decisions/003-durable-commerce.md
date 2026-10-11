# ADR-003: Durable commerce state on MySQL

Status: Accepted for foundation; provider integration pending · Date: 2026-10-10.

## Context

Concurrent stock purchases, webhook replay, provider timeouts and refunds can lose money if treated as ordinary form CRUD.

## Decision

Use MySQL 8.4/InnoDB, integer currency-aware amounts, immutable order snapshots, explicit state machines, transactional stock locks, unique idempotency/event keys, an inbox/outbox and reconciliation jobs. Start with database queue drivers.

## Alternatives

PostgreSQL is a valid relational alternative but supporting two databases multiplies concurrency/migration checks. SQLite does not validate the chosen production locking behavior. Cache-only idempotency and synchronous webhooks leave crash/retention gaps. A broker is unnecessary for the first deployment profile.

## Consequences

Financial flows need more explicit records than basic CRUD. Test on MySQL; do not infer production locking correctness from in-memory SQLite tests. Redis can be added later without becoming the durable record of payments or stock.

## T011 evidence and limits

The payment boundary now separates session creation/retrieval/expiry and refund creation/retrieval. Immutable intents provide stable operation keys; bound evidence rejects amount/currency/reference/account/environment mismatches. Unknown outcomes hold reservations and refund allocations. Only reconciled expired/non-payable sessions can release stock; complete/paid requires a capture reference. An observation does not itself transition an order or allocate stock.

A real MySQL 8.4 spike on test-only synthetic tables proves serialization of the last unit across two independent connections, and atomic rollback of stock and reservation changes. This supports the chosen locking architecture without prematurely introducing catalog/order schema. It does not prove multi-line lock ordering, durable reservation recovery or provider idempotency. Those remain later acceptance requirements. No real provider sandbox evidence exists yet; T035 cannot be accepted without it. Detailed operation/reconciliation contracts are in [commerce rules](../commerce-rules.md#paymentreservation-spike-t011).
