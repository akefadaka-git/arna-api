# ARNA APT-02 — Appointment Rescheduling
## A2 Read-Only Implementation Reconciliation

**Record ID:** ARNA-APT02-A2-001  
**Date:** 2026-11-26  
**A1 input:** `ARNA_APT02_A1_ASSESSMENT_SPECIFICATION.md` v0.1  
**API source snapshot:** `akefadaka-git/arna-api` `main` at `cd2227114fb186077d6aa04a9f99afd8b5806126`. APT-02 documentation commits are `e2133fe6558f3258cbc3b57bcadd43f415e40522`, `defb303a45cd6543973c0e3f12a80d5455f0e1c6` and `cc666e75538bae7c8057003a78fc9957a077db6b`. An independent Governance commit `a949194674d6fa693e16b535701a4684d9f88949` is also present on current `main` and is explicitly preserved.  
**App source snapshot:** `akefadaka-git/arna-app` `main` at `f694eb13ba822440c6842cb135a66f89218bc454`.  
**Status:** READ-ONLY RECONCILIATION COMPLETE · IMPLEMENTATION BLOCKED PENDING A1 OWNER FREEZE  
**Stage boundary:** A2 only. No production code, migration, route, UI, test or workflow was changed for APT-02.

## 1. Reconciliation summary

The APT-02 requirement is not implemented by APT-01 or the existing recurring-event exception API. The current repositories provide reusable scheduling foundations, but no standalone appointment rescheduling proposal, counterparty decision, accepted-time mutation, proposal history or reschedule UI.

**Conclusion:** APT-02 is a genuine new capability. It should be added as a proposal/acceptance lifecycle around the existing appointment and event aggregates, not by reopening APT-01 or reusing recurrence exceptions.

## 2. Repository baseline and evidence

### API — current source contracts

API source snapshot at the time of inspection: `cd2227114fb186077d6aa04a9f99afd8b5806126`.

| Evidence | Source | Finding |
|---|---|---|
| Appointment aggregate | `arna_core/src/Calendar/Models/CalendarAppointment.php` | One appointment references one event; stores requester, host, scope, status timestamps and version. No proposal relation or reschedule history. |
| Appointment persistence | `database/migrations/2026_11_25_000003_create_calendar_appointments_table.php` | One-to-one event association and requester/host/status indexes; no proposal table. |
| Booking and lifecycle service | `arna_core/src/Calendar/Services/CalendarService.php` | Booking, listing, confirmation, decline and cancellation exist. No reschedule-proposal service methods. |
| Ordinary event update | Same `CalendarService.php`, `updateEvent` | Updates title, description and status only. It does not update `starts_at` or `ends_at`. |
| Recurrence movement | Same `CalendarService.php`, `moveOccurrence` | Requires an event with a recurrence rule and writes a recurrence exception. APT-01 appointment events are standalone and are not eligible. |
| Appointment controller | `arna_core/src/Calendar/Http/Controllers/CalendarController.php` | Validates availability, booking and confirm/decline/cancel only. No reschedule proposal or decision actions. |
| API routes | `routes/api.php` | Appointment routes are list, availability, book, confirm, decline and cancel. No APT-02 routes. |
| Booking tests | `tests/Feature/CalendarAppointmentBookingTest.php` | Tests booking, availability conflict, participant membership, host decline, resource overlap and corporate-calendar boundary. No proposal, acceptance, withdrawal, reminder-rebase or reschedule-concurrency tests. |

### App — current source contracts

App source snapshot: `f694eb13ba822440c6842cb135a66f89218bc454`.

| Evidence | Source | Finding |
|---|---|---|
| API client | `src/features/appointments/api.ts` | Helpers/types for list, availability, booking, confirmation, decline and cancellation only. No reschedule proposal types or requests. |
| Appointment workspace | `src/features/appointments/AppointmentWorkspaceView.vue` | Booking form and actions for host confirmation/decline and requester/host cancellation. No reschedule editor, pending proposal view or accept/decline/withdraw proposal controls. |
| Calendar date conversion | `src/core/dates/calendar.ts` | Existing E.C.-first wall-clock conversion to an explicit UTC instant using an IANA timezone is reusable; APT-02 should not create a parallel converter. |
| Browser/contract tests | `tests/appointment-workspace-browser.spec.mjs`, `tests/appointment-workspace-contract.test.mjs` | Cover APT-01 booking lifecycle and accessibility; no APT-02 workflow coverage. |

### Shared Calendar persistence and delivery foundations

