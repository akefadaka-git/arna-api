# AGE v1.0 — Full Election System Architecture Design

**Document type:** Full-system architecture blueprint and implementation contract  
**Status:** Owner-directed design elaboration; pending Owner review/freeze  
**Version:** 1.0.0-design.1  
**Repository baseline:** `akefadaka-git/arna-api`  
**Dependency baseline:** Priority 2 committee-security foundation, merged main SHA `dc2c53aa1b0b0d192acff53880848c9e517ecf67`  
**Design branch:** `a3/board-election-candidate-intake-current-main`  
**Scope:** API, data, security, governance controls, integrations and ARNA App contracts  
**Deployment:** Excluded; A5 remains separately authorized

> This document elaborates the already Owner-approved and frozen AGE v1.0 A1 requirements into a complete implementation architecture. It does not reopen or silently amend the frozen A1 specification. Where legal instruments, approved corporate rules or authoritative source records are missing, the system must preserve an unresolved state rather than invent a rule.

## 1. Architecture objective

Deliver one complete, auditable, tenant-isolated, shareholder-governed election capability for ARNA. Design the entire lifecycle and its cross-module contracts before implementing modules. Implement the modules in dependency order, each against stable interfaces and a verified baseline.

The design must prevent:
- duplicate, unauthorized or improperly weighted voting;
- nomination being mistaken for legal qualification;
- a register being changed after approval/freeze without controlled correction;
- ballot secrecy being broken by linking voter identity to vote choice;
- a conflicted committee member influencing a decision;
- one actor performing incompatible duties without an approved compensating control;
- unverified results being represented as certified or ratified;
- a channel becoming operational merely because an enum, API or UI exists.

## 2. Frozen requirements and governing constraints

The following are design invariants:
1. Election model: `share_company`.
2. The electorate is shareholders entitled on the authoritative record date, subject to validated share-class and governing-instrument rules.
3. Voting weight is derived from eligible shares, not the number of persons, accounts or shareholder entities.
4. Candidates may be shareholders or qualified non-shareholders where the governing rules permit.
5. Nomination authority is shareholder-governed. Self-nomination must resolve to the authenticated shareholder's own canonical Person identity.
6. Candidate intake, evidence review, legal qualification, ballot approval and election outcome are separate states and decisions.
7. Secret ballot is mandatory for the approved secret-ballot mode. Identity/eligibility evidence and vote selections must not share a directly linkable record or ordinary application log.
8. Supported channel families: physical, electronic, proxy and hybrid. Each channel requires its own policy, readiness and activation gates. Hybrid must enforce cross-channel entitlement and duplicate-vote controls.
9. Committee authority, conflict disclosure, recusal, least privilege and durable audit are mandatory.
10. Counting, witnessing, certification and legal ratification are distinct actions and authorities.
11. Challenges, objections and appeals preserve evidence and must not be decided by an actor whose earlier decision is under review where independence is required.
12. No deployment or production migration is included in this design authorization.

### 2.1 Authority hierarchy

The implementation must consume, not invent, the controlling rules. Resolve requirements in this order:
- applicable Ethiopian law and binding regulatory requirements;
- the company's current approved Articles/Bylaws and other governing instruments;
- valid shareholder/General Assembly resolutions;
- valid Board decisions and committee charters/delegations within their powers;
- approved election-specific rules and notices;
- system configuration approved by the competent authority.

A conflict, missing instrument, ambiguous delegation, or unknown share-class rule is a blocking exception requiring documented legal/governance resolution. No default may silently create legal authority.

## 3. Bounded domain architecture

Use the existing Governance domain and canonical identity/tenant/authorization services. Avoid a second election domain or parallel identity model. Keep election subdomains separated by bounded responsibilities and service contracts.

