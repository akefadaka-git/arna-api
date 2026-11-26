# ARNA UEINE — A2 Read-only Repository Reconciliation and Gap Register

| Control | Value |
|---|---|
| Document ID | ARNA-UEINE-A2-001 |
| Version | 0.1 — evidence snapshot |
| Status | **PARTIAL — A2 NOT CLOSED** |
| Governing baseline | ARNA-UEINE-A1-001 v1.0 (frozen) |
| Assessment date | 2026-11-26 |
| API repository | `akefadaka-git/arna-api` |
| App repository | `akefadaka-git/arna-app` |
| API main SHA inspected | `058dc27c27173006bafba7658d80e5cbacc7c440` |
| App main SHA inspected | `f694eb13ba822440c6842cb135a66f89218bc454` |
| A1 specification commit | `d64b549f612d246295cb77fa9b67a3783640798a` |
| A2 evidence branch | `docs/a2-ueine-readonly-reconciliation` |
| Next gate | Complete domain inventory, reconcile unresolved items, then Owner A2 closure decision |

> **Read-only boundary:** This assessment inspected repository source, tests, route definitions and architecture documents. No application code, migrations, default-branch refs, workflow definitions, runtime configuration or production systems were changed. This report is a documentation artifact on a separate branch descended from the A1 specification commit. No A3 implementation is authorized.

## 1. Executive finding

The inspected mainline contains a functioning **tenant-scoped in-app notification foundation**, plus two demonstrated domain-to-notification publisher paths:

1. Communication announcement publication.
2. Calendar reminder delivery.

It does **not** yet demonstrate the A1 target as a universal enterprise event-intelligence capability. In particular, no shared event gateway, canonical event registry, general event classifier, policy-driven action orchestrator, role/organization/workflow recipient resolver, multi-channel dispatcher, digest/preference service, or complete event-to-action audit chain was found in the inspected code.

Several domains have business lifecycle events and audit listeners. Those are valuable source events and audit mechanisms, but they do not establish notification integration. Finance has a durable domain outbox and lease/retry primitives, but no central-notification bridge was established by the searched call sites.

The frontend has a notification bell, in-shell center, API adapter and in-memory store. Its current capabilities are a personal paginated inbox, unread count, read/read-all and allowlisted internal navigation. This is materially narrower than the A1 experience catalogue.

**A2 disposition: PARTIAL / OPEN.** The findings below are evidence-backed for inspected files. The complete 39+ domain/API and frontend feature inventory, exhaustive publisher/outbox search, and exact-head test execution remain outstanding. Absence from search results is marked NOT VERIFIED, not proof that a domain has no implementation.

## 2. Repository baseline and evidence method

The latest repository commit search returned the following mainline heads:

- API: `058dc27c27173006bafba7658d80e5cbacc7c440`
- App: `f694eb13ba822440c6842cb135a66f89218bc454`

The A1 document was independently fetched after creation and its frozen-status declaration confirmed. A2 branch is based on the A1 documentation commit, not on an unreviewed code change.

Evidence was collected by:
- searching the default branch for notification service references, event listeners, outbox/dispatcher classes, routes, workflow handlers and frontend notification components;
- fetching the relevant source files and test files;
- reading the API route group and the application shell integration;
- cross-checking repository architecture documents against current source.

Limitations:
- GitHub code search is useful for locating references but is not a substitute for a complete repository tree walk or static call-graph analysis.
- The source was not checked out and test suites were not executed in this assessment.
- No production database, live notification queue, scheduled worker, email/SMS/push provider or deployed application was queried.
- No delivery claims beyond persisted in-app notification creation are made.

## 3. API inventory — central notification capability

### 3.1 Central service

File: `mases_core/src/Notifications/Services/NotificationService.php`  
Blob SHA: `33a1bc15afbbaf97545f20d8a88c9a0fbab76a85`

