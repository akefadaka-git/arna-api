# ARNA Board Election System — A1 Assessment and Specification

**Status:** A1 CLOSED — requirements baseline frozen  
**API repository:** `akefadaka-git/arna-api`  
**Implementation branch:** `feat/board-election-a3`  
**Baseline API SHA:** `673896e7fb4abb5d73fc0202825b93bd1d0aaefd`  
**App repository:** `akefadaka-git/arna-app`, PR #31

## 1. Closure and change control

A1 establishes the requirements baseline, legal/governance constraints, domain boundaries, authoritative data sources, lifecycle, security controls and acceptance criteria. This baseline is frozen for A2–A4. Do not reopen A1 for ordinary implementation discoveries; record and resolve those within the approved design. Reopen only when verified law, a signed governing instrument, authoritative shareholder/ledger evidence, or a material architecture constraint contradicts a baseline decision. Any exception must identify the evidence and exact affected requirement. No silent policy substitution is permitted.

## 2. Goals and non-goals

**Goals:** tenant-isolated end-to-end Board election lifecycle; authority and rule verification; record-date voter-register reconstruction from immutable shareholding ledger; projection reconciliation and discrepancy quarantine; approval and immutable freeze; nominations and committee decisions; physical voting; tally evidence; objections and appeals; independent certification and separate ratification; matching bilingual mobile-first API/App workflows.

**Prohibited assumptions:** do not invent MOA/Articles, share-class rights, voting caps, disqualifications, proxy rules or appeal authority. No AI, smart contract or automated agent may decide eligibility, adjudicate disputes, authoritatively tally, certify or ratify. Electronic voting remains disabled until election-specific legal authority, security and operational acceptance are evidenced. A1 closure does not authorize merge or production deployment.

## 3. Governance and legal baseline

The signed GA resolution defines the Election Committee:
- Three GA members who are not Board members.
- One Legal Advisor ex officio.
- One Supervisory Committee member.
- Quorum: three.
- Weekly meetings during the election cycle.
- Candidate or immediate family member recuses from an affected decision.
- Committee dissolves 30 days after results are ratified.
- GA performs a post-election performance review.

Legal Advisor voting/decision eligibility is unresolved. Do not count the Legal Advisor as a voting committee member absent an authoritative instrument. Commercial Code of Ethiopia Proclamation 1243/2021: Articles 383–384 concern votes proportionate to capital represented and a possible equal-vote cap in the MOA; Article 385 concerns conflicts; Articles 373/377 concern proxy representation; Article 380 concerns voting technology. These do not authorize inferred company-specific rights. Voting weight stays disabled/unresolved until operative MOA/Articles, share-class rights, record-date entitlement and any cap are verified. Physical voting is the default; hybrid/electronic voting is fail-closed.

## 4. Domain boundaries

- **Governance:** election authority/configuration, committee, nominations, candidate approvals, lifecycle, objections, appeals, tally evidence, certification and ratification.
- **Shareholder:** canonical shareholder/person identity, shareholding ledger, current holding projection, share classes and shareholder status.
- **Organization/Governance authority:** authoritative governing-body and instrument references.
- **App:** presentation and user interaction only; never independently calculate or decide eligibility, vote weight, quorum, tally or certification.
- **Audit:** immutable actor/action/time/tenant evidence.
- Every operation is tenant- and election-scoped; cross-tenant identifiers fail closed.

## 5. Source of truth and record-date register

`share_holding_entries` is the append-only source of truth; `share_holdings.current_quantity` is only the current-state projection. Ledger rows include signed `quantity`, `balance_before`, `balance_after`, `occurred_at`, entry type and reversal linkage. Each row must satisfy `balance_before + quantity = balance_after`. Reconstruct historical balances using business-effective `occurred_at`, not `created_at`. Reversal entries are included by their own effective timestamp and immutable linkage; original entries are never edited/deleted. Use deterministic ordering for equal timestamps (stable primary-key tie-breaker). Integrity gaps, impossible balances, fractional quantities or ambiguous event ordering are quarantined.

Reconstruct each holding at the approved record-date cutoff; resolve each to exactly one canonical shareholder/person in the same tenant; apply only verified eligibility rules; preserve per-voter/per-class provenance to source ledger entries. Reconcile reconstructed current balances to the projection; differences become exceptions and are never silently repaired during register generation. Quarantine unresolved identity, ledger, class-right, status, proxy or disqualification cases. Register lifecycle: draft → reconciled → submitted for approval → approved → frozen. A frozen snapshot is immutable and content-hashed; correction requires a superseding snapshot, full provenance and reapproval. Voting cannot open without one approved, frozen, integrity-verified snapshot.

## 6. Lifecycle

