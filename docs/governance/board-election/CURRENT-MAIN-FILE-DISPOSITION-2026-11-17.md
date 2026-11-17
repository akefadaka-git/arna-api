# ARNA Board Election — Current-Main Reconciliation and File Disposition

**Assessment date:** 2026-11-17  
**Scope:** Read-only comparison of API PRs #77/#127 and App PR #31 against their live destination `main` branches.  
**Disposition:** Reconciliation completed at file-path and integration-contract level. This is not an implementation, merge, migration, or deployment approval.

## 1. Exact repository and PR references

| Item | Ref / SHA | Reconciliation result |
|---|---|---|
| API `main` | `0506dab4a3088a3ee4a910b10792fa18f755a8bf` | Current comparison base |
| API PR #77 | `feat/board-election-a3`, head previously recorded as `63e61e17e4cfa1669fd8ef7b7e070f6f8f27b187` | 102 commits ahead, 191 behind; diverged from main |
| API PR #127 | `feat/age-a3-register-governance`, head previously recorded as `2c07a38a8aef1e7b8991b42c7427477ef624055e` | One commit ahead of PR #77, zero behind; modifies only the workflow controller |
| App `main` | `f179c6f964407e586081b40f793882cb4f949f2d` | Current comparison base |
| App PR #31 | `feat/board-election-a3`, head previously recorded as `f4f53b6e3d4d2a30fada64b48903c5b91a65dd54` | 26 commits ahead, 177 behind; diverged from main |
| Reconciliation branch | `docs/board-election-a2-current-main-reconciliation` | Created from API main SHA `0506dab4a3088a3ee4a910b10792fa18f755a8bf`; identical to main before this report commit |

The API PR #127 is a one-commit child of PR #77, not an independent current-main-ready change. Its single changed path is `mases_core/src/Domains/Governance/Http/Controllers/GovernanceElectionWorkflowController.php`, which does not exist on current API main.

## 2. API PR #77 — all 33 changed paths

Disposition meanings:
- **PRESERVE + ADAPT:** the path exists on current main. Keep current-main content and selectively port/reconcile the election-specific delta; do not replace the file with the stale branch version.
- **PORT AFTER REVIEW:** the path is absent on current main. Preserve the PR work as candidate implementation, but port it onto a fresh branch from current main only after checking current architecture, contracts and migrations.
- **CARRY AS EVIDENCE:** documentation is absent on main and may be carried only after its source/authority claims and provenance are checked.