Observed contract:
- `publish(Tenant $tenant, array $recipients, array $payload)` accepts an explicit list of canonical persisted `User` models.
- Re-reads and locks the tenant and recipient rows; rejects missing/inactive tenant and inactive/missing user.
- Requires every recipient to have an active `TenantMembership` in the target tenant.
- Inserts `PlatformNotification` rows within a database transaction.
- Supports `event_key`, `category`, priority `low|normal|high`, Amharic/English title and body, internal route name, resource reference and optional dedupe key.
- Deduplication is per tenant + user + dedupe key using `firstOrCreate`.
- Inbox, unread count, mark-read and mark-all-read are scoped to active tenant context and the authenticated user.

Important boundary:
- The service explicitly documents that it publishes to **explicit recipients only** and does not broadcast to an arbitrary tenant population.
- It does not resolve role, department, committee, legal entity, workflow approver, meeting relationship or subscription recipients.
- It is a persistence/inbox service, not an event gateway, classification engine or general action orchestrator.
- Priority validation has three levels; no critical priority is accepted by this service. Communication maps urgent/critical announcements to high.

### 3.2 Persistence and HTTP surface

Files:
- `mases_core/src/Notifications/Models/PlatformNotification.php`
- `app/Http/Controllers/NotificationController.php` (blob SHA `920e19c64e85ea696d3155cc9ea6b3a389b7dfa2`)
- `routes/api.php` (blob SHA `f84dba507f88922088c9681ec718d4537275ebc8`)

Routes under the enclosing `auth:sanctum` + `tenant.membership` group:
- `GET /api/notifications`
- `GET /api/notifications/unread-count`
- `POST /api/notifications/{id}/read`
- `POST /api/notifications/read-all`

The controller returns the current user's paginated inbox and unread count, and supports read/read-all mutations. The service's `ownedNotification` query scopes mutation by tenant, user and notification ID.

**Authorization reconciliation:** the API routes do not add a separate `notifications.view` or `notifications.manage` permission middleware. The current access model is authenticated user + active tenant membership + ownership of the recipient-specific notification row. This provides personal inbox isolation, but it is not the same as role-based visibility or role workspace authorization. The app-side service integration contract proposes `notifications.view` and `notifications.manage`; those permission slugs are not evidenced as implemented on these routes. A2 must resolve whether the personal inbox should remain ownership-only and how any future role inbox will authorize individual records.

### 3.3 Central notification tests

File: `tests/Feature/CentralNotificationsTest.php` (blob SHA `e92248a51b61e7d55117f2c0a17a14d4265ce0ab`)

Tests inspected cover:
- authenticated user + active tenant inbox scoping;
- preventing one user from marking another user's notification read;
- cross-tenant notification read denial;
- active tenant context requirement;
- rejection of inactive/stale tenant and user records;
- atomic rollback if a later recipient in the recipient list is invalid;
- read-all restricted to the authenticated user's notifications.

Assessment: **VERIFIED FOUNDATION** for personal tenant/user isolation in these feature tests. The tests do not establish role recipient resolution, confidentiality-class filtering, source-resource authorization at dispatch, multi-channel delivery or universal event processing.

## 4. Domain event publisher and outbox inventory

### 4.1 Confirmed central notification publishers

| Domain | Source and behavior | Evidence | Disposition |
|---|---|---|---|
| Communication | Announcement moves through draft → pending approval → approved → published. At publication, active recipients are revalidated and central notifications are written in the same transaction. | `CommunicationAnnouncementController.php`; `CommunicationAnnouncementsTest.php`; `docs/communication/ANNOUNCEMENTS-001.md` | **VERIFIED PUBLISHER** |
| Calendar | Due reminders are read from `calendar_reminder_outbox`; event status, future occurrence, cancellation, tenant, active account and active membership are rechecked; central notification write and outbox delivered transition share a transaction; dedupe key is stable per outbox row. | `CalendarReminderDispatcher.php`; `CalendarReminderDispatcherTest.php`; `routes/console.php`; clean-install workflow check | **VERIFIED PUBLISHER** |

#### Communication detail

The controller requires distinct tenant-scoped permissions:
- `announcement.manage` for creation/submission;
- `announcement.approve` for approval;
- `announcement.publish` for publication.

