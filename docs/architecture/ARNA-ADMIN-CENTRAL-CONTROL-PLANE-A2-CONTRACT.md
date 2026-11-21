# ARNA Admin Central Control Plane
## Final A1 Capability Inventory and Owner-Approved A2 Implementation Contract

**Document ID:** ARNA-ADM-CP-A2-001  
**Version:** 1.1.0  
**Status:** Owner-approved specification; saved for controlled implementation planning  
**Owner:** Akefa Ayele Daka  
**Approval date:** 10 November 2026  
**Canonical home:** `akefadaka-git/arna-api/docs/architecture/ARNA-ADMIN-CENTRAL-CONTROL-PLANE-A2-CONTRACT.md`  
**Frontend companion:** `akefadaka-git/arna-app/docs/architecture/ARNA-ADMIN-CENTRAL-CONTROL-PLANE-A2-REFERENCE.md`

> **Authority boundary:** This document approves and freezes the A2 design contract. It does not authorize A2 code implementation, database migration, production data changes, PR merge, release, or deployment. Those actions require their own explicit stage authorization. A5 deployment remains separate.

---

## 1. Executive decision

ARNA Admin is the governed central control plane for the ARNA application estate. It must provide a coherent administrative view of applications, people, tenant membership, authorization, content, governance, operations, and security without taking ownership away from the domain services that own the underlying business records.

The A1 inventory covered four user-facing applications, the shared API route registry, domain-mounted APIs, the central permission and role catalogues, and the competing identity/RBAC/tenant implementation proposals.

**A1 disposition**
- Four-application route inventory: **PASS**
- Shared and domain API inventory: **PASS**
- Core/Finance permission and role inventory: **PASS**
- Admin capability assessment: **PASS**
- Competing A2 PR overlap analysis: **PASS**
- Historical custom-RBAC schema compatibility: **UNRESOLVED**
- Home-tenant field compatibility: **UNRESOLVED**
- Platform/application role mapping compatibility: **UNRESOLVED**

A1 is technically complete as an evidence inventory. The unresolved schema-authority items remain explicit A2 entry conditions; they are not waived by this approval.

**A2 disposition:** contract approved and frozen at v1.1.0. A2 implementation is **NOT AUTHORIZED** by this document.

## 2. Governance, scope and change control

### 2.1 Stage gates

| Stage | Purpose | Current state |
|---|---|---|
| A1 | Assessment, route/API/permission inventory, capability matrix, conflict identification | Evidence inventory complete; closure record pending |
| A2 | Read-only reconciliation and implementation-contract preparation | Contract frozen; implementation not authorized |
| A3 | Isolated implementation against approved contract | Not authorized |
| A4 | Exact-HEAD verification, security review and acceptance | Not started |
| A5 | Merge/release/deployment and production activation | Separate approval required; not authorized |

### 2.2 Non-negotiable controls

1. The destination repositories `akefadaka-git/arna-api` and `akefadaka-git/arna-app` are canonical.
2. Preserve unrelated work, existing domain ownership, and open PRs. Do not force-push, rewrite, close, or merge competing work as part of this contract.
3. Every implementation claim must identify repository, branch, exact commit SHA, changed paths, tests, and workflow evidence.
4. Do not claim production readiness from a passing unit test, a successful CI run, an existing route, or an open PR alone.
5. Do not infer tenant authority from request parameters, headers, URL path values, or frontend state. Resolve it through authenticated server-side context.
6. Do not silently replace an Owner-approved schema with a framework default or a newer implementation proposal.
7. Fail closed when authority, tenant membership, permission mapping, or required audit evidence is absent or ambiguous.
8. A2/A3 work must not require paid GitHub Actions, deposits, or paid services. Prefer repository-hosted checks and reproducible public CI.
9. Keep production secrets, real personal data, shareholder data, financial data, and live tenant identifiers out of fixtures, screenshots, and public logs.

### 2.3 Approval interpretation

This approval authorizes retaining and referencing this specification in the repositories. It does **not** grant blanket permission to implement every capability described in the long-term Admin matrix. Only the bounded A2 work packages and acceptance criteria in this document may be proposed for a later A3 authorization.

## 3. System boundaries and four-application inventory

The four applications are distinct client surfaces. They are not four independent identity or authorization authorities.

