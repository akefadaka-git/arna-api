# AGE v1.0 — Module 2 Candidate Intake, Nominations and Eligibility
## A2 Read-only Dependency and File-by-File Reconciliation

**Status:** A2 assessment complete; no Module 2 implementation performed  
**Repository:** `akefadaka-git/arna-api`  
**Current API main:** `dc2c53aa1b0b0d192acff53880848c9e517ecf67`  
**Protected Module 1 A4 baseline:** `9fe56f3c56da163c3da9dcd3c12a69adfb02b251`  
**A2 branch:** `a2/module-2-candidate-intake-read-only`  
**Related PRs preserved:** #77, #127, #128; Module 1 PR #164 remains draft and unmerged  
**Scope:** Candidate identity/intake, shareholder nomination authority, evidence, committee screening, eligibility decisions and candidate-decision history  
**Excluded:** voter-register reconstruction/freeze (Module 3), ballot/custody (Module 4), voting-channel activation (Module 5), count/certification/ratification (later modules), deployment/A5

---

## 1. A2 control statement

This is a read-only reconciliation against current API main, using the exact verified Module 1 branch as the protected dependency reference. It records existing source and design decisions; it does not modify API application source, migrations, routes, permissions, tests or `main`.

Module 1 A4 is verified on `9fe56f3c56da163c3da9dcd3c12a69adfb02b251`. Its draft PR #164 is not merged. Module 2 must therefore be developed as a dependent branch from that exact verified baseline (or a later explicitly approved, verified equivalent), not from an unmerged historical election branch and not from current main alone.

The frozen AGE v1.0 requirements and full-system architecture remain binding. No missing legal qualification, nominator threshold, share-class right, candidate disqualification or appeal authority may be invented.

## 2. Required Module 2 outcome

Deliver a tenant- and election-scoped candidate lifecycle that keeps five distinct concepts separate:

1. **Canonical Person identity** — an active natural-person record in the existing People domain.
2. **Nomination submission** — an auditable proposal submitted by an authenticated shareholder or an authorized representative under the operative election rules.
3. **Candidate intake** — identity, consent/acceptance, declarations and evidence completeness; intake is not legal eligibility.
4. **Eligibility screening and decision** — an authorized, conflict-cleared human committee decision against a versioned, authoritative rule set.
5. **Appeal/correction reference** — a challenge to a candidate decision is preserved and routed to the later independent objections/appeals capability; it must not silently overwrite the original decision.

Candidate eligibility must never be inferred from shareholder status. A qualified non-shareholder may be a candidate only where the applicable governing instrument and approved election rules permit it. A candidate does not acquire shareholder status or voting rights by being nominated.

## 3. File-by-file current-main reconciliation

| Current-main path / source | Observed contract | Module 2 disposition |
|---|---|---|
| `mases_core/src/Domains/People/Models/Person.php` | Canonical natural-person identity; soft deletes; immutable public UUID; separate account and tenant access relations; `shareholders()` relation | Reuse as the only candidate person identity. Require active status and non-deleted row. Never create a candidate-specific Person/User table. |
| `mases_core/src/Domains/People/Models/PersonTenantAccess.php` | Explicit tenant visibility record with active/revoked status | Reuse to prove a non-shareholder candidate has active tenant access. A Person record alone does not establish tenant eligibility or visibility. |
| `mases_core/src/Domains/People/Services/PersonIdentityService.php` | Tenant membership and `people.manage` checks; canonical Person create/link operations; shareholder linking is tenant-scoped and limited to individual shareholders; checks active Person access and account consistency | Reuse canonical linkage and do not write `shareholders.person_id` directly. Module 2 may read the established link, not reimplement or bypass People identity lifecycle. |
| `mases_core/src/Domains/Shareholder/Models/Shareholder.php` | Tenant-scoped shareholder identity; individual/legal-entity types; individual `person_id`; direct `user_id`; legal-entity representative; active flag and soft delete; current holdings are separate | Reuse as the nominator/holder record. A legal-entity representative may act for the shareholder where the operative rules permit; do not treat the representative as the shareholder or candidate automatically. |
| `mases_core/src/Domains/Shareholder/Services/ShareholderService.php` | Sole write path for shareholder identity; enforces shareholder type/name and representative invariants | No direct mutation of shareholder identity from Governance. |
| `mases_core/src/Domains/Shareholder/Models/ShareHolding.php` | Current-state projection for shareholder/share-class pair; not authoritative historical source | Do not use current balance as proof of record-date nomination entitlement. |
| `mases_core/src/Domains/Shareholder/Models/ShareHoldingEntry.php` and shareholder ledger migrations/services | Append-only, reversible shareholding ledger with effective `occurred_at`; entries and balance projections are transactionally maintained | Use only through a separately approved record-date/nomination-authority contract. Any as-of entitlement must reconstruct from ledger evidence; no current-balance shortcut. Full voter-register/weight reconstruction belongs to Module 3. |
| `mases_core/src/Domains/Governance/Services/GovernanceElectionCommitteeSecurityService.php` (merged main) | Election-scoped committee grants, granular permissions, conflict declarations/recusal and durable security event ledger | Reuse. Enforce current committee authority and conflict/recusal at each screening/decision action, not only at page load or login. |
| `mases_core/src/Domains/Governance/Database/Migrations/2026_11_17_100000_create_election_committee_security_foundation.php` (merged main) | Committee-security tables exist, but no canonical election aggregate existed until Module 1 | Module 1's verified composite tenant/election FK integration is the dependency. Do not add parallel committee tables. |
| Module 1 `GovernanceElection`, lifecycle service, transition model/migration, controller and routes on #164 | Canonical tenant-scoped election aggregate, frozen election invariants, guarded lifecycle, version checks, reason/evidence references, action-specific permissions and audit event | Depend on this exact verified baseline. Candidate intake opens only during an explicit nominations-open state/window; Module 1 currently provides foundation states only, so Module 2 must add its lifecycle extension through guarded, additive transitions without replacing Module 1. |
| `mases_core/src/Files/Services/FileService.php` and canonical File model | Tenant membership, `file.view` authorization, published-file lookup and canonical file identity | Reuse for candidate evidence. Store file IDs/references and digests; do not duplicate file blobs or bypass FileService. |
| `AuthorizationService`, existing Governance route/provider conventions and `AuditService` | Central permission evaluation, tenant-aware routes and audit writing | Use object-level service authorization in addition to route middleware; persist durable decision history and security/audit events transactionally or through a reliable after-commit/outbox pattern. |

