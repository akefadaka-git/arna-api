# Priority 3 — Candidate Intake and Eligibility: A2 Read-only Reconciliation

**Status:** A2 dependency reconciliation complete; implementation paused for full-system architecture approval  
**Repository:** `akefadaka-git/arna-api`  
**Read-only baseline:** `main` at `dc2c53aa1b0b0d192acff53880848c9e517ecf67`  
**Dependency:** Priority 2 committee-security foundation merged by PR #160  
**Design/assessment branch:** `a3/board-election-candidate-intake-current-main`

## 1. Current-main verification

The merged committee-security migration is present on current main:
- `mases_core/src/Domains/Governance/Database/Migrations/2026_11_17_100000_create_election_committee_security_foundation.php`
- `GovernanceElectionCommitteeSecurityService`, controller, permission seeder, tests and four protected routes are present.
- The committee-security migration creates committee-admin, conflict and security-event tables. It does **not** create `governance_elections`, candidate intake, nomination or candidate-screening tables.
- The merged-SHA MySQL evidence artifact for run #44249463605 lists 77 migrations as run, no pending migrations, and the committee-security migration as run. It does not list an election-core or nomination-submission migration.

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

PR #128 is stacked on PR #127 and adds register preparation/reconciliation/freeze. Its stated MySQL, automated security and operational acceptance are pending. It is outside the present candidate-intake implementation gate and remains untouched.

## 3. Reconciliation decisions

1. Preserve PRs #77, #127 and #128 and their branches as historical/source references; do not merge or retarget them as part of this A2.
2. Do not cherry-pick PR #127's controller-only patch: its referenced election model, lifecycle, routes and schema do not exist on current main.
3. Reuse canonical Person, shareholder relationship, tenant access, authorization and the merged committee-security authority/conflict services. No parallel identity or committee authority model.
4. Keep nomination intake separate from legal qualification and ballot approval. Record candidate identity, nominator authority and evidence references separately from committee screening decisions.
5. Non-shareholder candidacy remains permitted where the frozen A1 rule and governing instruments allow it; nomination authority remains shareholder-governed. Self-nomination must resolve to the authenticated shareholder's own canonical Person.
6. Candidate screening decisions must be election- and tenant-scoped, attributable, evidence-backed, conflict-checked and protected by least-privilege committee decision authority. A conflicted/recused committee member must fail closed.
7. No company-specific qualification, disqualification, share-class or voting rule may be inferred where the operative governing instrument is absent. Unknown legal qualifications remain unresolved and cannot be silently marked eligible.
8. The user's updated delivery direction supersedes the earlier narrow "minimum foundation only" implementation suggestion: complete the full election-system architecture and cross-module contracts first, then implement complete modules in dependency order.
9. No deployment, production migration, merge or A5 action is authorized by this A2 record.

## 4. Full-system design-first delivery contract

The complete architecture is recorded in:
`docs/governance/board-election/AGE-V1-FULL-SYSTEM-ARCHITECTURE-DESIGN.md`.

The design covers:
- election governance, authority, lifecycle and versioned rule snapshots;
- committee authority, conflict and recusal;
- candidate intake, nominations, evidence and eligibility;
- shareholder entitlement and immutable voter register;
- secret ballot, issuance, custody and cryptographic separation;
- physical, electronic, proxy and hybrid voting channel gates;
- witnessed counting, results and reconciliation;
- objections, appeals, certification and ratification;
- document, notification, calendar, identity, shareholder and Finance integrations;
- ARNA App role-based experience, Amharic-first/mobile-first accessibility;
- privacy, retention, observability, AI guardrails and independent security review;
- module dependency order and exact-SHA acceptance gates.

Implementation order is:
1. Freeze full architecture and traceable authoritative-rule inputs.
2. Election foundation and lifecycle.
3. Candidate intake, nominations and eligibility.
4. Shareholder entitlement and voter register.
5. Ballot security and custody.
6. Physical/electronic/proxy/hybrid channels.
7. Counting and results.
8. Objections and appeals.
9. Certification and ratification.
10. API/App integration and integrated A4.
11. A5 deployment only after separate Owner authorization.

Each module gets its own A2 file-by-file reconciliation, A3 implementation, A4 exact-SHA verification and closure record. Do not build all modules in one PR and do not create disposable schema that a later module must replace.

## 5. Candidate intake acceptance requirements

Once the full design is approved and frozen, the candidate module must satisfy:
- election-scoped nomination window and authority/rule preconditions;
- canonical active Person verification;
- authenticated shareholder nomination authority and self-nomination identity binding;
- non-shareholder candidate intake where permitted without conferring shareholder rights;
- separate screening states for pending, evidence-incomplete, eligible, ineligible and deferred/unresolved;
- evidence references and reviewer rationale persisted with actor/time;
- committee decision permission and conflict/recusal guard;
- append-only security audit integration;
- duplicate submission/idempotency and concurrent review protection;
- tenant isolation, authorization, negative-path and fresh-MySQL tests;
- exact-head workflow evidence before any merge.

## 6. Gate result

**A2 dependency reconciliation: VERIFIED — read-only assessment complete.**  
**Full-system architecture: DOCUMENTED — pending Owner review and freeze.**  
**A3 implementation: PAUSED until architecture approval/freeze.**  
**A4/A5: NOT RUN / NOT AUTHORIZED.**

No API source code, migration, route, permission or `main`-branch file was modified by this reconciliation or by the architecture design record.