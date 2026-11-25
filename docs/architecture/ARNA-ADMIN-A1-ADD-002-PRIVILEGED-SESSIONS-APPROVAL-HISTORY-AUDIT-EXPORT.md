# ARNA Administration Console — Follow-on A1 Assessment and Freeze

**Assessment ID:** ARNA-ADMIN-A1-ADD-002  
**Version:** 1.0.0  
**Date:** 2026-11-25 (EAT)  
**Stage:** A1 — Assessment, architecture specification and scope freeze  
**Status:** FROZEN — architecture and scope only  
**Repository:** `akefadaka-git/arna-api`  
**Documentation branch:** `feat/admin-a3-master-control-plane`  
**Implementation status of the three capabilities:** NOT IMPLEMENTED  
**Deployment:** NOT AUTHORIZED

## 1. Authority and boundary

The Owner approved the A1 scope boundaries for this addendum on 2026-11-25. This record freezes the architecture and scope for:

1. Privileged-session oversight.
2. Approval-history aggregation.
3. Audit export.

The previously closed provider-independent Platform Audit/Security A4 increment is preserved without amendment. This addendum does not reopen, expand, or qualify that closure.

This is a documentation-only A1 record. It does not authorize code changes, schema changes, migrations, permission seeding, route/page creation, merge, deployment, production configuration changes, provider activation, or external mutations. A2 remains a separate read-only reconciliation gate; A3 requires separate Owner authorization.

## 2. Frozen decisions

| ID | Decision | Status |
|---|---|---|
| D01 | Preserve the closed Platform Audit/Security A4 baseline and its platform-only `tenant_id IS NULL` boundary. | APPROVED / PRESERVED |
| D02 | Preserve the existing five-table RBAC schema as authoritative: `roles`, `permissions`, `user_has_roles`, `user_has_permissions`, `role_has_permissions`. | APPROVED / PRESERVED |
| D03 | Privileged-session oversight is initially read-only. No remote revocation, forced logout, account suspension or credential rotation is included. | OWNER-APPROVED |
| D04 | Approval history is a read-only aggregation of authoritative source evidence. It must not replace, rewrite or become the authority for any source workflow. | OWNER-APPROVED |
| D05 | Initial audit export is platform-only and excludes tenant-scoped audit/security rows. | OWNER-APPROVED |
| D06 | Each capability requires dedicated, scope-correct permissions and backend authorization. UI guards are not security boundaries; no universal admin bypass is allowed. | OWNER-APPROVED |
| D07 | No implementation begins before A1 freeze and a separate A2 read-only reconciliation; A3 requires separate authorization. | OWNER-APPROVED |
| D08 | The provider-adapter framework remains dormant and is not a dependency for these capabilities. | APPROVED / PRESERVED |

## 3. Existing implementation baseline

The following repository revisions were inspected:

- API PR #214, tenant identity/RBAC/Owner approval lifecycle: current PR head recorded in the PR resource as `210e7fcc0ad5af14040f20b6f981866d9c0aa424`. PR: https://github.com/akefadaka-git/arna-api/pull/214
- API PR #224, platform control-plane, settings, provider-action and platform audit/security: exact verified API head `7ca17c8f83ba59487ddb7b31644e2cf3a0dae94c`. PR: https://github.com/akefadaka-git/arna-api/pull/224
- Frontend PR #137, Admin Console route tree and platform audit/security page: exact verified frontend head `27e1688efeb297cb3d77c9c3ce3ed44482a6dd25`. PR: https://github.com/akefadaka-git/arna-app/pull/137

The PRs remain separate implementation candidates. Their existing verification and closure evidence is not changed by this A1 record.

## 4. Capability A — privileged-session oversight

### 4.1 Current source inventory

**Android recognized device sessions**

- `database/migrations/2026_11_22_000001_create_auth_device_sessions_table.php` creates `auth_device_sessions`.
- `mases_core/src/Auth/Models/DeviceSession.php` maps that table.
- `mases_core/src/Auth/Services/DeviceSessionService.php` establishes, lists, refreshes and revokes recognized Android device sessions; it associates a session with a Sanctum personal-access-token row.
- `app/Http/Controllers/Auth/AuthController.php` exposes self-service session listing/revocation through `GET /api/auth/sessions` and `DELETE /api/auth/sessions/{sessionId}`.
- The service scopes those operations to the authenticated user's own sessions. These endpoints are not platform-wide privileged-session oversight.

The device-session table includes user identity, optional tenant, platform, app version, device label, last-seen/expiry/revocation timestamps and a linked personal-access-token ID. It does not itself contain IP address or user-agent fields.

