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

## T008 protected owner form and staff session slice — October 10, 2026

The owner form now appears after saved settings. Creating the owner signs them into a protected workspace and permanently closes setup. Staff can sign in and sign out; unconfigured installations still show the closed admin page. The public storefront remains closed.

Every mounted workspace route uses the staff-session middleware. It loads the current staff row, requires an active staff principal and a matching integer session version, then enforces `admin.access`. Disabled, deleted, foreign or outdated principals lose access. Invalid staff sessions are destroyed; an anonymous request to administration preserves signed guest intent. Login and logout rotate/invalidate sessions and CSRF tokens; remember-me is not exposed. Workspace props include only the display name and role.

Sign-in failures are generic across unknown accounts, incorrect passwords and inactive staff. Limits are five attempts per normalized email/IP pair and thirty per IP each minute; email limiter keys use hashes. Setup unlock, settings and owner endpoints use distinct limiter prefixes so valid settings saves cannot exhaust owner-creation attempts. All mutations retain Laravel request-forgery protection.

Nested owner credentials are excluded from validation flash data and Inertia remembered form data. Password fields clear after responses, and error summaries receive focus. Nested owner password fields are excluded from whitespace trimming so a password entered at creation also works at sign-in.

Verification:

- HTTP tests first failed on absent routes. Additional tests reproduced password trimming, anonymous intent loss and shared rate-limit collisions before their fixes.
- The full PHP suite passes: **122 tests / 618 assertions**, including direct permission denial, session rotation, disabled/revoked session rejection, credential flash exclusion and forged mutation rejection.
- Nine Vue unit tests pass. TypeScript, ESLint without warnings, Prettier and production build pass.
- Ten real Chromium tests pass. The setup test now continues through invalid owner confirmation, successful creation, durable reload using UUID/database sessions, closed setup, logout, failed sign-in, canonical-email sign-in and another durable reload.
- Axe checks pass at 320/768/1440px on sign-in and on the workspace; owner setup remains covered at mobile width. Error focus and password clearing pass; no browser page errors occurred. Rendered workspace inspected locally.
- PHPStan level 8, Pint and whitespace checks pass. No dependencies or real merchant accounts were added; the browser owner exists only in the isolated fixture database.

T008 is still open: invitation delivery/acceptance UI, staff-management policies/screens and concurrent final-owner evidence remain. Staff 2FA and sensitive-action reauthentication remain T013, and the complete store/launch scope remains unfinished. Rate-limited HTML currently uses the protected generic error response; a recoverable sign-in notice remains a UI follow-up.

## T008 staff-management and owner-concurrency slice — October 10, 2026

Owners can open the staff list from their workspace, review accounts, select a role preset and enable/disable access. Lists paginate at 50 accounts and expose only UUID, display name, email, role, active state and edit version. Every staff list/update checks `staff.manage` before looking up account records; managers, content editors and fulfillment staff receive the same denial for existing and unknown targets.

Access updates now require the current account version under the security lock. Outdated edits receive a conflict; browser forms preserve their submitted version on failure and offer an explicit reload. Only a successful save advances the form version. Role/access changes continue to invalidate existing sessions through session-version enforcement. Final-owner conflicts return a focused, recoverable form error without changing the account.

Verification:

- Management HTTP tests failed on absent routes before implementation. Tests cover safe DTOs, all limited presets, existing/unknown target denial, last-owner conflict, successful role changes and rejection of stale edits.
- A real MySQL concurrency rehearsal uses two independent guarded PHP processes and two active owners. Both processes start while the parent holds the shared security lock. After release they simultaneously attempt self-demotion: exactly one commits, the other returns 409, one active owner remains, one account advances its session version and one access-change audit intent persists.
- Full PHP suite: **125 passed**. Assertion count varies with the bounded concurrency readiness checks (675 in the recorded full run).
- Eleven Vue unit tests pass, including preserved edit versions and escaped account names. TypeScript, ESLint without warnings, Prettier, production build, PHPStan level 8 and Pint pass.
- Ten real Chromium tests pass; the owner flow now opens staff management, receives a focused last-owner error, recovers with a valid save and reloads the persisted role. Staff management passes Axe and overflow checks at 320px; rendered mobile output inspected locally.