| # | Changed path | Current-main check | File disposition |
|---:|---|---|---|
| 1 | `.github/workflows/arna-clean-install-mysql.yml` | Exists; main blob `59cee10abcd20917f63ec30bdcb74e71b069bfa8` | **PRESERVE + ADAPT.** Port election test triggers/steps and expected-table checks only after validating current workflow structure. The PR changes the migration-count assertion from 71 to 72; recalculate against current main rather than transplanting that literal. |
| 2 | `docs/governance/board-election/A1-ASSESSMENT-AND-SPECIFICATION.md` | Absent | **CARRY AS EVIDENCE.** Retain frozen scope; verify cross-references and do not reopen A1 for routine implementation. |
| 3 | `docs/governance/board-election/A2-EVIDENCE-RECONCILIATION-AND-CONTRACTS.md` | Absent | **CARRY AS EVIDENCE.** Preserve the blocker register and engineering contracts; update only by dated addendum when authoritative evidence changes. |
| 4 | `docs/governance/elections/ARNA-GOV-ELE-A2-RECONCILIATION-v1.0.0.md` | Absent | **CARRY AS EVIDENCE.** Check consistency with the frozen A2 baseline before publication into main. |
| 5 | `docs/governance/elections/ARNA-GOV-ELE-AUTHORITY-RECORD-001.md` | Absent | **CARRY AS EVIDENCE — SOURCE CHECK REQUIRED.** Confirm the cited signed GA instrument and evidence hash against the authoritative original; do not treat a repository narrative alone as legal authority. |
| 6 | `mases_core/src/Domains/Governance/Config/governance.php` | Exists; main blob `e9c7cc5080ac454e1583a46877b7d147b2a7232f` | **PRESERVE + ADAPT.** Port explicit committee framework values and fail-closed voting defaults as a narrow additive change. Keep voting disabled and Legal Advisor voting eligibility unresolved until authoritative instruments settle it. |
| 7 | `mases_core/src/Domains/Governance/Database/Migrations/2026_11_02_120000_create_governance_election_foundation_tables.php` | Absent | **PORT AFTER REVIEW.** Reconcile tenant keys, foreign keys, naming, indexes and dependencies with current schema before adding. |
| 8 | `mases_core/src/Domains/Governance/Database/Migrations/2026_11_02_160000_create_governance_election_hybrid_workflow_tables.php` | Absent | **PORT AFTER REVIEW.** Review all committee, register, ballot and workflow table contracts; “hybrid” in the filename must not activate hybrid/electronic voting. |
| 9 | `mases_core/src/Domains/Governance/Database/Migrations/2026_11_02_161000_create_governance_election_nomination_submissions.php` | Absent | **PORT AFTER REVIEW.** Reconcile canonical Person/shareholder references and unique constraints against current identity and tenant-access models. |
| 10 | `mases_core/src/Domains/Governance/Database/Migrations/2026_11_02_162000_add_authenticated_committee_identity.php` | Absent | **PORT AFTER REVIEW.** Confirm canonical authenticated-user/Person binding, tenant scoping and nullable/backfill behavior. |
| 11 | `mases_core/src/Domains/Governance/Database/Migrations/2026_11_02_163000_create_election_challenges_and_certification.php` | Absent | **PORT AFTER REVIEW.** Keep objection, appeal, certification and ratification authority separate; block final dispositions where legal authority is unresolved. |
| 12 | `mases_core/src/Domains/Governance/Database/Migrations/2026_11_02_164000_add_committee_vote_idempotency.php` | Absent | **PORT AFTER REVIEW.** Verify database-enforced uniqueness and race-safe duplicate protection, not only controller checks. |
| 13 | `mases_core/src/Domains/Governance/Database/Migrations/2026_11_02_165000_add_committee_vote_ballot_flag.php` | Absent | **PORT AFTER REVIEW.** Verify that committee screening votes cannot be confused with secret voter ballots or counted as election votes. |
| 14 | `mases_core/src/Domains/Governance/Database/Seeders/GovernancePermissionsSeeder.php` | Exists; main blob `12abc29abd69be338f199e86e32eed1c0f3c18dc` | **PRESERVE + ADAPT.** Add the seven proposed granular election permissions to the current seeder without replacing newer main permissions. Then wire each route/policy to the appropriate permission; the PR routes currently use generic `governance.election.manage` for committee, certification and ratification actions. |
| 15 | `mases_core/src/Domains/Governance/Events/GovernanceElectionLifecycleChanged.php` | Absent | **PORT AFTER REVIEW.** Event payload and dispatch coverage must include the exact actor, tenant, election, action and before/after values. Do not assume lifecycle events cover committee decisions. |
| 16 | `mases_core/src/Domains/Governance/Http/Controllers/GovernanceElectionController.php` | Absent | **PORT AFTER REVIEW.** Reconcile authorization, tenant resolution, pagination and validation with current API controller conventions. |
| 17 | `mases_core/src/Domains/Governance/Http/Controllers/GovernanceElectionWorkflowController.php` | Absent | **PORT AFTER REVIEW.** This is the main workflow/committee security surface. Preserve PR #127's candidate-intake correction as a separate diff. Require action-specific authorization, server-derived actor identity, race-safe state transitions, reasoned decisions and complete audit coverage. |
| 18 | `mases_core/src/Domains/Governance/Listeners/AuditGovernanceEvent.php` | Exists; main blob `305c4a023c3fd345a363473177bec8e4c0ac742e` | **PRESERVE + ADAPT.** Port the election lifecycle event handler into current main. Current listener catches audit exceptions and logs them (fail-open); this is not proof of durable, atomic committee decision audit. Design committee audit persistence/failure behavior explicitly before relying on it. |
| 19 | `mases_core/src/Domains/Governance/Models/GovernanceElection.php` | Absent | **PORT AFTER REVIEW.** Reconcile casts, relations, tenant ownership and guarded attributes with current model conventions. |
| 20 | `mases_core/src/Domains/Governance/Models/GovernanceElectionBallot.php` | Absent | **PORT AFTER REVIEW.** Preserve secret-ballot separation and custody state invariants. |
| 21 | `mases_core/src/Domains/Governance/Models/GovernanceElectionNomination.php` | Absent | **PORT AFTER REVIEW.** Separate intake, committee screening and ballot eligibility; no intake action may imply legal qualification. |
| 22 | `mases_core/src/Domains/Governance/Models/GovernanceElectionVoter.php` | Absent | **PORT AFTER REVIEW.** Link only to an approved, immutable, record-date register snapshot; entitlement must remain blocked until authoritative share-class rules are verified. |
| 23 | `mases_core/src/Domains/Governance/Policies/GovernanceElectionPolicy.php` | Absent | **PORT AFTER REVIEW.** Rebuild/reconcile against current tenant and authority policies. Ensure policy methods do not collapse distinct committee, register, ballot, complaint, certification and ratification powers. |
| 24 | `mases_core/src/Domains/Governance/Providers/GovernanceServiceProvider.php` | Exists; main blob `0f8d94a07d4dc67057abac81e7ba4b5bb43b3488` | **PRESERVE + ADAPT.** Merge only required bindings, policy registration and event listeners into current provider. Re-check imports and registration order against current main. |
| 25 | `mases_core/src/Domains/Governance/Routes/api.php` | Exists; main blob `7c0cd827b674d18cafa87b5ba049ea04b31999aa` | **PRESERVE + ADAPT.** Port route definitions into the current route tree, not the stale full file. Apply distinct granular permissions. Committee endpoints, objections, appeals, certification and ratification currently share broad manage/view guards; this is insufficient for least privilege and separation of duties. |
| 26 | `mases_core/src/Domains/Governance/Services/GovernanceElectionLifecycleService.php` | Absent | **PORT AFTER REVIEW.** Verify state machine preconditions and lock rows within transactions; opening a poll must require an approved, frozen, integrity-verified register. |
| 27 | `mases_core/src/Domains/Governance/Services/GovernanceElectionPhysicalBallotService.php` | Absent | **PORT AFTER REVIEW.** Verify ballot custody, duplicate issuance/receipt prevention, separation from identity and independent reconciliation. |
| 28 | `mases_core/src/Domains/Governance/Services/GovernanceElectionRegisterService.php` | Absent | **PORT AFTER REVIEW.** Implement as-of reconstruction from append-only shareholding entries, deterministic ordering/reversals, anomaly quarantine, hash and independent approval; current-quantity projections alone are insufficient. |
| 29 | `tests/Feature/Governance/GovernanceElectionEndpointsTest.php` | Absent | **PORT + EXPAND.** Add exact-current-main authenticated allow/deny, tenant isolation, IDOR, validation and route-permission tests. |
| 30 | `tests/Feature/GovernanceElectionWorkflowHttpTest.php` | Absent | **PORT + EXPAND.** Reconcile fixtures to canonical Person, tenant access and current governance setup; add transaction and failure-path cases. |
| 31 | `tests/Feature/GovernanceElectionWorkflowSecurityContractTest.php` | Absent | **PORT + EXPAND.** Source-contract tests are supplemental only; add executable authorization, role separation, conflict, recusal, quorum and audit-persistence tests. |
| 32 | `tests/Unit/Governance/GovernanceElectionLifecycleServiceTest.php` | Absent | **PORT + EXPAND.** Test every legal lifecycle transition, invalid transition and concurrent transition. |
| 33 | `tests/Unit/Governance/GovernanceElectionRegisterServiceTest.php` | Absent | **PORT + EXPAND.** Test historical reconstruction, deterministic ties, reversal chains, projection reconciliation, hash stability and quarantine. |

