# ARNA Core Calendar & Scheduling Platform — Architecture Specification v1.0

**Status:** A1 architecture proposal  
**Owner:** ARNA Owner  
**Target:** API repository `akefadaka-git/arna-api`  
**Branch:** `feat/arna-calendar-core-a1`  
**Date:** 2026-10-31

## 1. Purpose and product boundary

Establish a Core-owned, tenant-scoped Calendar & Scheduling capability for ARNA OS. It is the authoritative coordination layer for time, participants, places/resources, agendas, reminders, commitments and cross-domain schedule references. It is not a replacement for Governance, Finance, Organization, Portfolio/Project, Documents, Communication, People, Shareholder or task-domain records.

A calendar item may coordinate domain work, but the owning domain remains authoritative for its business state, approvals, financial postings, voting validity, document content and permissions.

## 2. Product capabilities

- Calendar surfaces: month, week, work-week, day, agenda/list, timeline and resource/room views; responsive mobile agenda.
- Scheduling: one-time and recurring events, exceptions, availability, working hours, Ethiopian public holidays, conflict detection, room/resource booking and time-zone aware invitations.
- Appointment management: internal/external participants, organizer, purpose, status, location/meeting link, preparation material, outcome and follow-up.
- Meetings: Board, Board committees, management, shareholder General Assembly (GA), operational and project meetings.
- Governance meeting pack: agenda items, papers/documents, attendance, quorum evidence, motions, voting sessions, decisions/resolutions, minutes and assigned actions.
- Cross-domain schedule: project milestones, portfolio reviews, plan checkpoints, budget reviews, finance close dates, reporting deadlines, procurement milestones, loan/shareholder appointments, publication schedules and document deadlines.
- Personal/team tasks: calendar-linked tasks, due dates, reminders, dependencies, assignees and completion status. The task domain owns task state; calendar stores a typed reference and scheduling projection.
- Communications: attendee notifications, invitation responses, updates, reminders, cancellation notices and links to authorized communication threads.
- Reporting: workload, meeting utilization, attendance, overdue actions, decision turnaround, milestone variance and schedule adherence.

## 3. Canonical data model

Core scheduling records (proposed; final table names subject to A2 schema audit):

1. `calendar_events`: UUID, tenant_id, organizer_user_id, event_type, title_i18n, description_i18n, starts_at_utc, ends_at_utc, timezone, all_day, local_start_date/local_end_date for all-day entries, location/resource refs, visibility, status, source_domain, source_record_type/source_record_id, version, created_by, timestamps.
2. `calendar_event_attendees`: event_id, participant_type (user/contact/group), participant_ref, response_status, role, attendance status, responded_at; enforce unique participant per event.
3. `calendar_event_recurrences`: recurrence rule, timezone, effective range, exception dates, recurrence parent; bounded expansion and occurrence identity.
4. `calendar_event_resources`: room/equipment/resource references and reservation interval; prevent overlapping confirmed reservations using transactional conflict checks.
5. `calendar_agenda_items`: meeting/event, sequence, title, presenter, paper/document reference, duration, status and ordering.
6. `calendar_event_links`: typed links to task, plan, budget, report, portfolio/project, communication thread, document, governance body, appointment, voting session or decision; store references, not copied domain payloads.
7. `calendar_reminders`: recipient, channel, scheduled instant, delivery status, idempotency key and retry metadata.
8. `calendar_audit_events`: append-only lifecycle evidence for create/update/reschedule/cancel/invite/response/attendance/agenda changes.

Do not create a generic polymorphic write bypass. Validate every source reference through an explicit domain adapter and tenant check. Keep governance votes, resolutions and minutes in Governance; financial budgets and postings in Finance; tasks in their owning task/workflow domain.

## 4. Governance meetings, voting and decisions