| Application | Canonical route source | A1 route count | Primary responsibility |
|---|---|---:|---|
| MASES Workspace | `src/router/routes-workspace.ts` | 29 | Enterprise workspace and domain workflows |
| Shareholder Portal | `src/router/routes-portal.ts` | 6 | Shareholder self-service |
| Admin Console | Admin router and Admin feature modules | Inventory includes existing Admin routes plus candidate identity/RBAC/tenant views | Governed platform administration |
| Public Corporate Website | `public-entry/main.ts` | 8 | Public corporate pages, content, contribution and verification |

Shared authentication, legal, and error routes are common infrastructure and are not double-counted as application-specific route inventory.

### 3.1 Workspace route families

The 29 route records include:
- Dashboard and launcher.
- Communications and announcements.
- File list and file detail.
- Shareholder list, create, class, detail, and edit.
- Wallet, wallet detail, and statement.
- Loan list, detail, schedule, statement, and products.
- People profile.
- Governance overview, attendance, bodies, body detail, people/person, and attendance-finance.

### 3.2 Shareholder Portal route families

The six route records cover:
- Portal landing.
- Holdings.
- Certificates.
- Wallet.
- Loans.
- Loan detail.

### 3.3 Admin Console route families

The current Admin surface includes:
- Dashboard.
- Public-content review.
- Corporate website CMS.
- Audit and security-event review.

Candidate A2 identity work adds tenant, user, role, and permission directory/lifecycle views. The competing Admin branch must not displace or omit the existing corporate-site CMS and audit/security navigation or route guards. The later integration must reconcile route records, navigation/sidebar entries, permission guards, breadcrumbs, empty/error states, and deep-link behavior.

### 3.4 Public website route families

The eight route records cover:
- Homepage.
- Contribution.
- Document verification.
- Legal centre and legal document.
- Localized corporate content.
- Localized pages.
- Catch-all/not-found handling.

### 3.5 Frontend authority rule

Frontend routes and guards are presentation and navigation controls only. The API is authoritative for identity, tenant selection, membership, permission evaluation, data filtering, and state transitions. Hiding a button or route is not a security control.

## 4. API and domain ownership inventory

The shared API route registry contains 96 explicit route declarations in the inspected A1 snapshot. It includes health, public website/content, authentication, MFA/phone, tenant context, corporate CMS, public-content moderation, audit/security, launcher, notifications, announcements, People, Files, and Organization.

Domain-mounted route registries are also registered through their domain providers:

| Domain | Route source | Ownership boundary |
|---|---|---|
| Shareholder | `mases_core/src/Domains/Shareholder/Routes/api.php` | Shareholder records, holdings, certificates and related actions |
| Wallet | `mases_core/src/Domains/Wallet/Routes/api.php` | Wallet accounts, balances, statements and wallet operations |
| Loan | `mases_core/src/Domains/Loan/Routes/api.php` | Loan products, applications, schedules and loan lifecycle |
| Governance | `mases_core/src/Domains/Governance/Routes/api.php` | Governance bodies, meetings, attendance and resolutions |
| Finance | `mases_core/src/Domains/Finance/Routes/api.php` | Finance ledger, accounts and financial controls |
| People | Shared route registry | People/profile domain APIs |
| Files | Shared route registry | Document/file metadata and access boundaries |
| Organization | Shared route registry | Organization structure and organizational records |

A route count is a snapshot, not a permanent invariant. Before implementation, A2 must record the exact current base SHA and re-run the inventory against that SHA. Route additions or removals since the A1 snapshot must be reconciled, not silently treated as drift-free.

### 4.1 Existing Admin API capability

The A1 source review identified:
- Corporate CMS page drafts, editing, approval, publish/schedule/unpublish.
- Editorial content entries and moderation.
- Media quarantine/scan boundary.
- Inquiry status updates.
- Tenant-bound audit and security-event list/detail/resolution.
- Identity, membership, role, permission and tenant foundation proposals in API PR #85.

The existence of these APIs does not prove complete workflows, global platform visibility, or production readiness.

## 5. Existing permission, role and application catalogue

### 5.1 Inventory snapshot

The inspected central catalogue contains 60 effective central permission rows: 47 core and 13 Finance. Three Admin security permissions are included in the 47 core rows. This supersedes the older 57-row snapshot as an inventory count only; it does not authorize deleting or renaming historical permission records.

