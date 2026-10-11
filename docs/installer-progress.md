# Protected installer progress — October 10, 2026

T005a implements the backend foundation for protected setup. T005 remains incomplete until the protected HTTP/session UI and replay/resume browser checks land; T008 owns initial-owner creation and the final installation lock. This work does not open administration or selling.

## Contract and controls

- `bin/php artisan setup:check` checks PHP 8.4/extensions, base URL/TLS, application key, MySQL/InnoDB, private storage outside the web root and writable runtime directories. Required failures block settings saves. Queue/mail/scheduler launch readiness is reported separately; configuration alone is not evidence of a healthy worker or successful email delivery.
- `bin/php artisan setup:secret` issues or rotates a 256-bit secret after required prerequisites pass. The CLI prints it intentionally; do not redirect it into published logs or put it in a URL. Only its SHA-256 digest is stored. Secrets expire after 24 hours; every rotation invalidates previous secrets and grants.
- The secret can authorize a maximum 15-minute setup session, bounded by the secret expiration. The session grant contains generation and expiry and must remain server-side. Future HTTP controllers must obtain it from the encrypted session, never request fields.
- Settings saves lock the single installation row and recheck access inside the transaction. A saved settings aggregate has an optimistic version. Identical retry payloads return the existing version; conflicting changes at an old version fail with 409. Saving moves installation to `pending_owner`, without creating staff or marking completion.
- Settings validate name, currency, supported locale, timezone, contact/sender, an explicit sales-country allowlist, units and five plain-text policies. Unexpected fields, including provider secrets, are rejected. Service credentials need their own encrypted/masked settings contracts later.
- Completed installations refuse secret issuance, unlock and settings mutation. T008 must create the initial owner and set the completion timestamp/status in one transaction while holding the same installation lock. Recovery of an installed owner's account must not reopen anonymous setup.

## Evidence

The new 18-case installer suite covers digest-only persistence, missing/invalid/expired secrets, revoked grants, short expiry, locked installations, atomic settings and retry/version behavior, invalid values/unknown fields, blocked prerequisites, CLI-only issuance and forged/locked writes. The complete PHP suite passes 52 tests with 153 assertions. PHPStan level 8 and formatting checks are required alongside frontend/browser regressions. The local development database migration and `setup:check` ran successfully without issuing or publishing a development installation secret.

The full goal remains the entire specified platform. Protected UI, identities, outbox/media primitives, all commerce/theme/marketing features, provider evidence and release rehearsals remain in the backlog. Hosted CI remains unverified until a repository remote exists.
