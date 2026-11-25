# ARNA Enterprise Calendar — Consolidated A2 Disposition Register & Implementation Strategy

**Document:** ARNA-CAL-A2-REG-001 v1.0  
**Status:** A2 CLOSED for bounded source inventory; unresolved product/policy contracts remain explicit blockers.  
**API baseline:** `akefadaka-git/arna-api@717288a68c29a6c4e24c5875cba1190522a5bdd8`  
**App baseline:** `akefadaka-git/arna-app@23b2e65d9c16b03bbb7ccb2f0783c8813e2307da`  
**Method:** targeted destination-repository source, route, model/config and architecture-document review. No runtime code, migrations, tests, database, deployment or production state changed or tested.

## 1. Executive result

ARNA has reusable foundations: canonical Ethiopian calendar conversion, E.C.-default/G.C.-optional App preference, bounded Governance/Finance/People/Shareholder/Wallet/PublicContent domains, a notification inbox, Files capability and authenticated multi-shell routing. It does not yet have an integrated enterprise Calendar product.

The target is an **ARNA Time & Work Coordination Platform**: one governed temporal layer connecting personal diary, organizational schedules, appointments, meetings, action references, budgets, portfolio milestones, reports, announcements and communication—while each domain retains ownership of its authoritative records.

## 2. Consolidated source-to-requirement disposition register

Disposition: REUSE = verified foundation; EXTEND = existing seam needs contract/capability; CREATE = not found in inspected baseline; LINK ONLY = source domain retains truth; BLOCKED = Owner/policy decision required.