| Subdomain | Responsibility | Owns |
|---|---|---|
| Election governance | Election identity, type, scope, authority, rule snapshot, lifecycle and schedule | Election aggregate and lifecycle events |
| Committee authority | Committee appointment/admin authority, permissions, conflicts and recusal | Existing merged Priority 2 foundation; extend only through additive migrations |
| Candidate & nominations | Candidate profiles, nominations, evidence, screening and eligibility disposition | Candidate and nomination aggregates |
| Entitlement & register | Record-date shareholder holdings, eligible voting units, reconciliation, approval and freeze | Register snapshot and entitlement ledger |
| Ballot & custody | Ballot definition, issuance credentials, encrypted ballot intake, custody and reconciliation | Ballot package, anonymous ballot and custody records |
| Voting channels | Physical, electronic, proxy and hybrid channel sessions and controls | Channel sessions, proxy mandates, participation receipts |
| Count & results | Ballot validation, witnessed count, tally and result package | Count session and result package |
| Objections & appeals | Case intake, evidence, response, independent review and disposition | Challenge case and disposition |
| Certification & ratification | Independent certification and competent legal/corporate ratification | Certification and ratification records |
| Notifications & documents | Notices, secure evidence documents, templates, delivery and acknowledgements | Document/notification references; source files remain in canonical domains |
| App experience | Shareholder, candidate, committee, observer and administrator workflows | UI only; API remains authoritative |

No bounded context may write directly into another context's owned tables. Cross-context changes occur through application services and domain events/outbox.

## 4. Core aggregates and canonical records

Names below are logical design names, not a commitment to exact table names. Final physical schema follows repository naming conventions and a migration-collision review.

### 4.1 Election aggregate
- election ID, tenant/company ID, election reference, type, purpose and scope;
- governing-instrument references and approved rule-set version/hash;
- lifecycle state, opening/closing timestamps, timezone and calendar basis;
- competent authority, initiating resolution and notice references;
- record date, nomination period, challenge period, voting period and certification deadline;
- channel policies and activation state;
- version number, optimistic-lock token and audit metadata.

### 4.2 Candidate aggregate
- candidate ID, election ID, canonical Person ID, tenant/company ID;
- candidate classification (shareholder/non-shareholder), identity verification status;
- nomination source and eligibility evidence references;
- declared interests/conflicts, consent/acceptance and required declarations;
- screening status and immutable decision history;
- restrictions, reasons and appeal/correction references.

A candidate must not receive shareholder voting rights merely by being a candidate. Candidate classification is a fact/evidence summary, not an authorization.

### 4.3 Nomination aggregate
- nomination ID, election ID, candidate ID, nominating shareholder Person/shareholder relationship;
- nominator's verified shareholding/authority snapshot and authority rule reference;
- submission channel, timestamp, evidence bundle and idempotency key;
- nomination status, withdrawal/correction history and reviewer decision references.

### 4.4 Entitlement and voter register
- register version and election/record-date identity;
- shareholder relationship, legal holder identity and verified share class;
- eligible shares, restrictions/encumbrances where applicable, voting multiplier if formally approved;
- source ledger/registry references, reconciliation status and exceptions;
- approval signatures/attestations, freeze timestamp, digest and superseding version reference.

Never derive voting weight from account count, Person count, entity count or a mutable current balance when the authoritative record-date holding is required.

### 4.5 Ballot and participation separation
Maintain separate security domains:
- voter entitlement and issuance service knows who may receive a ballot and whether entitlement was consumed;
- ballot box accepts an anonymous ballot credential/proof and stores the encrypted vote payload without voter identity;
- a one-way, unlinkable participation receipt confirms use without exposing vote choice;
- custody records track ballot package hashes, encryption keys, key ceremony, access and transfer events.

Do not store a direct voter ID ↔ candidate choice mapping. Avoid shared correlation IDs across voter identity and ballot payload. Logs, traces, analytics, exports and support tools must not recreate that mapping.

### 4.6 Count, result and finality
- count session and ruleset version;
- sealed ballot batch manifest and digest;
- invalid/blank/spoiled/rejected ballot categories with controlled reasons;
- tally method and reproducible count output;
- independent witnesses and signed attestations;
- objections and unresolved exceptions;
- certification decision and authority;
- ratification resolution and effective date.

A computed tally is not a certified result; certification is not ratification.

## 5. Lifecycle and state machine