### 3.1 Confirmed absences on current main

At current main SHA `dc2c53aa1b0b0d192acff53880848c9e517ecf67`:

- There is no canonical `GovernanceElection` aggregate or election lifecycle controller/model/migration. Module 1 supplies these on its protected A4 branch only.
- There is no candidate intake or nomination-submission table/model/service.
- There is no candidate-specific evidence/declaration aggregate or immutable eligibility-decision history.
- There is no candidate eligibility policy/permission catalogue integrated with the current main election domain.
- The committee-security foundation exists, but it is not itself a candidate workflow.

## 4. Historical PR reconciliation

### PR #77 — broad election stack

- URL: https://github.com/akefadaka-git/arna-api/pull/77
- Branch: `feat/board-election-a3`
- Head: `63e61e17e4cfa1669fd8ef7b7e070f6f8f27b187`
- Contains 30+ changed files spanning election foundation, lifecycle, nomination, candidate review, register, ballots, physical voting, challenges and certification.
- It predates the merged committee-security foundation and the new Module 1 aggregate.
- Its controller and migration contracts use the old election schema/status model. They cannot be merged wholesale or used as Module 2's base.

**Disposition:** Preserve as a historical requirements and code reference. Port only individually reconciled concepts after adapting them to Module 1's canonical aggregate and the frozen architecture.

### PR #127 — candidate-intake adjustment

- URL: https://github.com/akefadaka-git/arna-api/pull/127
- Branch: `feat/age-a3-register-governance`
- Head: `2c07a38a8aef1e7b8991b42c7427477ef624055e`
- One controller-only patch stacked on #77.
- It permits candidate intake for a canonical Person with either an active individual-shareholder link or active Person tenant access, separating intake from legal qualification.

The intent is aligned with frozen AGE requirements, but the patch is not safe to cherry-pick. Its implementation has unresolved correctness/security defects:

1. Candidate lookup filters `deleted_at` but does not require `people.status = active`.
2. The expression `candidateShareholderCount === 1 || candidateHasTenantAccess` allows a Person with multiple active individual shareholder matches to pass if tenant access also exists. Ambiguous shareholder identity must fail closed.
3. The authenticated nominator lookup does not filter inactive or soft-deleted shareholder records.
4. `authenticatedShareholderId()` accepts both `user_id` and `representative_user_id`; the self-nomination branch only compares Person IDs. It does not prove that the authenticated account is the candidate's own direct account. A representative must not be mislabeled as a self-nominator.
5. `updateOrInsert()` rewrites an existing submission (including status and timestamps) rather than preserving a submitted event as immutable evidence. It is not a safe idempotent resubmission or correction protocol.
6. Submission is protected by `governance.election.view`/view policy in the historical route/controller, not a dedicated nomination-submit permission and action-level authorization.
7. The old submission table has no tenant/election composite foreign keys to the canonical Module 1 aggregate, no canonical Person/shareholder FKs, and no decision-history or idempotency-key contract.
8. The patch does not establish candidate consent/acceptance, declarations, evidence completeness, or a separately persisted eligibility decision.
9. The patch does not resolve the authoritative record-date/nomination-right rule. The system must not infer a nominator share threshold or eligibility from a current holding.

