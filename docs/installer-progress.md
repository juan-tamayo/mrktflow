# Protected installer progress — October 10, 2026

T005a–T005c now implement and locally verify protected setup through the pending-owner state. T008 owns initial-owner creation and the final installation lock. Hosted CI evidence remains pending T004b; this local completion does not satisfy that remote gate. This work does not open administration or selling.

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

## Protected HTTP and Vue setup

`GET /setup` returns 404 until a valid CLI secret exists, then displays only the unlock form. `POST /setup/access` validates the secret, rotates the session, stores the short-lived grant server-side and redirects to setup. It permits five attempts per minute per client. No HTTP route issues secrets. Setup-secret and settings input are excluded from validation flash data, and the Vue secret form explicitly excludes its value from remembered form state and clears it after each attempt.

`POST /setup/settings` uses only the server session grant, rechecks it under the installation row lock, validates the version/settings aggregate, and redirects to a saved notice. Settings, region choices and prerequisite reports are returned only after authorization. Country selections use a shared ICU-backed choice/validation boundary and canonical ordering so changing selection order is not a different intent. The UI uses searchable checkboxes on phones and retains selected countries during filtering.

Expired/revoked grants remove session access and return the browser to unlock. Stale browser edits show a recoverable error and a link to reload current values; they do not silently advance the form's version. JSON clients retain HTTP 403/409 semantics. A same-payload retry returns the current version. Completion hides setup and denies mutations even if an old grant is replayed.

The complete suite now passes 61 PHP tests, 8 Vue tests and 10 Chromium checks. Type/lint/format checks, PHPStan level 8 and production build pass. Browser tests exercise locked setup, real CSRF rejection, incorrect-secret recovery, unlocking, settings save and reload, competing browser-tab edits (including repeated stale saves and explicit reload), error-summary focus, phone overflow and WCAG A/AA scans. Vue regressions cover restored reactive data, re-unlock restoration, blocked prerequisites and country selection. The mobile screenshot was inspected locally; no real owner or production secret was created.

The browser harness issues a synthetic secret only in the guarded `mrktflow_browser` environment and stores it in an ignored private fixture file. The file and traces must never be published. CI disables traces and uploads only public-page screenshots. The general private-path smoke test now checks the absent `/setup/secret` route; `/setup` has become the intentionally secret-gated setup screen.