Domain-specific Wallet, Loan, Shareholder and Governance permission catalogues exist beyond this central 60-row count and must be inventoried at exact A2 HEAD before implementation.

The inspected application catalogue contains seven application slugs:
- `app.portal.access`
- `app.finance.access`
- `app.hr.access`
- `app.admin.access`
- `app.arna.access`
- `app.app.access`
- `app.launcher.access`

The inspected role catalogue contains twelve roles:
- Core: `super_admin`, `platform_admin`, `tenant_admin`, `manager`, `hr_manager`, `shareholder`, `board`, `president`, `secretary`, `ceo`.
- Finance: `finance_manager`, `cashier`.

These are source inventory observations, not a decision that every role is appropriate for every tenant or application.

### 5.2 Permission families

| Family | Permission examples | Scope |
|---|---|---|
| Application access | `app.*.access` | Application admission; still requires action permissions |
| Platform directory | `tenant.list`, proposed `platform.*.read` | Explicit platform-level read |
| Identity | `user.list/create/update/delete/assign_roles` | Tenant-scoped by default |
| RBAC | `role.list/create/update/delete/assign_permissions`, `permission.list/create/update/delete` | Tenant-scoped by default |
| Tenant lifecycle | `tenant.list/create/update/delete` | Global platform scope; mutation is outside A2 implementation |
| Audit/security | `audit.view`, `security-event.view`, `security-event.resolve` | Existing tenant-bound scope |
| Public content | `public-content.review` | Editorial review workflow |
| Domain permissions | Finance, Loan, Wallet, Shareholder, Governance actions | Owned by each domain |

Permission names must not be treated as self-describing scope. A2 must document scope, resource ownership, guard, and grant source for each endpoint.

## 6. Material historical schema authority conflict

### 6.1 Earlier Owner-approved canonical custom RBAC schema

The prior Owner-approved tenant-aware RBAC design designated these tables as canonical:

- `custom_roles`
- `custom_permissions`
- `custom_user_roles`
- `custom_user_permissions`
- `custom_role_permissions`

Laravel-standard tables and pivots remain for compatibility, not as the replacement canonical authority:

- `roles`
- `permissions`
- `model_has_roles`
- `model_has_permissions`
- `role_has_permissions`

The earlier migration set also established:
- `tenant_memberships`
- `platform_application_roles`
- `platform_application_users`
- `arna_tool_permission_map`
- `tenant_context_audit`

The recorded migration outcome included 42 `tenant_memberships`, 68 `platform_application_roles`, 0 `platform_application_users`, 0 `tenant_context_audit`, 12 `arna_tool_permission_map`, and 67 `custom_permissions`. These counts are historical evidence only; they must be re-verified against the actual target database before any migration or data operation.

### 6.2 Current destination-source mismatch

The A1 review of current destination source found:
- Current User model uses `default_tenant_id`.
- Current Role and Permission models use Laravel-standard `roles` and `permissions`.
- Current AuthorizationService is built around those models.
- The inspected current source did not demonstrate the earlier `custom_*` tables as active canonical authorization storage.
- The current source also did not demonstrate all earlier platform/application mapping and tenant-context audit tables as integrated active authority.
- Earlier tenant identity notes designate `users.tenant_key` as the primary/home tenant field, while current source uses `default_tenant_id`.

This is a **blocking architecture reconciliation**, not a routine naming difference.

### 6.3 Binding rule

Until A2 establishes the actual migration history, live schema, model/pivot mapping, and approved compatibility strategy:
1. The historical custom-RBAC design remains the Owner-approved design authority.
2. Current Laravel-standard models are treated as implementation state requiring reconciliation, not as implicit supersession.
3. No production migration, reseed, table drop, pivot rewrite, permission remap, or tenant-field replacement is permitted.
4. Do not dual-write to two authorization authorities without a formally specified consistency model, transaction boundary, repair strategy, and rollback plan.
5. A2 must recommend one canonical read/write authority and document any compatibility adapter. The recommendation must be evidence-backed and returned for Owner confirmation if it changes the earlier approved schema semantics.

### 6.4 Home-tenant authority

A2 must resolve `users.tenant_key` versus `users.default_tenant_id` using migration chronology, current database schema, existing data, model references, and API behavior. Do not infer equivalence from similar names.