**Disposition:** Preserve the patch as a source reference only. Do not cherry-pick or merge it. Reimplement its valid non-shareholder-intake intent against the Module 1 aggregate and corrected controls.

### PR #128 — voter register stack

- URL: https://github.com/akefadaka-git/arna-api/pull/128
- Branch: `feat/age-a3-voter-register-approval`
- Head: `1a157aaa1a06788e33dca53c4333b8abad36127f`
- Adds register preparation/reconciliation/freeze changes, stacked on #127 and #77.
- It is a Module 3 concern and depends on the old election schema.

**Disposition:** Preserve untouched. Do not port its register implementation into Module 2. Module 2 may expose stable candidate/nomination references for Module 3, but must not own register reconstruction, voting weight or freeze.

## 5. Required Module 2 contracts

The following is the A2 implementation contract proposal; exact physical names may follow repository naming conventions after schema collision review.

### 5.1 Candidate aggregate

A tenant/election-scoped candidate aggregate keyed to the canonical `people.id`, with:

- candidate ID, tenant ID, election ID, canonical Person ID;
- candidate classification/evidence summary (shareholder or non-shareholder; not an entitlement grant);
- intake status and explicit consent/acceptance;
- declaration and conflict-disclosure status;
- evidence completeness state and canonical document references/digests;
- eligibility state: `pending`, `evidence_incomplete`, `eligible`, `ineligible`, `deferred_unresolved`, plus controlled `withdrawn`, `appealed` and `superseded` states;
- aggregate version and append-only transition/decision history.

A unique tenant/election/Person candidate key should prevent duplicate candidate aggregates while allowing multiple independent nominators to submit nomination records.

### 5.2 Nomination submission

Each submission must preserve:

- tenant/election/candidate IDs;
- nominating shareholder ID and authenticated submitting User ID;
- whether it is self-nomination, with explicit proof of direct account-to-Person identity;
- submission channel, received timestamp, idempotency key and source reference;
- statement, evidence references and applicable nomination-rule version/digest;
- immutable submitted payload digest and append-only status/correction/withdrawal history.

Multiple shareholders may nominate the same candidate. Repeated requests with the same idempotency key must return the original receipt without rewriting the submission. A correction or withdrawal creates a linked event/version; it does not overwrite the original submission.

### 5.3 Eligibility decision and independence

- Intake validation and legal eligibility are separate.
- Screening/decision is restricted to active, authorized, non-recused committee members with the required action-specific permission.
- Enforce separation of duties and candidate/submitter/reviewer conflicts at the point of decision.
- Decisions require outcome, versioned rule reference, evidence references/digests, rationale, actor, timestamp and expected aggregate version.
- Ineligible/deferred decisions require reason and must preserve the original record.
- Appeal submission does not erase the initial decision; independent appeal adjudication is completed under the later objections/appeals module and competent authority.

### 5.4 Authority and legal-rule gate

Before nomination intake is treated as legally open, the election must have an approved, versioned nomination rule set and authoritative source reference. The system must not hard-code:

- minimum share count or ownership percentage for nominators;
- record-date or continuous-holding requirements;
- candidate qualifications/disqualifications;
- shareholder class-specific nomination rights;
- nomination caps, signatures or supporting-nominator counts;
- appeal authority, deadlines or remedies.

Where an operative instrument is absent or ambiguous, the decision remains `deferred_unresolved`; it cannot become eligible by default. The implementation may collect a clearly marked draft/intake record only if the competent authority has separately authorized such pre-intake collection. It must not claim that a legally valid nomination window is open without Module 1 lifecycle and authority evidence.

### 5.5 Permissions and events

Proposed least-privilege actions (final slugs to be reconciled with the permission catalogue during A3):

- candidate intake/view;
- nomination submit, withdraw and correct;
- evidence view/attach;
- candidate screen;
- eligibility decide/defer;
- conflict disclose/recuse;
- candidate appeal submit;
- candidate decision/audit view.

Reuse the existing committee grant and conflict/recusal system. Do not grant permissions automatically to committee appointments.

Expected events include `NominationSubmitted`, `NominationWithdrawn`, `CandidateIntakeCompleted`, `CandidateEvidenceAdded`, `CandidateConflictDisclosed`, `CandidateScreeningDecisionRecorded`, `CandidateEligibilityDecided` and `CandidateAppealOpened`. Events carry IDs, rule/evidence references and digests, never unnecessary sensitive document contents.