It rejects self-approval, requires approved state before publication, checks expiry, and revalidates all recipients as active users with active tenant membership at publication time. Draft recipient selection is explicit user IDs, capped at 1,000; no implicit tenant-wide broadcast exists. Publishing creates a central notification with event key `communication.announcement.published`.

Boundary/gap:
- Approval submission and approval transitions do not themselves notify a resolved approver or submitter in the inspected controller.
- Recipient resolution is a preselected list of user IDs, not a dynamic role/workflow resolver.
- Announcement acknowledgement is stored in the announcement recipient record, not represented as a general notification acknowledgement lifecycle.
- Notification action routes to the announcements list; the resource reference uses `announcement_id`, whereas the current frontend detail-route resolver expects `id` for allowlisted detail routes. A list route is allowed, so this is not an unsafe arbitrary redirect, but it is not a record-specific deep link.

#### Calendar detail

The dispatcher handles recurrence materialization, stable occurrence identities, stale/cancelled occurrence skipping, active membership checks, bounded attempts and retry/failure state. Its scheduled command is `calendar:dispatch-reminders`.

Boundary/gap:
- The current central notification is a generic reminder title/body and a calendar route. It does not carry a structured meeting/action workflow or participant-specific digest.
- The dispatcher is a domain-specific adapter; it does not demonstrate a universal event gateway or general dispatcher.

### 4.2 Domain event streams that are audit-only in inspected registrations

| Domain | Event registration / listener | Observed purpose | UEINE disposition |
|---|---|---|---|
| Finance | `FinanceServiceProvider` registers lifecycle events to `AuditFinanceLifecycleEvent`; `JournalPosted` also persists `FinanceOutboxMessage`. | Audit lifecycle + durable journal-posted event | **PARTIAL — event/outbox exists; notification bridge not verified** |
| Governance | `GovernanceServiceProvider` registers body, position, term, appointment and membership events to `AuditGovernanceEvent`; election lifecycle change to a dedicated audit listener. | Audit trail | **NOTIFICATION INTEGRATION NOT VERIFIED** |
| Loan | `LoanServiceProvider` registers the loan lifecycle event set to `AuditLoanEvent`. | Audit trail | **NOTIFICATION INTEGRATION NOT VERIFIED** |
| Shareholder | `ShareholderServiceProvider` registers shareholder registered/updated, shareholding changed and certificate issued to `AuditShareholderEvent`. | Audit trail | **NOTIFICATION INTEGRATION NOT VERIFIED** |
| Wallet | `WalletServiceProvider` registers wallet opened, obligation recorded, payment received/allocated, reversal, adjustment and entry reversal to `AuditWalletEvent`. | Audit trail | **NOTIFICATION INTEGRATION NOT VERIFIED** |

These source events are candidates for future adapters only after event semantics, confidentiality, transaction boundaries, recipients and owner-domain authorization are reconciled. An audit event must not be blindly forwarded to a user inbox.

### 4.3 Finance outbox boundary

Files:
- `PersistFinanceJournalPostedOutbox.php` (blob SHA `cca9edcb54c2e9b3a1fdbbdc3a56a62a222df307`)
- `FinanceOutboxDispatcher.php` (blob SHA `d5756828c4539bc4f97e40d90eb6bf5c187cee6b`)
- `FinanceServiceProvider.php` (blob SHA `84ce71513b5afbb9923b4c63e86153043539b8bd`)
- `FinanceGeneralLedgerTest.php` (blob SHA `d552104cfe49f44f0c00659dc3f0fbd8f922d021`)

The journal-post listener writes a versioned `finance.journal.posted` payload to a tenant/legal-entity scoped finance outbox inside the journal posting transaction. The dispatcher supports atomic lease, expired-lease recovery, claim-token acknowledgement and bounded retry delay.

The inspected dispatcher exposes claim/acknowledge/release primitives. The test exercises those primitives. The Finance provider binds the dispatcher but the inspected call-site search did not establish a registered scheduled consumer or a bridge to `NotificationService`. **Do not treat finance outbox persistence or successful claim/ack tests as notification delivery.**

