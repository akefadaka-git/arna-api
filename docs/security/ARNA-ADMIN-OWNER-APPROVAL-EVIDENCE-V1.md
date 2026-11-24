# ARNA Admin — Owner-signed tenant RBAC approval evidence contract

- **Document ID:** ARNA-ADMIN-OWNER-APPROVAL-EVIDENCE-001
- **Version:** 1.0.0
- **Status:** Implemented on the A3 candidate branch; production trust-anchor provisioning remains separately controlled
- **Repository:** akefadaka-git/arna-api
- **Applies to:** tenant role assignment, tenant role revocation, and tenant role-permission synchronization

## 1. Security objective

Consequential tenant RBAC mutations require a verifiable, cryptographic Owner approval bound to one exact operation. A normal administrator permission, role name, request-body flag, approval URL, or free-form reference is not approval evidence.

The approval is a detached Ed25519 signature over a server-issued canonical challenge. ARNA does not receive or store Owner private keys. A verified challenge can be consumed once, by the actor who requested it, in the same database transaction as the RBAC mutation, security event, and cache invalidation.

## 2. Existing authority and cryptographic foundations

The implementation reuses the current-main RBAC tables and does not add a second authorization engine:

- roles
- permissions
- role_has_permissions
- user_has_roles
- user_has_permissions — retained, but no direct-user grant endpoint or implicit direct grant is introduced.

Cryptography reuses the existing tenant-scoped document_signing_keys registry and document_signing_key_revocations ledger. Only Ed25519 keys are accepted. The signing key must belong to the exact Owner account and tenant, be valid at the signature instant, and not be revoked. The existing document signature verifier is not called with a fabricated document workflow; the Admin verifier independently verifies the same Ed25519 primitive against the Admin-specific canonical payload.

## 3. Owner trust-anchor provisioning

A signing key is not an Owner key merely because it exists in document_signing_keys. A separate row in admin_owner_approval_trust_anchors binds the exact tenant, Owner user, and existing signing-key row to the Owner approval purpose.

Trust-anchor provisioning is deliberately **not exposed as an API** and is not seeded:

1. Independently verify the Owner's identity, current authority, key custody, and public-key fingerprint through the approved out-of-band process.
2. Provision the tenant-scoped Ed25519 public key through the existing controlled signing-key onboarding procedure.
3. Record the authority instrument/reference and SHA-256 of the supporting evidence in the trust-anchor row. Record the approving/trusting account and effective validity.
4. Independently review the row and test a non-production challenge before relying on the trust anchor.
5. Revoke a trust anchor by appending to admin_owner_approval_trust_anchor_revocations; revoke the underlying signing key through the existing append-only signing-key revocation ledger as appropriate.

No production Owner trust anchor is included in migrations, seed data, or this PR. An explicit, opt-in AdminOwnerApprovalTrustAnchorSeeder is provided for a separately controlled provisioning operation; it requires an already verified active Owner, an already trusted tenant-scoped Ed25519 key, an independent active trust reviewer, an authority reference, and a SHA-256 evidence digest. It is not called by DatabaseSeeder and creates no Owner user, role assignment, direct permission grant, or key. Until a valid anchor is provisioned, every consequential RBAC operation fails closed.

### Explicit Owner trust-anchor bootstrap

After out-of-band identity and authority verification and after the public key is registered through the existing signing-key process, an operator may explicitly run:

```bash
php artisan db:seed --class=Database\\Seeders\\AdminOwnerApprovalTrustAnchorSeeder
```

Required environment values:

- ARNA_OWNER_BOOTSTRAP_TENANT_ID
- ARNA_OWNER_BOOTSTRAP_USER_ID
- ARNA_OWNER_BOOTSTRAP_TRUSTED_BY_USER_ID (must be a different active account)
- ARNA_OWNER_BOOTSTRAP_KEY_ID
- ARNA_OWNER_BOOTSTRAP_AUTHORITY_REFERENCE
- ARNA_OWNER_BOOTSTRAP_EVIDENCE_SHA256

The seeder is deterministic and non-destructive. Repeating the exact same bootstrap is a no-op; a conflicting or revoked anchor fails rather than being overwritten. It never infers the Owner from the first user, super_admin, platform_admin, or any seeded identity.

## 4. Challenge lifecycle

The requester must be authenticated, active, an active member of the server-resolved tenant, and hold the existing tenant Admin challenge-request capability tenant.membership.manage. That capability permits requesting a challenge only; it does not grant or mutate a role.

The challenge is server-created, immutable, tenant-bound, requester-bound, Owner-bound, role-bound, action-bound, and time-limited to ten minutes. It contains a cryptographically random nonce. The server returns the canonical payload and the selected trusted key ID for the Owner to sign outside ARNA.

### Supported actions