**Bearer personal-access tokens**

- Sanctum stores bearer credentials in `personal_access_tokens`.
- Android recognized sessions have an explicit relation to this table.
- Non-Android login paths can also issue bearer personal-access tokens. Do not assume every PAT has an `auth_device_sessions` row or a one-to-one recognized-device record.
- Token secrets/plaintext must never be exposed by oversight APIs or exports.

**Browser / trusted-subdomain SSO cookie**

- `app/Http/Controllers/Auth/AuthController.php` issues a revocable Sanctum bearer credential in an HttpOnly parent-domain cookie for explicitly trusted first-party SPA origins.
- The controller explicitly documents that this mechanism does not create a Laravel web session; the custom authentication middleware converts the cookie back to a bearer authorization header.
- This cookie-backed bearer credential is therefore a distinct channel from a database-backed Laravel session, even though both may be used by browser clients.

**Laravel session guard and session store**

- `config/auth.php` defines the `web` guard with the Laravel `session` driver.
- `config/sanctum.php` configures the `web` guard for Sanctum first-party stateful authentication.
- `config/session.php` defaults to the `database` driver and `sessions` table, with environment overrides.
- `DeviceSessionService::revokeAllForUser()` conditionally deletes rows from `sessions` when that table exists.

Repository configuration establishes supported/default behavior, not the actual deployed environment values, active session driver, cookie settings, or live session population.

### 4.2 Frozen target

A read-only platform oversight view may list sessions belonging to principals who are currently privileged under server-side platform authorization. The design must distinguish at least:

- Android recognized device session;
- bearer personal-access token without a recognized device-session record;
- trusted-subdomain SSO cookie carrying a bearer token;
- Laravel/Sanctum first-party session-guard session, if enabled in the deployed configuration.

The oversight projection may include principal, session/credential reference (non-secret identifier only), authentication channel, tenant/application context where relevant, creation/last-activity/expiry/revocation state, and risk metadata only where authoritative evidence exists. Privilege must be determined server-side from the current RBAC and scope model; a client-provided role or tenant identifier is never authoritative.

No session termination or account action is included in the initial capability.

### 4.3 A1 limitations carried to A2

- Actual production values for `SESSION_DRIVER`, `SESSION_TABLE`, `SESSION_DOMAIN`, `SESSION_SECURE_COOKIE`, Sanctum stateful domains, token expiry and cache settings have not been inspected.
- The deployed use of the Laravel session guard versus the custom SSO bearer-cookie flow is not proven.
- The complete inventory of every token-issuing client and any legacy token path must be reconciled.
- There is no unified platform privileged-session listing route or page in the inspected Admin route tree.
- Existing device-session rows do not provide a complete browser IP/user-agent/device fingerprint.
- No historical privilege-at-session-start snapshot is established. A2 must decide whether the view is based on current privilege, session-start privilege evidence, or both, without weakening current authorization.
- Session cleanup, token expiry, revocation cache consistency and last-activity update semantics require verification before any operational risk indicators are designed.

## 5. Capability B — approval-history aggregation

### 5.1 Authoritative platform settings/configuration source

- Migration: `database/migrations/2026_11_24_000002_create_platform_settings_and_approvals_tables.php`.
- Request table: `platform_setting_change_requests`.
- Event table: `platform_setting_audit_events`.
- Controller: `app/Http/Controllers/Admin/PlatformSettingsController.php`.
- Routes are under `/api/admin/platform/settings/change-requests` and `/api/admin/platform/configuration/change-requests`.
- Read, request and approve/reject operations use distinct global permissions: `platform.settings.read/manage/approve` and `platform.configuration.read/manage/approve`.

The request row stores requester, optional approver/decider, status, reason, approval note, base version, and applied timestamp. The separate event table records request/decision events with actor, before/after state, reason and timestamp. The controller enforces requester/approver separation and transactional application. Secret proposals are encrypted and must remain redacted in history.

The request row is mutable lifecycle state; the audit-event table is the historical event source. An aggregator must not treat the current request row as the complete decision history. The current list projection does not expose every stored field, including all decider/evidence fields.

### 5.2 Authoritative provider-action source — dormant

- Migrations: `2026_11_25_000001_create_platform_provider_action_control_tables.php` and subsequent provider-action extension migrations.
- Tables: `platform_provider_action_requests`, `platform_provider_action_approvals`, `platform_provider_action_audit_events`, `platform_provider_action_outbox`.
- Controller: `app/Http/Controllers/Admin/PlatformProviderActionsController.php`.
- Lifecycle service: `app/Services/Admin/Providers/ProviderActionLifecycleService.php`.
- Read-only request history route exists under `/api/admin/platform/provider-actions/{publicId}/history`.

