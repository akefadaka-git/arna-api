# ARNA Board Election System — A2 Evidence Reconciliation and Contract Verification

**Status:** A2 ENGINEERING RECONCILIATION CLOSED WITH EXPLICIT EXTERNAL-AUTHORITY BLOCKERS  
**Baseline:** A1 frozen at `3c3060974e5f945c32694b7d2026a48c34cae70a`  
**API branch:** `feat/board-election-a3`  
**App branch:** `feat/board-election-a3`  
**Scope:** Read-only evidence and implementation-contract verification. No A1 reopening.

## 1. Closure rule

A2 has reconciled the available repository evidence and frozen the implementation contracts below. A2 is closed for engineering discovery and contract mapping. Items that require an unavailable signed company instrument, a source-ledger database inspection, or a verified current production dataset are explicitly marked UNRESOLVED / NOT VERIFIED. They remain hard gates for the affected A3/A4 operations. They do not invalidate the A1 baseline and do not authorize engineering to infer missing company policy.

Reopen A2 only for new authoritative evidence that materially contradicts a recorded contract, or when a concrete implementation artifact demonstrates a contract mismatch. Routine implementation work proceeds under A3.

## 2. Evidence register

| ID | Evidence inspected | Repository evidence | Finding |
|---|---|---|---|
| A2-E01 | ShareHoldingEntry model | `mases_core/src/Domains/Shareholder/Models/ShareHoldingEntry.php`, blob `7c30f03dffaf6635128f7d03aed06231b9030f9a` | Ledger row describes signed quantity, before/after balances, entry type, occurred_at and reversal linkage; model documentation defines posted-entry immutability. VERIFIED |
| A2-E02 | ShareHolding model | `mases_core/src/Domains/Shareholder/Models/ShareHolding.php`, blob `f3bb01411824ce95a7c2e25111b84671b8ada9fe` | Current quantity is a projection; ledger is authoritative; writer uses transaction and row lock; whole-share constraint documented. VERIFIED |
| A2-E03 | Election lifecycle service | `mases_core/src/Domains/Governance/Services/GovernanceElectionLifecycleService.php`, blob `c681a0464e8d270ecaa729885b74ce40eba021ed` | Authority verification, nomination opening, physical voting and poll closure are implemented; physical voting checks for existence of a register snapshot. VERIFIED as source contract; DB behavior not yet verified. |
| A2-E04 | Election workflow controller | `mases_core/src/Domains/Governance/Http/Controllers/GovernanceElectionWorkflowController.php`, blob `0e42711c7eb3bd9ba2a750cb3af7efb6378fc01e` | Controller includes tenant/resource authorization and workflow operations; exact endpoint-level behavior still requires DB-backed acceptance. VERIFIED as source inspected. |
| A2-E05 | Challenge/certification migration | `2026_11_02_163000_create_election_challenges_and_certification.php`, blob `7ba855e89d1dcc04103358125c09fae02a083523` | Objection and appeal submissions and certification references are persisted; schema has status, decision reason, decider and time, but no separately modeled review history/evidence-hash chain or adjudication authority mapping. PARTIAL. |
| A2-E06 | Hybrid election migration | `2026_11_02_160000_create_governance_election_hybrid_workflow_tables.php`, blob `73b5ab5451cd2db2e26e544b9aeff982a1d13821` | Committee, nomination, consultation and ballot-related structures exist; electronic voting is disabled by default in related configuration. VERIFIED as schema contract; DB migration execution not verified here. |
| A2-E07 | Governance config | `mases_core/src/Domains/Governance/Config/governance.php`, blob `ced203eadb72f97fd5f9900571b34cee4da04599` | Domain config documents electronic voting disabled and business decisions sourced from authoritative instruments rather than hard-coded assumptions. VERIFIED. |
| A2-E08 | API routes | `mases_core/src/Domains/Governance/Routes/api.php`, blob `6ecbba69c3a9e740e10eb12b8bc4b36e27d78e6c` | Route file inspected; the election controller is present in the domain route contract. Exact runtime route resolution remains an A4 test. |
| A2-E09 | App API client | `src/features/elections/api.ts`, blob `0fd44f7711fc4d4be2ab5d44e6305ae1e7e9a733` | Typed election status includes planned/nominating/voting/counting/certified/ratified/cancelled; voting method typed physical; client wrappers exist for election workflows. PARTIAL lifecycle coverage. |
| A2-E10 | App committee workspace | `src/features/elections/ElectionCommitteeWorkspace.vue`, blob `a083003f4b2a58d8fcb437c3c04b21318ba888f3` | Committee appointment, queue and recusal/decision interaction exists. Full lifecycle not present in this component. VERIFIED as inspected. |
| A2-E11 | App candidate review | `src/features/elections/ShareholderCandidateReview.vue`, blob `274ccbbee8995dd65a09e28f0ef5be6a6ef4459b` | Candidate review/nomination/consultation and objection submission interaction exists. Adjudication and appeal decision UI not evidenced. PARTIAL. |
| A2-E12 | App CI workflow | `.github/workflows/frontend-ci.yml`, blob `a965427d8adbd539e97468a44724963b8956f6a0` | Workflow config runs npm ci, build, build verification, public-build smoke, palette/theme and responsive-shell contract checks. Configuration verified; exact-PR-head CI result NOT VERIFIED. |