The payload includes journal lines, account identifiers and monetary values. A future adapter must not expose this financial payload wholesale to notification content or recipients. It should publish a minimal, sensitivity-reviewed event reference and leave data access to Finance policy checks.

### 4.4 Other domain areas — inventory status

The following areas appear in the API's broader route/schema/workflow surface, but a complete event-publisher and outbox inventory was not established in this evidence pass:

| Domain / capability | Current A2 evidence status |
|---|---|
| People / HR | **NOT VERIFIED** — no central notification publisher found in targeted searches |
| Organization | **NOT VERIFIED** — no central notification publisher found in targeted searches |
| Documents / File Management / document approvals | **NOT VERIFIED** — document lifecycle contracts and approval boundaries exist; no central notification adapter confirmed |
| Tasks / task management | **NOT VERIFIED** — tenant-scoped task routes exist; event-to-notification mapping not confirmed |
| Corporate Site / public content review | **NOT VERIFIED** — review/publish routes exist; notification publisher not confirmed |
| Public content / CMS | **NOT VERIFIED** — review workflow surface exists; central notification integration not confirmed |
| Reports / analytics | **NOT VERIFIED** — no event-to-notification integration evidence established |
| Surveys / performance / plans / procurement / inventory / other registered domains | **UNRESOLVED** — full API and UI domain inventory still required |

The status means that the current search did not establish a publisher, not that no event or notification code exists anywhere in the repository.

## 5. Frontend notification inventory

### 5.1 Existing components and state

| File | Observed responsibility | Evidence |
|---|---|---|
| `src/layouts/AppShell/NotificationsBell/NotificationsBell.vue` | Bell trigger, unread badge, accessible label, initial unread-count fetch on mount | Blob SHA `f19ccc4111019ff9abc265456de6b0febb37cb61` |
| `src/features/notifications/NotificationsCenter.vue` | In-shell dialog, paginated feed, read/read-all, retry/empty/loading/error states, allowlisted route resolution | Blob SHA `9c1e9255c590d494297853a3787c54bd32a55ea2` |
| `src/features/notifications/api.ts` | Typed API adapter for inbox, unread count, read and read-all; no client-supplied tenant ID | Blob SHA `260c1d744ea418b969b9ea1381aaff9ef1e8f48c` |
| `src/stores/notifications.ts` | In-memory notification list, unread count, loading/error state, cache freshness; cleared on tenant/session change by app bootstrap contract | Blob SHA `98784f688182db8b14ddf055ce688c866693dded` |
| `src/layouts/AppShell/AppShell.vue` | Composes bell and notification center into authenticated shell | Blob SHA `59074bbfde0e93802e7405db5e0ed60a8a1af155` |
| `tests/app-shell-responsive-contract.test.mjs` | Asserts bell enabled, center connected, API methods present | Blob SHA `674301ba15a821fcb1d6b7c55f4a3bc63a92df96` |

### 5.2 Existing behavior

- Bell performs an unread-count fetch when mounted and renders a capped 99+ badge.
- Center loads 20 notifications per page, supports pagination, read and read-all, retry and empty/error/loading states.
- Locale selection resolves Amharic/English title and body in the API adapter.
- Client navigation accepts only registered, allowlisted internal route names; `resource_ref` is treated as data and is not spread into a route object.
- Requests are guarded against tenant changes while in flight.
- The store is in-memory and the design calls for clearing tenant/session-scoped cache on tenant switch/logout.

### 5.3 Frontend gaps against A1

Not evidenced in the inspected notification feature:
- role-based, organizational, committee, workflow and delegated work queues;
- realtime event subscription or guaranteed live badge updates;
- notification preferences, channel subscriptions, quiet hours and digest controls;
- notification acknowledgement distinct from read state;
- snooze, dismiss, assign, escalate and delegation controls;
- event timeline and action history;
- reusable approval/workflow/reminder/meeting/report/performance/survey notification cards;
- notification administration and policy configuration;
- external channel delivery UI/status (email, push, SMS or chat);
- event-to-action audit visibility.