T008 remains open for invitation delivery/acceptance screens. Reauthentication and mandatory privileged-staff 2FA remain T013 before launch. These screens operate only the implemented staff-account foundation; commerce, marketing and public-launch scope remain in the backlog.

## T008 one-time invitation link and acceptance slice — October 10, 2026

Owners can create an invitation from staff management, select its bound email and permission preset, and copy a one-time private link to share directly. The UI clearly says that the link expires in 48 hours, disappears on reload and is revoked by a replacement invitation for the same email. No email is sent by this workflow; automatic email delivery remains separate transactional-email work (T079).

Creation returns a private, no-store JSON response only after staff-session and `staff.manage` checks. The secret never enters Inertia page props, session flash data or remembered forms. The owner UI holds it only in component memory. The link uses a URL fragment, which is absent from HTTP requests. Before Inertia initializes, a dedicated bootstrap helper captures and removes the fragment and clears the existing history entry; the recipient page consumes the in-memory token once. Invalid/expired/replayed invitations produce a recoverable form error. Passwords clear after responses; their exact bytes, including whitespace, remain protected through the shared credential contract. Account creation and token consumption remain atomic.

Acceptance delegates identity and role selection entirely to the stored invitation; caller-supplied email/role/permission fields are rejected. The selected email is not marked verified merely from possession of a directly shared link. A successful acceptance establishes the versioned staff session and rotates session/CSRF identifiers.

Verification:

- Invitation HTTP tests failed on absent endpoints before implementation. The two new tests cover creator authorization, digest storage, private response headers, absence from page props/session data, nested-field injection rejection, bound email/permissions and single-use acceptance.
- Full PHP suite: **128 passed** (702 assertions in the recorded run; concurrency polling makes totals vary). PHPStan level 8 and Pint pass.
- Eleven Vue unit tests, TypeScript, ESLint without warnings, Prettier and production build pass.
- Ten real Chromium tests pass. The existing owner journey now creates a link and a separate context accepts it after a confirmation failure. Browser evidence proves fragment removal before saved page history, absence from network request URLs, clearing of passwords, a limited account's denied staff-management request, removal of the creator's link on reload, session revocation after a subsequent role change, and rejection of replay. Acceptance errors pass Axe checks.

T008's owner/staff session, invitation, server-permission and final-owner foundation acceptance is verified locally. Customer identity is T009; privileged staff 2FA/re-authentication is T013; automatic email delivery and provider/outage evidence remain T079. Rate-limited sign-in HTML still uses the generic protected error response and needs UX refinement before launch. The full commerce, marketing and launch scope remains unfinished.

