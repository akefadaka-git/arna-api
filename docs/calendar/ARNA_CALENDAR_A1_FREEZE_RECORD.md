# ARNA Calendar Enhancement — A1 Assessment, Specification & Freeze Record

**Record ID:** ARNA-CAL-A1-FRZ-001  
**Version:** 1.0  
**Decision status:** OWNER DECISIONS APPROVED — A1 FROZEN  
**Scope:** Calendar, Tasks, Appointments, Corporate Scheduling and ARNA AI integration  
**Stage authority:** A1 only; A2/A3/A4/A5 are separately controlled

## 1. Purpose

This record freezes the approved architecture direction and A1 requirements for enhancing ARNA Calendar and its integration with task management, appointments, corporate scheduling and ARNA AI. It records the assessed baseline and outstanding capability gaps. It does not claim that all scoped capabilities are implemented, tested, integrated or deployed.

## 2. Approved Owner decisions

| ID | Decision | Status |
|---|---|---|
| D01 | D08 — Task & Work Management is authoritative for personal and corporate task lifecycles. Calendar may display/link tasks and deadlines but must not duplicate task lifecycle ownership. | APPROVED |
| D02 | One shared appointment-scheduling capability serves personal and corporate bookings. Governance retains ownership of formal General Meeting, Board and committee appointment records. | APPROVED |
| D03 | ARNA AI may analyze schedules, identify conflicts, recommend changes and prepare actions. User permission and applicable approval controls are required before consequential Calendar or Task records are created, changed or cancelled. | APPROVED |

These are architecture decisions. They do not amend corporate bylaws or independently determine legal authority.

## 3. Scope

- Calendar events, personal and corporate calendars, recurrence, invitations, resource reservations and reminders.
- Ethiopian Calendar-first user experience and Gregorian Calendar interoperability.
- Personal and corporate task integration through D08.
- Shared personal/corporate appointment scheduling.
- Department and team scheduling, with Governance-owned formal meeting records.
- ARNA AI planning, recommendations and governed assistance.
- Integration with ARNA Core identity, authorization, tenant isolation and audit infrastructure.

## 4. Domain ownership boundaries

| Domain | Authoritative responsibility |
|---|---|
| D08 — Task & Work Management | Tasks, to-do lists, assignments, deadlines, priorities, dependencies and progress |
| Calendar | Events, scheduling, appointments, availability, meetings and reminders |
| ARNA AI | Intelligent planning, recommendations, conflict analysis and governed assistance |
| Governance | Formal General Meeting, Board and committee records |
| People | Person and employee identity |
| Organization | Departments, teams and organizational assignments |
| Finance | Financial deadlines and financial records |
| Documents | Meeting attachments and document lifecycle |
| Communication | Messages and meeting communication |
| ARNA Core | Identity, authorization, tenant isolation, shared audit and integration infrastructure |

## 5. A1 repository baseline (historical freeze references)

The A1 assessment recorded these destination repository main-branch references:

- API `akefadaka-git/arna-api`: `1d49dc17aa7a71e984002ca2badd1c20ceea15e0`
- App `akefadaka-git/arna-app`: `ffe0495c0eb7c8b5d2013419123aac5b68893d51`

These SHAs are retained as the A1 assessment snapshot. Later A2 observations must be recorded separately and must not silently rewrite this frozen baseline.

## 6. A1 capability assessment

| Capability | A1 finding |
|---|---|
| Calendar foundation | VERIFIED — substantial backend and App workspace implementation exists |
| Ethiopian/Gregorian interoperability | PARTIAL — E.C. all-day input/serialization, E.C./G.C. filters, all-day E.C. recurrence, Pagume handling and timezone-aware timed events exist; shared controls, browser/API boundary and timed recurrence need further verification |
| General task management | MISSING — no complete D08 general task lifecycle implementation was verified in the assessed baseline |
| Appointment workflows | PARTIAL — Calendar attendees, invitation responses and resource reservations exist; full shared appointment booking/availability/confirmation/reschedule/cancellation workflow was not established |
| Corporate scheduling | PARTIAL — tenant calendars and resource scheduling foundations exist; full team/department workflows were not established |
| ARNA AI Calendar integration | PARTIAL — ARNA AI application registry entry exists; operational Calendar integration and governed action path were not established |

## 7. Functional requirements

