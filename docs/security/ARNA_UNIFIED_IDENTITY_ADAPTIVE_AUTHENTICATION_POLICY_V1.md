# ARNA Unified Identity and Adaptive Authentication Policy v1.0

**Status:** Owner-directed implementation baseline  
**Scope:** Central ARNA identity and authentication across Workspace, Shareholder Portal, Finance, Wallet, Admin and Android  
**Implementation gate:** A3; merge, production migration and deployment remain separate approvals

## Identity identifiers

One canonical ARNA account may be resolved using any one of these six identifiers:

1. Email
2. Username
3. Mobile number
4. Employee ID
5. Shareholder number
6. Wallet number

Identifiers identify the account; they are not authenticators. All identifiers must resolve to the same canonical user and the applicable tenant membership. Ambiguous, inactive, unlinked or cross-tenant matches fail closed. Identifier changes must preserve account ownership and uniqueness constraints.

## Authenticators

ARNA supports four authenticators:

- **Password:** mandatory for the user's first successful authentication.
- **PIN:** available only on a successfully recognized and non-revoked device.
- **OTP:** one-time, short-lived, single-use verification.
- **Google Authenticator:** TOTP using a securely enrolled authenticator secret.

After first-time password authentication, the user may use any authenticator permitted by the operation's risk policy. A PIN is never accepted merely because the caller supplies a device identifier; the server must validate a registered device/session credential.

## Risk policy

| Level | Operation examples | Requirement |
|---|---|---|
| Standard | Ordinary login/session resume | One permitted authenticator |
| Enhanced | Password recovery/reset, identity/security changes, classified actions, sensitive transactions | OTP **or** Google Authenticator |
| Critical | Critical financial transactions, wallet ownership transfer, privilege escalation, critical administration | Two independent factor groups: one knowledge factor (password or recognized-device PIN) **and** one possession factor (OTP or Google Authenticator) |

Unknown or unclassified sensitive operations fail closed at the critical level until explicitly classified. Existing authorization/permission checks remain mandatory; authentication never grants business permissions.

## Device and session controls

- Device enrollment must be authenticated and bound to the canonical user and tenant.
- Device installation identifiers are not secrets and must be stored only as keyed hashes.
- PIN verification is bound to a recognized device and protected by throttling, retry limits, secure hashing and revocation.
- Users can inspect and revoke their sessions/devices.
- A revoked, expired or compromised device cannot use PIN authentication.
- Web SSO and Android device sessions remain distinct credential channels.

## Recovery and identity changes

Password recovery and changes to email, username, mobile, employee reference, shareholder reference or wallet association require OTP or Google Authenticator. Recovery must not rely solely on the contact point being changed. Use a previously verified channel, enrolled TOTP, recovery codes or an audited recovery procedure; notify old and new verified channels and apply a cooling-off period for high-impact changes.

## Critical transaction binding

Before a critical action is authorized, show and bind the confirmation to the canonical action details (for example amount, currency, recipient, wallet/account, tenant and transaction reference). Any change to those details invalidates the prior approval and requires fresh step-up authentication. Replays and duplicate submissions must be rejected.

## Audit and risk signals

Record security events for authenticator enrollment/removal, device recognition/revocation, recovery, identity changes, step-up challenges, failed attempts and critical-action approval. Never log passwords, PINs, OTPs, TOTP seeds, recovery codes or bearer credentials. Apply rate limits, generic account-recovery responses, session revocation and anomaly review.

## Implementation sequence

1. Preserve existing six-identifier resolver and tenant-safe account mapping; add regression coverage for all six identifiers and ambiguity.
2. Add policy evaluation and operation classification.
3. Implement device enrollment, PIN credential lifecycle and recognized-device proof.
4. Implement OTP challenge lifecycle and integrate existing TOTP MFA as an alternative step-up method.
5. Gate password recovery and identity/security changes with verified step-up proof.
6. Gate critical financial/admin operations with two independent factor groups and transaction-bound confirmation.
7. Update web and Android login/authenticator selection, recovery, device/session management and Amharic-first error/accessibility states.
8. Run exact-head API, MySQL migration, tenant isolation, concurrency, frontend and Android verification.

## Non-goals and safeguards

- No weakening of RBAC, tenant isolation, domain policies or approval workflows.
- No forced conversion of browser SSO to mobile device tokens.
- No production secret provisioning or live OTP delivery-provider changes in this A3 work.
- No merge, release, production migration or deployment without separate authorization.

