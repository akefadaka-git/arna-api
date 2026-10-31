# ARNA Integration Reconciliation Plan 001

**Status:** A2 reconciliation plan — review only  
**Repository:** akeayu/arna-api  
**Integration branch:** integration/arna-system-consolidation  
**Integration base at plan creation:** 585f5979ab654988d31afcfd7f1719ce5b7fc1dd  
**Source inventory PR:** https://github.com/akeayu/arna-api/pull/6  
**Source branch/head:** arna-login-six-identifier-regression / 2df836e973324128e8c2a00a61f8fb2408843f70

## 1. Decision

Do **not** merge the 65-file PR as a single unit. Keep PR #6 draft and use it as the inventory/review umbrella only. Integrate only through new, bounded candidate branches and separate PRs after each unit has an explicit diff, dependency check, and relevant test evidence.

This plan records source selection and review boundaries. It does not claim that code has been reconciled, tested, approved, or merged.

## 2. Bounded integration units

Each source path from the 65-file PR is assigned to one primary review unit below. Shared integration points must be reviewed with their consumers; do not copy/merge the same path independently in multiple units.

### U1 — Identity resolution, login, and MFA

**Goal:** establish six-identifier login and MFA contract, with trusted tenant context and no token issuance before any required MFA challenge is completed.

Files:
- app/Http/Controllers/Auth/AuthController.php
- composer.json
- composer.lock
- config/auth.php
- database/migrations/2026_10_19_000002_create_user_authentication_factors_and_recovery_codes_tables.php
- docs/ARNA-IDENTITY-AUTHENTICATION-SECURITY-SPEC.md
- docs/ARNA-MFA-LIFECYCLE-CONTRACT-001.md
- mases_core/src/Audit/Listeners/LogFailedLogin.php
- mases_core/src/Auth/Events/FailedLogin.php
- mases_core/src/Auth/Models/AuthenticationRecoveryCode.php
- mases_core/src/Auth/Models/User.php
- mases_core/src/Auth/Models/UserAuthenticationFactor.php
- mases_core/src/Auth/Services/AuthenticationService.php
- mases_core/src/Auth/Services/MfaChallengeService.php
- mases_core/src/Auth/Services/MfaEnrollmentService.php
- mases_core/src/Auth/Services/TotpService.php
- mases_core/src/Domains/Shareholder/Repositories/ShareholderRepository.php
- tests/Feature/AuthenticationFactorSchemaTest.php
- tests/Feature/AuthenticationLifecycleTest.php
- tests/Feature/MfaChallengeTest.php
- tests/Feature/MfaEnrollmentTest.php
- tests/Unit/Auth/AuthenticationIdentifierResolutionTest.php
- tests/Unit/Auth/TotpServiceTest.php

**Acceptance gate:** verify username, email, phone, shareholder number, employee number, and wallet account number resolution; tenant-scoped lookup and ambiguity rejection; inactive/unlinked identity rejection; invalid/conflicting tenant context rejection; password and MFA state transitions; replay/one-time recovery-code behavior; no bearer token before required MFA; migration reversibility and Composer dependency integrity. Tests must run on the candidate revision, not merely on the source branch.

**Dependency:** U2 tenant membership and U5 wallet schema/identity may be needed for full end-to-end proof. If focused U1 tests require those changes, record that dependency and test a combined candidate; do not silently import unrelated domain files.

### U2 — Tenant membership lifecycle and tenant-context contract

**Goal:** ensure tenant membership status and lifecycle are consistent with login enforcement and tenant isolation.

Files:
- app/Http/Controllers/TenantContextController.php
- database/migrations/2026_10_19_000001_add_status_to_tenant_memberships_table.php
- mases_core/src/Tenancy/Middleware/TenantMembershipMiddleware.php
- mases_core/src/Tenancy/Models/TenantMembership.php
- mases_core/src/Tenancy/Services/TenantMembershipService.php
- tests/Feature/TenantMembershipLifecycleTest.php
- tests/Feature/TenantMembershipTest.php
- tests/Feature/C09/TenantResolutionTest.php