The final contract must explicitly distinguish:
- **Home/default tenant:** the user's default workspace context, if retained.
- **Membership:** an independently governed relationship between a user and a tenant.
- **Active request tenant:** the tenant resolved for one authenticated request.
- **Platform authority:** cross-tenant capabilities granted independently of tenant membership.

No client-provided tenant identifier may override the server-resolved active request tenant.

### 6.5 Platform/application role mappings

A2 must reconcile `platform_application_roles`, `platform_application_users`, `arna_tool_permission_map`, and any newer equivalent against the actual source and schema. Application access, platform role, tenant role, and domain permissions are separate dimensions; one must not implicitly grant another.

## 7. Canonical identity and authorization model

### 7.1 Identity

- Preserve the canonical MASES/ARNA User identity model; do not create a second user principal for Admin.
- Identity creation and initial tenant membership must be atomic when the workflow requires both.
- A user may have multiple tenant memberships where the approved data model supports it.
- Authentication establishes the principal; it does not itself establish authorization for a tenant or action.
- Sensitive fields, credential material, recovery data, internal security metadata, and unrelated tenant attributes must not be serialized in directory responses.

### 7.2 Tenant context resolution

The API must resolve tenant context from authenticated server-side state and an approved tenant-selection mechanism. The resolver must:
1. Authenticate the principal.
2. Resolve the requested tenant context without trusting a client-supplied tenant claim as authority.
3. Confirm the principal has an active membership or an explicitly approved platform-level exception.
4. Bind the resolved tenant to the request lifecycle.
5. Reject ambiguous, revoked, suspended, nonexistent, or unauthorized context.
6. Record security-relevant context transitions and denials without leaking sensitive data.

Tenant context must be immutable within the request after resolution. Services and repositories must receive the resolved context explicitly or through a well-defined request-scoped abstraction.

### 7.3 Membership lifecycle

Supported tenant membership states:
- **Active:** eligible for tenant-scoped authorization, subject to role and permission checks.
- **Suspended:** tenant-scoped access denied; other tenant memberships are unaffected.
- **Revoked:** terminal state; the same membership record cannot be reactivated. A new membership, if allowed by policy, must be a distinct auditable action.

Transitions must be explicit, authorized, validated, transactional, and audited. Revocation must invalidate relevant authorization/session context. A tenant-scoped suspension must never be represented as account-wide suspension.

### 7.4 RBAC semantics

- Tenant roles and permissions are evaluated in the resolved tenant context.
- Platform roles and system permissions are separately governed and protected.
- A tenant administrator cannot grant privileges beyond the administrator's effective grant ceiling.
- Protected platform roles, system permissions, and reserved role bindings cannot be modified by tenant-level actors.
- Role-permission changes must be validated as a whole and audited.
- Effective authorization must be computed by one authoritative service/contract; duplicated ad hoc checks are prohibited.
- Application access is necessary where configured, but never substitutes for action-level permission checks.
- Domain services retain authority over their own business invariants.

### 7.5 Deferred authority

The following remain deferred and must not be introduced as incidental additions:
- Direct per-user tenant permission grants.
- Account-wide suspension and global session termination.
- SSO reconciliation or cross-application session redesign.
- Automated privilege escalation or self-approval.
- Any system-principal grant or automation activation not separately approved.

## 8. API contract: platform read versus tenant-scoped mutation

The namespace split prevents global directory reads from colliding with tenant-scoped lifecycle actions.

### 8.1 Platform read-only endpoints

| Method and path | Permission | Contract |
|---|---|---|
| GET `/api/admin/platform/identity/users` | `platform.identity.read` | Paginated, minimal cross-tenant identity directory |
| GET `/api/admin/platform/rbac/roles` | `platform.rbac.read` | Platform-level role catalogue and scope metadata |
| GET `/api/admin/platform/rbac/permissions` | `platform.rbac.read` | Permission catalogue with scope and ownership metadata |
| GET `/api/admin/platform/tenants/{tenant}/memberships` | `platform.tenancy.read` | Read-only membership directory for one identified tenant |
| Existing tenant directory | `tenant.list` | Preserve current global tenant-list semantics |

These proposed platform permissions are not automatically granted to any role. Platform read access must be separately provisioned and audited. The API must not return password hashes, secrets, MFA recovery material, session tokens, private contact fields without a defined need, or cross-tenant business data.

