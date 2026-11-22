# ARNA OS — Board of Directors Election System
## Functional and Technical Specification

- **Document ID:** ARNA-GOV-ELE-SPEC-001
- **Version:** 1.0.0
- **Status:** A1 specification baseline; proposed, not adopted
- **Date:** 2026-11-02
- **Domain:** Governance, integrated with Shareholder and ARNA Core Platform
- **Authority:** Owner-governed; Owner retains scope, policy, approval and release authority

> **Approval notice:** This is a proposed system specification. It is not a General Assembly resolution, adopted corporate policy, certified legal opinion, or authority to conduct an election. Legal and institutional approvals remain pending. No implementation, migration, production change, or electronic voting activation is authorized by this document.

## 1. Purpose and outcomes

Provide a secure, transparent, auditable and accessible system to administer the election of the Board of Directors of Maale Arna Share Company through the ARNA OS Governance domain. Support physical voting, electronic voting only after separate authorization and security approval, and authorized proxy representation. Use the verified shareholder register and applicable share/voting rights.

The system must:
- Provide a controlled lifecycle from election authorization through certification and records handover.
- Protect shareholder voting rights, ballot secrecy, candidate fairness and committee independence.
- Prevent duplicate voting across physical, electronic and proxy channels.
- Reconcile eligible voting weight, attendance, ballots issued, cast, spoiled, unused, challenged and counted.
- Produce evidence-backed results, minutes, reports and archival records.
- Provide an Amharic-first, English-capable, mobile-first experience.

## 2. Owner-approved requirements and boundaries

| Requirement | Recorded decision | Status |
|---|---|---|
| Electorate | Registered shareholders | Owner requirement recorded; authoritative register snapshot required |
| Voting channels | Physical, digital/electronic and authorized proxy | Physical/proxy in scope; e-voting disabled pending separate approval |
| Proxy | Authorized proxy representation required | Capability approved; detailed rules require legal/policy confirmation |
| UX | Amharic-first, English-capable, mobile-first | Design requirement |
| Architecture | Extend Governance; integrate Shareholder and Core Platform | Architecture direction |
| Legal authority | Do not infer unresolved company/legal powers | Pending certified instruments and approvals |

The electorate requirement does **not** establish one-person-one-vote. Applicable law provides share-based voting weight for ordinary/dividend shares, subject to statutory and operative company rules. Entitlement must be calculated from verified share records and approved share-class/cap provisions.

## 3. Source-of-truth hierarchy

1. Applicable Ethiopian law and regulations.
2. Certified operative Memorandum and Articles/bylaws, including amendments.
3. Valid General Assembly and Board resolutions within their respective authority.
4. Approved company policies and election directives.
5. Approved election-specific rules and configuration.
6. Database schema, services, authorization rules, UI and tests.

Lower-level configuration must not override a higher-level source. Conflicts are **UNRESOLVED** and must be escalated; the system must fail closed rather than invent a rule.

The scanned company document reviewed during A1 appears to describe a Board of 3–8 directors, a three-year term, a director shareholding qualification, General Assembly appointment/removal, proxy participation, secret-ballot request and tellers. OCR is imperfect and is not a certified transcription. Verify all clauses against a certified operative copy and amendments.

The Ethiopian Commercial Code Proclamation No. 1243/2021 contains provisions relevant to Board composition/appointment, General Meetings, proxies, voting weight, tellers and minutes. Their application must be reconciled by qualified Ethiopian legal counsel. This specification is not legal advice.

## 4. Governance and authority model

- **General Assembly:** competent corporate organ for election-related decisions and appointment/removal of directors, subject to law and operative instruments.
- **Board:** only lawful preparatory/administrative functions; must not compromise independent counting or certification.
- **Electoral Committee:** independent election administrator only after valid appointment by the competent organ. Composition, appointment authority, term, quorum, removal and reporting line remain pending.
- **Candidates:** persons meeting verified statutory and company eligibility criteria.
- **Shareholders:** electorate based on the approved register and voting entitlement at the legally determined record date.
- **Proxy holders:** act only under valid, scoped and non-revoked proxy authority; duplicate exercise of entitlement is prohibited.
- **Tellers/observers:** appointed under law and approved procedure, with role-limited access.
- **Platform administrators:** technical operation only; no election-policy authority or discretion over ballots/results.