Election lifecycle (illustrative state names; transitions must be authorized):
`draft → authorized → scheduled → nominations_open → nominations_closed → eligibility_review → register_preparation → register_reconciliation → register_approved → register_frozen → ballot_preparation → ready_to_vote → voting_open → voting_closed → counting → results_provisional → objections_window → certification_pending → certified → ratification_pending → ratified → archived`.

Permitted side paths include `suspended`, `cancelled`, `corrective_action_required` and `disputed`, each requiring reason, authority, effective time, notice and audit. Reopening a closed/frozen stage requires a new version or formal superseding decision; never overwrite a prior approved state.

Candidate state machine:
`draft → submitted → intake_validated → evidence_pending → under_review → eligible | ineligible | deferred_unresolved`, with controlled `withdrawn`, `appealed`, `reconsidered` and `superseded` transitions. `submitted` or `intake_validated` never means `eligible`.

Register state machine:
`draft → source_reconciled → exceptions_resolved | exceptions_accepted_by_authority → approved → frozen`. Frozen snapshots are immutable; corrections create a linked new version with independent approval and preserved prior digest.

Ballot state machine:
`design_draft → approved → keys_ceremonied → issuance_ready → open → closed → sealed → counted → reconciled`. No issuance until the register and channel activation gates pass.

## 6. Security and trust architecture

### 6.1 Identity, tenant and authorization
- Use canonical User, Person, shareholder relationship and tenant access services; no parallel User/Person table.
- Resolve every record against the active tenant and election scope.
- Enforce object-level authorization in policies/services, not only route middleware.
- Apply least privilege with separate permissions for administer, nominate, screen, decide, reconcile, approve, freeze, issue, receive, count, witness, certify, ratify, challenge and audit.
- Committee role assignment never grants broad election permissions implicitly.
- Require step-up authentication for high-impact actions where supported by the platform.

### 6.2 Committee conflict and separation of duties
- Reuse the merged Priority 2 committee-security services and event ledger.
- Check active election-scoped authority and conflict/recusal at the point of every protected decision, not only at login or page load.
- A recused user must fail closed for decision, review and access actions prohibited by the conflict determination.
- Prevent submitter/reviewer/approver/certifier self-approval where the decision requires independence.
- Require dual control or witnessed approval for register freeze, key ceremonies, ballot sealing, count close and certification.

### 6.3 Secret ballot cryptographic design
- Conduct a documented threat model and independent cryptographic design review before implementation of production ballot custody.
- Use established, reviewed cryptographic primitives and libraries; do not invent a new cipher or home-grown anonymous voting protocol.
- Separate key generation, key custody, ballot encryption, decryption ceremony and audit identities.
- Define threshold key custody, recovery, rotation, revocation and destruction with independently appointed witnesses.
- Keep keys and secrets outside source control and ordinary database rows; use supported secret-management facilities.
- Preserve ballot secrecy in application logs, database backups, exports, analytics, error reports and support access.
- Test unlinkability, replay, ballot substitution, coercion risks, key compromise, insider access and backup/restore.

This design does not claim cryptographic secrecy is proven merely by encrypting a database column. Independent security review is a release gate.

### 6.4 Audit and integrity
- Append-only audit events with actor/service identity, tenant/election scope, event type, timestamp, reason, prior hash and event hash.
- Hash-chain integrity is tamper evidence, not a substitute for external anchoring, signed attestations or immutable backup.
- Capture before/after state digests for material changes without recording secret vote content.
- Use transactional outbox for reliable event publication; consumers must be idempotent.
- Keep business audit, security audit and ballot custody evidence logically separated.

### 6.5 Concurrency, idempotency and resilience
- Use database constraints and transactions for unique nomination submissions, entitlement consumption, proxy assignment, ballot issuance and lifecycle transitions.
- Use idempotency keys on retryable submission and issuance endpoints.
- Use optimistic locking or row-level locks for register approvals, proxy mandate activation, ballot issuance and count closure.
- Define retry, timeout, outage, restore and disaster-recovery behavior for each active channel.
- Fail closed when authoritative entitlement, permission, tenant context, conflict status or required audit write cannot be verified.

## 7. Channel architecture and activation gates

