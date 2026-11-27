# ARNA APT-02 — A3 Implementation Progress

**Record ID:** ARNA-APT02-A3-001  
**Owner authorization:** Explicit A3 authorization received 2026-11-27  
**Target repositories:** akefadaka-git/arna-api and akefadaka-git/arna-app  
**Branch policy:** Existing main branches only; no new branches  
**Status:** **A3 IN PROGRESS — IMPLEMENTATION PARTIAL — VERIFICATION NOT RUN**  
**API main head observed:** 9a7b705e2d9cc55d4b6c1595565eda7c4db7606d  
**App main head at last code checkpoint:** 648815b69b6419554b656ff91bf5b53df211aebb

## 1. Authorization and boundaries

The Owner explicitly authorized APT-02 A3 implementation for:
- API proposal lifecycle and authorization.
- Transactional event/resource rescheduling.
- Reminder and notification handling.
- App rescheduling workflow.
- Automated tests and documentation.

The authorization requires preserving unrelated changes, using existing main branches, and not executing production migrations or deploying. A5 remains separately gated.

A1 v1.0 remains **VERIFIED-CLOSED / FROZEN** and A2 remains **VERIFIED-CLOSED**. APT-01 remains closed and unchanged.

## 2. API implementation delivered to main

### Files changed

- mases_core/src/Calendar/Services/CalendarService.php
- mases_core/src/Calendar/Http/Controllers/CalendarController.php
- mases_core/src/Calendar/Services/CalendarReminderDispatcher.php
- routes/api.php
- database/migrations/2026_11_27_000001_create_calendar_appointment_reschedule_proposals_table.php
- database/migrations/2026_11_27_000002_create_calendar_appointment_notification_outbox_table.php
- tests/Feature/CalendarAppointmentBookingTest.php

### Implemented behavior

- Append-only proposal records with pending, accepted, declined and withdrawn states.
- Proposal history restricted to requester and host.
- Either requester or host may propose; only the other party may accept/decline; only the proposer may withdraw.
- One pending proposal enforced while the appointment row is locked.
- Required appointment/event base-version checks.
- Confirmed, standalone timed appointments only; proposed start must be future; event timezone is immutable.
- Availability checks exclude the appointment's own event and resource reservations.
- Acceptance revalidates tenant membership and conflicts under transaction and locks.
- Existing event and reservation IDs are preserved; event interval and shifted resource reservation intervals are updated atomically.
- Reservation shifts that would extend outside the proposed event interval fail closed.
- Existing pending reminders are skipped; delivered history remains intact; new reminders use event-version-specific idempotency keys.
- Proposal and outcome audit entries include before/after schedule context and proposal reason.
- Cancellation closes an outstanding proposal.
- Durable appointment notification outbox rows are written in the same transaction as proposal/outcome state changes.
- The existing Calendar reminder dispatcher now dispatches appointment notifications with idempotency and bounded retry/backoff.

### API implementation commits

- 14d85c8e5a9e9b0b3a893a8d515d9eb91799e332 — reschedule lifecycle service.
- 8b7d4d1a3a446df6aab0bdfdfc6019882a1bc464 — proposal persistence migration.
- e8d44c7c95f1f72801d16a813c3f406eaca27602 — controller actions.
- 4707201640743e414108592d3940c0a7dc3c140b — proposal routes.
- ef027638ad670310a37b812c9265f9d4aa54047a — proposal lifecycle feature tests.
- a812fe1585c38b8779d9f509448c927a918fce60 — tenant-scoped pending proposal closure fix.
- b7b0aa3449afb8840927d43fcf6b23cb4811d7b7 — reservation bounds and proposal reason audit.
- 3abb712195c98b76895dd48ae06b26c46fd7ce84 — active tenant membership enforcement for proposal access.
- edcfed9d5d8fa2173296f970f715ac2c9d001324 — durable notification outbox migration.
- c75829265c709e57e725a6a334667d392e32e2f4 — transactional notification outbox enqueue.
- 365800315d0daa482c711e6bf8a6438bfff5348b — notification dispatcher.
- ae5ee0cb5d6fedec3eeba97c07330f3bc9f65652 — retry schedule migration update.
- 77f5365c8a11d25b8d0c0dd5c2584bd143b6eaab — immediate notification dispatch scheduling.
- 78fd9d78a33c36a71aa38d32d74fbc97ec5c46f0 — retry backoff correction.
- 7dc638be5dbbc6779a05cbc735dbaf9306d523f7 — notification outbox assertions.
- 50025a3abe6e6b1b85aea24006802375ca0d34cd — notification outbox dispatch feature test.
- 0c2812e4fe281a2e40fe3572c22a97ce604da052 — notify parties when cancellation closes a pending proposal.
- 9a7b705e2d9cc55d4b6c1595565eda7c4db7606d — resource movement/conflict and cancellation feature tests.

## 3. App implementation delivered to main

### Files changed

- src/features/appointments/api.ts
- src/features/appointments/AppointmentWorkspaceView.vue
- src/features/appointments/labels.ts
- src/core/dates/calendar.ts
- tests/appointment-workspace-contract.test.mjs
- tests/appointment-workspace-browser.spec.mjs

### Implemented behavior