No committee membership count, appointment formula, quorum or dissolution date is adopted by this specification.

## 5. Election lifecycle

| Stage | Controls / purpose | Exit evidence |
|---|---|---|
| Authorization | Valid resolution; seats, term, timetable, rules and committee authority | Signed resolution and approved charter |
| Committee constitution | Appointments, declarations, conflicts, access and training | Appointment/declaration/access records |
| Election setup | Dates, seats, record date, channels, language, rules and notices | Approved configuration snapshot |
| Voter register | Reconcile shareholder records, share classes, restrictions and proxies | Frozen register and reconciliation |
| Nomination | Open/close nominations; collect evidence; assess eligibility | Candidate register and decisions |
| Objections/appeals | Receive, investigate and decide with separation of duties | Reasoned decisions and outcomes |
| Candidate information | Publish approved profiles and equal-access material | Publication log |
| Voting readiness | Stations, ballot templates, security, accessibility and contingency | Signed readiness checklist |
| Voting | Verify entitlement; issue/record ballots; prevent duplicates | Ballot issue and custody records |
| Counting | Count; segregate challenged, spoiled and invalid ballots | Signed tally sheets |
| Reconciliation | Reconcile check-in, issued, cast, unused, spoiled and channel totals | Reconciliation certificate |
| Results | Apply approved thresholds, seat rules and tie rules | Provisional result |
| Complaints | Resolve within approved deadlines; preserve evidence | Final complaint/appeal register |
| Certification | Certify only after controls pass; archive handover | Signed certificate and archive manifest |

Existing Governance configuration contains forward-declared election statuses (planned, nominating, voting, counting, certified, cancelled). This vocabulary is not a functioning election workflow.

## 6. Functional requirements

### 6.1 Election administration
- Create an election only against a valid authorizing resolution.
- Configure seats, dates, record date, nomination/objection windows, voting channels, rules and language.
- Freeze approved configuration; amendments require authorized approval and audit history.
- Postponement/cancellation requires authority, reasons, notice and preserved history.

### 6.2 Shareholder voter register
- Read from the canonical Shareholder domain; do not create a second shareholder identity or share ledger.
- Freeze an election-specific register snapshot at the approved record date.
- Calculate voting entitlement from verified shares, class rights and valid vote caps.
- Record exclusions, restrictions, transfers, corrections and disputes with evidence.
- Provide controlled challenge/correction before register lock.

### 6.3 Candidate nominations and eligibility
- Accept nominations only within the approved window and route.
- Capture identity, consent, disclosures, shareholding evidence if required and supporting documents.
- Check current law and operative company criteria; never hard-code an unresolved interpretation.
- Support review, reasoned rejection, objection, appeal, withdrawal and final publication.

### 6.4 Proxy management
- Capture shareholder, proxy, written authorization, date, signature/evidence, scope and permitted actions.
- Validate authority, dates, submission and applicable deposit deadline.
- Support approved revocation/replacement and preserve history.
- Enforce personal/proxy mutual exclusion where law requires.
- Prevent duplicate use or exercise beyond the shareholder's verified voting weight.

### 6.5 Physical voting
- Register polling stations, custodians, tellers, observers and authorized staff.
- Verify identity and entitlement before ballot issue.
- Use ballot control identifiers without linking voter identity to the marked choice.
- Log sealed boxes, custody transfers, opening/closing seals and station records.
- Record spoiled, replaced, unused, challenged and cast ballot quantities.
- Provide accessible assistance without compromising secrecy.

