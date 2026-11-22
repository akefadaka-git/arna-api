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