A meeting event may link to a Governance meeting pack. Governance remains authoritative for:
- board/committee/management body and membership eligibility;
- appointment/authority and acting/delegation rules;
- meeting notice and required notice period;
- quorum calculation at the meeting's effective time;
- agenda and motion lifecycle;
- voter eligibility, ballot secrecy where applicable, vote window and tally;
- resolution/decision number, adoption status, effective date and supersession;
- approved minutes and immutable decision evidence.

Calendar can display meeting status and permitted summary metadata, but cannot manufacture quorum, grant voting rights, expose secret ballots or mark a decision adopted. Voting and decision state changes must use Governance commands/policies and produce audit events. Shareholder GA eligibility must use the authoritative share register/cut-off snapshot, not attendee selection.

## 5. Cross-domain integration contracts

| Domain | Calendar integration | Source of truth |
|---|---|---|
| Governance | Board/committee meetings, agenda, papers, attendance, quorum, voting, resolutions, minutes, action follow-up | Governance |
| Organization | Team calendars, working groups, resource ownership | Organization |
| People | User/contact participants, availability and profile display | Identity/People |
| Shareholder | GA notices, eligible participants, registration/check-in, meeting papers | Shareholder + Governance |
| Finance | Budget review dates, accounting close, approvals, payment/procurement deadlines | Finance |
| Portfolio/Projects | Milestones, stage gates, reviews, dependencies, owners | Portfolio/Project |
| Plans | Strategic/operational plan checkpoints and due dates | Planning domain |
| Tasks/TODO | Personal/team tasks, reminders, dependencies and completion | Task/workflow domain |
| Documents | Agenda packs, papers, minutes, decisions, attachments | Documents |
| Communication | Invitations, responses, discussion threads and notices | Communication |
| Reporting | Report due dates, review events, recurring reporting calendar | Reporting |
| Notifications | Invite, update, reminder, cancellation, agenda, vote and action alerts | Core notification service |

Adapters must be explicit, typed, versioned and idempotent. Calendar must never write directly into another domain's tables.

## 6. Time, Ethiopian calendar and localization

- Persist instants as UTC and retain an IANA timezone (default `Africa/Addis_Ababa`); preserve local wall-clock intent for recurring schedules.
- Store all-day events as local calendar dates + timezone, not midnight UTC timestamps.
- E.C. is the default display calendar throughout ARNA; G.C. is a mutually exclusive user preference. Never display both unless a user explicitly requests a dual-calendar export (not the default UI).
- The existing Core `EthiopianCalendar` conversion service is the canonical conversion authority. Do not reimplement conversion rules in individual domains.
- Amharic-first labels, month/day names, Pagume, leap-year handling, Ethiopian holidays, business-day rules and locale-aware numeral options.
- Store preference centrally in the authenticated user profile/settings API; browser storage may cache only. Apply consistently across calendar, forms, tables, detail pages, exports, notifications, reports and document date labels.
- Browser must not silently fall back from E.C. to G.C. If E.C. formatting is unavailable, surface a visible compatibility error and keep the canonical instant intact.

## 7. Security, privacy and tenancy

- All records are tenant-scoped; resolve tenant from authenticated context and fail closed.
- No client-supplied tenant ID is trusted.
- Enforce organizer/attendee/team visibility and event classification (private, team, tenant, restricted governance).
- Permission checks on every command and read; event visibility does not imply permission to open linked domain resources.
- Resolve linked records through their own domain authorization policy; return safe link metadata only.
- Do not expose private attendee lists, shareholder eligibility, board papers, secret ballots or restricted minutes to unauthorized users.
- Audit all lifecycle and access-sensitive governance actions; rate-limit public invitation/response endpoints and use signed, expiring tokens where external participants are supported.
- Enforce CSRF/authentication, input validation, anti-enumeration, resource booking conflict locks and idempotency keys.

## 8. API contract outline