**Acceptance gate:** trusted request tenant context outranks ordinary body/query input; invalid/inactive tenant headers cannot be rescued by body tenant_id; conflicting context fails closed; inactive/suspended memberships cannot authenticate or access tenant data; HTTP context does not silently fall back to a user's default tenant; lifecycle transitions and audit events are tested.

**Relationship to U1:** B01 already includes much of the tenant enforcement in its authentication service and lifecycle tests. Compare behavior and tests against B04's eight unique commits before choosing which implementation to retain. Do not merge B04 wholesale or duplicate its history.

### U3 — Authorization, permissions, and shared infrastructure

**Goal:** reconcile authorization semantics and service registration without weakening tenant scoping or silently removing shared behavior.

Files:
- database/seeders/Data/PermissionCatalogue.php
- database/seeders/PermissionSeeder.php
- mases_core/src/Authorization/Services/AuthorizationService.php
- mases_core/src/Exceptions/AuthorizationFailureEvent.php (deleted by source PR; do not accept deletion until separately justified)
- mases_core/src/Domains/FileManagement/Services/FileService.php
- tests/Unit/AuthorizationServiceTest.php
- tests/Unit/UserPermissionScopeTest.php

**Acceptance gate:** tenant-scoped permission evaluation and fail-closed behavior; least privilege; permission catalogue/seeder consistency; regression tests for role/permission scope; prove all references and autoload assumptions around AuthorizationFailureEvent. The deletion is a stop-ship review item until reference search and replacement/equivalence evidence exist.

**Dependency:** coordinate final behavior with U1 User model and U2 tenant context. Do not overwrite auth service/provider changes by broad branch merging.

### U4 — Employee domain

**Goal:** review the employee domain as a self-contained domain unit, including API, policy, repository, lifecycle, migration, and tests.

Files:
- mases_core/src/Domains/Employee/Config/employee.php
- mases_core/src/Domains/Employee/Database/Migrations/2026_10_19_120000_create_employees_table.php
- mases_core/src/Domains/Employee/Http/Controllers/EmployeeController.php
- mases_core/src/Domains/Employee/Http/Requests/StoreEmployeeRequest.php
- mases_core/src/Domains/Employee/Http/Requests/UpdateEmployeeRequest.php
- mases_core/src/Domains/Employee/Http/Resources/EmployeeResource.php
- mases_core/src/Domains/Employee/Models/Employee.php
- mases_core/src/Domains/Employee/Policies/EmployeePolicy.php
- mases_core/src/Domains/Employee/Providers/EmployeeServiceProvider.php
- mases_core/src/Domains/Employee/Repositories/EmployeeRepository.php
- mases_core/src/Domains/Employee/Routes/api.php
- mases_core/src/Domains/Employee/Services/EmployeeService.php
- mases_core/src/Domains/Employee/Tests/Feature/EmployeeControllerTest.php
- mases_core/src/Domains/Employee/Tests/Feature/EmployeePolicyTest.php

**Acceptance gate:** tenant-scoped unique employee number; nullable user linkage handled safely; inactive/unlinked employee identifiers cannot authenticate; policy/controller/service authorization consistency; request validation and resource exposure review; migration/index/soft-delete behavior; provider and route registration; all Employee tests pass on the candidate.

### U5 — Wallet account identity and wallet regressions

**Goal:** add wallet account-number support without breaking wallet accounting, ownership, tenant isolation, audit events, or resource boundaries.

Files:
- mases_core/src/Domains/Wallet/Database/Migrations/2026_10_19_150000_add_account_number_to_wallets_table.php
- mases_core/src/Domains/Wallet/Http/Resources/WalletResource.php
- mases_core/src/Domains/Wallet/Listeners/AuditWalletEvent.php
- mases_core/src/Domains/Wallet/Models/Wallet.php
- mases_core/src/Domains/Wallet/Services/WalletService.php
- mases_core/src/Domains/Wallet/Tests/Unit/WalletServiceTest.php