| Evidence | Finding |
|---|---|
| `arna_core/src/Migrations/2026_11_01_000001_create_calendar_core_tables.php` | Calendar events hold timed UTC timestamps and timezone; attendees are unique per event/user; reservations store resource/event and confirmed interval with version. |
| `arna_core/src/Migrations/2026_11_01_000002_create_calendar_lifecycle_tables.php` | Recurrence exceptions are keyed by event/original occurrence; reminder outbox has unique idempotency key, status and delivery metadata. |
| `arna_core/src/Calendar/Services/CalendarReminderDispatcher.php` | Recurrence reminders have stable occurrence identities; existing outbox rows can be re-materialized only under defined idempotency conditions. Appointment rescheduling needs a safe new reminder identity and must preserve delivered history. |
| `calendar_audit_entries` | Existing tenant-scoped aggregate/action/change audit is reusable for proposal and accepted schedule transitions. |

## 3. A1 requirement-to-evidence reconciliation

| APT-02 requirement | Existing evidence | Reconciliation |
|---|---|---|
| Preserve existing appointment/event IDs | `calendar_appointments.event_id` unique; appointment aggregate separate from event | **PASS — architectural fit.** Update the existing event; do not cancel and recreate on accepted reschedule. |
| Propose a new slot without changing current booking | No proposal model/table | **MISSING.** Add proposal history persistence. |
| Counterparty accept/decline | APT-01 host confirms initial request; no post-confirmation reschedule decision | **MISSING.** New relationship-aware decision workflow required. |
| Proposer withdraws pending proposal | No proposal lifecycle | **MISSING.** New action/state required. |
| One pending proposal per appointment | No proposal records or uniqueness/locking mechanism | **MISSING.** Enforce transactionally under appointment lock; consider a database-supported uniqueness guard. |
| Availability excludes current appointment | Existing `appointmentParticipantConflicts` checks scheduled event participation; no excluded event ID | **PARTIAL / BLOCKER.** Reschedule conflict check must exclude the appointment's own event or it will report its current slot as a conflict. |
| Availability excludes current resource reservations | Existing `appointmentResourceConflicts` checks all confirmed reservations; no excluded event ID | **PARTIAL / BLOCKER.** Exclude this appointment's existing reservations while checking the proposed interval. |
| Acceptance revalidates and is concurrency-safe | Booking locks participant/resource rows and rechecks availability; appointment/event have versions | **PARTIAL.** Reuse ordered locks and revalidation; add expected appointment/event/proposal versions and acceptance-time conflict checks. |
| Atomic event/reservation update | Event and reservation persistence exists; event update path does not change time and reservation API inserts only | **MISSING.** New transactional service must update event and existing reservation intervals together. |
| Reminder rebase | Durable outbox exists; current booking creates base reminder rows; unique idempotency keys and dispatcher behavior are established | **PARTIAL / DESIGN BLOCKER.** Superseded pending reminders must be skipped; delivered history retained; new reminder identities must not collide with the original event/recipient/type key. |
| Participant notifications | Canonical notification infrastructure exists and Calendar reminder delivery exists | **PARTIAL.** No appointment-reschedule proposal/outcome notification contract is evidenced. Define event notification and retry semantics without conflating it with timed reminders. |
| Tenant, permission and party checks | Calendar route permissions, tenant context, active membership checks and service-level host/requester checks exist | **PASS — reusable foundation.** Add proposal actor/counterparty rules and verify tenant-scoped proposal ownership on every read/write. |
| E.C./G.C. and timezone correctness | API event timezone and E.C. serialization; App `localDateTimeToIso` | **PASS — reusable foundation.** Proposal retains existing event timezone; do not accept a client-driven timezone mutation. |
| Audit history | Calendar audit entries used for booking/confirmation/cancellation | **PASS — reusable foundation.** Add immutable proposal and outcome audit actions. |
| Governance boundary | APT-01 generic appointment aggregate is distinct from Governance records | **PASS — preserve.** Do not create or mutate formal Governance appointment records. |
| App workflow | APT-01 UI and API helpers only | **MISSING.** Add proposal editor/history/decision controls after API contract is frozen. |
| Automated verification | APT-01 MySQL and browser tests exist | **MISSING for APT-02.** Add MySQL transaction/concurrency and Playwright workflow/accessibility coverage. |

## 4. Critical technical findings

### 4.1 Do not reuse the recurring occurrence-move endpoint