History privacy review also reproduced a missing history-key retirement flag before its fix. Sign-in/account switching and sign-out now request Inertia history clearing after session rotation/invalidation. The HTTP test verifies the next page flag; the real browser test presses Back after logout and confirms that only sign-in returns, with no previous owner details restored. The installed API was checked against [Inertia history encryption](https://inertiajs.com/docs/v3/security/history-encryption). Invitation creation/acceptance also reject forged real-HTTP requests without CSRF/origin proof.

## Customer identity foundation — T009 in progress

Customer identity uses its own `customer_accounts` UUID table, model, session guard, and provider. Staff remains the default guard; matching a staff email never grants staff abilities. Registration is available as an internal action after permanent installation completion, validates a strict customer field allowlist, canonicalizes email, hashes passwords without bcrypt byte truncation, and creates an unverified active account. Database uniqueness enforces canonical email identity. Account creation and minimal ID-only audit intent share one transaction; an audit failure rolls the account back.

Customer session support rotates the session ID and CSRF token, retires Inertia history, drops setup grants and flashed form input, and retains the current guest intent across login rotation. Private-request middleware requires a real CustomerAccount principal plus the current active state and strict session version from a fresh database read. Staff-only authentication and a foreign principal with an identical UUID are denied. Logout invalidates the browser session and CSRF token, removing private account state. The shared browser cookie is invalidated as a whole on logout; guards still use distinct providers and explicit principal checks.

`ProveCustomerIdentity` is the contract for later guest-order ownership: callers must start the ownership transaction, supply the authenticated customer from the customer guard, and consume the result inside that transaction. It locks the current customer row, requires verified email, active status, matching email and session version, then supplies the server-owned customer ID and canonical email. Its DTO is not a signed token or bearer credential; a caller must prove identity again for every claim. Historical order claiming is still T043/T050 and requires actual order ownership predicates when those tables exist.

Sixteen focused tests cover registration, rejected privilege/verification injection, password limits, canonical duplicates, install gating, atomic audit rollback, staff/customer separation, current verified identity, transaction requirements, session/CSRF rotation, logout and revoked/foreign sessions. HTTP registration/login controllers, account UI, rate limits, nested password trim/flash protections, email verification/recovery delivery, profiles/saved addresses, and real guest cart/watchlist transfer are still pending. No production customer routes are mounted by this slice; test-only session routes remain inside the test files. T009 remains unchecked.

Implementation guidance was checked against the [official Laravel authentication documentation](https://github.com/laravel/docs/blob/13.x/authentication.md) and installed Laravel 13.35 SessionGuard implementation. The project adds its own active-state/version checks and transaction-scoped ownership proof rather than treating guard authentication as verified-email ownership.

Local evidence for this customer foundation slice: final full PHP run passed 144 tests (794 assertions), including 16 new customer tests. The existing 13 Vue tests and 10 Chromium browser workflows also passed. Pint, PHPStan level 8, TypeScript, ESLint, Prettier, production build, and diff whitespace checks passed. Browser evidence is regression coverage of existing staff/setup/storefront routes, not customer UI coverage; customer UI routes remain unmounted. No live emails or external providers were used.

## Customer server-rendered account screens — T009 in progress

Customer forms now live at `/account/register` and `/account/login`; authenticated customers reach `/account` and sign out through POST `/account/logout`. All routes require permanent installation completion. The public system pages use Blade plus the shared compiled CSS, with native POST/CSRF forms and no JavaScript requirement or Node serving process. This follows the storefront rendering architecture while preserving the branded foundation layout. Name/email are explicitly passed as a private account view DTO; password, session version and principal objects are not rendered. Output escaping, private/no-store/noindex responses and existing CSP remain enforced.

A live customer must sign out before another customer can sign in or register in that browser session. Guest-only middleware rechecks the real customer principal and current database state; it invalidates a stale session instead of carrying private state into a new authentication. Login explicitly uses the customer guard, canonicalizes email, rejects inactive accounts and bcrypt-overlength passwords, and returns the same credential error for unknown/inactive/wrong-password accounts. Customer login counters use hashed canonical email plus IP and an overall IP limit, separate from staff counters. Registration has a distinct five-per-minute IP counter. Nested customer passwords are exempt from trimming, and the entire nested customer request is excluded from flashed input. Failed native forms render empty password fields and focus the error summary.

The read-only account screen exposes name, email and verification status. It cannot expose staff controls or historical orders. Verification sending/acceptance, password recovery, editable profile/saved addresses and actual guest-resource merge still remain; T009 is not complete. The account URL is deliberately not added to the unopened storefront's navigation before the real storefront/launch milestone.

Browser evidence includes invalid confirmation, password clearing/error focus, successful registration using an existing staff email without acquiring staff access, account accessibility/overflow at 320/768/1024/1440px, failed login, and sign-in/logout with JavaScript disabled. Back after logout returns to sign-in without restored account information in both JavaScript-enabled and disabled contexts. Native form mutations without CSRF/origin proof are rejected. These customer scenarios run inside the complete installation journey so they do not depend on a different test having prepared an owner. Desktop account and mobile error screenshots were visually inspected.

Final local checks for the account-screen slice: 156 PHP tests (890 assertions), 13 Vue tests, and 10 complete Chromium journeys passed, including the added customer browser scenario. Pint, PHPStan level 8, TypeScript, ESLint, Prettier, production build and whitespace checks passed. The isolated `mrktflow_dev` database/account and local environment were positively checked before applying additive pending migrations; current migration status shows all migrations applied there. No test reset was run against development, and no development owner/customer was fabricated.
