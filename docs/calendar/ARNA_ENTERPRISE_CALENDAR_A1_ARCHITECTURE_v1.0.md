# ARNA Enterprise Calendar & Coordination Platform — A1 Architecture Specification v1.0

**Status:** A1 proposal for Owner review  
**Architecture role:** Core platform capability consumed by bounded domains  
**Default calendar:** Ethiopian Calendar (E.C.); G.C. is an optional mutually exclusive user preference  
**Time storage:** UTC instants plus explicit IANA timezone; date-only values remain date-only  
**Security:** Tenant-scoped, server-authorized, auditable

## 1. Purpose

Provide one enterprise coordination platform for schedules, appointments, meetings, action items, milestones, reminders, and deadlines across ARNA. Calendar coordinates domain-owned work; it does not replace domain workflow engines, finance ledgers, governance authority, or document records.

## 2. Core services

- Calendar Core: calendars, event lifecycle, recurrence, timezone handling, invitations, RSVP, attendance, visibility, availability, conflicts, and linked-resource references.
- Scheduling: resource/room availability, tentative holds, reservations, confirmation, rescheduling, and cancellation.
- Action Coordination: calendar-linked tasks, owners, due dates, priority, dependencies, checklists, and source-domain references.
- Notification Orchestration: transactional outbox, reminders, invitations, updates, cancellations, deduplication, retries, delivery state, preferences, and escalation.
- Calendar UX: month/week/day/agenda/timeline views, E.C.-first date entry/display, optional G.C.-only preference, filters, search, accessibility, and mobile-first behavior.

## 3. Domain ownership and integration

| Domain | Authoritative ownership | Calendar integration |
|---|---|---|
| Governance | bodies, seats, appointments, charters, quorum, voting rights, resolutions | Board/committee/management/Shareholders' GA meetings, agenda, attendance, quorum snapshots, voting windows, decisions, actions |
| Organization / People | canonical identities, teams, positions | attendees, responsibility assignment, team calendars, availability |
| Finance | budgets, commitments, approvals, journals, actuals | link budget plans and approval status; no ledger mutation from calendar |
| Projects / Portfolio | programmes, projects, milestones, risks, dependencies | portfolio timeline, milestone reviews, owner/action links |
| Documents / Files | canonical records, versions, retention | agenda packs, minutes, resolutions, attachments |
| Communication Hub | announcements, correspondence, conversations | invitations, agenda distribution, meeting updates, follow-ups |
| Reporting | report definitions, metrics, snapshots | recurring reports, due dates, review meetings, evidence links |
| Tasks / Work Management | domain task workflow | task projection, due dates, dependencies, action items |
| Notifications | recipient inbox and delivery | event reminders, invitations, assignments, voting deadlines |

Calendar stores typed source references (domain, resource type, resource ID, source version) and safe display summaries. It must not duplicate authoritative domain data. Opening a linked record always rechecks that domain's authorization.

## 4. Proposed canonical records

- calendar_calendars: tenant, owner/scope, name, category, visibility, color token, timezone, lifecycle.
- calendar_events: tenant, calendar, title/description, event type, start/end UTC, timezone, all-day/date-only values, status, visibility, organizer, source reference, version.
- calendar_event_attendees: event, canonical person/user, role, RSVP, response timestamp, attendance state, delegate reference where authorized.
- calendar_recurrence_rules and calendar_event_exceptions: validated recurrence and per-instance overrides.
- calendar_resources and calendar_resource_reservations: rooms/equipment, capacity, holds, collision constraints.
- calendar_reminders: event/action, recipient, channel, trigger instant, delivery state, dedupe key.
- calendar_action_items: event/source reference, owner, due date, priority, status, dependencies; may point to domain-owned tasks.
- calendar_links: typed links to budgets, projects, reports, documents, communications, governance bodies, votes, resolutions.
- calendar_audit_entries: actor, tenant, operation, before/after reference, reason, timestamp, correlation ID.
- calendar_outbox: transactional event publication for reliable downstream synchronization.

Every record is tenant-scoped with tenant-aware indexes, foreign keys, lifecycle, audit, and retention controls. User-supplied tenant IDs are never trusted.

## 5. Event lifecycle and consistency

Lifecycle: draft → scheduled → published → in progress → completed; terminal alternatives cancelled and superseded. Tentative holds and rescheduling are explicit.

- Optimistic concurrency using a version/ETag prevents silent overwrites.
- Mutations use idempotency keys for retry-safe create, edit, RSVP, reminder, and cancellation.
- Changes publish through a transactional outbox after commit; no pre-commit notification.
- Reschedule/cancel retains prior state and notifies affected recipients.
- Calendar projections are rebuildable and reconcile by source ID/version.
- Event completion does not imply task completion, budget approval, or governance decision approval.