| ID | Requirement / source evidence | Disposition and target |
|---|---|---|
| CAL-01 | API canonical `Localization/Calendar/EthiopianCalendar.php` and immutable `EthiopianDate.php`; CoreServiceProvider binds canonical namespace. | **REUSE** one conversion engine; no parallel converter. |
| CAL-02 | Legacy `Localization/EthiopianCalendar.php`, `EthiopianDateTime`, `EthiopianFormatter`, `LocalizationManager/Facade` and bootstrap facade alias coexist. | **EXTEND** compatibility map; new code uses canonical namespace; deprecate only after call-site review. |
| CAL-03 | Canonical engine localizes instants to configured timezone; CoreServiceProvider defaults to `app.timezone` / `Africa/Addis_Ababa`; engine documents E.C. leap/Pagume, holidays and Friday/Saturday weekend. | **REUSE / CLARIFY** default event timezone Addis Ababa; event timezone explicit; workweek/holidays configurable, not globally hard-coded. |
| CAL-04 | App `src/core/dates/calendar.ts`: E.C. default, G.C. optional exclusive display, browser localStorage/storage-event sync. | **REUSE / EXTEND** account/profile sync for cross-device consistency; local cache; never show both calendars in one label. |
| CAL-05 | App formatter accepts generic string/number/Date; API examples include Loan `due_date` (date-only) and Wallet `occurred_at`/`due_at` (ISO date-time). | **EXTEND** typed date-only `LocalDate` separately from UTC `Instant` + IANA timezone; prevent date shifts. |
| CAL-06 | Shared formatter used in Notifications, Governance appointments, Wallet, People, Portal, Public Content and homepage. Direct Gregorian `new Date().getFullYear()` remains in PublicSiteLayout/PublicContributionRoute; native DatePicker is Gregorian-oriented. | **PARTIAL** complete date call-site migration and E.C.-aware date input. |
| CAL-07 | No Calendar API domain, event/attendee/recurrence/resource/reminder migrations/models/routes in inspected API inventory. | **CREATE** tenant-scoped Calendar Core, events, attendees, recurrence, links, resources, reservations, audit and outbox. |
| CAL-08 | App router has base/workspace/portal/admin trees; no Calendar feature or route. | **CREATE** workspace Calendar page: month/week/day/agenda/timeline; mobile agenda-first. |
| CAL-09 | No private Diary feature/API found. | **CREATE / BLOCKED** private-by-default diary; retention/export/deletion/sharing require Owner approval. |
| CAL-10 | No standalone Tasks domain/feature. Loan installments and Wallet obligations are domain-owned; Governance policy expects action owners/deadlines. | **LINK ONLY / DECISION** shared Tasks domain vs domain-owned actions aggregated in Calendar. Do not mutate financial obligations as generic tasks. |
| CAL-11 | No Calendar recurrence/exception engine found. | **CREATE** bounded recurrence with timezone-aware expansion, exceptions, moved instances and idempotent updates. |
| CAL-12 | Governance appointments and People domain exist; no universal booking/calendar API. | **LINK / EXTEND** distinguish legal Governance appointment from personal appointment and meeting. |
| CAL-13 | Governance bodies, positions, terms, appointments, memberships, seats, acting assignments and authority grants exist; no meeting/agenda/minutes/resolution/vote/ballot routes/models found. | **REUSE foundation / CREATE contract** Governance owns formal meeting lifecycle; Calendar provides schedule/workspace. |
| CAL-14 | ARNA-GOV-POL-PSC-001 requires charter-defined quorum/voting; if rule unknown, no binding decision may be marked approved. P1.3 deferred meetings/voting; electronic voting disabled (ADR-GOV-010). | **BLOCKED** no invented quorum, threshold, proxy, eligibility or e-voting. |
| CAL-15 | Governance body foundation and Shareholder domain exist; formal meeting workflow absent. | **LINK / CREATE under Governance** Board, committee, management and shareholder GA attendees derive from authoritative membership/eligibility. |
| CAL-16 | API NotificationService provides recipient-scoped persisted inbox for active tenant members; App store/center/bell exists. Durable reminder scheduler/outbox/retry not established. | **REUSE / EXTEND** transactional outbox, delayed jobs, retries, dedupe, delivery evidence and preferences. Never browser timers. |
| CAL-17 | App AdminPublicContent review flow passes scheduled timestamp; API PublicContent domain exists. | **EXTEND / VERIFY** PublicContent owns draft/moderation/approval/publish/withdraw; Calendar references schedule/status, not duplicate content. |
| CAL-18 | No social publishing orchestration/channel connector found. | **NOT FOUND / FUTURE** adapters only after channel security, approvals, media policy, rate limits, retries and audit contract. |
| CAL-19 | Finance is bounded; README establishes tenant/legal-entity and maker-checker controls; accounting contract defers budget commitments. | **LINK ONLY** display Finance-owned budget/approval references; never mutate ledger or bypass maker-checker. |
| CAL-20 | No Projects/Portfolio domain or App feature found. | **NOT FOUND** future Portfolio owns projects, milestones and stage gates; Calendar renders authorized references. |
| CAL-21 | No Reporting domain or App feature found. | **NOT FOUND** Reporting owns report definitions/data/generation; Calendar schedules runs and links results. |
| CAL-22 | No Communication Hub domain/feature found. | **NOT FOUND** Communication owns threads/messages/channels; Calendar links rooms/threads, not duplicate messages. |
| CAL-23 | API Files is platform capability; App Files feature exists. | **REUSE** authorized file references and immutable meeting-pack snapshots; recheck permission on open/download. |
| CAL-24 | API domain policies and trusted tenant context exist; App route guard treats backend as final authority. | **REUSE / EXTEND** explicit Calendar permissions; fail-closed tenant scope; reauthorize linked resources; prevent IDOR. |
| CAL-25 | Domain audit foundations exist; Calendar event audit/outbox absent. | **CREATE** immutable actor/time/tenant history, version checks, reschedule/cancel provenance. |
| CAL-26 | No resource/room/equipment reservation feature found. | **CREATE** resource catalogue, capacity, buffers, conflict and approval rules. |
| CAL-27 | No unified cross-domain Calendar view/query found. | **CREATE** permission-filtered federated agenda with source freshness and domain references. |
| CAL-28 | Amharic-first shared shell and date preference exist; Calendar accessibility not implemented. | **EXTEND** localized labels/numerals, keyboard navigation, accessible status, responsive and RTL-ready design. |
| CAL-29 | External Google/Microsoft/ICS sync excluded from A1 first release. | **DEFER** adapter boundary; later OAuth scopes, consent, conflict and privacy review. |
| CAL-30 | No Calendar analytics module found. | **FUTURE** aggregate utilization/reminder/action metrics; exclude diary content. |

## 3. Calendar convention and temporal contract

