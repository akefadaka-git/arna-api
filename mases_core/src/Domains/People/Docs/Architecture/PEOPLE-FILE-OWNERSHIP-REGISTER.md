# People Domain File Ownership and Disposition Register

- Document ID: PD-REG-001
- Version: 1.0.0
- Date: 2026-10-30
- Status: Initial read-only inventory; not a relocation authorization
- Canonical API root: `mases_core/src/Domains/People/`
- Canonical application root: `src/features/people/`

## 1. Purpose

This register applies the Owner decision that all People-specific code, tests, scripts, migrations, seeders, configuration, localization and documentation must reside inside the People Domain folder in the relevant repository.

This is an initial inventory from destination GitHub main and the currently open People PR heads. It is not yet a complete tracked-file audit. It must be extended to include every branch, import/reference, generated artifact, workflow path filter, test discovery path and all historical/legacy locations before any relocation.

## 2. Rules for disposition

| Classification | Meaning | Required treatment |
|---|---|---|
| DOMAIN-OWNED | People business logic or People-specific artifact | Canonical People root |
| SHARED-INTEGRATION | Minimal registration/wiring required by platform | Remain at framework/shared integration point; People contract/docs remain in People |
| FRAMEWORK-EXCEPTION | Fixed path required by framework/tooling | Retain only the minimal executable declaration; document dependency |
| LEGACY-TO-RELOCATE | Existing People artifact outside root | Plan bounded move after dependency and discovery analysis |
| CROSS-DOMAIN | Belongs to another ARNA domain | Remain with authoritative domain; People must not absorb it |
| DUPLICATE/OBSOLETE | Superseded or duplicated artifact | Prove references and history before deprecation/removal |
| UNRESOLVED | Ownership or dependency not established | No move/delete until resolved |

## 3. API repository — current main baseline

Repository: `akefadaka-git/arna-api`
Main SHA: `2915c10432208f0535ede2b7c3ea5a74d2dc7e99`

| Current path | Classification | Target / disposition | Notes |
|---|---|---|---|
| `app/Http/Controllers/PersonController.php` | LEGACY-TO-RELOCATE | `People/Http/Controllers/PersonController.php` | Current main controller outside domain; inspect route bindings and compatibility before moving. |
| `database/migrations/2026_10_27_000001_create_people_table.php` | LEGACY-TO-RELOCATE | `People/Database/Migrations/` | Migration history must be preserved; do not rename/replay without migration-ledger analysis. |
| `database/migrations/2026_10_27_000002_add_person_id_to_people_domains.php` | LEGACY-TO-RELOCATE | `People/Database/Migrations/` | Cross-domain foreign-key additions need explicit schema ownership review. |
| `database/migrations/2026_10_27_000003_create_person_tenant_accesses_table.php` | LEGACY-TO-RELOCATE | `People/Database/Migrations/` | People-owned tenant access data. |
| `database/migrations/2026_10_28_000001_create_person_document_links_table.php` | LEGACY-TO-RELOCATE | `People/Database/Migrations/` | People owns association; Files owns binary/version/retention engine. |
| `database/migrations/2026_10_28_000001_enforce_active_person_domain_uniqueness.php` | LEGACY-TO-RELOCATE | `People/Database/Migrations/` | Verify migration ordering and database-specific behavior. |
| `tests/Feature/PersonApiIntegrationTest.php` | LEGACY-TO-RELOCATE | `People/Tests/Feature/` | Test runner discovery must be updated and verified. |
| `tests/Feature/PersonIdentityContractTest.php` | LEGACY-TO-RELOCATE | `People/Tests/Feature/` | Same discovery concern. |
| `tests/Feature/PersonIdentityMySqlConcurrencyTest.php` | LEGACY-TO-RELOCATE | `People/Tests/Concurrency/` or `People/Tests/Feature/` | Final location based on project conventions; preserve MySQL-only intent. |
| `docs/phase-1/P1.2-UNIFIED-PEOPLE-PROFILE-COMPLETION-EVIDENCE.md` | LEGACY-TO-RELOCATE | `People/Docs/Reports/` | Historical phase evidence; preserve provenance and original SHA. |
| `mases_core/src/Domains/People/Models/Person.php` | DOMAIN-OWNED | Keep | Canonical Person model. |
| `mases_core/src/Domains/People/Models/PersonTenantAccess.php` | DOMAIN-OWNED | Keep | Explicit tenant visibility grant model. |
| `mases_core/src/Domains/People/Models/PersonDocumentLink.php` | DOMAIN-OWNED | Keep | Person-document association. |
| `mases_core/src/Domains/People/Models/EmployeeRecord.php` | DOMAIN-OWNED | Keep; assess lifecycle scope | Must not be confused with complete Employment module. |
| `mases_core/src/Domains/People/Services/PersonIdentityService.php` | DOMAIN-OWNED | Keep | Core identity lifecycle service. |