- Typed client methods for proposal history, creation, acceptance, decline and withdrawal.
- Confirmed appointment reschedule editor available to requester and host.
- Separate Ethiopian-calendar start/end date and time inputs.
- Existing appointment timezone is retained; proposal times are converted to UTC instants.
- New timezone-aware E.C.-to-G.C. date conversion helper supports appointments outside Addis Ababa without changing the legacy helper.
- Proposal history, reason, status and counterparty decision controls are displayed.
- Amharic-first and English labels added.
- Contract coverage for APT-02 API/UI boundaries and browser coverage for the reschedule editor plus Axe accessibility analysis.

### App implementation commits

- 02a0a0e5f0e8789be880c10c102dd78f01f909aa — APT-02 API client.
- eb9767c7db43187d5a457b0070ce76ceb983d83e — bilingual labels.
- abca2ac78709bd2395b86ebdcfaceb6d551d7192 — proposal history view type.
- fa2c8a9c348a2d2c96e3ffa6ae1751ad2a7d07fa — reschedule workspace UI.
- 754f2e2b8ea43628e0cf9e2997444fe987d82a9b — contract tests.
- 769e00c13558c00e61c8138b5365d73bb88f2e1d — browser editor test.
- b4e804ade29e1bd47a1475e8594541c432e607f5 — timezone-aware E.C. conversion.
- b8d1e83afd5d862a4fefb35bb6c65f065e2e16d9 — use event timezone in reschedule form.
- 5b0a31834b0a7cfbad6a08e5009d41c7815cc719 — timezone contract assertions.
- 7a77b69044bf08b6570d2fc183e897b0445fc89b — editor accessibility coverage.
- 80e42c814222093509467a88a2412620c45880f2 — deterministic timezone date formatting.
- 648815b69b6419554b656ff91bf5b53df211aebb — browser fixture event version.

## 4. Verification and unresolved requirements

| Requirement | Status | Evidence / limitation |
|---|---|---|
| API proposal lifecycle and relationship authorization | IMPLEMENTED — NOT TESTED | Service, controller, routes and feature tests committed |
| Proposal persistence and history | IMPLEMENTED — NOT TESTED | Migration and query path committed |
| Acceptance-time availability revalidation | IMPLEMENTED — NOT TESTED | Exclusion-aware conflict helpers and locked transaction |
| Atomic event/resource update | IMPLEMENTED — NOT TESTED | Existing event and reservations updated in one transaction |
| Reminder rebase and idempotency | IMPLEMENTED — NOT TESTED | Old pending rows skipped; version-specific new keys |
| Durable notification outbox and retry | IMPLEMENTED — NOT TESTED | New outbox migration and dispatcher retry/backoff |
| Notification preference enforcement | **PARTIAL / UNRESOLVED** | Inspected NotificationService.publish and PlatformNotification do not expose a preference lookup/filter. Notifications currently target all active appointment participants. Do not claim user-preference compliance until an authoritative preference contract is located or approved. |
| App proposal editor/history/decision controls | IMPLEMENTED — NOT TESTED | API client, Vue workspace and labels committed |
| Ethiopian Calendar and event timezone handling | IMPLEMENTED — NOT TESTED | New timezone-aware helper and contract coverage committed |
| API feature tests | ADDED — NOT RUN | CalendarAppointmentBookingTest.php extended; no test execution evidence |
| App contract tests | ADDED — NOT RUN | Contract suite extended; no test execution evidence |
| App browser/accessibility tests | ADDED — NOT RUN | Browser suite extended with editor and Axe assertions; no run evidence |
| Production migrations | NOT RUN — NOT AUTHORIZED | Migration files committed only |
| Deployment | NOT RUN — NOT AUTHORIZED | A5 remains separately gated |

The combined GitHub commit-status queries for the observed API and App main heads returned no status checks. This is **not** evidence of passing tests. No PHP, frontend, browser or database test execution is claimed in this record. The API feature suite now also includes resource reservation movement, acceptance-time resource conflict rollback, and cancellation of a pending proposal with attendee notification-outbox coverage.

## 5. Stage-gate disposition

| Stage | Status |
|---|---|
| A1 | VERIFIED-CLOSED / FROZEN |
| A2 | VERIFIED-CLOSED |
| A3 | IN PROGRESS — IMPLEMENTATION PARTIAL |
| A4 | NOT STARTED — TEST EXECUTION AND VERIFICATION EVIDENCE REQUIRED |
| A5 | NOT AUTHORIZED — PRODUCTION MIGRATION AND DEPLOYMENT EXCLUDED |

**Estimated APT-02 overall completion:** 70% (planning estimate, not a measured metric).  
**Implementation readiness:** PARTIAL.  
**Production/deployment readiness:** NOT READY.

## 6. Next actions

1. Run API feature tests against the supported MySQL test configuration, including the new migrations, proposal/acceptance/decline/withdraw flows, conflict handling, reservation updates, reminder rebase and notification outbox dispatch.
2. Run App typecheck/build, contract tests, Playwright browser tests and Axe accessibility checks.
3. Resolve the authoritative notification-preference contract and enforce it before declaring D08 complete.
4. Fix every evidenced failure, rerun affected suites, and record exact workflow/run/job evidence.
5. Complete A3 closure only after implementation gaps and required verification are resolved. Keep A5 separately gated.

**Integrity statement:** This record documents A3 implementation only. No production migration was executed, no deployment was performed, no new branch was created, and APT-01 or Governance formal appointment records were not reopened or modified.