The frontend service adapter accepts only priority `low|normal|high`, matching the current API. A1's future priority policy and any critical semantics therefore require a controlled contract extension after reconciliation.

### 5.4 Documentation/source divergence

The App repository contains `docs/ARNA_APP_SHELL_SERVICE_INTEGRATION_CONTRACT_v1.0.md`, marked **PROPOSED**, which specifies `notifications.view` / `notifications.manage` permissions and says the bell remains disabled until API routes, schema, permissions and isolation tests are deployed and verified.

At the inspected main SHA:
- the API notification routes are inside authenticated tenant-membership middleware but have no separate notification permission middleware;
- the AppShell passes `:disabled="false"`;
- AppShell responsive contract tests assert the bell is enabled and connected to the current API adapter/center.

This is a documentation/source status divergence, not proof of a security bypass. Current server authorization still limits notifications to the authenticated recipient in the active tenant. The intended permission model and the proposed contract's current status must be reconciled and the stale/proposed contract either superseded or updated through its own approval process. This A2 report does not edit it.

## 6. Recipient authorization and workflow boundary reconciliation

### 6.1 Current proven authorization

- API inbox: authenticated canonical user, resolved active tenant, tenant membership middleware, tenant + user row scope.
- Publish: active persisted tenant; active persisted user; active membership in target tenant; all recipient validation and notification persistence inside a transaction.
- Read mutation: notification must belong to current tenant and current user; other user's or other tenant's record resolves as not found.
- Communication: separate manage/approve/publish permissions; self-approval prohibited; recipients explicitly selected and revalidated at publication.
- Calendar: owning domain validates event state/occurrence, tenant, active user and membership before publishing.

### 6.2 Boundaries that must remain

- Notification visibility must never grant source-resource visibility.
- Role membership is not equivalent to permission to see every notification addressed to that role.
- A role/committee/workflow resolver must expand recipients only after validating tenant, organizational scope, active membership, effective permission, relationship and source-record access.
- Source-domain approval remains authoritative. The notification engine can request an approval or route an action, but must not approve it.
- Read, acknowledgement, task completion and approval must be separate state transitions.
- Financial journal events, shareholder records, governance records and personal/HR data require sensitivity-specific content projection and least-privilege recipient checks.
- All contextual actions must re-authorize against current source-domain state and permissions when clicked.

### 6.3 Workflow evidence and gap

Communication provides the strongest inspected approval example: `draft → pending_approval → approved → published`, with explicit permission gates and separation of submitter/approver. However, the notification call occurs on publication to the preselected recipients; there is no verified notification to the approver at submission or to the submitter on decision.

Finance, Loan, Governance and other domains have their own lifecycle and policy boundaries. Their events must not be treated as generic approval commands. The full domain-specific workflow matrix (initiator, responsible role, approver, reviewer, delegation, escalation, veto, final authority and source API) remains an A2 open item.

## 7. Evidence-backed gap register

Severity is an A2 planning assessment, not a production incident rating.