**API file count:** 33 changed paths: 6 exist on current main and require selective adaptation; 27 are absent and require port/review or evidence validation. The 27 absent paths are not approved for blind copying.

## 3. API PR #127 — stacked candidate-intake correction

| Path | Current-main status | Disposition |
|---|---|---|
| `mases_core/src/Domains/Governance/Http/Controllers/GovernanceElectionWorkflowController.php` | Absent on current API main; same new controller path introduced by PR #77 | **PRESERVE AS A SEPARATE DELTA.** Reapply its 23-addition/5-deletion candidate-intake correction after the base controller has been safely ported. Keep canonical active Person resolution, active individual shareholder/tenant access, shareholder-only nomination authority and self-nomination identity checks. Automated tests are still pending per PR description. |

PR #127 is not independently mergeable into current main while its parent controller remains absent. Do not retarget or merge it directly to main.

## 4. App PR #31 — all 10 changed paths

| # | Changed path | Current-main check | File disposition |
|---:|---|---|---|
| 1 | `src/features/elections/ElectionCommitteeWorkspace.vue` | Absent | **PORT AFTER API CONTRACT FREEZE.** Existing draft UI includes member appointment, recusal and nomination decisions, but accepts manually entered Person/User IDs, uses `window.prompt` for recusal scope, and calls multiple committee operations. Add server-backed identity selection, accessible recusal form, error/state handling, conflict declarations, audit evidence display and explicit role-aware action gates. |
| 2 | `src/features/elections/GovernanceElectionListView.vue` | Absent | **PORT AFTER API CONTRACT FREEZE.** Verify pagination, loading/error/empty states, tenant-safe detail navigation and Amharic-first accessibility. |
| 3 | `src/features/elections/README.md` | Absent | **CARRY/UPDATE.** Keep only verified endpoint and permission contracts; clearly label future endpoints as not available until backend delivery. |
| 4 | `src/features/elections/ShareholderCandidateReview.vue` | Absent | **PORT AFTER API CONTRACT FREEZE.** Validate candidate ownership, non-binding feedback semantics and permission boundaries; do not imply shareholder preference is a committee decision. |
| 5 | `src/features/elections/api.ts` | Absent | **PORT AFTER API CONTRACT FREEZE.** It currently defines calls for objections, appeals, certification and ratification as well as implemented-on-branch actions. Do not expose/call any endpoint until the matching backend route, policy and tests are verified. |
| 6 | `src/features/elections/labels.ts` | Absent | **PORT + REVIEW.** Confirm Amharic wording and complete labels for accessibility/error/confirmation states. |
| 7 | `src/features/elections/types.ts` | Absent | **PORT + RECONCILE.** The file itself warns that contracts are design-time only until matching backend delivery. Keep types aligned with the final verified API and do not encode unresolved voting entitlement as an active capability. |
| 8 | `src/router/routes-portal.ts` | Exists; main blob `8068cce3c6c6ebc4bba523d93afc48fe5c6a73b6` | **PRESERVE + ADAPT.** Add the candidate-review child route only after the target component and portal authorization/self-service API contract exist. Current main has no election route. |
| 9 | `src/router/routes-workspace.ts` | Exists; main blob `4e0758567a39ef5cd33c9f79251a66bd281059d9` | **PRESERVE + ADAPT.** Add election list/committee routes to the current governance route tree after permission contracts are finalized. Current main has no election route. |
| 10 | `src/router/shell-labels.ts` | Exists; main blob `92bca75a103e027b115d169312e9c481a755d403` | **PRESERVE + ADAPT.** Add the election nav item to the current main navigation in its established ordering and verify permission filtering and responsive labels. |