## 6. A2 acceptance and risk matrix

| ID | Requirement / risk | A2 result | A3 acceptance evidence |
|---|---|---|---|
| M2-01 | Canonical active Person identity | Existing People model/service reusable | Negative tests for missing, inactive, deleted and cross-tenant Person |
| M2-02 | Non-shareholder candidacy where rules permit | Supported by frozen design; old PR #127 partially attempts it | Active Person + active tenant access; legal-rule gate; no shareholder rights inferred |
| M2-03 | Unambiguous shareholder link | Old patch has ambiguous-match bypass | Exactly one active individual shareholder when classified as shareholder; duplicates fail closed |
| M2-04 | Nominator authority | Historical helper accepts inactive/deleted holders and lacks rule-version check | Active shareholder/representative authority, election rule and record-date evidence; unknown threshold remains unresolved |
| M2-05 | Self-nomination | Historical patch does not prove direct account identity | Authenticated User must be the candidate Person's own canonical linked account; representatives cannot claim self-nomination |
| M2-06 | Submission integrity | Historical upsert mutates submission state | Immutable receipt, idempotency key, correction/withdrawal events and concurrency tests |
| M2-07 | Evidence management | Canonical Files domain exists | FileService tenant/permission checks, published file status, digest, evidence access audit |
| M2-08 | Eligibility separation | Old workflow partly mixes nomination and committee candidate state | Separate candidate, nomination and eligibility decision aggregates/states |
| M2-09 | Committee authority/conflict | Priority 2 foundation exists on main | Action-time permission, active grant, conflict/recusal and separation-of-duties tests |
| M2-10 | Durable audit/history | Audit and committee security ledger exist | Transactional decision history, idempotent after-commit/outbox events, tamper/rollback tests |
| M2-11 | Tenant isolation | Current domains provide patterns; old nomination migration lacks composite FKs | Composite tenant/election FKs and negative cross-tenant tests |
| M2-12 | Legal qualification | Authoritative candidate rules remain unresolved in A1 register | Versioned source/rule evidence; unresolved stays blocked; no inferred qualification |
| M2-13 | Module boundary | PR #128 register changes are stacked and out of scope | Module 2 does not calculate voting weight, prepare/freeze register or activate channels |
| M2-14 | Exact-SHA verification | Module 1 baseline is verified | Fresh MySQL, full migration, lifecycle, tenant, authorization, concurrency and security tests for Module 2 exact head |

## 7. Module 2 A3 implementation sequence

After explicit Owner authorization:

1. Reconcile exact schema names, existing permission slugs, event/outbox conventions and migration constraints against Module 1 A4 baseline.
2. Add candidate and nomination aggregates with tenant/election composite FKs and immutable submission/decision histories.
3. Implement canonical Person/shareholder resolution through existing People/Shareholder services; no duplicate identity model.
4. Implement nomination authority and self-nomination guards, fail-closed on missing rule/source or ambiguous identity.
5. Implement candidate evidence and declarations using FileService and versioned rule/evidence references.
6. Integrate committee action permissions, active authority grants, conflict/recusal checks and independent decision controls.
7. Add correction/withdrawal/appeal references without implementing the later independent appeal adjudication module.
8. Add route/controller/service contracts, tenant-aware policy checks, audit events and idempotency.
9. Add feature, negative, concurrency, migration and MySQL regression coverage; independently verify the exact resulting SHA.

## 8. Explicitly excluded and unresolved

- No candidate eligibility criteria are invented.
- No nominator share threshold or record-date rule is assumed.
- No candidate is automatically eligible because intake succeeded.
- No legal entity is treated as a natural-person candidate; an authorized representative acts for the entity only where the governing rules allow, and is not the entity.
- No ballot, voter register, vote weight, channel activation, tally, certification or ratification implementation is included.
- No PR #77/#127/#128 merge, cherry-pick, retarget or closure is performed.
- No production migration, deployment or A5 action is performed.

## 9. A2 closure

**A2 file-by-file reconciliation: COMPLETE — read-only.**  
**Module 1 dependency: VERIFIED — exact A4 SHA `9fe56f3c56da163c3da9dcd3c12a69adfb02b251`.**  
**Historical PR disposition: #77/#127/#128 PRESERVED; none is safe to merge wholesale.**  
**Module 2 A3: NOT STARTED — requires explicit Owner authorization.**  
**A5 / production: NOT AUTHORIZED.**

No API source code, migration, route, permission, test or main-branch file was modified by this A2 reconciliation.
