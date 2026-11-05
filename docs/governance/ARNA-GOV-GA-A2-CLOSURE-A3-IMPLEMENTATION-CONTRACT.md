# ARNA General Assembly — A2 Disposition and A3 Implementation Contract

- Document ID: ARNA-GOV-GA-A2-A3-001
- Version: 1.0.0
- Status: A2 technical reconciliation disposition recorded; A3 implementation contract prepared
- Controlling baseline: `docs/governance/ARNA-GOV-GA-A1-001.md`
- API baseline inspected: `main` at `9f65465e9e78107ca0530cc758b268ea4a27d5ed`
- Attendance reference: merged PR #79, merge commit `767ecf29a5fa6e361b12d5766f00d705ab027783`
- Meeting foundation reference: PR #52 (open/unmerged at inspection)
- Calendar references: PRs #71, #73 and #75 (open/unmerged at inspection)

## 1. Purpose and authority

This document records the technical disposition of A2 findings and defines the implementation contract for the GA system. A1 remains controlling. This document does not amend legal requirements or activate unresolved legal values.

This commit is on a dedicated feature branch. It does not modify `main`, create or run migrations, merge another branch, deploy, or change production data.

## 2. Canonical design decisions

### 2.1 Existing attendance foundation is retained

The merged attendance/proxy/penalty foundation is the starting point. Do not reintroduce parallel versions of:
- `governance_meetings`
- `governance_meeting_attendance`
- existing meeting activity/audit storage

PR #52 is not a direct merge candidate. Its meeting and attendance migrations duplicate existing tables and its attendance identity model differs from current main.

### 2.2 Canonical meeting record

The existing `governance_meetings` record remains the authoritative meeting aggregate until A3 schema inspection and migration design prove a safe additive evolution. The canonical aggregate must own:
- tenant and legal-entity scope;
- meeting type (ordinary/extraordinary GA, and other types only where separately authorized);
- lifecycle status and authority evidence;
- approved scheduled start/end, venue and time zone;
- record-date reference and electorate snapshot reference;
- links to agenda, notices, minutes and calendar event;
- version/concurrency token and audit metadata.

Do not create a second meeting table to obtain these fields. A3 must map every proposed field to the actual current schema and choose additive nullable columns, related tables or a versioned metadata model only after checking existing data and constraints.

### 2.3 Identity and attendance

Current main's polymorphic subject identity and user association must be reconciled with the separate membership-based PR #52 model. The canonical mapping must identify:
- shareholder/member identity;
- authenticated person/user identity;
- attendance capacity (self, proxy representative, authorized officer);
- represented shareholder(s) and voting entitlement(s).

One individual may attend in more than one capacity only where the rules allow it, but a single shareholder voting entitlement must never be counted twice. Keep person identity distinct from shareholder voting entitlement.

### 2.4 Quorum

No numerical GA quorum is approved by this technical contract. The election `half_register` policy and PR #52's 50% assumption must not be reused.

Quorum evaluation must be a pure, versioned calculation over:
- a frozen electorate snapshot;
- approved voting-right rules effective on the record date;
- valid attendance and proxy representation;
- the applicable, legally approved quorum rule;
- adjournment/session context.

Persist a reproducible snapshot containing rule ID/version, source authority, inputs, exclusions, calculation trace, result, evaluated time and evaluator. If no approved rule is active, return `blocked_unconfigured_rule`; do not infer a quorum result.

### 2.5 Agenda, motions, voting and resolutions

Add capabilities to the existing meeting aggregate without duplicating meeting identity:
- versioned agenda and agenda items;
- authorized item amendments and shareholder submissions where permitted;
- motions and amendments;
- voting sessions, eligibility and method;
- immutable vote records and independently reconcilable tallies;
- certified resolution text, result, authority, effective date and evidence.

Do not assume a universal simple-majority threshold. Voting remains blocked where the applicable threshold or method is not established by an approved source.

## 3. Calendar integration contract

A scheduled GA must be represented in ARNA Calendar.

- Calendar event type: `governance_meeting`
- Domain: `governance`
- Resource type: `meeting`
- Resource ID: canonical Governance meeting ID
- Relationship: `primary`
- Time zone: `Africa/Addis_Ababa`
- Default display: Ethiopian Calendar; Gregorian display is a user preference.

Governance is authoritative for date, time, venue and lifecycle status. Calendar is a projection for discovery, display and reminders; it cannot independently alter a legally approved meeting.

Required behavior:
1. Create/link one event only after the meeting reaches its authorized scheduled state.
2. Update the same event after an authorized schedule/venue change.
3. Reflect cancellation, adjournment and closure without deleting the audit history.
4. Use an idempotency key derived from tenant + meeting ID + event purpose.
5. Record synchronization state, last successful version, failure and retry metadata.
6. Use an outbox/event delivery pattern; do not write Calendar tables directly from Governance.
7. Calendar's owning-domain authorization adapter must verify tenant, meeting access and the caller's Governance permission.
8. Calendar reminders are not legal notice/service unless the approved procedure explicitly recognizes them.

The inspected Calendar service rejects domain links without an owning-domain authorization adapter. A3 must implement that adapter before enabling the GA link. The Calendar PR branches are reference material, not proof of Calendar availability on current main.