## A3 implementation record — current branch

The following backend increments are now implemented on the isolated A3 branch:

### Returning-user authentication

- Password login remains the first-authentication path and records last_login_at.
- Returning users can authenticate with password, recognized-device PIN, email/SMS OTP, or Google Authenticator.
- These are alternative standard-login authenticators: enrolling Google Authenticator does not silently force password-plus-TOTP for ordinary login. TOTP remains available as an independent login method and as step-up proof.
- OTP and Google Authenticator login are denied while last_login_at is null; a password login is required first.
- Login identifier resolution continues through the canonical resolver for all six identifiers. Unknown, ambiguous, inactive, unlinked and cross-tenant identities fail closed.
- OTP login challenge responses use a uniform response shape for unknown, inactive, first-login and undeliverable accounts.

### Recognized Android devices and PIN

- Android password/MFA login declares client_type=android and a per-installation identifier.
- Installation identifiers are stored as keyed hashes; the API returns an opaque device-session UUID.
- Each Android installation has an independently revocable Sanctum token/session.
- PIN is six numeric digits, stored only as a password hash, bound to the current recognized device session.
- Five failed PIN attempts lock that device's PIN for 15 minutes.
- PIN login is available only after a successful password login and only for a matching, active, non-expired device session.
- Token refresh rotates the token for that device; logout and revocation do not revoke other devices.
- Browser/SSO wildcard tokens remain outside the Android device-session model.

### OTP and step-up grants

- OTP is six digits, expires after five minutes, and permits at most five verification attempts.
- OTP is delivered only to a previously verified email address or phone number. SMS requires the configured delivery adapter; no provider credentials are added by this change.
- Google Authenticator TOTP is accepted as the alternative possession factor for step-up.
- A successful proof creates a five-minute, single-use grant bound to user, tenant, action and a canonical request-context fingerprint.
- Grants are consumed atomically and cannot be replayed or reused for a different amount, resource or action.
- Critical actions require both a knowledge factor (password or recognized-device PIN) and a possession factor (OTP or Google Authenticator).

### Protected API operations

| API operation | Step-up classification |
|---|---|
| Wallet payment recording | Enhanced |
| Loan repayment recording | Enhanced |
| Wallet balance adjustment | Critical |
| Wallet allocation reversal | Critical |
| Loan approval / disbursement / restructuring / write-off | Critical |
| PIN enrollment | Enhanced identity-change step-up |

### New API routes

- POST /api/login/pin
- POST /api/login/otp/challenge
- POST /api/login/authenticator
- GET /api/auth/sessions
- DELETE /api/auth/sessions/{sessionId}
- POST /api/auth/step-up/challenges
- POST /api/auth/step-up/verify
- POST /api/auth/device-pin/enroll
- POST /api/auth/identity-change/challenge
- POST /api/auth/identity-change/verify
- PATCH /api/auth/identity
- POST /api/password/email (OTP challenge)
- POST /api/password/reset (OTP or Google Authenticator proof)

The password login endpoint and existing browser SSO contract remain available.

### Remaining A3 work and verification boundaries

- Verify password recovery and self-service identity-change routes against the new OTP/TOTP flow; future identity mutation endpoints must use the same policy and context binding.
- Complete the web and Android authenticator-selection, PIN enrollment and device-management UI.
- Verify every route's transaction-context mapping against its request validator and controller.
- Run exact-head PHP 8.3, SQLite feature, MySQL clean-install/concurrency, tenant-isolation, and frontend/Android regression suites.
- A3 remains in progress until these checks pass. No merge, production migration, release or deployment is authorized by this implementation record.

### Password recovery and identity changes

- Password recovery now issues a neutral OTP challenge to an already verified email address or phone number; Google Authenticator is also accepted at reset time.
- The reset operation changes the password only after OTP/TOTP verification and atomically revokes bearer tokens, recognized Android sessions, browser database sessions, outstanding step-up challenges and grants.
- Legacy password-reset-token links are no longer accepted; the web application directs users to the OTP/TOTP recovery flow.
- Self-service identity changes are limited to email, username and mobile number. Employee ID, shareholder number and wallet number remain controlled by their owning domains.
- Identity-change challenges are bound to the server-normalized proposed identifier hash. A grant for one new email/username/mobile number cannot authorize a different value.
- Email changes clear email verification and trigger a new verification notification. Mobile-number changes clear phone verification. All sessions are revoked after a successful identity change, requiring a fresh login.
