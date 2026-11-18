# Priority 3 — Candidate Intake and Eligibility: A2 Read-only Reconciliation

**Status:** A2 assessment complete; A3 implementation not yet started  
**Repository:** `akefadaka-git/arna-api`  
**Read-only baseline:** `main` at `dc2c53aa1b0b0d192acff53880848c9e517ecf67`  
**Dependency:** Priority 2 committee-security foundation merged by PR #160  
**Assessment branch:** `a3/board-election-candidate-intake-current-main`

## 1. Current-main verification

The merged committee-security migration is present on current main:
- `mases_core/src/Domains/Governance/Database/Migrations/2026_11_17_100000_create_election_committee_security_foundation.php`
- `GovernanceElectionCommitteeSecurityService`, controller, permission seeder, tests and four protected routes are present.
- The committee-security migration creates committee-admin, conflict and security-event tables. It does **not** create `governance_elections`, candidate intake, nomination or candidate-screening tables.
- The MySQL evidence artifact for the merged SHA lists 77 migrations as run, no pending migrations, and the committee-security migration as run. It does not list an election-core or nomination-submission migration.

## 2. Existing candidate-intake proposal

PR #127:
- URL: https://github.com/akefadaka-git/arna-api/pull/127
- Branch: `feat/age-a3-register-governance`
- Head: `2c07a38a8aef1e7b8991b42c7427477ef624055e`
- Base: PR #77 branch `feat/board-election-a3`, base SHA `63e61e17e4cfa1669fd8ef7b7e070f6f8f27b187`
- PR #127 changes one controller file by +23/-5 lines, but is stacked on PR #77. Relative to current main its branch is 103 commits ahead and 199 behind. It is not safe to merge or cherry-pick as a whole.

The proposed candidate-intake change:
- accepts a canonical active Person who is either an active individual shareholder or has active tenant access;
- keeps legal qualification as a separately evidenced committee screening decision;
- restricts nomination submission to an authenticated shareholder and self-nomination to that shareholder's own Person identity.

The controller change depends on code and schema that are not present on current main, including:
- `governance_elections` and `GovernanceElection`;
- the election workflow controller and its lifecycle service;
- `governance_election_nomination_submissions`;
- election policy registration and lifecycle routes;
- election permission registration and service-provider wiring.

PR #77 contains the broader stacked election foundation and lifecycle set (30+ changed files), including voter register, ballots, physical voting, challenges/certification and related workflows. It is not a focused candidate-intake patch and must not be merged wholesale into current main.

PR #128 is stacked on PR #127 and adds register preparation/reconciliation/freeze. Its stated MySQL, automated security and operational acceptance are pending. It is outside Priority 3 and remains untouched.

## 3. Reconciliation decisions

1. Preserve PRs #77, #127 and #128 and their branches as historical/source references; do not merge or retarget them as part of this A2.
2. Do not cherry-pick PR #127's controller-only patch: its referenced election model, lifecycle, routes and schema do not exist on current main.
3. Build Priority 3 on a new branch from the exact merged Priority 2 SHA.
4. Implement only the minimum election and nomination foundation needed for candidate intake and eligibility. Do not import ballot, register, tally, certification, electronic/hybrid or appeal workflows into this priority unless an essential dependency is demonstrated.
5. Reuse the canonical Person and PersonTenantAccess models and the merged committee-security authority/conflict service.
6. Nomination intake must not imply legal eligibility or ballot approval. Record candidate identity, nominator authority and evidence references separately from committee screening decisions.
7. Non-shareholder candidacy remains permitted where the A1 rule allows it; nomination authority remains shareholder-governed. Self-nomination must resolve to the authenticated shareholder's own canonical Person.
8. Candidate screening decisions must be election- and tenant-scoped, attributable, evidence-backed, conflict-checked and protected by the committee's least-privilege decision authority. A conflicted/recused committee member must fail closed.
9. No company-specific qualification, disqualification, share-class or voting rule may be inferred where the operative governing instrument is absent. Unknown legal qualifications remain unresolved and cannot be silently marked eligible.
10. No deployment, production migration, merge or A5 action is authorized by this A2 record.

## 4. Priority 3 acceptance criteria

- Fresh migrations for only the required election/nomination/screening foundation; no duplicate or overlapping migration names.
- Tenant-scoped election identity and explicit authority/rule/window preconditions before intake.
- Canonical active Person verification; active tenant relationship or exactly one valid individual shareholder relationship.
- Authenticated shareholder nomination authority; self-nomination identity binding.
- Non-shareholder candidate intake supported without granting shareholder voting or nomination rights.
- Separate screening states for pending, evidence-incomplete, eligible, ineligible and deferred/unresolved; no intake-to-approval shortcut.
- Evidence references and reviewer decision rationale persisted with actor/time.
- Committee decision permission, conflict/recusal guard and hash-chained audit event integration.
- Duplicate submission/idempotency and concurrent review protection.
- Tenant isolation, authorization, negative-path and MySQL integration tests.
- Exact-head workflow evidence before merge; merge/cleanup only after all required checks pass.

## 5. Gate result

**A2: VERIFIED — read-only reconciliation complete.**  
**A3: READY TO IMPLEMENT on the assessment branch, based on the merged Priority 2 SHA.**  
**A4/A5: NOT RUN / NOT AUTHORIZED.**

No API source, migration, route, permission, or main-branch file was modified by this reconciliation.