**App file count:** 10 changed paths: 3 current-main files need selective route/navigation adaptation; 7 feature files are absent and must be ported after API contracts are stable.

## 5. Current-main architecture and security findings

1. **No election feature is integrated into current API main.** The current Governance route file has no election endpoints; current main has no `GovernanceElection` model/controller/policy or election migrations at the PR paths. The current permission seeder has only `governance.election.view` and `governance.election.manage`.
2. **Current main already has substantial newer Governance work.** The existing Governance provider, routes, permission seeder and audit listener have later main content. The election branch's 191-commit API lag means these files must be adapted, not replaced.
3. **Granular permissions are proposed but not enforced by the PR routes.** PR #77 adds committee/register/nomination/ballot/count/complaint/certification permission slugs, but its routes still use broad `governance.election.manage` for committee appointment/recusal/decisions and certification/ratification. Route guards and policy abilities need a deliberate least-privilege matrix.
4. **Committee audit is not yet proven durable.** PR #77 adds a lifecycle event listener integration. Committee appointment, recusal and decisions must be shown to emit immutable, actor-attributed, tenant-scoped events. Current main's audit listener catches exceptions and logs them; this does not establish atomic audit persistence for sensitive committee actions.
5. **Committee governance controls are only partial.** Existing branch controller evidence shows appointment/recusal/nomination decision paths, but the earlier review found no verified immediate-family conflict enforcement, no proof of independent committee-majority semantics across all paths, no fully evidenced immutable decision ledger, and no committee administrator provisioning/revocation lifecycle.
6. **App work is not a finished committee system.** The workspace has a partial appointment/recusal/decision UI, while the PR description itself says detailed operational views are not yet complete. Some client methods describe future objection/appeal/certification/ratification APIs; their presence in TypeScript is not backend evidence.
7. **Legal and source blockers remain.** Operative MOA/Articles, share-class rights/equal-vote cap, record-date/proxy/disqualification rules, Legal Advisor voting eligibility and appeal authority remain unresolved or unverified. No voting-weight activation, electronic/hybrid activation, appeal finality, certification or ratification authority may be inferred.
8. **Verification evidence is stale or missing.** Earlier Governance authorization and tenant-isolation passes were on earlier A3 heads. API clean-install MySQL run #808 failed before index-name correction; a fresh exact-head MySQL run remains required. App workflow #537 passed on the historical PR head, but election-specific browser/component tests were not run. No production/shared database migration was run.

