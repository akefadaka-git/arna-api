# ARNA APT-02 — Appointment Rescheduling
## A1 Assessment and Specification

**Record ID:** ARNA-APT02-A1-001  
**Version:** 0.1 — proposed specification  
**Assessment snapshot:** API `main` `cd2227114fb186077d6aa04a9f99afd8b5806126`; App `main` `f694eb13ba822440c6842cb135a66f89218bc454`  
**Status:** ASSESSMENT COMPLETE · SPECIFICATION DRAFTED · OWNER FREEZE PENDING  
**Stage boundary:** A1 only. No implementation, migration, route, UI, test, merge or deployment work is authorized by this record.

> This is a distinct work package after APT-01. It does not reopen or modify the APT-01 verified closure.

## 1. Purpose and separation from APT-01

APT-01 delivered shared personal/corporate appointment availability, booking requests, listing, host confirmation/decline and requester/host cancellation. APT-01 is closed and remains unchanged.

APT-02 adds the controlled lifecycle for proposing and accepting a new time for an existing appointment. It must preserve the existing appointment identity and the Calendar event as the authoritative scheduled occurrence. It must not implement a second booking system.

Inherited architecture decisions:
- D08 owns task records and task lifecycle; Calendar does not own tasks.
- Calendar owns appointment scheduling and Calendar events.
- Governance remains authoritative for formal General Meeting, Board and committee appointment records.
- ARNA AI may analyze and recommend scheduling changes, but cannot execute consequential changes without user permission and applicable approval controls.
- Tenant isolation, authorization, auditability and Ethiopian-first / Gregorian-interoperable date handling remain mandatory.

## 2. A1 assessment

The existing platform has:
- A `calendar_appointments` aggregate linked one-to-one to a Calendar event, with requester, host, scope, status timestamps and an integer version.
- A timed Calendar event containing UTC start/end instants and an IANA timezone.
- Tenant-scoped attendees, active-membership validation, resource reservations, conflict checks, audit entries and a durable reminder outbox.
- A shared App appointment workspace for checking availability, booking, listing, host confirmation/decline and cancellation.
- A separate recurrence-exception mechanism for moving individual occurrences of recurring Calendar events.

The current implementation does not establish a rescheduling workflow for a standalone appointment. APT-01 booking and cancellation are not rescheduling.

## 3. Proposed APT-02 objective

Allow an authorized appointment party to propose a new start/end time for an existing confirmed appointment. Keep the current confirmed schedule active until the other appointment party accepts the proposal. Apply an accepted proposal atomically to the existing Calendar event and its associated resources/reminders, preserving the appointment and event identifiers.

## 4. Proposed scope

### 4.1 Included in APT-02 v1
- Rescheduling a single, timed, confirmed APT-01 appointment.
- Proposal initiation by either the original requester or the appointed host.
- Acceptance or rejection by the other party.
- Withdrawal by the party that submitted the still-pending proposal.
- A visible proposal history and current proposal status.
- Availability revalidation against participant and resource schedules, including the appointment's own event/reservations being excluded from conflict results.
- Optimistic version checks and transactional locking to prevent stale or concurrent updates.
- Atomic update of the existing Calendar event and existing resource reservations after acceptance.
- Reminder reconciliation and durable notifications for affected participants.
- Tenant-scoped audit history, actor attribution and traceable before/after times.
- Ethiopian Calendar-first App controls using the event's existing IANA timezone; persisted timed values remain unambiguous UTC instants.

### 4.2 Explicitly excluded
- Reopening APT-01 booking, confirmation or cancellation behavior.
- Rescheduling an initial booking that is still in `requested` status. Proposed fallback: cancel/rebook using APT-01 until the Owner decides otherwise.
- All-day appointments (APT-01 creates timed appointments only).
- Recurring-event series changes or recurrence-exception editing. The existing occurrence-move endpoint is not an appointment-rescheduling substitute.
- Changing appointment title, description, host, requester, attendee set, calendar, scope or resource set as part of a time change. Proposed v1 preserves those fields.
- Formal Governance appointment records and Governance approval workflows.
- Corporate team/department scheduling beyond the existing APT-01 shared appointment capability.
- Autonomous ARNA AI rescheduling.
- Production deployment or production database migration.

## 5. Proposed business workflow

