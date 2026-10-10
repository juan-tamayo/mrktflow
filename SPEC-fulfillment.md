# Module: fulfillment

Objective: ship physical goods and deliver purchased files with separate, accurate lifecycle controls. Depends on platform, identity, catalog and checkout-orders. Inherits [commerce rules](docs/commerce-rules.md), [data model](docs/data-model.md), and [commands](docs/coding-handoff.md).

## Requirements

- **FUL-01:** Staff selects paid unfulfilled physical quantities and records carrier/tracking/manual tracking URL. Validate URL scheme/domain policy and prevent overshipment; partial/multiple shipments supported.
- **FUL-02:** Shipment creation atomically consumes the correct stock allocation and emits notification intent. A retried request cannot decrement stock twice. Cancellation of a shipment record does not automatically restock goods already dispatched.
- **FUL-03:** Return records distinguish approval, receipt and restock from refunds. Explicit quantities and reasons; returned stock is added exactly once.
- **FUL-04:** Currently eligible paid digital lines issue unique version-pinned entitlements; stale paid-event replay after refund cannot grant access. Eligible lines show downloads in verified guest/customer order areas. Mixed orders unlock digital access independently of physical shipping.
- **FUL-05:** Private files require current entitlement authorization and bounded single-use download grants with a five-minute start window and separately authorized one-hour resumable transfer. Refund/dispute/expiry rules revoke or suspend future access; download counters are concurrency-safe.
- **FUL-06:** Staff can grant a purchased-file update or reset remaining grants with reason and audit; cannot expose the storage bucket/key or create an unlogged permanent public URL.

## Verification

Test partial shipment/refund/return combinations, parallel shipment clicks, private-file guessing, token reuse, refunded ownership, range resume, large streaming transfers and asset version replacement. Target `php artisan test --filter=Fulfillment`; browser digital delivery and staff shipping flow.