## 4. Penalty lifecycle correction

Current main creates a penalty in `assessed` status. This does not provide the independent review gate recorded in the later Owner-confirmed attendance policy.

A3 must implement:
`candidate/pending_review -> approved_assessment | rejected | waived -> disputed/reviewed -> settled`

Requirements:
- the attendance recorder cannot approve their own penalty;
- candidate creation is not a payable debt;
- authorized reviewer and source rule are recorded;
- dispute/appeal and review outcomes are auditable;
- Finance posting/settlement occurs only after valid approval;
- dividend deduction is never automatic.

The historical ETB 50 late-arrival and ETB 300 unexcused-absence values, and GA's three-day proxy deadline, are sourced from the later Owner-confirmed policy record. Enforcement still requires confirming the operative policy version/effective date and legal/corporate source package. Do not copy the stale policy branch wholesale.

## 5. Notices and notification boundaries

Documents owns official notice artifacts and their versions. Notifications owns delivery attempts and acknowledgements. Governance owns recipient eligibility and notice workflow state.

Each notice must record:
- authority and approved version;
- meeting and recipient/electorate snapshot;
- service channel and destination reference;
- sent/attempted/delivered/failed/acknowledged timestamps;
- provider receipt or failure evidence;
- correction/reissue history.

A notification delivery receipt is not automatically proof of legally valid service.

## 6. Migration and data safety contract

Before authoring migrations:
1. Inventory every current-main migration and actual table/column/index/foreign-key definition.
2. Identify duplicate table creation and incompatible identity references.
3. Establish existing production data shape and migration history.
4. Define a forward-only, additive migration plan with explicit backfill and rollback/recovery strategy.
5. Ensure tenant-scoped uniqueness and foreign-key integrity.
6. Test upgrade from the exact current-main schema and verify no data loss.
7. Do not rename/drop existing tables or columns without a separately reviewed data migration plan.

Prohibited:
- merging PR #52 or the Calendar PR stack wholesale;
- duplicate creation of meeting or attendance tables;
- hard-coded GA quorum;
- silently mapping a shareholder to a person/user without identity evidence;
- using Calendar as a second meeting source of truth.

## 7. A2 blocker disposition

| ID | A2 finding | Disposition |
|---|---|---|
| GA-A2-01 | Duplicate meeting migrations | Direct merge prohibited; canonical existing aggregate retained; additive schema contract established |
| GA-A2-02 | Conflicting attendance identities | Separate person, shareholder entitlement and representation identities; mapping required before schema change |
| GA-A2-03 | Hard-coded 50% quorum | Rejected; unresolved legal rule blocks calculation |
| GA-A2-04 | Diverged meeting branch | Selective extraction only; no wholesale merge |
| GA-A2-05 | App depends on unmerged meeting API | API contract must be implemented and verified before App integration |
| GA-A2-06 | Calendar owning-domain adapter absent | Adapter and idempotent link/outbox contract specified |
| GA-A2-07 | Penalty immediately assessed | Independent review lifecycle required |
| GA-A2-08 | Proxy lifecycle incomplete | Evidence, revocation, expiry and entitlement reconciliation required |
| GA-A2-09 | Official notice workflow missing | Documents/Notifications responsibilities and evidence specified |
| GA-A2-10 | Minutes/resolution execution missing | Certified records and accountable action lifecycle specified |

## 8. Legal source gates that remain unresolved

These are not technical defects that may be guessed away:
- exact GA quorum and denominator;
- adjourned-meeting quorum;
- notice periods and valid service channels;
- shareholder record date and share-class voting rights;
- proxy formality, caps, representative eligibility and revocation;
- remote attendance and electronic voting authority;
- ordinary/special resolution thresholds and voting methods;
- chairperson, secretary and convening authority;
- penalty applicability, exceptions and appeal authority;
- minutes approval, signatures, filing and retention.

A3 must support versioned rules and inactive/unconfigured states. Legal activation requires the operative Memorandum and Articles, approved GA procedure/resolutions, applicable law and competent legal review. The half-register election rule is not a GA quorum rule.

## 9. A3 acceptance gates

- [ ] No duplicate meeting or attendance tables/migrations.
- [ ] Identity mapping is explicit and auditable.
- [ ] Legal rules are versioned, sourced and effective-dated.
- [ ] Unconfigured quorum/voting rules block the affected operation.
- [ ] Proxy expiry/revocation and double-count prevention pass.
- [ ] Penalty approval is independent and Finance posting is gated.
- [ ] GA event is linked to exactly one Calendar event.
- [ ] Schedule changes update the same Calendar event idempotently.
- [ ] Official notices are distinct from Calendar reminders.
- [ ] Minutes, resolutions and action execution are attributable.
- [ ] Tenant isolation and audit integrity pass.
- [ ] API and App verified against exact SHAs.

## 10. Status

A2 technical findings have documented dispositions and A3 contracts are prepared. This document does not claim that the underlying API, App, Calendar or penalty code has been implemented or verified. Legal source gates remain open. A2 is technically specified for Owner review; implementation and production readiness remain separate gates.