- **Timed events:** persist UTC instants plus explicit IANA event timezone. Preserve original timezone for recurring local wall-clock events.
- **All-day events, holidays and due dates:** persist date-only `YYYY-MM-DD`; never serialize as UTC midnight or shift across zones.
- **E.C. entry:** validate through canonical EthiopianCalendar/EthiopianDate; normalize to canonical Gregorian date-only/UTC instant; retain original entered calendar metadata where audit requires it.
- **Display:** E.C. default; G.C. exclusive user choice. Preference changes rendering, not stored business meaning.
- **Timezone:** default new ARNA event timezone `Africa/Addis_Ababa`; each event may specify another IANA timezone. Viewer timezone must not silently reinterpret event time.
- **Ethiopian clock:** consider sunrise-based 6-hour clock as optional display/input only after usability/conversion tests; APIs remain unambiguous ISO-8601/24-hour.
- **Workweek/holidays:** engine documents Friday/Saturday weekend; actual working days, weekends and holidays are tenant/organization policy. Approximate religious dates must be labelled.
- **Recurrence:** expand in event timezone, not fixed UTC durations; define DST gaps/folds for non-Ethiopian zones, moved/cancelled instances and series-vs-instance updates.
- **Correctness vectors:** E.C. leap/Pagume 5/6, Meskerem boundary, G.C. leap day, UTC/Addis midnight boundary, date-only no-shift, foreign-zone DST recurrence, invalid dates and timezone changes.

## 4. Product strategy — what makes ARNA distinct

1. **E.C.-native, not merely translated:** E.C. first-class in entry, recurrence, fiscal periods, holidays and views; G.C. exclusive optional mode.
2. **One temporal layer, domain-owned truth:** Calendar coordinates time; Governance owns proceedings, Finance owns ledger/budgets, PublicContent owns publication, People owns appointments, future domains own their data.
3. **Event-to-execution workspace:** agenda, attendees, RSVP, papers, action references, budget context, portfolio milestones, reports, communication and outcomes in one event view.
4. **Governed decision trail:** notices, attendee snapshots, agenda versions, attendance, conflicts, quorum, votes, resolutions, certification and corrections are auditable and policy-driven.
5. **Professional Amharic-first UX:** mobile agenda-first, desktop planning views, accessible navigation, localized numerals, responsive layouts and explicit loading/offline/stale/conflict/denied states.
6. **Reliable notifications:** server-side reminders with idempotency, retries, delivery evidence, quiet hours and preferences.
7. **Privacy by design:** private diary private by default; restricted board/committee material; explicit sharing; analytics never expose diary content.
8. **Future adapters:** email/SMS/push/social/conferencing/external calendars behind versioned, consented adapters.

## 5. Target architecture

- **Calendar Core (new API domain):** calendars, events, attendees/invitations/RSVP, recurrence/exceptions, links, visibility, resources/reservations, audit and query APIs.
- **Temporal services (reuse/extend core):** EthiopianCalendar/EthiopianDate, timezone resolver, date-only parser, locale formatter and configurable holiday/workday policy.
- **Diary (new private capability):** private entries/notes/attachments/reminders, separate ACL and retention; link to events without exposing diary text.
- **Action coordination:** aggregate references to authoritative work. Decide whether a dedicated Tasks domain is needed; Governance action items remain Governance-owned until then.
- **Notification orchestrator (extend platform):** transactional outbox, queue, reminder planner, delivery adapters, retries, dedupe and preferences.
- **Adapters:** Governance meetings; PublicContent publication; Finance budget references; Shareholder GA; People appointments; Files; future Portfolio/Reporting/Communication.
- **App Calendar workspace:** month/week/day/agenda/timeline, event detail, scheduling composer, diary, meeting workspace, global date/timezone preferences.
- **Suggested records, subject to A3 schema review:** calendars, events, event_attendees, recurrence_rules, occurrence_exceptions, event_links, resources, reservations, reminder_rules, reminder_deliveries, audit_entries, outbox; diary_entries remain separate privacy boundary. Never copy source-domain records.

## 6. Phased implementation and acceptance gates