**Acceptance gate:** tenant-unique and correctly indexed account number; existing-wallet backfill/default behavior reviewed; no unintended identifier reuse or cross-tenant resolution; account-number exposure approved; audit event semantics preserved; migration safety and wallet tests verified. U1 may depend on this unit for the sixth login identifier.

### U6 — Integration wiring and CI

**Goal:** make route/provider registration and CI behavior explicit only after underlying units are reviewed.

Files:
- bootstrap/providers.php
- routes/api.php
- .github/workflows/arna-focused-tests.yml
- .github/workflows/arna-login-six-identifier.yml
- .github/workflows/auth-tenancy-regression.yml
- .github/workflows/employee-policy-tests.yml
- .github/workflows/update-totp-composer-dependency.yml

**Acceptance gate:** route/provider registrations match accepted units; no duplicate/conflicting routes; workflow pull-request target branches and path filters run for integration PRs; dependency-update automation has appropriate scope and does not silently change the lockfile; checks run on integration PRs and results are reviewed. Current auth-tenancy-regression.yml targets codex/ar-na-p0-authorization-fail-closed, so its trigger must be corrected or replaced before relying on it for integration-branch PRs.

## 3. Recommended sequence

1. **U1/U2 contract reconciliation:** compare B01 and B04 behavior and tests; choose one coherent tenant-context contract. Produce a small candidate, not a whole-branch merge.
2. **U1 authentication/MFA candidate:** include only necessary authentication code, schema, dependency, and tests. If wallet/employee identity support is not yet integrated, explicitly defer full end-to-end six-identifier acceptance until U4/U5 are integrated, while proving safe rejection of absent records.
3. **U3 authorization:** reconcile tenant-scoped permission semantics and investigate the exception-class deletion before accepting any change.
4. **U4 Employee domain:** integrate as a separate PR after authorization and identity contracts are stable.
5. **U5 Wallet identity:** integrate as a separate PR and then rerun six-identifier authentication tests.
6. **U6 wiring and CI:** finalize providers/routes/workflow triggers after domain units are agreed.
7. Run the full consolidated suite and security regression matrix; only then consider moving the integration result toward main through a separate Owner-approved gate.

This is a dependency-aware order, not permission to skip prerequisites when tests or migrations reveal a hidden dependency.

## 4. Candidate PR rules

- Each unit gets a dedicated branch from the then-current integration head and a dedicated PR targeting the integration branch.
- Each PR lists exact included paths and any intentional cross-unit dependency.
- Do not cherry-pick or merge all 185 B01 commits as a substitute for file-level reconciliation.
- Preserve existing B01–B08 branches; do not reset, rewrite, or delete them.
- Keep source PR #6 in draft as a traceability umbrella. Do not merge it.
- No production database migration, deployment, or merge into main is authorized by this plan.
- Version/freeze labels remain unchanged until a component reaches FROZEN — VERIFIED.

## 5. Evidence classification

**Verified from GitHub metadata/file snapshots:** PR #6 has 65 changed paths; the listed paths above are its returned file inventory; B01 and B04 have different authentication-service and route/test blob versions; B01 source head has one successful check named “MFA schema and authentication lifecycle regression”; PR #6 is draft and currently reported mergeable.

**Not verified:** complete integrated test suite; runtime route/provider behavior; all migration execution/rollback behavior; six-identifier end-to-end behavior on integration branch; full MFA lifecycle on integrated revision; exception-class deletion safety; production readiness.

**Next gate:** review this unit map, then create the U1/U2 reconciliation candidate from the current integration head. No code-unit merge should occur until its exact diff and test evidence are reviewed.