| ID | Severity | Gap / evidence | Impact against A1 | Required A2 resolution |
|---|---|---|---|---|
| UEINE-G01 | Critical | No universal event gateway/registry found; existing central service accepts caller-built notification payloads. | No single governed intake or complete event disposition. | Confirm event producer inventory, canonical contract candidates, schema/version ownership and transactional boundaries. |
| UEINE-G02 | Critical | Only Communication and Calendar central publishers were confirmed by current code search. | Enterprise-wide event coverage not established. | Complete domain-by-domain search and exact publisher/outbox call graph across all registered API domains. |
| UEINE-G03 | High | NotificationService accepts explicit users only. | No role/org/workflow/relationship recipient resolution. | Reconcile current role/permission schema, memberships, committee/organization scopes and record access rules; define safe recipient resolver contract. |
| UEINE-G04 | Critical | Finance journal outbox has claim/ack/retry primitives; central notification bridge not found. | Durable business event may not reach intended user action/notification. | Identify actual consumer/scheduler/handler, payload contract and integration tests; if none, record Finance as unintegrated. |
| UEINE-G05 | High | Governance, Loan, Shareholder and Wallet event registrations observed primarily feed audit listeners. | Lifecycle events are not yet shown as actionable notifications. | Classify event catalogues and decide which events are actionable, informational, audit-only or telemetry. |
| UEINE-G06 | High | Communication submission/approval transitions do not notify approvers/submitters in inspected controller. | Workflow queues may require users to poll; action ownership and deadlines may be missed. | Map approval recipients and event points; preserve source-domain approval authority. |
| UEINE-G07 | High | No general notification preference, subscription, quiet-hours, digest or channel adapter found in inspected notification surface. | No personalized delivery policy or digest lifecycle. | Search full API/app for preference and provider adapters; define consent and security controls. |
| UEINE-G08 | High | API route group has membership + recipient ownership but no explicit notification permission; App integration contract proposes notification permissions. | Permission contract and implementation documentation diverge; role inbox model unresolved. | Decide personal-inbox ownership model vs permission-gated shared views; reconcile permission catalogue and docs. |
| UEINE-G09 | High | Frontend implements inbox/read/read-all only. | A1 modular approval, workflow, reminder, meeting, report, performance and survey experiences absent/not verified. | Inventory all feature modules and define reuse contracts and API dependencies. |
| UEINE-G10 | Medium | Notification API supports low/normal/high only. | No explicit critical class or policy-defined urgency/deadline model. | Define severity/priority mapping without conflating business urgency with AI confidence. |
| UEINE-G11 | Medium | Frontend action resolver supports allowlisted routes; Communication resource reference uses `announcement_id`, not generic detail `id`. | Some notifications navigate only to list rather than exact record. | Standardize typed resource references and reauthorize at owning route. |
| UEINE-G12 | High | No general event decision/action audit chain was found. | Cannot yet inspect event → policy → recipient → action → delivery → acknowledgement lineage. | Define correlation and audit entities/retention; map existing domain audit/outbox records. |
| UEINE-G13 | High | No test execution performed; code search/fetch is not exhaustive. | No exact-head end-to-end or regression certification. | Run authorized API/App test and workflow evidence review in A2 without source modification; report exact SHAs. |
| UEINE-G14 | Medium | No full domain and frontend feature tree inventory completed in this pass. | Cannot claim complete 39+ domain reconciliation. | Enumerate canonical domain catalogue against API providers/routes/tests and App features/routes/components. |
| UEINE-G15 | Medium | Finance outbox payload includes journal-line/account/amount details. | Risk of oversharing financial data if payload is copied to notifications. | Require minimal notification projection and source-resource authorization; never broadcast raw outbox payload. |
| UEINE-G16 | Medium | Bell fetches unread count on mount; no verified live update/push/subscription mechanism in inspected component. | Badge freshness depends on remount/other fetches; users may not see timely changes. | Inspect service polling/realtime lifecycle; set explicit freshness and offline behavior requirements. |

## 8. A2 domain and UI coverage matrix — current evidence snapshot

Legend:
- **VERIFIED** — direct source and supporting test evidence found for the stated behavior.
- **PARTIAL** — relevant event/outbox or workflow exists, but central notification integration is not established.
- **NOT VERIFIED** — search did not establish the capability; not proof of absence.
- **UNRESOLVED** — inventory or source evidence remains incomplete.

