# Identity implementation progress

## T008 backend account and invitation slice — October 10, 2026

T008 remains open. No staff login, owner form, invitation delivery/acceptance screen, or staff management route is exposed by this slice. Administration remains closed. Customer accounts, staff 2FA and sensitive-action reauthentication belong to later slices and remain launch requirements.

Implemented:

- Independent UUID staff principals and a fixed staff session provider. Session storage accepts UUID principals; rollback discards volatile sessions rather than converting UUIDs into numeric identities.
- Atomic first-owner creation and installation completion. The platform holds its installation lock while the application supplies the identity write callback; platform never imports identity. Invalid credentials or extra role/permission fields roll back the owner, lock and audit intent together. Successful creation revokes setup secrets and grants permanently.
- Server-owned permission registry and owner/manager/content-editor/fulfillment presets. Gates reject inactive staff, unknown abilities and other principal classes even when email or role fields match. Customers will receive their own table and guard in T009.
- Staff access changes re-read the actor under a shared security row lock, protect the final active owner, and increment a session version while clearing remember tokens. Session-version enforcement comes with the login middleware; this slice does not claim existing HTTP sessions are already invalidated.
- Invitations bind one canonical email and an explicit preset permission snapshot. Secrets have 256 bits of randomness, only SHA-256 digests persist, expiry is 48 hours, and acceptance consumes the token atomically with account creation. Reissuing revokes prior unused invitations. Acceptance rechecks the inviter's current staff-management permission. Possession alone does not mark email verified.
- Passwords use the configured Laravel bcrypt hasher, minimum 12 characters and maximum 72 bytes to prevent silent bcrypt truncation. Confirmation is required. No generic signup or password-reset broker is enabled.
- Security audits record UUID subjects, actors and session version only; no email, password or invitation secret enters audit metadata. Invitation JSON serialization excludes the one-time delivery secret.

Verification:

- First-owner tests failed before implementation; permission/access and invitation tests separately failed before their implementations.
- `bin/php artisan test`: **112 passed / 531 assertions**, including eight identity tests for rollback, replay, grant expiry, injected permissions, password byte limits, foreign principals, role permissions, final-owner protection, stale actors and expired/reissued/revoked invitations.
- PHPStan level 8 passes without suppressions or baseline. Cast-method source analysis is enabled so Eloquent JSON/boolean/integer casts are understood accurately.
- Pint and whitespace checks pass. No dependencies were added.

Remaining T008 work: login/logout and session-version enforcement; protected first-owner UI; invitation delivery and acceptance UI; permission-protected staff management; concurrent final-owner rehearsal; HTTP, browser, frontend and full regression evidence. Staff 2FA and reauthentication remain T013, before public launch.

Framework contracts were checked against [Laravel 13 authentication](https://laravel.com/framework/docs/13.x/authentication) and [authorization](https://laravel.com/framework/docs/13.x/authorization). Local checks are not hosted CI or production evidence.
