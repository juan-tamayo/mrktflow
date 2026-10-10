# Module: operations

Objective: merchants can operate, diagnose, export, update and recover their own store. Dependencies listed in [capability map](CAPABILITY-MAP.md). Inherits [quality and runbook requirements](docs/quality-and-operations.md) and [commands](docs/coding-handoff.md).

## Requirements

- **OPS-01:** Transactional email for verification/reset, order receipt, shipment, refund, download availability and subscribed watch alerts; preview/test sender; deduplicated intent and visible delivery failures. No unsolicited marketing subscription.
- **OPS-02:** Dashboard reports paid orders, gross merchandise sales, merchandise discounts/refunds, net merchandise sales, tax, shipping and average order value with documented definitions and store-timezone ranges. Exclude test/unpaid orders; show refunds on refund date and offer order-cohort detail separately.
- **OPS-03:** Customer/order/product CSV export is permission-gated, audited, formula-safe and time-limited. Export jobs use private storage and expiry, not public permanent URLs.
- **OPS-04:** Owner diagnostics show application version, disk usage, queue/scheduler heartbeat, pending payment reconciliation, failed jobs, email readiness, storage and backup freshness. Sanitized support export contains no secrets/customer payloads.
- **OPS-05:** Publish installation, update, rollback and restore guides; build reproducible release packages with compiled assets, dependency lockfiles, checksums and compatibility metadata. Store data and custom themes survive upgrades.
- **OPS-06:** Database plus public/private assets plus encryption-key recovery procedure are backed up; restore is rehearsed on isolated infrastructure with outgoing mail/payments/AI disabled until reconciliation.
- **OPS-07:** Distribution includes chosen license, dependency license inventory, security reporting instructions, changelog, support policy and contributing guide before public release.
- **OPS-08:** Marketing reports distinguish delivery/qualified-click/paid-conversion states and show recovery, coupon cost, referral qualification and net sales without adding overlapping attributed orders twice. Diagnostics expose recovery lag, suppression/feedback health, reward reconciliation and hub/feed freshness; restore suppresses outbound marketing and reward issuance until reconciled.

## Verification

Reconcile reports with captured/refunded ledger fixtures, simulate queue/provider failures, verify export ownership/expiry, and rehearse clean installation, N−1 upgrade and restore. Target `php artisan test --filter=Operations`; deployment rehearsal evidence is required and cannot be replaced with unit tests.