| Area | Event/lifecycle evidence | Central notification evidence | A2 status |
|---|---|---|---|
| Central notifications | Notification record, service, API routes | Personal inbox and publish persistence | **VERIFIED FOUNDATION** |
| Communication announcements | Draft/submit/approve/publish lifecycle | Publish → explicit recipients | **VERIFIED PUBLISHER** |
| Calendar | Reminder outbox + recurring occurrence handling | Reminder dispatcher → central service | **VERIFIED PUBLISHER** |
| Finance | JournalPosted event + transactional outbox | No bridge confirmed | **PARTIAL** |
| Governance | Domain lifecycle + election audit events | No publisher confirmed | **NOT VERIFIED** |
| Loan | Loan lifecycle events + audit listener | No publisher confirmed | **NOT VERIFIED** |
| Shareholder | Shareholder/shareholding/certificate events + audit listener | No publisher confirmed | **NOT VERIFIED** |
| Wallet | Wallet/payment events + audit listener | No publisher confirmed | **NOT VERIFIED** |
| People / HR | Domain and policy surfaces exist; full event inventory not inspected | No publisher confirmed | **UNRESOLVED** |
| Organization | Domain and policy surfaces exist; full event inventory not inspected | No publisher confirmed | **UNRESOLVED** |
| Documents / Files | Lifecycle and approval contracts exist | No publisher confirmed | **UNRESOLVED** |
| Tasks | Tenant-scoped task route surface | No publisher confirmed | **UNRESOLVED** |
| Corporate Site / public content | Review/publish routes | No publisher confirmed | **UNRESOLVED** |
| Reporting / analytics | Domain scope known; event inventory incomplete | No publisher confirmed | **UNRESOLVED** |
| Plans / performance / surveys / procurement / inventory / remaining domains | Full inventory not completed | No publisher confirmed | **UNRESOLVED** |
| App notification shell | Bell, center, API adapter, store | Personal inbox UI | **VERIFIED FOUNDATION** |
| App advanced notification components | No component inventory evidence in this pass | Not established | **NOT VERIFIED** |

## 9. A2 work still required before closure

1. Retrieve the frozen ARNA domain catalogue and enumerate every registered domain/subdomain against the API source tree, service providers, routes, events, listeners, outboxes, scheduled commands and tests.
2. Enumerate the App feature tree, route registry, navigation permissions, API adapters, stores, shared components and tests; map each feature to domain-owned event and workflow sources.
3. Search every direct publisher, event dispatch, queued listener, scheduler/command and outbox consumer, including non-notification event buses and provider integrations.
4. Inspect the canonical RBAC and organization/committee/relationship models to determine valid recipient-resolution sources and source-resource authorization behavior.
5. Build a domain workflow matrix for approval, review, assignment, reminder, escalation, delegation, acknowledgement and final action ownership.
6. Reconcile App Shell proposed contract versus current API and App implementation; document the decision without changing A1.
7. Inspect notification migrations/indexes, retention, queue configuration, worker/scheduler deployment assumptions and transactional behavior.
8. Run exact-head targeted API and App tests/workflows only after confirming available no-cost workflow paths; no code changes are needed to execute tests. Record run IDs, job outcomes and exact tested SHAs. No deployment.
9. Complete A1 requirement traceability and produce a final A2 recommendation with unresolved Owner decisions.

## 10. Stage disposition

- A1 target architecture: **APPROVED / FROZEN**.
- A2 source reconciliation: **PARTIAL / OPEN**.
- A3 implementation: **NOT AUTHORIZED / NOT STARTED**.
- A4 verification: **NOT STARTED**.
- A5 deployment: **NOT AUTHORIZED**.

No material contradiction requires changing the A1 target itself. The principal issue is implementation coverage and contract divergence: current mainline provides a strong personal notification foundation with two confirmed domain publishers, while the universal event intelligence and recipient-resolution layers remain unverified or absent from inspected code. The App Shell proposed integration contract also differs from the currently enabled implementation and API permission surface; this requires an Owner decision during A2, not an implicit A1 rewrite.

**A2 closure recommendation:** Do not close A2 yet. Complete the domain/frontend inventory and workflow/authorization reconciliation, then submit the final evidence matrix and decision log for Owner review.

---
**End of A2 evidence snapshot v0.1.**


## 11. Supplementary repository-structure inventory

A further read-only pass used the API provider registry and the App repository README and route composition files to improve the domain map. This is a registered-provider and route-surface inventory, not a complete file-tree or domain acceptance audit.

### 11.1 API registered provider evidence

File: `bootstrap/providers.php` (blob SHA `6482927ae5c5373cfcb0d6f16ee5a75a9a24b2b0`).

The active provider registry explicitly lists the following domain providers:
- Shareholder — `ShareholderServiceProvider`
- Wallet — `WalletServiceProvider`
- Loan — `LoanServiceProvider`
- Governance — `GovernanceServiceProvider`
- Finance — `FinanceServiceProvider`