Proposed endpoints, subject to route convention and A2 audit:
- `GET /api/calendar/events` with bounded date window, view, filters and cursor pagination.
- `POST /api/calendar/events`, `GET /api/calendar/events/{id}`, `PATCH /api/calendar/events/{id}`, `POST /api/calendar/events/{id}/cancel`.
- `POST /api/calendar/events/{id}/attendees`, attendee response and attendance commands.
- `GET /api/calendar/availability`, `GET /api/calendar/resources`.
- `GET /api/calendar/agenda`, agenda-item reorder and lifecycle commands.
- `GET /api/calendar/holidays`, `GET/PUT /api/me/preferences/calendar`.
- Domain-owned endpoints for governance meeting packs, voting and decisions; Calendar only links and orchestrates.
Use resource envelopes, bounded pagination/windows, optimistic version checks, idempotency keys and stable route names. Do not implement these routes until A2 verifies existing route naming and API envelope.

## 9. Notification and synchronization model

- Publish lifecycle events after successful transaction commit through an outbox; never send messages before commit.
- Use idempotent delivery keys per event/recipient/channel/notification type.
- Notification service remains the user inbox and unread-count source of truth. Calendar schedules reminders; notification delivery worker dispatches them. No competing notification store.
- Update/reschedule/cancel must supersede pending reminders safely; timezone/recurrence recalculation must be deterministic.
- Use versioned event changes and a sync cursor (or ETag) for incremental refresh; support polling first, then optional push/WebSocket/SSE after infrastructure assessment.
- Prevent duplicate reminders after retries, worker restarts or recurrence expansion.

## 10. Reliability and operational requirements

- Transactional writes; after-commit outbox; retry with dead-letter/visibility; idempotent handlers.
- Recurrence expansion bounded by requested date range and maximum horizon; never materialize unbounded future instances.
- Conflict checks run transactionally; resource overlaps cannot be double-booked.
- Query indexes on tenant/time/status, attendees, source refs and reminder due status.
- Use MySQL 8 compatible schema, clean-install migration tests, migration idempotence, rollback strategy and timezone/recurrence test matrix.
- Accessibility target WCAG 2.2 AA; keyboard-first interaction, screen-reader semantics, high contrast, reduced motion and responsive mobile-first layout.

## 11. Acceptance criteria

1. E.C.-first preference is profile-backed, applies consistently to every date-bearing ARNA surface, and G.C. never appears alongside E.C. by default.
2. Month/week/day/agenda views show identical authorized events and consistent filtering.
3. Date-only and timed events behave correctly around midnight, timezone boundaries, leap years and Pagume.
4. Recurrence edits support this occurrence / this and following / entire series, with deterministic exceptions.
5. Tenant and event visibility isolation pass cross-tenant and cross-user feature tests.
6. Board/committee/management/GA meeting records link to authoritative Governance data; quorum and voting are never inferred by Calendar.
7. Tasks, budgets, plans, reports, projects, documents and communications remain owned by their domains and synchronize through explicit adapters.
8. Notification reminders are idempotent and cancelled/rescheduled correctly.
9. MySQL clean-install, migration idempotence, concurrency, authorization, recurrence, timezone, resource-conflict and notification delivery tests pass on exact SHA.
10. Browser verification covers mobile/tablet/desktop, Amharic/English, E.C./G.C., accessibility and authenticated role/tenant scenarios.

## 12. Delivery phases and gates

- **A1 — Architecture/specification:** approve domain boundaries, canonical model, governance rules, time semantics, notification/sync design.
- **A2 — Read-only audit:** inspect current Core calendar conversion, existing Governance appointments, Loan schedules, routes, notification/outbox infrastructure, UI shell and current calendar preference; produce exact file/route/schema reuse map and gap register.
- **A3 — Implementation:** Core calendar API + migrations + policies + services + outbox/reminders; then App calendar workspace and domain adapters in dependency order.
- **A4 — Verification:** exact-head CI, clean MySQL, tenant/security/authorization, recurrence/timezone, governance voting invariants, notification idempotency, browser visual/accessibility.
- **A5 — Release:** controlled merge, deployment, smoke checks, monitoring and rollback readiness.

**A1 decision requested:** approve this as the governing calendar architecture baseline, or identify amendments before A2. No existing domain schema is modified by this specification.