## 4. API repository — PR #31 candidate

PR: https://github.com/akefadaka-git/arna-api/pull/31
Head: `536335d07294a2f373c047702dd886a0006cbac2`
Base recorded by PR: `e853874ee41130d47be07d2ffc097a41ecb69a9b`
Current main at inventory time: `2915c10432208f0535ede2b7c3ea5a74d2dc7e99`
State: open, unmerged; 183 commits; 77 changed files; 3,437 additions; 641 deletions.

### 4.1 People-specific items already inside the domain root

PR #31 places the following categories under `mases_core/src/Domains/People/`: config, contracts, migrations, seeder, enums, events, controllers, requests, resources, listeners, models, production-readiness notes, policies, provider, repository, translations, routes, services and feature tests.

These are structurally aligned with the ownership rule, subject to full source and dependency review.

### 4.2 People-specific items still outside the domain root on PR #31

| Path | Classification | Proposed disposition |
|---|---|---|
| `docs/phase-2/PEOPLE-DOMAIN-IMPLEMENTATION-REGISTER.md` | LEGACY-TO-RELOCATE | `People/Docs/Implementation/` |
| `docs/phase-1/P1.2-UNIFIED-PEOPLE-PROFILE-COMPLETION-EVIDENCE.md` | LEGACY-TO-RELOCATE | `People/Docs/Reports/` |
| `scripts/people/Invoke-ArnaPeopleLocalTests.ps1` | LEGACY-TO-RELOCATE | `People/Support/Scripts/` or approved domain tooling folder |
| `scripts/people/Sync-ArnaPeopleLocalRunner.ps1` | LEGACY-TO-RELOCATE | `People/Support/Scripts/`; assess runner/environment dependencies before moving |

The scripts are People-specific artifacts and do not qualify for a framework fixed-path exception. Their commands, references and execution environment must be reviewed before relocation.

### 4.3 Cross-domain/shared changes in PR #31

| Path | Ownership classification | Required P0 disposition |
|---|---|---|
| `app/Http/Controllers/FileController.php` | CROSS-DOMAIN / Files | Review as separate Files change; do not move into People. |
| `mases_core/src/Files/Services/FileService.php` | CROSS-DOMAIN / Files | Files owns file service; People may call contract. |
| `mases_core/src/Authorization/Middleware/CheckPermission.php` | SHARED-INTEGRATION | Platform authorization owner; inspect least-privilege impact. |
| `database/seeders/Data/PermissionCatalogue.php` | SHARED-INTEGRATION | Central permission catalogue; People permission definitions must have domain-owned specification. |
| `database/seeders/Data/RolePermissionGrants.php` | SHARED-INTEGRATION | Central grants; require explicit role-grant review. |
| `database/seeders/PermissionSeeder.php` | SHARED-INTEGRATION | Platform seeder; no People business logic. |
| `database/seeders/RolePermissionSeeder.php` | SHARED-INTEGRATION | Platform seeder; document People grant ownership. |
| `bootstrap/providers.php` | FRAMEWORK-EXCEPTION | Minimal provider registration only; provider implementation remains inside People. |
| `routes/api.php` | FRAMEWORK-EXCEPTION / SHARED-INTEGRATION | Minimal route discovery/mount only; actual People routes stay in People. |
| `mases_core/src/Domains/Organization/**` | CROSS-DOMAIN / Organization | Must be independently reviewed and dispositioned; not People-owned. |

