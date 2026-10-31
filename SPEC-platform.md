# Module: platform

Objective: a repeatable, secure installation and the shared runtime primitives needed by every feature. No domain dependency. Inherits [engineering conventions](docs/architecture.md), [commands](docs/coding-handoff.md), and [quality requirements](docs/quality-and-operations.md).

## Requirements

- **PLAT-01:** Installer checks PHP/extensions, database transaction capability, writable directories, TLS/base URL, private storage, scheduler/queue and mail configuration. Failures explain a concrete remedy.
- **PLAT-02:** First-owner creation requires an installation secret generated through the server/CLI. Setup is not a publicly claimable admin account. Completion persists an install lock; reopening requires authenticated owner/CLI recovery.
- **PLAT-03:** Settings include store name, locale, timezone, currency, contact details, allowed sales countries, units, policies and email sender. Secrets are encrypted, masked, excluded from client responses and separately documented for recovery.
- **PLAT-04:** Images and private files use separate storage policies. Validate actual MIME, size/dimensions, ownership and processing state; default SVG upload disabled. Originals never execute. Failed processing remains a draft error.
- **PLAT-05:** Durable outbox, queue retry/dead-letter handling, scheduler heartbeat, audit events and correlation IDs are available early. A failed email must not reverse a paid order.
- **PLAT-06:** Development fixtures use fictional customers, a physical product, a digital product and a mixed cart. Production installation does not silently seed sample customer data.
- **PLAT-07:** Shared delivery-preference primitives record verified contact identity, purpose grants/revocations and global/transport suppressions. Marketing and watchlist features consult one suppression policy; signed unsubscribe/provider feedback replays are idempotent and cannot silently re-enroll a contact.

## Boundaries and verification

Own installation/settings/media/event primitives; never implement product or payment rules here. Verify a fresh install, interrupted resume, replayed setup request, absent worker, failed storage write and redacted diagnostic export. Shared command suite plus targeted `php artisan test --filter=Platform` once implemented. Success: first-owner setup cannot be stolen/replayed and background failures are visible/recoverable.

T091 implements the PLAT-07 delivery-preference primitives: verified contacts, purpose-scoped grants/revocations with provenance, and global/transport suppressions behind one `DeliveryPolicy` that marketing consumers must consult while essential transactional templates stay unblockable; the signed one-click unsubscribe POST is the single cookie-free CSRF exception and every replay is idempotent (`bin/php artisan test --filter=MarketingPreferences`).