### 7.1 Calendar
- Preserve and extend existing Calendar implementation; do not rebuild verified capabilities.
- Support personal, organizational and resource calendars.
- Support event creation, updates, cancellation, recurrence, invitations and reminders.
- Preserve tenant isolation, authorization and auditability.

### 7.2 Localization and temporal correctness
- Ethiopian Calendar is the default user-facing calendar priority.
- Gregorian Calendar remains supported for interoperability.
- All-day events preserve civil-date semantics across E.C. and G.C.
- Timed events preserve absolute UTC instants and IANA timezone metadata.
- Recurrence, leap-year and Pagume boundaries require explicit tests.
- Shared date controls and cross-platform behavior require audit.
- No second canonical Ethiopian conversion engine is to be introduced in the API.

### 7.3 Tasks
- D08 owns authoritative task records and lifecycle.
- Calendar may display task deadlines and link to D08 records.
- Assignments, dependencies, recurring tasks, priorities and progress must not become duplicate Calendar-owned task records.

### 7.4 Appointments
- Provide one shared personal/corporate scheduling capability.
- Support availability, booking, invitations, confirmations, rescheduling, cancellation and reminders.
- Formal General Meeting, Board and committee appointment records remain Governance-owned.

### 7.5 Corporate scheduling
- Support department and team schedules.
- Integrate Board, committee and General Meeting calendar entries without taking over Governance record ownership.
- Enforce organizational assignment and authorization boundaries.

### 7.6 ARNA AI
- Analyze schedules and identify conflicts.
- Recommend scheduling changes and task priorities.
- Prepare proposed actions for user review.
- Require user permission and applicable approval controls before consequential record changes.
- Do not introduce an IROKA dependency.

## 8. Controlled implementation work packages

| Work package | Scope | Priority |
|---|---|---|
| CAL-01 | E.C.-first Calendar UI and shared date controls | P0 |
| CAL-02 | Event lifecycle and recurrence completion | P0 |
| CAL-03 | G.C. interoperability and timezone correctness | P0 |
| TASK-01 | Personal tasks and to-do lists in D08 | P1 |
| TASK-02 | Corporate task assignment and responsibility in D08 | P1 |
| TASK-03 | Deadlines, recurring tasks and dependencies in D08 | P1 |
| APT-01 | Personal and corporate appointment booking | P1 |
| APT-02 | Invitations, confirmations and rescheduling | P1 |
| CORP-01 | Department and team schedules | P1 |
| CORP-02 | Governance calendar integration | P1 |
| NOT-01 | Durable reminders, preferences and escalation | P1 |
| AI-01 | AI scheduling and conflict detection | P2 |
| AI-02 | AI task prioritization and planning | P2 |
| AI-03 | Meeting preparation and intelligent reminders | P2 |

## 9. A2 reconciliation requirements

A2 is a read-only comparison of this frozen specification against the live destination repositories. It must establish:
1. Exact current main SHAs and relevant branch/PR states.
2. Existing Calendar API and App implementation against the frozen requirements.
3. Migrations, schema, routes, permissions, tests and API contracts.
4. D08 task-management implementation and ownership boundary.
5. Appointment and Governance integration boundaries.
6. E.C./G.C. conversion consistency, recurrence and timezone behavior.
7. ARNA AI integration contracts and approval enforcement.
8. Existing open PRs, historical branches and reusable work.
9. Missing capabilities, dependencies, security risks and A3 sequence.
10. Exact verification plan and evidence required for A4.

No code changes, commits, branch creation, PR merges or deployments are permitted during A2.

## 10. Stage and release controls

- A2 is read-only and separately authorized.
- A3 implementation requires separate Owner authorization after A2.
- Use existing branches only; do not create new branches.
- Preserve unrelated changes and existing work.
- Do not merge or deploy without separate approval.
- A4 must verify implementation against this frozen specification.
- A5 deployment requires separate authorization.
- Do not introduce paid services or billing requirements without prior discussion.

## 11. A1 freeze statement

Owner decisions D01, D02 and D03 are approved. The scope, domain ownership boundaries, A1 capability assessment, functional requirements, implementation work packages and A2 reconciliation requirements are consolidated in this controlled baseline.

**A1 STATUS: FROZEN.**

This freeze closes the A1 specification stage only. It does not certify implementation completeness, integration, test execution, production readiness or deployment.