## 5. Application repository — current main baseline

Repository: `akefadaka-git/arna-app`
Main SHA: `7b04286d4a20b3587249eed8b90281699492b4c7`

| Current path | Classification | Target / disposition |
|---|---|---|
| `src/features/people/PeopleDirectoryView.vue` | DOMAIN-OWNED | Keep within People feature. |
| `src/features/people/PeopleProfileView.vue` | DOMAIN-OWNED | Keep within People feature. |
| `src/features/people/api.ts` | DOMAIN-OWNED | Keep; consider subfolder `api/` when refactoring is justified. |
| `src/features/governance/GovernancePeopleDirectoryView.vue` | CROSS-DOMAIN / Governance | Governance owns its governance-specific view; consume People identity/profile API. |
| `src/features/governance/GovernancePersonProfileView.vue` | CROSS-DOMAIN / Governance | Governance owns authority/appointment view; avoid duplicate Person master logic. |
| `src/features/governance/people-service.ts` | UNRESOLVED / CROSS-DOMAIN | Inspect whether it duplicates People API client or contains Governance-specific orchestration. |

## 6. Application repository — PR #5 candidate

PR: https://github.com/akefadaka-git/arna-app/pull/5
Head: `faf16e769756dcb1bc01afab69c418b881c8d980`
Base: `7b04286d4a20b3587249eed8b90281699492b4c7`
State: open, unmerged; 13 commits; 4 changed files; 731 additions; 7 deletions.

| Path | Classification | Disposition |
|---|---|---|
| `docs/phase-2/PEOPLE-APPLICATION-IMPLEMENTATION-REGISTER.md` | LEGACY-TO-RELOCATE | `src/features/people/docs/Implementation/` |
| `src/features/people/PeopleDirectoryView.vue` | DOMAIN-OWNED | Keep. |
| `src/features/people/PeopleProfileView.vue` | DOMAIN-OWNED | Keep. |
| `src/features/people/api.ts` | DOMAIN-OWNED | Keep. |

## 7. Workflow, discovery and dependency checks required before relocation

No path move is approved by this register. Before any move:
1. Search all imports, namespace/autoload references, route mounts and service-provider discovery.
2. Inspect Laravel migration discovery and migration ledger behavior. Historical migration files must not be casually renamed or replayed.
3. Inspect PHPUnit configuration, test suite discovery and explicit workflow test paths.
4. Inspect workflow path filters, scripts and artifact paths.
5. Inspect Vue/TypeScript imports, route registry, feature exports and build aliases.
6. Inspect scripts that reference repository paths or local tooling.
7. Check for generated documentation, release notes and references in other domains.
8. Prepare a source-to-destination mapping and compatibility plan.
9. Execute relocation only in a separately authorized, isolated change with exact file manifest.
10. Verify exact-head discovery/build/tests after relocation; no local test execution is implied.

## 8. Known path-contract risk

The inspected API PR #31 tree relocates identity/API/concurrency tests into `mases_core/src/Domains/People/Tests/Feature/`, while the inspected clean-install workflow still references legacy `tests/Feature/...` paths. The legacy paths are absent from the PR #31 tree. This is a source-level mismatch requiring reconciliation before that workflow can provide valid People verification. No workflow was run for this register.

## 9. Status and next steps

- Inventory completeness: PARTIAL; this is a focused People-path inventory, not a complete repository-wide tracked-file proof.
- Ownership decision: ESTABLISHED.
- File relocation: NOT AUTHORIZED by this register.
- Source changes: NOT PERFORMED.
- Migrations, seeders, tests and workflows: NOT RUN.
- PR #31 / PR #5: unchanged and unmerged.
- Next P0 task: complete reference/discovery analysis and finalize every tracked file disposition, then submit P0 report for Owner closure review.