The existing occurrence-move method rejects events without a recurrence rule and writes to `calendar_recurrence_exceptions`. APT-01 appointment events are created as non-recurring `event_type=appointment` events. Reusing that endpoint would conflate two different aggregates and would not update appointment reservation/reminder state.

### 4.2 Exclusion-aware availability is mandatory

The current conflict helpers have no `excludeEventId` parameter. The appointment being rescheduled is itself a scheduled event attended by the requester/host and may own confirmed resource reservations. APT-02 must exclude only that appointment's own event/reservations from the proposed-slot conflict query; it must not exclude other appointments or events belonging to the same participants/resources.

### 4.3 Acceptance is the commit point

A proposal must not mutate the event or move resource reservations. On acceptance, revalidate the proposed interval under transaction and deterministic locks. If participant or resource availability changed, return a conflict and preserve the existing confirmed appointment unchanged.

### 4.4 Reminder identity and delivery history need explicit treatment

The reminder outbox uses a unique idempotency key. The initial event reminder key is based on event, recipient and reminder type; a second reminder for the new time cannot reuse that key. The implementation must preserve delivered rows, prevent old pending reminders from firing, and create a new versioned identity for the accepted schedule. Reminder delivery and proposal/outcome notifications are separate concerns and should be separately auditable.

### 4.5 Resource changes are outside the v1 proposal

APT-01 resource reservations are tied to the event and current interval. The proposed APT-02 v1 should retain the same resources and move their existing reservation intervals atomically. Changing resource identity/capacity or the attendee set is outside the reschedule contract unless separately approved.

## 5. Reconciled target design

Subject to A1 Owner decisions, the evidence supports this target:
1. Add an append-only appointment reschedule proposal aggregate linked to the existing tenant-scoped appointment.
2. Keep the appointment status `confirmed` and current event/reservations unchanged while a proposal is pending.
3. Permit one pending proposal at a time; lock the appointment when creating or deciding proposals.
4. Permit only the other party to accept/decline and the proposer to withdraw, if approved by the Owner.
5. On acceptance, verify proposal status and expected versions; lock appointment, event, participants and resources; recheck slot conflicts excluding the appointment's own event/reservations; then atomically update event and reservations.
6. Rebase reminders with versioned idempotency while preserving delivered history; send durable proposal/outcome notifications.
7. Record proposal and outcome audit entries and expose proposal history only to authorized appointment parties.
8. Add App reschedule controls using the existing E.C./timezone utility and add API MySQL, concurrency and browser/accessibility tests.

## 6. A2 gap and blocker register

| ID | Gap / blocker | Severity | Required resolution |
|---|---|---|---|
| APT02-G01 | No proposal persistence or state lifecycle | BLOCKER | Freeze and implement proposal aggregate |
| APT02-G02 | No proposal/decision/withdrawal API | BLOCKER | Freeze endpoint and authorization contracts |
| APT02-G03 | Availability cannot exclude the appointment being moved | BLOCKER | Add narrowly scoped event/reservation exclusion |
| APT02-G04 | No atomic update path for event and existing reservations | BLOCKER | Implement transactional acceptance |
| APT02-G05 | Reminder idempotency and old pending reminder handling not defined for reschedule | BLOCKER | Approve and test versioned reminder rebase |
| APT02-G06 | No appointment reschedule notifications | HIGH | Define recipient/event/delivery semantics |
| APT02-G07 | No App reschedule workflow | HIGH | Add UI after API contract freeze |
| APT02-G08 | No APT-02 MySQL concurrency/browser test coverage | HIGH | Add tests in A4 verification plan |
| APT02-G09 | Owner decisions D01–D09 in A1 remain pending | BLOCKER | Owner approval/amendment and A1 freeze |

## 7. Stage-gate disposition

| Stage | Status | Evidence / decision |
|---|---|---|
| A1 — assessment/specification | ASSESSMENT COMPLETE; SPECIFICATION PROPOSED; NOT FROZEN | A1 record v0.1; Owner decisions D01–D09 pending |
| A2 — read-only reconciliation | VERIFIED-COMPLETE | Current API/App source inspected; requirements reconciled; gaps and target contracts recorded |
| A3 — implementation | NOT AUTHORIZED / BLOCKED | Requires A1 Owner freeze and A2 closure |
| A4 — verification | NOT STARTED | No APT-02 code exists to verify |
| A5 — deployment | NOT AUTHORIZED | No deployment or production migration |

## 8. Read-only integrity statement