| Action | Required binding | Mutation after verification |
|---|---|---|
| tenant.role.assign | target user + tenant role | Insert user_has_roles with actor and timestamp |
| tenant.role.revoke | target user + tenant role | Remove the exact user_has_roles pair |
| tenant.role.permissions.sync | tenant role + complete sorted permission slug set | Synchronize role_has_permissions through the canonical Role model |

Assignment and revocation require the target to remain active and actively joined to the same tenant at execution time. Permission synchronization accepts only existing canonical permission slugs and revalidates tenant/application scope compatibility before mutation. Platform and application roles cannot be mutated through these tenant routes.

## 5. Canonical signed payload

The Owner signs the exact UTF-8 bytes of the JSON string returned as canonical_payload. Property order is fixed, no whitespace is added, and encoding uses JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR.

\`\`\`json
{
  "contract": "ARNA-ADMIN-OWNER-APPROVAL-V1",
  "challenge_id": "server-issued UUID",
  "tenant_id": 123,
  "action": "tenant.role.assign",
  "requested_by_user_id": 456,
  "owner_user_id": 789,
  "key_id": "tenant-registered-owner-key",
  "target_user_id": 987,
  "role_id": 42,
  "role_slug": "tenant-finance-manager",
  "permission_slugs": [],
  "permission_set_sha256": "64 lowercase hexadecimal characters",
  "reason": "Specific business reason",
  "nonce": "64 lowercase hexadecimal characters",
  "issued_at": "2026-11-24T07:00:00Z",
  "expires_at": "2026-11-24T07:10:00Z"
}
\`\`\`

For a permission-set synchronization, target_user_id is null and permission_slugs contains the complete desired set, normalized, deduplicated, and sorted lexically. For assignment/revocation, permission_slugs is empty. The permission-set digest is SHA-256 of the canonical JSON encoding of that exact sorted array.

The execute request supplies only:
- signature: base64-encoded 64-byte detached Ed25519 signature;
- signed_at: UTC YYYY-MM-DDTHH:MM:SSZ instant.

The verifier checks the exact stored challenge bytes and digest, requester and tenant binding, Owner trust anchor, key validity/revocation, signature time, challenge expiry, and Ed25519 signature. A caller cannot replace challenge fields at execution time.

## 6. Atomic execution and replay protection

Within one database transaction, ARNA:

1. Locks the exact unconsumed challenge and revalidates tenant/requester binding and expiry.
2. Locks and validates the active Owner trust anchor and signing key.
3. Verifies the detached Ed25519 signature and stored payload hash.
4. Revalidates the tenant role, target account/membership, and exact permission set.
5. Applies only the approved role assignment, role revocation, or permission-set synchronization.
6. Invalidates the affected user/tenant cache or the role holders' scope-specific permission caches.
7. Persists the mandatory security event.
8. Appends the unique challenge-consumption record, including signature and verified payload digest.

The unique (tenant_id, challenge_id) consumption key prevents replay. Any mutation, cache invalidation, security-event, or consumption failure rolls back the transaction. The challenge and trust/consumption evidence tables are append-only on MySQL and SQLite. A failed signature does not consume the challenge and does not change RBAC state.

## 7. API surface

Both routes require Sanctum authentication, validated tenant membership, and auth.permission:tenant.membership.manage:

- POST /api/admin/tenant/rbac/approval-challenges — request an immutable challenge; no RBAC mutation.
- POST /api/admin/tenant/rbac/approval-challenges/{challenge}/execute — verify Owner signature and atomically execute the exact approved operation.

There are no direct tenant role assignment, revocation, permission-sync, or direct-user-permission endpoints outside this verifier.

## 8. Persistence

The migration adds:

- admin_owner_approval_trust_anchors — Owner/key/purpose binding;
- admin_owner_approval_trust_anchor_revocations — append-only anchor revocation;
- admin_owner_approval_challenges — immutable canonical request;
- admin_owner_approval_consumptions — one-time verified signature receipt.

The migration does not alter the current RBAC schema, add direct permission grants, seed an Owner identity, seed keys, or provision trust anchors.

## 9. Verification requirements

A4 must verify at the exact candidate SHA:

- MySQL 8 clean-install migration and rollback/forward compatibility checks;
- PHP 8.3 lint and Laravel route registration/duplicate-name scan;
- valid Owner signature, wrong key, wrong signer, wrong tenant, wrong requester, altered payload, expired challenge, revoked anchor/key, and replay denial;
- exact role assignment/revocation and complete permission-set synchronization;
- tenant/application scope enforcement and no platform-role mutation;
- cache invalidation, audit atomicity, and rollback on any failure;
- no writes to user_has_permissions;
- all existing Admin, tenancy, authorization, Governance, Documents and domain regressions.

Passing CI proves test behavior on the candidate revision only. It does not provision production keys, migrate production, merge the PR, or authorize deployment.
