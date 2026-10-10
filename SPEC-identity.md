# Module: identity

Objective: separate shopper accounts from store administration and enforce minimum required access. Depends on platform. Inherits [engineering conventions](docs/architecture.md), [commands](docs/coding-handoff.md), and [quality requirements](docs/quality-and-operations.md).

## Requirements

- **ID-01:** Customer registration, login/logout, email verification, password reset, profile and saved addresses. Guest checkout remains available. Rotate sessions at authentication and privilege changes.
- **ID-02:** Staff invitations expire, are single-use and assign explicit permissions. Built-in role presets: owner, manager, content editor, fulfillment. An owner cannot remove the final owner without replacement.
- **ID-03:** Staff two-factor enrollment, challenge and recovery codes; require 2FA for owner and privileged payment/settings/publishing staff before launch. Re-authenticate sensitive credential/refund/staff changes.
- **ID-04:** Every admin action and customer-owned resource has a server policy. Customer registration and email matching never grant staff access. Unauthorized object access does not reveal whether a private order/file exists.
- **ID-05:** Guest cart/watchlist transfer uses the current signed session and atomic merge; verified email is required to claim historical guest orders. Log out removes access to private account data from shared-device caches.
- **ID-06:** Account export/deletion requests enter an audited workflow honoring configured order retention; no blanket deletion of financial history.

## Verification

Permission matrix tests cover each preset and direct HTTP access. Test expired/replayed invitations, reset token reuse, rate limits, customer-to-staff escalation, wrong-order access, 2FA recovery and session invalidation. Target `php artisan test --filter=Identity`; browser login/guest-account-merge tests. Success: roles permit intended tasks and deny cross-customer/cross-role access.