### 8.2 Tenant-scoped lifecycle endpoints

The existing tenant-scoped namespace is retained, subject to exact A2 route reconciliation:

| Resource | Route family | Permission family |
|---|---|---|
| User identity | `/api/admin/identity/users` | `user.list/create/update/delete/assign_roles` |
| Tenant membership | Tenant membership lifecycle routes | Tenant membership authority, explicit per-action permission |
| Roles | `/api/admin/authorization/roles` | `role.list/create/update/delete/assign_permissions` |
| Permissions | `/api/admin/authorization/permissions` | `permission.list`; mutation only if explicitly supported and governed |

Every tenant-scoped operation requires resolved tenant context and active membership, except for a narrowly defined platform-authorized operation with explicit permission and audit. Route names alone do not confer global scope.

### 8.3 Collision resolution

API PR #84 and #85 both proposed overlapping paths, including `GET /admin/identity/users` and `GET /admin/tenants`, with different permission/context assumptions. They must not be merged independently.

The contract is:
- Global read-only directories use the explicit `/admin/platform/*` namespace.
- Tenant-scoped lifecycle actions retain tenant-scoped namespaces.
- Existing `tenant.list` semantics are not silently replaced by `platform.tenancy.read`.
- The exact API prefix and route grouping must follow the current Laravel route conventions after A2 inspection.
- If a route cannot be made unambiguous, do not implement it until the route and authorization contract is corrected.

## 9. Admin capability matrix and roadmap boundary

| ID | Capability | A1 state | A2 treatment |
|---|---|---|---|
| ADM-01 | Command Centre / operational overview | PARTIAL | Reconcile current dashboard data contracts only |
| ADM-02 | Application registry and lifecycle | MISSING | Out of scope |
| ADM-03 | Cross-application page registry | PARTIAL/MISSING | Out of scope; distinguish CMS pages from app routes |
| ADM-04 | Navigation registry/editor | MISSING | Out of scope |
| ADM-05 | Website presentation/theme/page builder | PARTIAL | Preserve existing CMS; no visual builder |
| ADM-06 | Corporate content/CMS | PRESENT/PARTIAL | Preserve and regression-test |
| ADM-07 | Identity directory and tenant identity lifecycle | PARTIAL | Bounded A2 contract |
| ADM-08 | RBAC catalogue and lifecycle | PARTIAL/UNRESOLVED | Bounded A2 contract after schema reconciliation |
| ADM-09 | Tenant directory and membership | PARTIAL | Read directory and membership contract; full tenant lifecycle out of scope |
| ADM-10 | Organization administration | PARTIAL | Preserve domain ownership; no broad rewrite |
| ADM-11 | Audit and security | PARTIAL | Preserve tenant-bound capabilities; no global audit expansion |
| ADM-12 | Platform configuration | MISSING | Out of scope |
| ADM-13 | Integrations and secret management | MISSING | Out of scope |
| ADM-14 | Operations/health | PARTIAL | Read-only contract reconciliation only |
| ADM-15 | Governance Centre | PARTIAL | Preserve Governance domain ownership |
| ADM-16 | Reporting and analytics | MISSING | Out of scope |

This is a capability maturity matrix, not a promise that every Admin module will be implemented in A2.

## 10. Existing PR and branch reconciliation

The A1 snapshot identified these open, unmerged proposals:

| Repository | PR | Head SHA | Role in reconciliation |
|---|---:|---|---|
| arna-api | #84 | `0f8e3e6ac297a7e3dc43b232ab8de9dce6b7cbb5` | Earlier global read-only identity/RBAC/tenant directory proposal |
| arna-api | #85 | `ef26a85531c5d4df9a6d32df36e368bcbf00e72a` | More developed tenant-scoped identity/membership/RBAC lifecycle proposal |
| arna-app | #36 | `ff05ecff36004914a30cbca9b68d7efed5aaea49` | Earlier Admin control-plane UI proposal |
| arna-app | #37 | `e3a92a7ec256bc27c9895776d33d21535940295f` | Later tenant/user/role/permission UI proposal |