## 6. Governance meetings, voting, and decisions

Meeting types include Board, Committee, Management, Shareholders' General Assembly, and other authorized bodies.

A meeting links to its governance body, membership/appointment snapshot, agenda, document pack, minutes, attendance, vote, resolution, and actions.

1. Organizer proposes time, venue/online location, agenda, attendees, and pack.
2. Authorized secretary/authority publishes notice under the body's policy and notice period.
3. Attendance is recorded against canonical identities and the applicable membership/appointment snapshot.
4. Quorum is evaluated from the charter/policy applicable at meeting time; inputs and result are auditable.
5. Voting opens only where governing instruments permit it. Eligibility, voting method, abstention, proxy/delegation, ballot secrecy, opening/closing, and certification are explicit policy inputs.
6. A binding decision is recorded only after valid quorum and thresholds are satisfied. Unknown or missing rules mean discussion may proceed but approval cannot be recorded.
7. Certified resolutions/minutes link to Documents; actions synchronize to Tasks, Portfolio, or Finance only through authorized domain commands.
8. Calendar permissions never grant governance authority or bypass existing RBAC, delegation, quorum, voting eligibility, or certification.

## 7. Security and privacy

- Resolve tenant from authenticated trusted request context; fail closed without it.
- Scope event, attendee, resource, search, recurrence, action, and reminder reads/writes to tenant.
- Enforce policy per operation and linked resource; calendar visibility does not authorize linked-record access.
- Private appointments expose only permitted free/busy data.
- Distinguish organizer, editor, attendee, viewer, secretary, resource manager, and domain authority through existing RBAC/policies, not a parallel permission engine.
- Prevent cross-tenant attendee/resource injection and IDOR; validate each source reference in the same tenant.
- Audit sensitive access, exports, attendance edits, voting, decision certification, and administrative changes.
- Apply retention, legal hold, export, and deletion rules by record class.

## 8. Calendar and date contract

- E.C. is default for all date display and date input.
- User may select E.C. or G.C.; exactly one is shown, never both in one label.
- Persist user preference at account level; tenant default is E.C.
- Store instants in UTC with IANA timezone (default Africa/Addis_Ababa); all-day events and governance effective dates are date-only, not UTC instants.
- Reuse the existing API EthiopianCalendar/localization services as canonical conversion engine. Frontend views share one tested adapter; no per-view conversion snippets.
- Define E.C. month boundaries, leap-year/13th-month behavior, parsing, timezone, and cross-language test vectors.
- API exposes machine-readable ISO values and stable IDs; clients render one selected calendar. Do not emit duplicate display siblings that clients then reformat.

## 9. UX and planning/reporting

- Unified Calendar page: month/week/day/agenda/timeline.
- Permission-filtered side rail: My Calendar, teams, governance bodies, portfolio/projects, reports, rooms/resources.
- Quick create: event, appointment, meeting, action, milestone, report schedule, resource reservation.
- Event detail: attendees, RSVP, agenda, documents, discussion, actions, budget/portfolio/report links, audit and notification state.
- Filters: domain, calendar, type, organizer, attendee, status, priority, location, project, governance body, date.
- Conflict warnings and alternatives; never auto-reschedule without confirmation.
- Dashboard: upcoming events, overdue actions, pending invitations, milestones, governance deadlines, utilization, conflicts.
- Budget amounts and approvals are read-only references from Finance unless an explicitly authorized Finance command is invoked.
- Reports distinguish scheduled, completed, cancelled, overdue, and unverified; attendance is not proof of decision or task completion.
- Amharic-first, responsive, keyboard accessible, screen-reader labelled, high contrast, reduced motion.

## 10. Phased delivery and gates

A1 architecture/contracts: inventory current calendar engine, Governance lifecycle, notifications, app routes, domain owners; approve data model, API, permission matrix, retention, E.C. test vectors, events, migrations.

A2 read-only baseline: map models, migrations, routes, policies, workflows, frontend routes, formatters, domain events; report exact SHAs and reuse/gaps.

A3 core foundation: schema/services/API, authorization, outbox, recurrence, availability/resources, lifecycle, E.C. centralization, audit.

A4 integrations: governance meetings/voting/decisions; tasks/actions; portfolio; finance budget references; documents; reports; communication; notifications.

A5 UX and release: calendar views, event detail, cross-domain tests, tenant isolation, clean MySQL/idempotence/concurrency, exact-head CI, browser tests, staged activation.

No gate closes without exact SHA and evidence.

## 11. Explicit non-goals

No replacement of domain workflow engines; no duplicate ledger/budget approval engine; no parallel RBAC/identity; no automatic approval from attendance; no external Google/Microsoft/ICS sync in the first release. External sync is a separately scoped connector phase requiring consent, conflict, and data-residency review.