The APT-02 A1/A2 work changed documentation only. No API or App runtime source, route, migration, database, test, workflow, branch, PR, deployment or production data was modified by this work. The separate commit `a949194674d6fa693e16b535701a4684d9f88949` (`fix(governance): restore membership service namespace and imports`) modifies `arna_core/src/Domains/Governance/Services/GovernanceMembershipService.php`; it is unrelated to APT-02 and was preserved without modification. Comparing the API source snapshot `cd2227114fb186077d6aa04a9f99afd8b5806126` to the APT-02 documentation head shows only the two APT-02 documentation files plus that independent Governance service file.

**A2 conclusion:** VERIFIED-COMPLETE as a read-only technical reconciliation. APT-02 is implementation-ready only after the Owner resolves APT02-D01–D09 and the A1 specification is explicitly frozen. Until then, A3 remains blocked.


---

## 9. Owner approval, A1 freeze and A2 closure — 2026-11-27

**Decision record:** APT02-OWNER-DECISION-001  
**Owner disposition:** APT02-D01 through APT02-D09 approved exactly as proposed in the A1 record.  
**A1 disposition:** **VERIFIED-CLOSED / FROZEN — v1.0 — 2026-11-27**  
**A2 disposition:** **VERIFIED-CLOSED — READ-ONLY RECONCILIATION — 2026-11-27**

### 9.1 Approved decision baseline

The Owner-approved decisions are binding for the APT-02 v1 implementation specification:

- Confirmed appointments only; requested appointments use APT-01 cancel/rebook.
- Requester and host are the only decision parties; the non-proposing party accepts or declines. Other existing attendees are notified but do not hold a veto.
- Only start/end time may change. Existing timezone, calendar, title, description, scope, host, requester, attendees and resources are preserved.
- One pending proposal per appointment.
- No automatic proposal expiry in v1; proposer may withdraw.
- Optional reason is visible to the other party and audit history.
- A further proposal is permitted after the previous proposal closes; each accepted change increments versions and is audited.
- Notify all existing attendees of proposal and outcome, respecting notification preferences.
- Proposed start must be in the future and end must be later than start; no additional minimum-notice or maximum-horizon limit applies in v1.

The full decision record and approval effect are preserved in ARNA_APT02_A1_ASSESSMENT_SPECIFICATION.md, Section 11.

### 9.2 A2 closure determination

The read-only technical reconciliation recorded in Sections 1–8 is accepted as the APT-02 implementation baseline. It identifies the missing proposal aggregate/API/UI/test coverage and the required exclusion-aware conflict checks, transactional event/resource update, version checks, reminder rebase and notification semantics.

These findings are **implementation work items**, not unresolved A2 discovery questions. Their presence does not prevent A2 closure: A2's purpose was to establish the source contracts, identify gaps and define the implementation target without changing runtime code. The Owner-approved A1 baseline now resolves the outstanding product-policy blocker APT02-G09.

The remaining gaps G01–G08 are carried forward as explicit A3 implementation requirements and A4 verification obligations. They are not represented as already implemented or verified.

### 9.3 Closed stage-gate disposition

| Stage | Final status | Basis |
|---|---|---|
| A1 — assessment/specification | **VERIFIED-CLOSED / FROZEN** | Owner approved D01–D09 on 2026-11-27; A1 v1.0 baseline frozen |
| A2 — read-only reconciliation | **VERIFIED-CLOSED** | API/App source contracts reconciled; target design, blockers and implementation evidence requirements recorded; no runtime changes |
| A3 — implementation | **NOT STARTED — REQUIRES SEPARATE OWNER AUTHORIZATION** | No implementation authorization is implied by A1 approval or A2 closure |
| A4 — verification | **NOT STARTED** | APT-02 runtime implementation does not yet exist |
| A5 — deployment | **NOT AUTHORIZED** | Production migration/deployment requires separate approval |

### 9.4 Integrity and scope preservation

This closure records documentation and stage-gate decisions only. It does not alter API/App runtime source, routes, migrations, database state, tests, workflows, production data or deployment. The independent Governance change identified in Section 8 remains outside APT-02 and must remain preserved.

APT-01 remains **VERIFIED-CLOSED** and is not reopened. Governance formal appointment records remain separate from generic APT-02 appointment rescheduling.

**A2 closure statement:** The Owner-approved APT-02 v1 requirements have been reconciled against the existing API and App contracts. A1 and A2 are now closed. A3 may be considered only after the Owner issues a separate, explicit implementation authorization defining its permitted scope. No A3 work has been performed under this closure.