The request record binds a request to a plan digest and policy version. Approver decisions are separate rows; transition evidence is recorded in the audit-event table. Critical operations may also require a separate provider Owner signature. The held outbox is execution-intent evidence, not an approval decision.

The provider framework remains dormant: no vendor adapter is registered by default; mutation, sandbox and certification gates default off; no provider credential, live probe, sandbox certification or external mutation is authorized. Showing existing approval evidence must not activate or dispatch provider actions. Provider Owner signature evidence is distinct from the tenant RBAC Owner signer.

### 5.3 Authoritative tenant RBAC Owner-approval source

- Migration: `database/migrations/2026_11_24_090000_create_admin_owner_approval_evidence_tables.php`.
- Tables: `admin_owner_approval_trust_anchors`, `admin_owner_approval_trust_anchor_revocations`, `admin_owner_approval_challenges`, `admin_owner_approval_consumptions`.
- Controller: `app/Http/Controllers/Admin/TenantRoleOwnerApprovalController.php`.
- Service: `mases_core/src/Authorization/Services/TenantRoleOwnerApprovalService.php`.
- Routes are tenant-bound under `/api/admin/tenant/rbac/approval-challenges` and the corresponding execute endpoint.

The signed challenge binds tenant, requester, Owner, key, action, target, role, complete permission set, reason, nonce and validity interval. Consumption stores the verified signature evidence and is one-use. The migration installs append-only update/delete protections for these evidence tables.

These records are tenant-scoped. They must not be placed into a platform-global feed or exposed outside an authorized tenant context. The inspected route set provides challenge creation and execution, not a general read-history endpoint. A separate read-only history projection requires explicit tenant-scoped authorization and must not become an alternate approval path.

### 5.4 Other approval/review workflows identified but not included in the initial Admin aggregator

The route inventory also contains public-content/corporate-site review and tenant-scoped announcement approve/publish workflows. They are domain/editorial lifecycle decisions, not interchangeable platform settings or provider approvals. Governance, Documents and Finance have additional authority or approval design material; a complete implemented-source determination for those domains has not been made in this A1 assessment. In particular, a design mention of a possible Finance journal-approval table is not proof that such a table is implemented.

The initial aggregator is therefore limited to the three authoritative source families in §§5.1–5.3, presented with their source scope and authority preserved. Other domain approval systems are excluded unless separately inventoried and approved.

### 5.5 Frozen target and unresolved A2 items

The aggregator is read-only and must present source identity, request/target, requester, scope, reason, policy/version, required approvers, each decision and timestamp, Owner-signature verification evidence where applicable, lifecycle state and execution outcome only where the source actually records it.

It must preserve distinct platform and tenant views. It must never create, approve, reject, execute, replay, re-sign, or rewrite an underlying request.

A2 must confirm full source-table/route/controller coverage, all state transitions, historical completeness, actor identity joins, retention, event immutability, tenant authorization and whether provider-action history is surfaced while the provider framework remains dormant.

## 6. Capability C — audit export

### 6.1 Existing authoritative sources

- `audit_logs`, model `mases_core/src/Audit/Models/AuditLog.php`, migration `database/migrations/2026_09_07_000010_create_audit_logs_table.php`.
- `security_events`, model `mases_core/src/Security/Models/SecurityEvent.php`, migration `database/migrations/2026_09_07_000011_create_security_events_table.php`.
- Platform read controller: `app/Http/Controllers/Admin/PlatformAuditSecurityController.php`.
- Existing read endpoints: `GET /api/admin/platform/audit-logs` requiring `platform.audit.read`, and `GET /api/admin/platform/security-events` requiring `platform.security.read`.
- Both platform endpoints explicitly query only `tenant_id IS NULL`. Existing tenant-bound records remain in tenant-scoped endpoints and are not widened.
- Audit logs and security events are separate tables with separate purposes. The audit table has indexes for actor, tenant, action, resource and created timestamp; the security table has indexes for actor, tenant, event type, severity, IP, created timestamp and resolution timestamp.
- Audit payloads and security event data require recursive credential-shaped redaction. SecurityEventService separately sanitizes event data on write; export must apply its own output-boundary controls rather than assuming historical data is uniformly sanitized.

### 6.2 Frozen target

Initial export is limited to the platform-only records already visible through the two platform read endpoints. Supported output formats are CSV and JSONL. Every export must be bounded by date range, filters, row count and byte size; must preserve source identifiers and timestamps; and must include a manifest with requester, request time, filters, source scope, record counts, redaction-policy version, generation result and cryptographic checksum.