### 6.6 Electronic voting — disabled by default
- No activation until separate Owner-approved ADR/change, legal approval, threat model, independent security review and A4 acceptance.
- If approved, require strong authentication, eligibility snapshot, one-time entitlement consumption, ballot secrecy, integrity proofs and tamper-evident logs.
- Separate identity/eligibility from ballot content; use independent key custody and verifiability.
- Approve recovery, incident response, availability, accessibility and audit controls.
- Participation receipts must not reveal vote choice.
- Electronic meeting provisions do not automatically approve remote secret-ballot voting.

### 6.7 Counting and reconciliation
- Support separate candidate/seat counts where required by law.
- Capture raw tally sheets, teller signatures, channel/station and count time.
- Reconcile eligible weight, check-ins, issued, cast, spoiled, invalid, challenged and unused ballots.
- Require dual control for count entry, corrections and sign-off.
- Recounts are new linked versions; preserve originals and reasons.
- Block certification for material discrepancies or unresolved matters.

### 6.8 Results and certification
- Calculate provisional results only from approved, reconciled inputs.
- Apply legally approved vote weighting, thresholds, seat allocation and tie rules.
- Keep provisional, final and certified states distinct.
- Only the competent authority may certify; software cannot self-certify.
- Generate results, minutes, attendance, resolutions, teller reports and evidence manifest.

### 6.9 Complaints, records and archive
- Accept complaints with category, claimant, deadline, affected stage, remedy and evidence.
- Separate intake, investigation, decision and appeal roles.
- Preserve chain of custody, access restrictions and legal holds.
- Log sensitive record access, export and download.

### 6.10 Notifications and reporting
- Integrate with ARNA notification services when available; do not claim delivery guarantees until verified.
- Provide Amharic/English notices, reminders and acknowledgements.
- Provide committee dashboards for milestones, exceptions, register, nominations, proxies, polling and reconciliation.
- Shareholder-facing views must not expose secret ballots or restricted personal data.

## 7. Proposed logical data model — not migrations

The following are proposed logical entities for A2 mapping, not approved table names, schema changes or migration instructions. A2 must identify existing equivalents and confirm placement.

- Election control: `governance_elections`, `governance_election_authorizations`, `governance_election_rules`.
- Committee: `governance_election_committees`, `governance_election_committee_members`, `governance_election_conflicts`.
- Electorate: `governance_election_voter_registers`, `governance_election_voters`.
- Candidates: `governance_election_nominations`, `governance_election_candidates`, `governance_election_objections`, `governance_election_campaign_records`.
- Proxy/polling: `governance_election_proxies`, `governance_election_polling_stations`, `governance_election_ballot_issues`, `governance_election_ballots`.
- Count/results: `governance_election_tallies`, `governance_election_reconciliations`, `governance_election_results`.
- Challenges/archive: `governance_election_complaints`, `governance_election_appeals`, `governance_election_certifications`, `governance_election_audit_events`.

These are logical proposals only. All records require canonical tenant/identity context, explicit ownership, status/version controls and append-only audit evidence for critical actions. Never store a direct voter-to-choice relationship in an ordinary application table.

## 8. Security, privacy and assurance

- Tenant resolution must be explicit and fail-closed; all reads/writes tenant-scoped.
- Reuse ARNA Core Identity/SSO, RBAC, policies/Gates, audit, Files/Documents, notifications and workflow; do not create parallel platform services.
- Enforce least privilege, separation of duties, conflict declarations, time-bounded election roles and logged emergency access.
- Protect personal data through minimization, encryption, access logs and retention controls.
- Use idempotency and concurrency-safe entitlement consumption.
- Threat-model insider manipulation, ballot substitution, unauthorized result edits, replay, cross-tenant access, privilege escalation, denial of service and evidence deletion.
- Maintain tamper-evident audit for rules, register changes, proxies, ballot issue, counts, recounts, complaints, certification and exports.
- Require independent security and accessibility review before production readiness.

## 9. Target frontend workspaces

| Workspace | Capabilities | Audience |
|---|---|---|
| Electoral Committee Console | Election setup, committee tasks, register, nominations, proxies, readiness, counts, reconciliation, complaints and certification pack | Committee and authorized officers |
| Shareholder Election Portal | Notices, voter status, candidate profiles, nominations/objections, proxy submission, participation status and approved results | Shareholders and proxies |
| Independent Oversight Workspace | Read-only evidence, custody logs, tallies, reconciliation, audit exports and complaint status | Tellers, observers, auditors and oversight roles |