1. An appointment is confirmed and remains scheduled at its current time.
2. Either requester or host submits one reschedule proposal containing a new start and end instant. The existing event's timezone is retained; the proposal cannot silently change the timezone or other appointment attributes.
3. The API validates that the proposed interval is future, ordered and available for all existing participants and reserved resources. Conflict evaluation excludes the appointment's own current event and reservations.
4. The proposal is recorded as pending. The current confirmed event, reservations and reminder schedule remain unchanged.
5. The other party receives a notification and may accept or reject. The proposer may withdraw while it remains pending.
6. On acceptance, the API locks the appointment, event, participant rows and resource rows; rechecks authorization, versions, tenant membership, schedule conflicts and resource conflicts; then atomically updates the event interval, reservation intervals, reminder schedule and audit history.
7. If the slot is no longer available or a version is stale, acceptance fails with a conflict response and the original confirmed schedule remains intact. A new proposal is required.
8. Rejection or withdrawal closes only the proposal; it does not cancel or alter the original appointment.
9. Cancellation of the appointment while a proposal is pending closes/withdraws the pending proposal and follows APT-01 cancellation behavior.

### Proposed state model
The appointment remains `confirmed` during proposal review. Rescheduling state belongs to a separate proposal record:

- `pending`
- `accepted`
- `declined`
- `withdrawn`
- `expired` (only if an expiry policy is approved)

Only one pending proposal may exist per appointment. Proposal records are historical records; accepted/rejected/withdrawn proposals are not overwritten or deleted. Each accepted change increments the appointment and event versions.

## 6. Proposed API contract (subject to A2 and Owner decisions)

Candidate endpoints:
- `POST /api/calendar/appointments/{id}/reschedule-proposals` — create a proposal.
- `GET /api/calendar/appointments/{id}/reschedule-proposals` — read proposal history, subject to participant authorization.
- `POST /api/calendar/appointments/{id}/reschedule-proposals/{proposalId}/accept`
- `POST /api/calendar/appointments/{id}/reschedule-proposals/{proposalId}/decline`
- `POST /api/calendar/appointments/{id}/reschedule-proposals/{proposalId}/withdraw`

Candidate proposal payload:
- `starts_at`, `ends_at`: ISO-8601 instants with explicit UTC offset or Z.
- `timezone`: omitted or equal to the appointment event's established IANA timezone; never silently change the timezone.
- `expected_appointment_version` and `expected_event_version`: required optimistic concurrency preconditions.
- Optional reason/comment only if Owner-approved and length/visibility rules are defined.

The server derives proposer and recipient from authenticated identity and appointment membership; clients must not select arbitrary actor or recipient IDs. Endpoint names and exact request/response schemas remain subject to A2 contract reconciliation.

## 7. Proposed persistence and transactional design

A separate append-only `calendar_appointment_reschedule_requests` table is the preferred design. It should capture tenant, appointment, proposer, counterparty, proposed UTC interval, timezone snapshot, base appointment/event versions, proposal status, created/responded/withdrawn/expiry timestamps, response actor, and timestamps. Exact schema, foreign keys, unique/locking strategy and retention are A2/A3 design items.

Acceptance must run in one database transaction:
- Lock appointment and event in a consistent order.
- Lock affected participant and resource rows in deterministic order.
- Verify the appointment remains confirmed, the proposal is pending, the counterparty is acting, and base versions still match.
- Recheck conflicts while excluding the appointment's own event and current reservations.
- Update the existing event start/end, increment its version.
- Update existing active reservation intervals, increment reservation versions.
- Reconcile reminders without deleting delivery history or reusing an already-consumed idempotency key.
- Mark proposal accepted, increment appointment version, and write an audit record.
- Commit all state changes together.

No proposed time is treated as a confirmed reservation before acceptance. Acceptance-time revalidation is mandatory. If a race makes the proposed slot unavailable, the transaction must fail closed and preserve the existing schedule.

### Reminder and notification requirements
- Already delivered reminder/notification records remain immutable historical evidence.
- Pending reminders for the superseded time must not fire.
- New reminders must be scheduled for the accepted time with unique, version-aware idempotency keys.
- The requester, host and other existing attendees receive a reschedule outcome notification, subject to approved notification preferences and delivery policy.
- Notification failure must not partially apply or roll back a successfully committed schedule change; durable retry semantics are required.

## 8. Security, concurrency and temporal requirements

- Enforce tenant isolation on appointment, event, proposal, attendee and reservation reads/writes.
- Only the requester or host may initiate a proposal; only the other party may accept/decline; only the proposer may withdraw. (Owner approval required.)
- Existing `calendar.update` permission is a coarse capability gate, not a substitute for relationship checks.
- Do not trust user-supplied tenant, proposer, recipient, event or reservation identifiers.
- Use row locks and optimistic versions to prevent double acceptance, stale writes and concurrent proposals.
- Revalidate active tenant membership and active resource state at acceptance.
- Use half-open interval overlap semantics consistently: existing start < proposed end AND existing end > proposed start.
- Preserve the event's timezone; resolve Ethiopian Calendar wall time using the canonical App/API calendar utilities and reject invalid/nonexistent/ambiguous local times as those utilities specify.
- Require future start times and end > start; define the policy for minimum notice and maximum duration with Owner approval.
- Keep audit history for proposed, accepted, declined, withdrawn and failed conflict outcomes as appropriate.