| Phase | Scope | Exit evidence |
|---|---|---|
| A1 Architecture | Product boundary and experience | **APPROVED** architecture docs. |
| A2 Baseline | Source inventory, disposition, owners, risks, strategy | **CLOSED** by this register and updated API/App reports. |
| A3.1 Temporal foundation | Date-only/instant DTOs, timezone contract, preference sync, legacy compatibility map | Approved contracts, compatibility evidence, E.C./G.C. correctness tests. |
| A3.2 Calendar Core | Tenant-scoped event APIs, attendee/RSVP, recurrence, links, audit, resources, policies | Exact-head CI; tenant isolation, IDOR, concurrency, recurrence and authorization tests. |
| A3.3 Reminders | Outbox, delayed jobs, dedupe/retry, delivery adapters/preferences | Failure-atomicity, duplicate/retry, delayed-job and timezone tests. |
| A4.1 Governance | Meeting lifecycle, body/member snapshots, agenda, papers, attendance, policy-based quorum/votes/resolutions | Governing instruments and Owner approval; certified immutable decision trail. |
| A4.2 Public communication | Announcement scheduling and post queue | PublicContent moderation/approval remains authoritative; idempotent publication/audit. Social adapters separately approved. |
| A4.3 Operational integrations | Finance read-only budget, People, Shareholder GA, Files, future Tasks/Portfolio/Reporting/Communication | Per-domain API/permission contract, source ownership and freshness behavior. |
| A5 Experience/release | Calendar views, diary, composer, mobile agenda, accessibility, release | Exact commit CI, E.C./G.C. vectors, accessibility/responsive and authenticated runtime checks; separate deployment approval. |

Sequence: temporal foundation → Calendar Core → reliable reminders → Governance/PublicContent adapters → remaining domain integrations → full App experience/release. Do not parallelize dependent work before contracts stabilize.

## 7. Security, privacy and reliability

- Tenant-scoped records resolved only through trusted server tenant context; fail closed.
- Explicit permissions for view/create/edit/invite/manage-resource/diary-owner/governance-secretary/auditor; approve slugs before code.
- Enforce Calendar permission **and** linked-domain permission for every read, write, notification and deep link.
- Prevent attendee enumeration, tenant leakage, guessed-ID access, unauthorized subscriptions and double-booking.
- Diary private by default; tenant administrators/managers cannot inspect entries absent explicit sharing.
- Governance classification controls meeting packs, ballots and resolutions.
- Idempotency for create/reschedule/publish/reminders; optimistic version checks; no silent overwrite of certified decisions.
- At-least-once delivery with dedupe and delivery state; do not promise exactly-once external delivery.
- Observe queue lag, reminder backlog, retries/dead letters, publication failures, recurrence latency, resource conflicts and API errors.
- Paginated/virtualized agenda, bounded recurrence expansion, indexed tenant/time queries and permission-safe caching.
- Keyboard-operable calendar, semantic labels, focus management, reduced motion and color-independent status.

## 8. A2 inventory closure and Owner decisions

The previously outstanding inventories are now addressed at source-review level:
- Legacy compatibility classes, facade, manager, formatter, datetime and bootstrap alias identified. Exact call-site compatibility mapping is an A3 prerequisite.
- Date semantics verified from Loan date-only `due_date`, Wallet ISO `occurred_at`/`due_at`, canonical E.C. timezone conversion and App generic JS Date formatter.
- Loan/Wallet obligations classified as source-domain records; Governance actions classified as Governance-owned; shared Tasks domain remains a decision.
- App date coverage includes shared formatter adoption and remaining direct Gregorian rendering/native picker risks.
- Domain boundaries and ownership recorded above; endpoint-level permission slug mapping is an A3 contract task.
- Governance policy constraints/deferred lifecycle recorded; no quorum/voting rules inferred.
- App public-content review passes a scheduled timestamp; API authoritative scheduler/delivery contract must be verified before production reliance. Social publishing not found.

**Owner decisions before affected implementation:** diary privacy/retention/export/deletion/sharing; shared Tasks domain vs domain-owned actions; account-synced calendar preference and display timezone; recurrence/resource booking scope; authoritative Governance instruments; PublicContent scheduling and social scope; Portfolio/Reporting/Communication ownership; reminder channels/quiet hours/escalation.

## 9. Verification boundary

No tests, builds, browser/API production checks or runtime changes were part of this closeout. A2 is **CLOSED** for bounded source inventory and disposition. Product/policy decisions remain **BLOCKED / OWNER DECISION REQUIRED**. A3 is **NOT STARTED / NOT AUTHORIZED**.