| Channel | Required controls before activation |
|---|---|
| Physical | Controlled voter check-in, identity/entitlement verification, ballot issue control, secure ballot boxes, opening/closing witnesses, reconciliation of issued/used/spoiled/unused ballots |
| Electronic | Strong voter authentication, one-time entitlement consumption, anonymous ballot issuance, secure ballot box, receipt that cannot reveal vote, resilience and independent security assessment |
| Proxy | Valid written/electronic mandate, scope/election/meeting linkage, effective period, revocation and substitution controls, proxy holder limits if governing rules specify, independent verification |
| Hybrid | All relevant channel gates plus one shared entitlement ledger, atomic cross-channel consumption, proxy-vs-direct precedence from approved rule, reconciliation and exception handling |

A channel has states `disabled → configured → tested → independently_reviewed → authorized → active → suspended → closed`. Configuration does not equal activation. If the governing instrument does not resolve a cross-channel precedence rule, hybrid activation is blocked.

## 8. Counting, certification, objections and ratification

1. Close voting under dual control; preserve channel-specific closure evidence.
2. Reconcile entitlement consumption, ballot issuance, accepted ballots, spoiled/unused ballots and channel manifests.
3. Seal ballot batches with cryptographic digests and witness attestations.
4. Count using the approved, versioned method; preserve reproducible inputs and output.
5. Record provisional results and publish only information authorized for disclosure.
6. Open the objections window; receive cases with evidence, timestamps and standing/authority checks.
7. Route each case to an independent reviewer/panel; preserve response, findings, remedies and appeal rights.
8. Resolve all certification-blocking exceptions; unresolved cases remain visible and block certification where required.
9. Certify through the competent independent authority; do not let the tally service certify itself.
10. Record ratification only when the competent General Assembly/Board/legal authority has adopted the required resolution.
11. Archive the election evidence package under the approved retention and privacy rules.

## 9. API and event contracts

Expose versioned APIs through the Governance domain route/provider conventions. The exact endpoints are finalized in module-specific A2 contracts, but responsibilities are:

- elections: create, show, authorize, schedule, suspend, cancel, transition;
- rules and notices: attach/version approved rule set, publish notice, record acknowledgement;
- candidates: candidate intake, declarations, evidence references, screening, decision, withdrawal and appeal;
- nominations: submit, validate, correct, withdraw, review;
- register: prepare, reconcile, exception, approve, freeze, supersede;
- ballot: approve design, custody ceremony, issue entitlement, accept anonymous ballot, seal;
- channels: configure, test, activate, suspend, close, reconcile;
- count: open, witness, tally, reconcile, provisional result;
- objections: lodge, acknowledge, respond, review, decide, appeal;
- certification/ratification: certify, record resolution, archive;
- audit: authorized security and evidence views.

Domain events include `ElectionAuthorized`, `NominationSubmitted`, `CandidateScreeningDecisionRecorded`, `RegisterApproved`, `RegisterFrozen`, `VotingChannelActivated`, `BallotIssued`, `BallotAccepted`, `VotingClosed`, `CountSealed`, `ProvisionalResultPublished`, `ObjectionFiled`, `CertificationRecorded` and `RatificationRecorded`. Events must contain references and digests, not secret ballot contents or unnecessary personal data.

## 10. ARNA App experience contract

The App is a presentation/client layer and never the authority for eligibility, entitlement, permission, channel activation, tally or certification.

Role-oriented workspaces:
- shareholder: notices, own eligibility/holding summary, nomination, proxy mandate, register correction request, voting access and receipt, objections;
- candidate: candidacy status, declarations, evidence submission, response and appeal;
- committee: case queue, conflict disclosure, recusal, evidence review, decision rationale and audit;
- election administrator: lifecycle, schedules, rule snapshots, notices, operational readiness and controlled exceptions;
- register reviewers: reconciliation, discrepancies, attestations and freeze;
- channel officers: check-in, issuance, custody, closure and reconciliation;
- count witnesses: manifests, ceremonies, count evidence and attestations;
- certifying authority: independent evidence review and certification;
- observer/auditor: least-privilege read-only evidence and audit views.