1. Create planned election with tenant, governing body, code and record date.
2. Verify authority against a signed instrument; audit reference and verifier.
3. Record approved rule version and nomination window; open nominations.
4. Validate candidate identity/evidence and review nominations.
5. Verify committee composition, recusals and quorum (three active, non-recused voting committee members).
6. Approve final candidate ballot under operative rules.
7. Reconstruct/reconcile record-date register, resolve exceptions, approve and freeze snapshot.
8. Open physical voting only after authority, rules, ballot and register gates pass.
9. Close poll; reconcile ballots and generate independently checked tally evidence.
10. Submit, review and decide objections with reasons.
11. Submit and decide appeals through competent independent authority established by governing instruments. If not established, appeal disposition and certification remain blocked.
12. Certify only when every objection/appeal has a valid final outcome, material exceptions are resolved, tally preparer and certifier are independent, and evidence hash matches frozen tally.
13. Ratify separately by the competent body and an actor different from the certifier; retain durable ratification reference.
14. Dissolve committee 30 days after ratification and record GA post-election review.

## 7. Objection, appeal and audit requirements

Cases are tenant/election scoped and linked to the challenged register, candidate, ballot, tally or decision. Capture submitter, time, grounds, evidence references and integrity hash. Transitions are explicit, validated, transactionally locked and idempotent. Reviewers require proven authority; conflicted/recused actors cannot decide affected cases. Decisions require outcome, rationale, actor, timestamp and evidence. Appeal independence must follow the governing process; do not invent an appeal panel. Until the competent appeal authority and procedure are evidenced in a signed instrument, final appeal decision is blocked. Audit is append-only and records tenant, election, case, actor, action, before/after state, time, correlation ID and evidence hash. Duplicate/replayed actions must not create duplicate decisions.

## 8. Security, privacy and operational requirements

Enforce policy authorization and tenant isolation on every endpoint. Use transactions/row locks for lifecycle transitions, register freeze, adjudication, ballot entitlement consumption and certification. Prevent duplicate/replayed ballots and entitlements. Separate voter identity from secret ballot content; restrict and audit access to any linkage. Protect personal and ballot data. Hash frozen registers, tally packages and adjudication evidence. Server-side authoritative calculations only; reject client-supplied eligibility, vote weight, quorum or tally. Provide Amharic/English, responsive mobile layouts, keyboard access, visible focus, semantic labels and WCAG 2.2 AA acceptance.

## 9. API and App scope

**API:** authority verification; lifecycle; committee appointment/roster/recusal/quorum; nomination and candidate approval; record-date reconstruction/reconciliation/exception disposition/register approval/freeze; objection and appeal review/decision/audit; poll open/close and entitlement; tally evidence; certification/ratification; policies, tenant scope, idempotency and locking. MySQL integration tests must cover migrations/idempotency, authorization, concurrency and fail-closed paths.

**App:** election overview and authority/rules; committee workspace; nomination and candidate review; voter-register preview, evidence, discrepancy queue, reconciliation, approval and freeze; objection/appeal inbox and case decision; poll operations and tally evidence; certification/ratification controls; loading/empty/validation/forbidden/conflict/network/pending-majority states. Component tests cover each security-sensitive workflow.

## 10. Acceptance and gate ownership

| Gate | Required evidence | Closure rule |
|---|---|---|
| A1 | This baseline, source-of-truth/legal constraints, requirements and acceptance criteria | Frozen; reopen only for material authoritative contradiction |
| A2 | Exact schema/migration/route/policy inventory, source mapping, API/App contracts, threat/data-flow review | All contracts trace to this baseline |
| A3 | API and App workflows, migrations, audit, tests and exception handling | No required lifecycle TODO remains |
| A4 | Exact-SHA API/App CI, MySQL integration, migration/idempotency, concurrency, accessibility/device, security and end-to-end acceptance | Every mandatory gate PASS; skipped tests remain NOT RUN |
| A5 | Separate Owner approval, deployment plan, backup/rollback, environment config and post-deploy evidence | Not authorized by this task |

## 11. A1 exit risks and downstream blockers

- Operative MOA/Articles and any equal-vote cap must be verified before vote weight is enabled.
- Legal Advisor voting eligibility remains unresolved; exclude from voting quorum/decisions.
- Appeal authority/procedure must be grounded in a signed instrument; otherwise final appeal disposition and certification remain blocked.
- Register reconstruction, projection reconciliation, approval and freeze require implementation.
- Objection/appeal adjudication and audit workflows require implementation.
- App lifecycle screens and component tests require implementation.
- Database-backed API acceptance and exact-head App CI are not yet evidenced.
- The quorum source-contract test correction is committed at the baseline SHA above but was not yet rerun on that exact SHA at A1 assessment time.

## 12. A1 closure statement

A1 is CLOSED as the requirements and control baseline. Proceed through A2/A3 without reopening A1 for ordinary engineering details. Legal authority and source-data unknowns are fail-closed gates, not permission to infer missing policy. A2 documents evidence and maps contracts; A3 implements within these constraints; A4 proves acceptance before any A5 proposal.