The same registry includes application, Core, Auth and Policy providers. Its comments describe Employee and later providers as planned/future in the older integration reconciliation plan; that plan must not be treated as current registration evidence. Calendar's reminder dispatcher is invoked from the central console command registry rather than being one of the five domain providers listed above. Communication announcements are implemented in the app-level HTTP controller/routes.

**Implication:** provider registration alone does not inventory every API domain. Several domain areas are integrated through Core or application-level registration, and some domain directories may exist without a dedicated provider. A complete A2 pass must map both provider-backed and non-provider-backed domains.

### 11.2 App README and route surface

App README (blob SHA `e0a341263168f177e455f313d0ece04ff55eb839`) identifies four independent application entry points:
- workspace;
- shareholder portal;
- admin;
- public website.

The README names these domain-oriented feature groups: Authentication/dashboard, People/shareholder experiences, Governance, Documents/File Management, Loans/Wallet, Public content/portal experiences and launcher. It explicitly warns that feature folders/routes/mockups/open PRs do not prove workflow completeness.

Workspace route composition file: `src/router/routes-workspace.ts` (blob SHA `580b1c39004623778dadf7f88c25b51f62e33132`). The inspected route imports/components include:
- Dashboard and Executive Overview;
- Communications Hub and Announcements;
- Files;
- Shareholders and Share Classes;
- Wallet;
- Loans and loan products;
- People;
- Governance overview, attendance, bodies and people views;
- Appointments;
- Tasks;
- Calendar.

Portal route composition: `src/router/routes-portal.ts` (blob SHA `8068cce3c6c6ebc4bba523d93afc48fe5c6a73b6`), with portal home, shareholdings, certificates, wallet and loan pages.

Admin route composition: `src/router/routes-admin.ts` (blob SHA `5b3b648a31dad0ba19967387fa8d503bb4996572`). The full admin feature route catalogue and public-site entrypoint route catalogue were not fully inspected in this pass.

This expands the known App surface beyond the feature list in README. It does not prove API parity, notification support or end-to-end completeness for each route.

### 11.3 Revised App/API domain mapping

| API / business area | App route or feature evidence | UEINE notification evidence |
|---|---|---|
| Authentication / identity | Auth feature and shared session foundations | No business notification publisher established; security-event pipeline is separate |
| Dashboard / Executive Overview | Workspace dashboard and executive overview routes | No notification adapter established |
| Communication / announcements | Communication Hub and Announcements workspace | **VERIFIED** central publisher on announcement publication |
| Documents / Files | Files list/detail routes and file feature | No central publisher established |
| Shareholder / share classes | Workspace shareholder routes; portal shareholdings/certificates | Shareholder audit events; no central publisher established |
| Wallet | Workspace wallet and portal wallet | Wallet audit events; no central publisher established |
| Loan | Workspace loan/product routes; portal loan pages | Loan audit events; no central publisher established |
| People | People directory/profile routes | No central publisher established |
| Governance | Governance overview, attendance, bodies, people routes | Governance audit events; no central publisher established |
| Appointments | Appointment workspace route | No central publisher established |
| Tasks | Task workspace route | No central publisher established |
| Calendar | Calendar workspace route | **VERIFIED** reminder publisher |
| Public content / corporate site | Public/admin application surfaces and API review routes | No central publisher established |
| Reports / surveys / performance / plans | Not identified as dedicated notification-backed App routes in inspected workspace/portal route files | **UNRESOLVED** |
| Admin | Separate admin entrypoint and route composition | Admin event/notification inventory incomplete |

### 11.4 Completeness qualification

This supplementary pass improves the route/provider inventory but does not close UEINE-G02, UEINE-G09 or UEINE-G14. GitHub code search and a small set of route/provider files do not expose a reliable complete tree of all domain subfolders, all frontend routes across all four entrypoints, or every event/outbox call site. The 39+ domain catalogue referenced by the wider ARNA programme has not yet been fully retrieved and mapped in this assessment. A2 remains open until that canonical catalogue is reconciled row-by-row with repository source.