All interfaces must be Amharic-first, mobile-first, accessible to WCAG 2.2 AA target, support poor connectivity with carefully bounded drafts/queues, and clearly distinguish draft, submitted, pending, approved, frozen, active, provisional, certified and ratified states. Offline mode must never authorize an irreversible election action without server-side revalidation.

## 11. Privacy, retention and records

- Minimize personal data and separate identity/eligibility data from secret ballot data.
- Record the purpose, authority, source and retention class for each evidence type.
- Restrict candidate medical, criminal, financial or other sensitive evidence to lawful, necessary, authorized processing; do not collect by default.
- Encrypt sensitive evidence in transit and at rest using platform-approved mechanisms.
- Provide lawful access/correction workflows without altering frozen historical evidence or exposing ballot choice.
- Define retention, legal hold, archival and secure deletion only after authoritative legal/corporate schedule approval.
- Exports must be scoped, watermarked where appropriate, audited and screened for ballot secrecy leakage.

## 12. Integration contracts

- People/Identity: canonical Person and authentication; no duplicate identity registry.
- Shareholder/Finance: authoritative shareholding ledger and record-date snapshot; Finance balances alone are not assumed to be legal share ownership.
- Documents: immutable references, digest verification, access control and retention metadata.
- Notifications/Communication Hub: versioned notice, delivery, failure and acknowledgement evidence.
- Calendar: timezone-aware election and meeting dates, reminders and deadline computation.
- Audit/Observability: correlation-safe logs; never log ballot choices or identity-to-ballot join keys.
- ARNA AI: assist with OCR, document classification, completeness checks, translation, search and anomaly triage only under human review. AI must not decide legal eligibility, voter entitlement, candidate disqualification, vote choice, tally certification or ratification.

## 13. Delivery order and verified baselines

Each module gets its own A2 file-by-file reconciliation, A3 implementation, A4 exact-SHA verification and closure record. Merge only after the module's gates pass. Keep deployment/A5 separate.

| Order | Module | Key exit baseline |
|---:|---|---|
| 0 | Full architecture contracts and source/rule inventory | Approved/frozen design; unresolved legal inputs registered |
| 1 | Election foundation and lifecycle | Schema, lifecycle, policy, tenant, authorization and audit verified |
| 2 | Candidate intake, nominations and eligibility | Identity, nominator authority, evidence, conflict-safe screening and appeals verified |
| 3 | Shareholder entitlement and voter register | Record-date source reconciliation, share-weighted calculation, approval and immutable freeze verified |
| 4 | Ballot security and custody | Independent cryptographic review, unlinkability, custody and adversarial tests passed |
| 5 | Physical/electronic/proxy/hybrid channels | Each channel independently gated; hybrid anti-duplication verified |
| 6 | Counting, results and witnesses | Reproducible tally, reconciliation and witness evidence verified |
| 7 | Objections and appeals | Independent review, evidence integrity and remedy/appeal workflow verified |
| 8 | Certification and ratification | Separation of duties and authoritative resolution evidence verified |
| 9 | API/App end-to-end integration | Contract, browser, mobile, accessibility and cross-channel suites verified |
| 10 | Integrated A4 | Clean install, MySQL, tenant, authorization, privacy, cryptographic, failure/recovery and independent security verification |
| 11 | A5 deployment | Requires separate explicit Owner authorization |

Do not build all modules in one PR. Do not create temporary schema that a later module must replace. Establish stable interfaces, migrations and event contracts first; implement and verify one dependency-complete module at a time.

## 14. Verification matrix

Every module must include:
- fresh MySQL install and full migration run;
- repeat migration/idempotence and no-pending-migrations checks;
- PHP lint/static checks and relevant unit/feature tests;
- route inventory and policy/object-level authorization tests;
- cross-tenant negative tests;
- conflict/recusal and separation-of-duties tests;
- concurrency, idempotency and rollback tests;
- event/outbox and audit-chain integrity tests;
- privacy/logging/export leakage tests;
- exact-SHA workflow evidence and downloadable artifacts;
- API contract tests and, when App scope begins, browser/mobile/accessibility checks;
- threat model and independent review for ballot cryptography and high-risk release gates.