## 9. Owner decisions required before A1 freeze

The following are explicit decisions, not silently assumed approvals:

| Decision | Proposed default for review |
|---|---|
| APT02-D01 — eligible appointment state | Confirmed appointments only; requested appointments use APT-01 cancel/rebook |
| APT02-D02 — parties who must agree | Requester and host only; the non-proposing party accepts/rejects; additional attendees are notified but are not veto holders |
| APT02-D03 — changeable fields | Start/end only; preserve timezone, calendar, title, description, scope, host, requester, attendees and resources |
| APT02-D04 — pending proposal cardinality | One pending proposal per appointment |
| APT02-D05 — proposal expiry | No automatic expiry in v1 unless an Owner-approved duration is supplied; allow proposer withdrawal |
| APT02-D06 — reschedule reason | Optional reason, visible to the other party and audit history |
| APT02-D07 — repeated rescheduling | Permit another proposal after a previous proposal is closed; every acceptance increments versions and remains auditable |
| APT02-D08 — notification | Notify all existing appointment attendees of proposal and outcome; preserve user notification preferences |
| APT02-D09 — timing limits | Future time and valid positive interval are mandatory; minimum notice / maximum horizon remain unspecified |

## 10. A1 exit criteria

A1 can be frozen only after the Owner approves or amends the decisions in Section 9 and the final scope/state/API/persistence boundaries are recorded. No A3 work may begin before A1 freeze and A2 closure.

**A1 disposition:** assessment completed; specification proposed; Owner decision and freeze pending.


---

## 11. Owner approval and A1 freeze — 2026-11-27

**Decision record:** APT02-OWNER-DECISION-001  
**Decision authority:** Owner  
**Decision date:** 2026-11-27  
**Disposition:** **APPROVED — A1 FROZEN — v1.0**

The Owner explicitly approved APT02-D01 through APT02-D09 exactly as proposed in Section 9. The approved values are:

| Decision | Frozen v1.0 rule | Owner disposition |
|---|---|---|
| APT02-D01 — eligible appointment state | Confirmed appointments only; requested appointments use APT-01 cancel/rebook | APPROVED |
| APT02-D02 — parties who must agree | Requester and host only; the non-proposing party accepts/rejects; additional attendees are notified but have no veto | APPROVED |
| APT02-D03 — changeable fields | Start/end only; preserve timezone, calendar, title, description, scope, host, requester, attendees and resources | APPROVED |
| APT02-D04 — pending proposal cardinality | One pending proposal per appointment | APPROVED |
| APT02-D05 — proposal expiry | No automatic expiry in v1; proposer may withdraw | APPROVED |
| APT02-D06 — reschedule reason | Optional reason, visible to the other party and audit history | APPROVED |
| APT02-D07 — repeated rescheduling | Another proposal is permitted after the previous proposal closes; every acceptance increments versions and is audited | APPROVED |
| APT02-D08 — notification | Notify all existing appointment attendees of proposal and outcome, respecting notification preferences | APPROVED |
| APT02-D09 — timing limits | Future start and valid positive interval are mandatory; no additional minimum-notice or maximum-horizon limit in v1 | APPROVED |

### Freeze effect

1. This approval freezes the APT-02 v1 product scope, business rules and Owner decisions recorded in this A1 document.
2. The original proposal and its decision history above are retained; this approval does not rewrite the historical draft as if it had been approved earlier.
3. The approved A1 baseline supersedes the earlier “Owner freeze pending” status in the document header and Section 10.
4. The A1 exit criteria are satisfied. A1 is **VERIFIED-CLOSED / FROZEN** as of 2026-11-27.
5. This approval does not authorize A3 implementation by itself. A3 requires a separate explicit Owner authorization after A2 closure is recorded.
6. APT-01 remains closed and unchanged. APT-02 remains limited to rescheduling; no APT-01 booking, confirmation or cancellation scope is reopened.
7. Any material change to this frozen scope requires a separately recorded Owner change decision and impact assessment before implementation.

**Frozen baseline:** ARNA APT-02 A1 v1.0, Owner-approved 2026-11-27.