These are target workspaces, not evidence that screens already exist. A2 must map routes, views, APIs, permissions and components before classifying any UI as reusable.

## 10. Acceptance criteria and phase gates

- **A1:** Requirements, legal/source risks, architecture direction, functional/technical specification and proposed directives. Prepared; legal/institutional reconciliation partial.
- **A2:** Read-only inventory, source-of-truth mapping, API/schema/policy/UI traceability, gap register and unresolved decisions. Authorized read-only.
- **A3:** Separately approved implementation scope, reviewed design and code/schema/API/UI change evidence. Not authorized.
- **A4:** Exact-head tests, tenant/security/concurrency/functional/accessibility checks and independent review. Not started.
- **A5:** Explicit release authorization, deployment/rollback plan, monitoring and signed go/no-go. Not authorized.

Acceptance requires: no cross-channel duplicate voting; register and voting weight reconcile to authoritative records and approved rules; valid proxy and mutual exclusion; independently reviewed ballot secrecy; reproducible counts/recounts; discrepancies block certification; competent authority certifies; Amharic/English accessible responsive workflows; and all sensitive actions are tenant-scoped, permission-checked and audited.

## 11. Unresolved decisions — do not infer

| Decision | Why it matters | Required evidence/authority |
|---|---|---|
| Certified operative Memorandum/Articles and amendments | Seats, term, eligibility, voting, notice, proxy and corporate powers | Certified corporate records; legal review |
| Current shareholder register/share classes | Electorate and voting weight; founding list is not current proof | Authoritative register and share ledger |
| Board seat count/election cycle | Ballot and vacancies | Operative instrument and valid resolution |
| Director shareholding qualification | Potential tension with statutory non-shareholder director allowance | Legal opinion on operative clause/current law |
| Voting cap/class rights | Vote calculation | Memorandum and class resolutions |
| Proxy form, scope, deadline, revocation | Representation and duplicate voting | Code, operative bylaw, approved rules |
| Electronic secret ballot legality | Cannot be inferred from electronic meeting participation | Legal opinion, competent resolution, security approval |
| Committee composition/quorum/tenure | Legitimacy and independence | Competent organ resolution |
| Tie-break, threshold, appeal, certification | Outcome and finality | Law and approved policy/resolution |

## 12. A1 conclusion

A1 produced a functional/technical baseline and proposed directives framework. Repository evidence shows a Governance foundation and forward-declared election vocabulary, but not a complete election engine. Legal reconciliation and institutional authority remain partial. Electronic voting must remain disabled. The next authorized activity is A2 read-only traceability and architecture verification.

## References

- Ethiopian Commercial Code Proclamation No. 1243/2021 — [WIPO Lex](https://www.wipo.int/wipolex/en/legislation/details/21658), [PDF](https://ethiodata.et/wp-content/uploads/2023/01/Commercial-Code-of-Ethiopia-English-Proclamation-No.-1243_2021.pdf), [Refworld](https://www.refworld.org/legal/natleginstr/natlegbod/2021/151093).
- [ARNA API Governance configuration](https://github.com/akefadaka-git/arna-api/blob/main/mases_core/src/Domains/Governance/Config/governance.php).
- [ARNA API Governance routes](https://github.com/akefadaka-git/arna-api/blob/main/mases_core/src/Domains/Governance/Routes/api.php).
- [Governance contract and correction plan](https://github.com/akefadaka-git/arna-api/blob/main/docs/governance/P1.3-governance-contract-and-correction-plan.md).
- [Shareholder charter scaffold — draft, not approved](https://github.com/akefadaka-git/arna-api/blob/main/docs/PHASE_2_SHAREHOLDER_CHARTER.md).
- [ARNA App workspace routes](https://github.com/akefadaka-git/arna-app/blob/main/src/router/routes-workspace.ts).