Test reports must name the exact commit SHA, workflow run, job, test count/assertions, migration state, artifact digest and any excluded/not-run scope. A green test suite is not a legal approval or independent security certification.

## 15. Open authoritative inputs — blocking register

The following inputs must be identified and validated before the relevant module can be declared legally complete or activated:
- current approved Articles/Bylaws and amendments;
- approved election/Board/General Assembly resolution authorizing this election and its scope;
- board size, seats, terms, nomination requirements and candidate qualification/disqualification rules;
- shareholder register authority, share classes, voting rights, transfer/pledge/encumbrance treatment and record-date source;
- quorum, voting thresholds, tie, abstention, invalid/blank ballot and recount rules;
- proxy form, execution, scope, revocation, substitution and holder limitations;
- physical/electronic voting legal acceptability and hybrid precedence;
- challenge standing, time limits, reviewer independence and remedies;
- certification and ratification authority;
- privacy, retention, evidence disclosure and appeal requirements.

Each input receives an owner, authoritative source, version/effective date, legal review status and SHA-256 digest. No inferred value is promoted to an approved rule.

## 16. Scope boundaries and non-goals

- No implementation of a new cryptocurrency, payment rail or wallet as part of election work.
- No AI-only eligibility, entitlement, counting or certification decisions.
- No public disclosure of candidate evidence or ballot records.
- No election channel activation by default.
- No direct database mutation across bounded contexts.
- No production deployment, production migration, real election operation or legal ratification under this design.
- No reopening of frozen AGE v1.0 A1 decisions except on material authoritative contradiction with an explicit Owner-directed change record.

## 17. Design status and next gate

This blueprint is the complete system-level architecture proposal. It is not yet an approved implementation contract until Owner review/freeze.

**Current implementation state:** no Priority 3 application source code has been changed by this design record. The existing Priority 3 A2 reconciliation remains valid as a historical dependency analysis, but implementation is paused pending this architecture baseline's approval.

**Next gate:** Owner reviews this full-system architecture. Once approved, freeze the design baseline, convert its open authoritative inputs and module contracts into a traceable acceptance register, then start module 1 (election foundation) and module 2 (candidate intake) in dependency order. Candidate intake must not be built against an incomplete or disposable election core.


## 18. Existing ARNA App election work — read-only integration disposition

The App repository was inspected as part of the full-system design so that the architecture does not duplicate or discard existing frontend work.

**App current main baseline:** `29cf650f9337d547b395f0e05688691e2ea4e83f`.

**App PR #31** — [WIP] Add ARNA Board Election frontend contracts:
- Branch `feat/board-election-a3`, head `f4f53b6e3d4d2a30fada64b48903c5b91a65dd54`.
- Base is App `main` at `2eff0a0e08062728bba071b1728ffe1886e200ad`; comparison with current main is diverged (26 commits ahead, 207 behind).
- Adds election list/committee/candidate-review views, API client, labels/types and portal/workspace routes.
- PR body reports frontend verification run #537 passed for configured type-check/build and App/portal/admin/public checks. Election-specific browser/component tests were not run; create form, detailed election workspace and committee operational views were not yet implemented.
- Treat as a source reference only; do not merge wholesale or claim current-main verification.

**App PR #68** — voting-method contract alignment:
- Branch `feat/age-a3-governance-ui`, head `2129187191e2d9e32e20beff9ba1522a1d8a13a1`, stacked on PR #31.
- Changes four files: `GovernanceElectionListView.vue`, `api.ts`, `labels.ts`, `types.ts`.
- Adds physical/electronic/proxy/hybrid voting-method union and explicit statement that all methods remain gated pending legal, security and operational acceptance.
- Type-check, lint, build and workflow evidence are pending in the PR description.
- Preserve both PRs and branches. Do not merge or retarget them as part of this architecture design.

**App integration rule:** after the API contracts are frozen, reconcile the App PR #31/#68 work file-by-file against current App main and the frozen API OpenAPI/event contracts. Port only compatible, needed changes onto a fresh App branch. Do not let frontend types or routes activate a voting method or override API authorization. Add election-specific browser, component, accessibility, tenant-scope and failure-state tests before the App integration gate closes.