**Historical CI evidence captured at the A1 review snapshot:**
- API #85 MySQL clean-install: https://github.com/akefadaka-git/arna-api/actions/runs/41728884242
- API #85 tenant isolation: https://github.com/akefadaka-git/arna-api/actions/runs/41728883869
- API #85 effective authorization audit: https://github.com/akefadaka-git/arna-api/actions/runs/41728884204
- API #85 membership/authorization reconciliation: https://github.com/akefadaka-git/arna-api/actions/runs/41728884051
- App #37 frontend verification: https://github.com/akefadaka-git/arna-app/actions/runs/41728377171

These links are historical evidence only. Before any later A3 implementation or acceptance, re-fetch each PR's state, base/head, exact SHA, changed files, review comments, checks and merge base. A successful run at a previous head is not evidence for a changed head or current main.

### 10.1 Integration guidance

- Preserve PRs #84, #85, #36 and #37 as evidence until a separately approved disposition.
- Treat #85 and #37 as implementation references, not canonical architecture.
- Do not merge #84 or #36 independently; reconcile their unique useful work against the namespace and scope contract.
- Rebase or recreate integration branches from current main only after exact-HEAD inspection.
- Preserve current corporate CMS and audit/security routes, guards, navigation and tests.
- Do not discard changes simply because a competing branch is stale; inspect and port required behavior deliberately.

## 11. A2 work packages and required outputs

A2 is a reconciliation and design-validation stage. It is read-only with respect to application code and production data.

| Package | Required work | Required output | Gate |
|---|---|---|---|
| A2.1 | Capture exact current main SHAs, PR metadata and changed files | Evidence ledger with timestamps and immutable refs | No stale-head assumptions |
| A2.2 | Reconcile migrations, live schema evidence, models, pivots, seeders and historical approvals | Canonical schema decision record; unresolved questions explicit | No implementation while schema authority is unresolved |
| A2.3 | Reconcile authorization service, middleware, policies, guards and tenant resolver | One authorization sequence and responsibility map | Fail-closed behavior specified |
| A2.4 | Reconcile route inventory and proposed platform/tenant namespaces | Endpoint matrix with method, path, authn, permission, scope, response and audit behavior | No route collision |
| A2.5 | Reconcile tenant membership and lifecycle semantics | State-transition table and transaction/invalidation contract | Revocation terminal |
| A2.6 | Reconcile frontend routes, navigation, API clients and permission guards | Four-app route delta and Admin integration map | CMS and audit/security preserved |
| A2.7 | Threat-model and test-design review | Abuse cases, negative tests, privacy and audit requirements | Independent review findings recorded |
| A2.8 | Produce A2 closure package | Updated ADR, endpoint matrix, test matrix, unresolved-decision log | Owner review before A3 |

A2 must not edit source files, run migrations against a shared/production database, reseed permissions, change PR branches, merge, or deploy.

## 12. A3 implementation sequence — planning only

The following sequence is the recommended implementation order after A2 closure and separate A3 authorization. It is not permission to begin coding now.

1. **A3.1 — Schema compatibility adapter and canonical persistence:** implement only the Owner-confirmed canonical RBAC/tenant mapping; avoid dual authority.
2. **A3.2 — Tenant context and membership enforcement:** central resolver, active membership validation, tenant isolation and request immutability.
3. **A3.3 — Authorization core:** one effective authorization service, privilege ceiling, protected platform roles and system permissions.
4. **A3.4 — Platform read-only directory APIs:** explicit platform permissions, pagination, field minimization and audit.
5. **A3.5 — Tenant identity and membership lifecycle:** atomic creation, duplicate protection, suspend/reactivate/revoke semantics and cache/session invalidation.
6. **A3.6 — Tenant RBAC lifecycle:** role and permission catalogue operations, protected bindings, transaction safety and audit.
7. **A3.7 — Admin frontend integration:** routes, guards, navigation, forms, loading/empty/error states, responsive/mobile behavior; preserve CMS and audit/security.
8. **A3.8 — Adversarial verification and independent review:** exact-HEAD tests, security review, remediation and A4 evidence package.

Each package must be independently reviewable and must not be marked complete until its acceptance evidence exists.

## 13. Security and adversarial acceptance matrix