Exports must be private, access-controlled, expiring and auditable. A checksum establishes file integrity against the manifest, not source authenticity, signature validity or tamper-proof storage. No permanent public URL, public-disk artifact, unbounded export or tenant-data inclusion is allowed in this initial scope.

### 6.3 Storage and queue evidence / unresolved items

- `config/filesystems.php` defaults to the local disk rooted at `storage/app/private`; it also defines a public disk and an S3 disk.
- `config/queue.php` defaults to the database queue and also supports sync and other drivers.
- These are configuration defaults only. Actual environment overrides, worker availability, private-storage permissions/capacity, S3 configuration, retention, cleanup and operational monitoring have not been verified.
- No audit-export route or Admin export page is present in the inspected route tree/page.
- No evidence was found in the inspected Admin contract of a completed export workflow.

A2 must verify runtime queue/storage configuration, worker operation, private retrieval controls, retention and cleanup, export-size limits, date semantics, database indexes/query plans and operational failure handling before choosing synchronous versus asynchronous generation.

## 7. Cross-cutting security and data boundaries

1. Existing platform audit/security endpoints remain read-only, permission-gated and limited to `tenant_id IS NULL`.
2. Tenant-scoped audit/security and tenant Owner approval evidence remain separately authorized; platform access is not a tenant-data bypass.
3. Current RBAC tables and scope semantics remain authoritative. No parallel role/permission schema is permitted.
4. Privilege and scope are derived server-side. UI visibility is not authorization.
5. Tokens, cookies, passwords, OTP/MFA secrets, signing private keys, ciphertext and secret configuration values must never be returned in session, approval or export projections.
6. Provider adapters remain dormant and are not prerequisites for any of the three capabilities.
7. Every read/export action must be auditable; export creation and retrieval require explicit authorization and bounded retention.
8. No production configuration, live database, secrets, provider endpoints or production records were accessed during this assessment.

## 8. A1 source-reconciliation result

| Workstream | A1 result | A2 requirement |
|---|---|---|
| Privileged sessions | PARTIAL — Android device sessions, PATs, SSO cookie path and Laravel session configuration identified; deployed channel inventory and privilege semantics remain unverified. | Reconcile deployed auth channels, token/session stores, revocation and privilege policy. |
| Approval history | PARTIAL — platform settings, dormant provider-action and tenant Owner RBAC evidence sources identified; other domain workflows are deliberately excluded pending separate scope. | Reconcile every selected source's lifecycle, state mapping, immutable evidence, retention and scope enforcement. |
| Audit export | PARTIAL — platform audit/security sources, redaction boundary and config defaults identified; no export feature; runtime storage/queue and volume/retention unverified. | Verify runtime infrastructure, data volumes, query plans, retention, private storage and retrieval controls. |

**A1 assessment completion:** 100% — approved scope and architecture are frozen; unresolved source/runtime questions are explicitly carried into A2.  
**A2 status:** NOT STARTED.  
**A3 implementation:** NOT AUTHORIZED / NOT STARTED.  
**Capability implementation status:** all three NOT IMPLEMENTED.  
**Production readiness:** NOT READY / NOT ASSESSED FOR RELEASE.

## 9. A2 entry criteria and sequence

A2 is read-only and must not modify source code, schema, migrations, seeders, permissions, routes, frontend pages, production configuration or records.

1. **A2.1 — Authentication and privileged-session reconciliation:** inspect actual deployment configuration where safely available; enumerate all credential/session channels; reconcile device-session, PAT, SSO cookie and Laravel session lifecycles; verify privilege and revocation semantics.
2. **A2.2 — Approval-source reconciliation:** reconcile each selected approval table, controller, route, permission, state transition, actor/Owner evidence and retention. Confirm tenant/platform segregation. Record excluded domain workflows without silently broadening the scope.
3. **A2.3 — Export feasibility reconciliation:** inspect audit/security schema and query plans, data volume/retention evidence, runtime queue/worker and private storage configuration, cleanup and authorized retrieval options.
4. Produce an A2 evidence matrix with exact repository refs, migration/test evidence, confirmed gaps and unresolved decisions. A3 remains a separate authorization gate.

## 10. Change control

This A1 freeze may be amended only by a controlled addendum/revision recording the reason, affected decision IDs, impact, evidence and Owner approval. The closed Platform Audit/Security A4 baseline remains preserved. No implementation, merge, deployment or provider activation is authorized by this document.
