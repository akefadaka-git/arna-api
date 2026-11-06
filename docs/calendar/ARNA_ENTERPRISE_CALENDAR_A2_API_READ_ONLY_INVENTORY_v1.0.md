# ARNA Enterprise Calendar — API A2 Read-only Inventory

**Status:** A2 CLOSED for bounded source inventory; unresolved contracts remain explicit blockers
**Repository:** akefadaka-git/arna-api
**Main SHA:** 717288a68c29a6c4e24c5875cba1190522a5bdd8
**A1 PR:** #68; proposal commit f8a836908fc8c16c3ac69d27ad34e0bda6a0d334
**Method:** GitHub source/tree inspection only. No code, migrations, database, tests, deployment or production state changed.

## Executive findings

| Capability | Source evidence / disposition |
|---|---|
| Ethiopian calendar | Canonical Localization/Calendar/EthiopianCalendar.php and EthiopianDate.php; CoreServiceProvider binds canonical class. REUSE. |
| Legacy calendar | Older Localization/EthiopianCalendar.php and LocalizationFacade references coexist. Audit call sites before deprecation; do not create another engine. |
| Governance foundation | Bodies, positions, terms, appointments, memberships, seats, acting assignments and authority grants have models, migrations, services, policies and routes. REUSE. |
| Meetings and agendas | No meeting/agenda/minutes/resolution migrations, models or API routes in inspected Governance inventory. CREATE after contract approval. |
| Quorum and voting | Existing governance policy requires charter-defined rules; electronic voting disabled by ADR-GOV-010. No vote/ballot implementation found. DEFER pending authoritative policy. |
| Notifications | Platform NotificationService supports explicit active-tenant recipients and recipient-scoped inbox/read operations. REUSE; add durable outbox/queue for calendar reminders. |
| Finance | Finance is bounded with own models/services/routes/tests. Budget commitments explicitly deferred in accounting contract. Calendar links only; Finance remains authoritative. |
| Tasks | No standalone Tasks domain in top-level API domain inventory. Ownership of action items unresolved. |
| Projects/Portfolio | No Projects/Portfolio domain in top-level API domain inventory. MISSING. |
| Reporting | No Reporting domain in top-level API domain inventory. MISSING. |
| Communication Hub | No Communication domain in top-level API domain inventory. MISSING. |
| Files/Documents | Files is a platform capability, not a Documents domain in inspected tree. Reuse file references; official minutes/records require domain contract. |
| Resource booking | No Calendar resource/reservation capability found. CREATE within Calendar Core if approved. |

## Calendar engine evidence

- mases_core/src/Localization/Calendar/EthiopianCalendar.php — canonical conversion, JDN, leap-year/Pagume, localized month/day names, arithmetic, fiscal/holiday helpers and timezone-aware conversion. Blob 11815af260d31308293a9a339da51bdec9317b1f.
- mases_core/src/Localization/Calendar/EthiopianDate.php — immutable validated E.C. value object. Blob 584c80a612fe1bc2e36fbf47d55004fe36bbda4e.
- CoreServiceProvider and LocalizationService use the canonical Localization/Calendar namespace.
- A legacy Localization/EthiopianCalendar.php and LocalizationFacade references also exist. LocalizationServiceProvider explicitly identifies Localization/Calendar/EthiopianCalendar as canonical.

**Disposition:** reuse the canonical calendar engine. A3 must audit legacy call sites and compatibility before deprecation. No parallel conversion implementation.

## Governance boundary

Governance directory includes Config, Database, Enums, Events, Http, Listeners, Models, Policies, Providers, Repositories, Resources, Routes, Services and Tests.

Current migrations create bodies, positions, terms, appointments, memberships, authority grants, seats and acting assignments. The inspected models/services/controllers cover those same foundations. Current API route file includes body, position, term, appointment and membership operations. It does not include meetings, agenda, minutes, resolutions, votes, ballots or decision endpoints.

Governance policy ARNA-GOV-POL-PSC-001 requires agenda, date/time, attendance, conflicts, papers, decisions, material dissent/abstentions, action owners and deadlines. Quorum/voting/resolutions must be defined by approved charter/governing documents. If a valid quorum or decision rule cannot be established, the meeting may discuss but must not record a binding decision as approved.

Governance P1.3 contract explicitly deferred meetings, attendance, quorum, voting and resolutions to a separate contract and Owner decisions. Governance config only forward-declares future lifecycle vocabularies; it states notifications disabled and electronic voting disabled (ADR-GOV-010).

**Disposition:** calendar may schedule meetings, but Governance owns legal meeting lifecycle, quorum, voting eligibility, proxies, certification and binding decisions. Do not infer thresholds or legal rules.

## Domain integration boundaries

- Finance README establishes tenant/legal-entity boundaries and maker-checker controls. Accounting contract defers budget commitments. Calendar must link finance-owned budgets and approval states, never own or mutate ledger values.
- Top-level API domains: Finance, Governance, Loan, Organization, People, PublicContent, Shareholder and Wallet. No Projects/Portfolio, Reporting, Communication Hub or standalone Tasks domain appears in this inventory.
- Files is platform-level; link to canonical files rather than copying documents into calendar records.

## Notification integration

NotificationService requires explicit persisted canonical User recipients with active membership in the target tenant. Inbox reads use trusted tenant context and scope by tenant + recipient. This is reusable for invitations and reminders.

Service publish creates notification rows transactionally but does not by itself establish a durable event outbox, reminder scheduler, retry policy or delivery adapter. Calendar needs committed-event outbox/queue semantics; never rely on browser timers for authoritative reminders. Governance notification feature flag remains disabled and requires explicit reconciliation.

## A3 prerequisites

1. Approve event, recurrence, timezone, date-only and instant contracts using the existing canonical E.C. engine.
2. Audit all legacy EthiopianCalendar call sites and define compatibility.
3. Approve visibility, organizer/editor/attendee roles and mapping to existing RBAC.
4. Approve audit, retention, cancellation, reschedule, idempotency and conflict rules.
5. Define Governance meeting contract and source authoritative charter inputs before quorum/voting work.
6. Identify source owners for tasks, projects/portfolio, reports and communications; do not duplicate domain records.
7. Define durable outbox, queue, reminder delivery, dedupe, retries and notification preferences.
8. Verify tenant/legal-entity constraints for linked resources and resource booking.

## Limitations

This is a bounded source inventory, not a full route/schema audit, test run, runtime inspection or production DB inspection. Absence from inspected domain tree means not found in this inventory, not proof of absence from every historical branch or external service.

**A2 result: CLOSED for bounded source inventory.** Legacy calendar compatibility classes and bootstrap facade alias were identified; task-like Loan/Wallet obligations and Governance action expectations were classified by domain ownership; date-only versus instant examples and timezone behavior were verified. See [the consolidated disposition register and implementation strategy](ARNA_ENTERPRISE_CALENDAR_A2_CONSOLIDATED_DISPOSITION_AND_IMPLEMENTATION_STRATEGY_v1.0.md) for source-to-requirement mapping, unresolved decisions and delivery sequence. Exact endpoint-level permission mapping, runtime verification and A3 compatibility/test work remain future-phase tasks. No A3 implementation is included.