| ID | Test / control | Expected result |
|---|---|---|
| SEC-01 | Unauthenticated access to every Admin API | 401 or established auth failure; no data |
| SEC-02 | Authenticated user without permission | 403; no side effect |
| SEC-03 | Tenant A principal requests Tenant B data | Denied; no data leakage |
| SEC-04 | Client forges tenant ID/header/body context | Server ignores/rejects unauthorized override |
| SEC-05 | Suspended membership makes tenant request | Denied |
| SEC-06 | Revoked membership is reused/reactivated | Rejected; revocation remains terminal |
| SEC-07 | Duplicate user-tenant membership | Deterministic conflict; no duplicate row |
| SEC-08 | Identity plus initial membership creation fails midway | Full rollback; no orphan principal/membership |
| SEC-09 | Tenant admin grants role above own ceiling | Denied and audited |
| SEC-10 | Tenant actor mutates protected platform role/system permission | Denied |
| SEC-11 | Role-permission update fails midway | Atomic rollback |
| SEC-12 | Role synchronization occurs | Platform/application role mappings are preserved |
| SEC-13 | Direct per-user tenant permission endpoint is invoked | Not exposed; denied/not found |
| SEC-14 | Account-wide suspension/session termination is attempted through tenant lifecycle | Not exposed; no global effect |
| SEC-15 | Platform directory read without explicit platform permission | Denied |
| SEC-16 | Platform directory response serialization | No secrets, hashes, tokens or unnecessary sensitive fields |
| SEC-17 | Membership/role/permission mutation | Durable actor, tenant, action, target, outcome and correlation evidence |
| SEC-18 | Authorization cache after role/membership change | Invalidated or versioned; stale grant cannot persist |
| SEC-19 | Concurrent membership/role mutation | Consistent transaction/locking; no privilege race |
| SEC-20 | Cross-tenant list filters and pagination | Tenant scope enforced before pagination/counts |
| SEC-21 | Existing CMS publish/review workflows | No regression |
| SEC-22 | Existing audit/security routes and guards | No route, sidebar, permission or API regression |
| SEC-23 | Workspace, Portal and Public Website smoke tests | No unrelated regression |
| SEC-24 | Failure/exception path | Fail closed; no partial unauthorized side effect |

Test fixtures must use synthetic identities and tenant records. Tests must assert both the expected response and absence of forbidden database changes or emitted side effects.

## 14. Audit, privacy and operational requirements

Every sensitive identity, membership, role, permission and platform-directory operation must have a defined audit policy.

Minimum audit fields, subject to the existing canonical audit schema:
- Actor principal identifier.
- Resolved tenant identifier or explicit platform scope.
- Action and target type/identifier.
- Before/after state or safe change summary where appropriate.
- Outcome (success/denied/failure).
- Timestamp and correlation/request identifier.
- Reason or approval reference for privileged actions where required.

Do not place passwords, access tokens, MFA secrets, recovery codes, private keys, or full sensitive payloads in audit metadata.

Authorization caches must be invalidated or versioned after changes to membership, role assignment, role permissions, platform grants, and account authentication state. Cache invalidation failure must not create a stale privilege grant; define a fail-closed strategy.

Platform-wide audit visibility is not part of this contract. Existing audit and security-event functions remain tenant-bound unless a separately approved global audit authority is designed.

## 15. Database migration and rollback policy

No migration is authorized by this document.

Before a future migration proposal:
1. Identify exact source and target schema versions and migration IDs.
2. Inspect the actual database engine/version and schema; do not infer production state from CI.
3. Inventory row counts, foreign keys, unique constraints, indexes, nullability, orphan references and duplicate mappings.
4. Define a reversible or forward-repair plan, backup/restore evidence and a tested rollback boundary.
5. Define how existing Laravel-compatible pivots and custom RBAC records are reconciled without privilege loss or privilege expansion.
6. Prove idempotence and safe retry behavior.
7. Obtain explicit approval before applying to any shared or production database.

If a destructive or irreversible migration is proposed, it requires a separate decision record and explicit Owner approval. A migration must never silently drop historical roles, grants, memberships or audit evidence.

## 16. A4 verification and closure criteria

A2 is ready to close only when:
- Exact current main SHAs and all relevant PR heads are recorded.
- Historical schema approvals are mapped to actual migrations and current source.
- Canonical RBAC table and home-tenant field authority are resolved or explicitly returned to the Owner as decisions.
- Platform/application role mappings are reconciled.
- Route/API/permission matrix has no unresolved collision.
- Tenant resolver, authorization sequence and privilege ceiling are unambiguous.
- Frontend integration map preserves all current Admin capabilities.
- Security threat model and adversarial test plan are reviewed.
- Every unresolved item has an owner, impact, evidence needed and disposition.
- Owner explicitly accepts A2 closure and separately authorizes A3.