## 3. Frozen implementation contracts

### 3.1 Record-date reconstruction
- Use immutable ledger entries as historical source; never derive historical record-date holdings from current projection alone.
- Use business-effective `occurred_at`; retain stable deterministic ordering for same-time rows.
- Validate each entry invariant `balance_before + quantity = balance_after`.
- Respect signed quantity and reversal linkage; do not mutate original entries.
- Reconstruct balances per holding/share class at the approved cutoff.
- Resolve each holding to exactly one canonical shareholder/person in the tenant.
- Compare reconstructed latest ledger balance with `share_holdings.current_quantity`; quarantine differences.
- Do not auto-repair, auto-exclude or auto-include disputed balances.
- Whole-share constraint applies; fractional quantity is a data-integrity exception.
- Preserve per-entitlement source ledger provenance.

### 3.2 Register approval and freeze
- Draft → reconciled → submitted → approved → frozen.
- Approval must be a human-authorized action with actor, time, rationale and evidence.
- Snapshot content hash, record date, rule version, source cutoff and reconciliation summary must be immutable.
- Any correction creates a superseding snapshot and requires full reapproval.
- Poll opening must validate approved/frozen/integrity-verified snapshot, not mere row existence. Existing service currently checks snapshot existence only; strengthen this precondition in A3.

### 3.3 Committee and nomination
- Three active, non-recused committee_member roles are required for quorum.
- Legal Advisor remains ex officio and is not counted as a voting member absent authoritative evidence.
- Candidate or immediate family recusal applies to affected decisions.
- Majority and duplicate-vote protections must be transaction-safe and concurrency-tested.
- Candidate identity resolves through canonical tenant-scoped person/shareholder records.
- Shareholder feedback/preferences remain advisory and non-binding.

### 3.4 Objection and appeal
- Current schema persists submissions and decision fields, but schema alone does not establish authority, reviewer independence, reasoned workflow transitions or immutable audit evidence.
- A3 must implement case state machine, reviewer authority policy, conflict/recusal checks, reasoned decisions, immutable event history, evidence hashes, locking and idempotency.
- Appeals must not be finalized until competent appeal authority and procedure are evidenced in a signed governing instrument. No panel composition may be invented.
- Certification remains blocked by any submitted, under-review, appealed, unresolved or otherwise invalid case outcome.

### 3.5 Tally, certification and ratification
- Tally evidence is server-authoritative and independently reconciled.
- Certifier must be independent from tally preparer.
- Certification evidence hash must match the frozen tally package.
- Ratification is separate, performed by the competent body and an actor other than certifier.
- No automated agent determines or certifies election results.

### 3.6 API/App separation
- API owns authorization, tenant scoping, eligibility, vote weight, quorum, lifecycle transitions and authoritative state.
- App is a client only; it must not derive eligibility, quorum, vote weight or tally.
- Every workflow needs loading, empty, validation, forbidden, conflict, offline/error and pending states.
- Amharic/English, mobile responsiveness, semantic controls, keyboard operation and WCAG 2.2 AA are acceptance requirements.

## 4. Material evidence gaps and downstream blockers