## 6. Recommended implementation branch strategy (not executed)

1. Keep API PR #77, stacked API PR #127 and App PR #31 open and unchanged as historical source branches.
2. Start a **fresh API implementation branch from current API main** only after this disposition is accepted as the source map. Port shared-file deltas selectively; port new files in dependency order; do not cherry-pick the entire 102-commit stale branch.
3. Keep candidate-intake correction from PR #127 as a separate commit on the fresh API branch after the base controller and identity contracts are reconciled.
4. Start the App implementation branch from current App main only after the API route/policy/response contracts are frozen. Port feature files and adapt the three existing route/navigation files.
5. Implement committee security foundation before broad UI expansion: administrator appointment/revocation authority, dedicated permissions, conflict declarations including immediate family, recusal scope, quorum and majority semantics, independent decision ledger, immutable audit events, idempotency/concurrency, and fail-closed behavior.
6. Run exact-head public/free CI, clean-install MySQL, authenticated allow/deny and tenant-isolation tests, committee concurrency/audit tests, and App browser/accessibility tests. No merge, deployment or production migration is included in this plan.

## 7. Explicit boundaries and final disposition

- **Read-only reconciliation:** COMPLETE for the 33 API PR #77 paths, the one stacked API PR #127 path, and the 10 App PR #31 paths against the live main refs above.
- **File-by-file disposition:** PUBLISHED in this report.
- **A3 implementation:** NOT STARTED in this reconciliation pass.
- **Tests/CI:** NOT RUN in this reconciliation pass.
- **Merge/deployment/production database:** NOT AUTHORIZED and NOT PERFORMED.
- **A1/A2:** Keep the previously frozen baseline closed; update only by evidence-backed dated addendum, not by reopening it for routine porting.