A4 must verify the exact implementation HEAD, not merely a branch name. Required evidence includes:
- API test suite and isolated MySQL clean-install.
- Tenant isolation and authorization adversarial tests.
- Migration status and schema-diff evidence (isolated environment).
- Frontend type-check, full four-app build, build-output verification and route smoke tests.
- Existing CMS and audit/security regression evidence.
- Dependency/security scan results where available.
- Independent security review and remediation disposition.
- Exact workflow run links and artifact references.

Any changed commit after verification invalidates the affected exact-HEAD evidence and requires re-verification.

## 17. Release, deployment and production boundary

A5 is explicitly separate. No merge, release, signing, production migration, secret provisioning, hosting configuration change, or deployment is authorized by A1/A2 approval.

Production activation requires:
- Separate Owner approval.
- Approved change window and rollback owner.
- Verified backup and restoration procedure.
- Production schema and least-privilege database access review.
- Secret and environment configuration review.
- Monitoring, alerting and audit retention.
- Smoke tests and operational handover.
- Explicit post-deployment verification and rollback decision.

## 18. Decision register

| Decision ID | Decision | Status |
|---|---|---|
| D-01 | Canonical User identity remains shared MASES/ARNA identity | Approved |
| D-02 | Historical `custom_*` tenant-aware RBAC schema remains design authority pending source reconciliation | Approved constraint |
| D-03 | Laravel-standard RBAC tables remain compatibility unless Owner explicitly supersedes | Approved constraint |
| D-04 | Resolve `tenant_key` versus `default_tenant_id` from evidence; do not silently switch | Open evidence gate |
| D-05 | Separate platform read-only directory namespace from tenant-scoped lifecycle routes | Approved |
| D-06 | Platform read permissions are explicit and not auto-granted | Approved |
| D-07 | Tenant membership must be active for tenant-scoped actions | Approved |
| D-08 | Revocation is terminal; suspension is tenant-scoped | Approved |
| D-09 | Enforce privilege ceiling and protect platform roles/system permissions | Approved |
| D-10 | Direct per-user tenant grants remain deferred | Approved deferral |
| D-11 | Account-wide suspension and global session termination remain deferred | Approved deferral |
| D-12 | Existing CMS and tenant-bound audit/security capabilities must be preserved | Approved |
| D-13 | No A2 implementation, migration, merge or deployment authorization is implied | Binding boundary |
| D-14 | A3 requires a separate explicit authorization after A2 closure | Binding boundary |
| D-15 | A5 requires separate explicit approval | Binding boundary |

## 19. A1 evidence snapshot and limitations

The inventory counts and PR heads in this document reflect the A1 review snapshot on 10 November 2026. They are not asserted to be the live repository state after that review. A2 must refresh all source evidence before relying on these values.

The historical CI links establish only that those workflows were reported successful for the referenced PR heads at the time of inspection. They do not prove:
- Compatibility with the historical custom-RBAC schema.
- Production database state.
- Correctness of every route or permission.
- Independent security approval.
- Merge readiness or deployment readiness.

## 20. Final disposition

| Item | Status |
|---|---|
| A1 four-application route inventory | PASS |
| A1 shared/domain API inventory | PASS |
| A1 central permissions, applications and roles | PASS |
| A1 Admin capability matrix | PASS |
| A1 competing PR conflict identification | PASS |
| Historical RBAC schema compatibility | UNRESOLVED |
| Home-tenant field compatibility | UNRESOLVED |
| Platform/application role mapping compatibility | UNRESOLVED |
| A1 formal closure | PENDING evidence-gate disposition |
| A2 contract | OWNER-APPROVED / FROZEN v1.1.0 |
| A2 implementation | NOT AUTHORIZED |
| A3 | NOT AUTHORIZED |
| A4 | NOT STARTED |
| A5 | NOT AUTHORIZED |

**Next permitted action:** execute the A2 read-only reconciliation packages and return the evidence-backed schema/authority decisions for Owner review. Do not modify application code or production data as part of that work.

---

*End of controlled specification.*