| ID | Gap | Status | Required closure |
|---|---|---|---|
| A2-B01 | Signed operative MOA/Articles and amendments, including share-class voting rights and any equal-vote cap, not available in inspected repository evidence | UNRESOLVED | Verify signed current instrument and record exact sections/version before enabling vote weights |
| A2-B02 | Company-specific disqualification, proxy and record-date rules not independently evidenced by signed instruments | UNRESOLVED | Map each applied rule to operative legal/constitutional authority |
| A2-B03 | Legal Advisor voting/decision eligibility not resolved by inspected signed resolution | UNRESOLVED | Keep Legal Advisor non-voting until authoritative clarification |
| A2-B04 | Appeal authority, composition, procedure and finality not evidenced | UNRESOLVED | Do not finalize appeal or certify until authoritative instrument is produced |
| A2-B05 | Shareholding migration DDL and live ledger contents not fully inspected in this pass | NOT VERIFIED | A3 schema inventory and DB-backed ledger integrity/reconstruction tests |
| A2-B06 | Voter-register snapshot approval/freeze workflow not implemented end-to-end | MISSING | A3 implementation |
| A2-B07 | Objection/appeal review, decision and immutable audit event workflow incomplete | MISSING | A3 implementation |
| A2-B08 | App register, dispute, tally, certification and ratification screens/tests incomplete | MISSING | A3 implementation |
| A2-B09 | Exact App PR head and CI run result not confirmed in this evidence pass | NOT VERIFIED | Retrieve exact PR head; confirm CI run against that SHA |
| A2-B10 | API quorum correction at SHA `673896e7fb4abb5d73fc0202825b93bd1d0aaefd` has not been rerun on that exact SHA | NOT RUN | Exact-SHA API workflow and database-backed acceptance |
| A2-B11 | API PR mergeability and App PR mergeability not freshly verified | NOT VERIFIED | Verify before A4 closure; do not merge before all required gates pass |

## 5. Implementation contract traceability

| Requirement | API owner | App owner | Acceptance proof |
|---|---|---|---|
| Authority and rule version | Governance lifecycle service/controller | Election authority/rules screens | Unauthorized and invalid transition tests |
| Committee/recusal/quorum | Governance committee workflow | Committee workspace | 3-member quorum, recusal, candidate conflict and concurrency tests |
| Record-date ledger reconstruction | Shareholder ledger + Governance register service | Register preview and provenance | Historical fixtures, reversals, equal timestamps, fractional and integrity-gap tests |
| Projection reconciliation | Register reconciliation service | Discrepancy queue | Matching/mismatch and quarantine tests |
| Approval/freeze | Register approval/snapshot service | Approval and freeze controls | Role, hash immutability, superseding snapshot tests |
| Objections | Governance case workflow | Objection inbox/detail | Submission, review, reasoned decision and audit tests |
| Appeals | Competent authority workflow, only after instrument verified | Appeal inbox/detail | Independence, authority, finality and certification-block tests |
| Poll/ballot/tally | Governance ballot/tally service | Poll and tally workspace | Duplicate/replay, secrecy, reconciliation, concurrency tests |
| Certification/ratification | Governance certification service | Certification/ratification controls | Evidence hash, separation of duties and unresolved-case block tests |
| Accessibility/localization | API error contract + App design system | All election screens | WCAG 2.2 AA, Amharic/English and device acceptance |

## 6. A2 exit decision

**A2 engineering evidence reconciliation: CLOSED.** The implementation contracts and evidence register are frozen for A3. This closure does not represent legal clearance, production readiness, or acceptance-test success. External authority blockers B01–B04 and verification gaps B05/B09/B10/B11 remain explicitly open and fail-closed. They are not grounds to reopen A1.

Proceed directly to A3 implementation against this contract. A3 must close implementation gaps; A4 must prove behavior on exact API/App PR heads and supported MySQL/device environments. A5 remains separately authorized only.

## 7. No-reopen instruction

Do not reopen A1 or repeat the A2 repository inventory for routine coding, test repair, UI completion or migration work. Maintain this document as the A2 evidence baseline. Update it only with a dated evidence addendum when a specific authoritative instrument or verified implementation result changes a recorded status.
