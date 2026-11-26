# ARNA Universal Event Intelligence & Notification Engine (UEINE)
# A2 Full Diagnostic and Read-Only Reconciliation Report

- **Record:** ARNA-UEINE-A2-FULL-DIAGNOSTIC-001
- **Version:** 1.0.0
- **Assessment date:** 2026-11-26
- **Status:** **A2 PARTIAL / OPEN — NOT VERIFIED-CLOSED**
- **A1 baseline:** ARNA UEINE architecture, Owner-approved/frozen; ARNA ESPPM A1 v1.2.0 30-domain catalogue is the current enterprise domain baseline.
- **API repository:** `akefadaka-git/arna-api`
- **App repository:** `akefadaka-git/arna-app`
- **API main inspected:** `058dc27c27173006bafba7658d80e5cbacc7c440`
- **App main inspected:** `f694eb13ba822440c6842cb135a66f89218bc454`
- **Evidence branch:** `docs/a2-ueine-readonly-reconciliation`
- **Latest evidence branch commit at report preparation:** `bc05135a10f265989fa301129ae665937c50d70a`
- **Scope:** Read-only diagnosis and documentation. No implementation, schema mutation, merge, deployment or paid service action.

---

## 1. Executive decision

**UEINE is not ready for A2 closure.** The current platform has a useful personal notification inbox and two bounded direct notification publishers: Communication Announcement publication and Calendar reminders. It does not yet have the universal event gateway, canonical event registry, classification/disposition engine, deterministic cross-domain decision/action orchestrator, generalized recipient resolver, multi-channel dispatcher, configurable notification policy administration or end-to-end event/action/delivery audit chain required by the frozen UEINE architecture.

The 30-domain catalogue has been retrieved and mapped at the provider/route/event/outbox/frontend-feature inventory level. The complete domain matrix is a companion record:

- `docs/notifications/ARNA_UEINE_A2_DOMAIN_EVENT_AUTHORIZATION_MATRIX.md`
- Current companion blob at this report's preparation: `cd02482ccb2892c1585bfb6e63fc273a24d0e842`

The domain dispositions remain the Owner-approved 2 SUPPORTED / 11 PARTIAL / 6 UNVERIFIED / 11 MISSING. These are ESPPM business-domain evidence dispositions; they are not UEINE feature-completion percentages.

### Overall A2 assessment

| Area | Finding | Status |
|---|---|---|
| Frozen A1 and domain catalogue | Retrieved and preserved; 30 domains | **PASS — BASELINE IDENTIFIED** |
| API provider/route/event/outbox map | Mapped at source inventory level; absent surfaces labelled | **PARTIAL** |
| Frontend route/feature map | Mapped across identified workspace/portal/admin surfaces; full recursive feature-tree export not obtained | **PARTIAL** |
| Central inbox and user/tenant isolation | Source implementation and test definitions inspected | **SUPPORTED AT SOURCE / TESTS NOT RUN** |
| Communication Announcement | Lifecycle and publication integration inspected; material UI/event gaps remain | **PARTIAL** |
| Calendar reminder path | Durable outbox, scheduler registration and publisher code inspected | **SUPPORTED AT SOURCE / RUNTIME NOT VERIFIED** |
| Finance outbox | Transactional event persistence and lease/retry primitives found; notification bridge not found | **OUTBOX ONLY** |
| Other domain events | Several audit event listeners found; no user notification delivery proven | **AUDIT ONLY / NOT VERIFIED** |
| Recipient authorization | Explicit-user membership checks exist; no generalized recipient resolver | **PARTIAL** |
| Workflow/approval authority | Only bounded workflows have source evidence; 30-domain authority mapping incomplete | **PARTIAL / FAIL CLOSED** |
| Queue/scheduler/runtime | Configuration and command registration inspected; deployed worker/scheduler health unknown | **UNKNOWN** |
| Automated verification | Test definitions and CI workflow inspected; no exact-head test result attached | **NOT RUN / NOT VERIFIED** |
| A2 closure | Multiple critical acceptance gaps remain | **NOT READY** |

### Recommendation

Continue A2 read-only reconciliation. Do not request A2 closure yet. Do not begin A3 implementation until A2 closure criteria are satisfied and separately approved. Preserve the frozen A1 baseline without amendment.

---

## 2. Scope, authority and evidence rules

### 2.1 Frozen architecture

The A1 UEINE architecture remains the approved target. This report assesses implementation and authorization evidence against that target; it does not redesign the target.

The enterprise catalogue is the frozen 30-domain successor in `ARNA-ESPPM-A1-SPEC-002 v1.2.0`. Its historical-source qualification is important: v1.2.0 is an Owner-approved reconstructed successor because the complete original v1.0/v1.1 wording could not be recovered verbatim. It is not a verbatim restoration. The A1 document explicitly prohibits silent rewriting.

The Owner-approved A2 decision record explicitly states that approval of the A2 disposition package does not close A2, establish unresolved facts, approve implementation or authorize merge, migration, production transactions, A4 or A5.

### 2.2 Evidence classification

- **VERIFIED SOURCE:** Directly observed in the inspected repository commit.
- **TEST DEFINED:** A test exists in source; it has not necessarily been executed.
- **TEST PASS:** Only use when an exact run/job result proves it. No such UEINE test result is claimed here.
- **NOT FOUND IN INSPECTED SCOPE:** Search did not locate the capability in the inspected source paths; not proof of global absence.
- **UNKNOWN:** Runtime, environment, database or provider state cannot be established from repository source.
- **PARTIAL:** A bounded part exists, but the full architecture requirement is not met.
- **NOT READY:** A2 closure criterion is not satisfied.

### 2.3 Repositories and source heads

| Repository | Exact inspected main SHA |
|---|---|
| `akefadaka-git/arna-api` | `058dc27c27173006bafba7658d80e5cbacc7c440` |
| `akefadaka-git/arna-app` | `f694eb13ba822440c6842cb135a66f89218bc454` |

The domain matrix contains exact paths and selected file blob SHAs. Main branch was not modified by this A2 continuation.

---

## 3. Current UEINE architecture coverage

| Frozen UEINE capability | Current source evidence | Assessment |
|---|---|---|
| Universal Event Gateway | No canonical event ingress/registry or shared event schema identified | **MISSING** |
| Event identity and registry | Domain-specific events and outboxes exist, but no central event catalogue/version contract | **MISSING** |
| Event classification/disposition | No universal actionable/informational/audit-only/telemetry/ignore classifier found | **MISSING** |
| Event Intelligence Engine | No cross-domain interpretation/impact/urgency engine found | **MISSING** |
| Decision & Action Orchestrator | Announcement and Calendar have bounded domain-specific transitions; no universal policy/action orchestrator | **PARTIAL** |
| Recipient resolution | Explicit user IDs and Calendar recipient rows; no role/org/committee/workflow resolver | **PARTIAL** |
| Universal Notification Dispatcher | Central DB inbox persistence only; no verified email/SMS/push/in-app channel adapter framework | **PARTIAL** |
| Retry/dead-letter | Calendar reminder outbox has retry state; Finance outbox has claim/retry primitives; no universal delivery retry/dead-letter policy | **PARTIAL** |
| User experience layer | Bell, personal inbox, announcement register, Calendar views | **PARTIAL** |
| Preferences and subscriptions | No verified preferences, subscriptions, quiet hours, channel selection or digest policy | **NOT FOUND** |
| Workflow center / approval center | Announcement approval exists; no universal approval inbox/action model | **PARTIAL** |
| Event-to-action-to-delivery audit | Separate domain audit/outbox/notification/recipient tables; no unified correlation lineage | **MISSING** |
| Administration and analytics | No UEINE policy administration, delivery analytics or unified health dashboard found | **MISSING** |

The present service should therefore be described as a **tenant-scoped personal notification inbox with two bounded publishers**, not as an already implemented universal notification engine.

---

## 4. Central notification service diagnosis

### 4.1 API service and persistence

Source:
- `mases_core/src/Notifications/Services/NotificationService.php`, blob `33a1bc15afbbaf97545f20d8a88c9a0fbab76a85`.
- `mases_core/src/Notifications/Models/PlatformNotification.php`, blob `0fb4c6a06e4ecded540701db461fb8333603400f`.
- `database/migrations/2026_10_31_000001_create_platform_notifications_table.php`, blob `aba422c8d1edb0f63413723167c007bd958c68c1`.

The service:
- Accepts an explicit list of persisted canonical User recipients.
- Re-reads the target tenant and recipients and rejects missing/inactive records.
- Requires active tenant membership for every recipient.
- Inserts recipient-specific notification rows inside a database transaction.
- Supports event key, category, low/normal/high priority, bilingual title/body, internal route name, resource reference and deduplication key.
- Uses tenant + user + dedupe key uniqueness to avoid duplicate rows where a non-null dedupe key is supplied.
- Scopes inbox, unread count, mark-read and mark-all-read to the authenticated user and current tenant.

It does **not**:
- Resolve recipients from role, department, branch, committee, legal entity, workflow stage, appointment, delegation, relationship or subscription.
- Interpret arbitrary domain events or decide their disposition.
- Authorize the underlying business resource.
- Deliver email, SMS, mobile push or external messages.
- Maintain a universal event registry, policy version, channel delivery state, dead-letter queue or complete event-to-action lineage.

### 4.2 API routes and access boundary

Current route group in `routes/api.php`:
- `GET /api/notifications`
- `GET /api/notifications/unread-count`
- `POST /api/notifications/{id}/read`
- `POST /api/notifications/read-all`

These routes inherit `auth:sanctum` and `tenant.membership`. The service then scopes notification records to the authenticated recipient and active tenant.

There is no dedicated `notifications.view` or `notifications.manage` middleware on these routes. The App integration contract proposes those slugs, but the live source contract does not enforce them. This is a contract divergence requiring an Owner decision, not proof of cross-user access: recipient row ownership is the current data boundary.

### 4.3 Persistence and retention

The platform notification migration defines:
- UUID notification ID.
- Tenant and user foreign keys with cascade delete.
- Event key, category, priority, localized title/body, action route, resource reference, dedupe key, read timestamp and creation timestamp.
- Inbox index on tenant/user/read/created.
- Unique dedupe key on tenant/user/dedupe.

No expiry/retention/archive field, channel state, attempt count, source event ID, correlation ID, policy decision, delivery receipt, or acknowledgement field is present in this table. No retention/cleanup policy was established in the inspected source. Cascade deletion means tenant/user deletion cascades to notification rows; operational retention requirements still need explicit reconciliation.

---

## 5. D21 Communication Announcement — complete workflow diagnosis

### 5.1 Source inventory

| Layer | Source |
|---|---|
| API controller | `app/Http/Controllers/CommunicationAnnouncementController.php`, blob `02c2eba99d093fff3c535fd2322a32fb7eb013cf` |
| API routes | `routes/api.php`, blob `f84dba507f88922088c9681ec718d4537275ebc8` |
| Persistence migration | `database/migrations/2026_11_01_000001_create_communication_announcements_tables.php`, blob `d00ad41b3b17ba2e8df38f850ab29ade22ceaa93` |
| API feature tests | `tests/Feature/CommunicationAnnouncementsTest.php`, blob `b2067c76c3a99f9873734ea7228c813777c18403` |
| Permission catalogue | `database/seeders/Data/PermissionCatalogue.php`, blob `d79c67b2188e6583ed73b79d5a25853d01517245` |
| Role grants | `database/seeders/Data/RolePermissionGrants.php`, blob `5e56a2f1cce37d82fa915e546fb054a0e8ac20e9` |
| App adapter | `src/features/communications/api.ts`, blob `3bd4cd9a38001b2707bc400adbbe3c33df7cdc45` |
| App view | `src/features/communications/AnnouncementsView.vue`, blob `0161990ed37f0d87b1dce64e0b6ea6040aab0320` |
| App documentation | `docs/COMMUNICATION-HUB.md`, blob `5cfc35153babc3db8d9c18eb7f0d5138d56fcdf9` |
| API contract | `docs/communication/ANNOUNCEMENTS-001.md`, blob `dfba76a9634f90295c411e2443d4eef037efcdce` |

### 5.2 Workflow and route contract

All six announcement routes are within the API's `auth:sanctum` + `tenant.membership` route group:
1. `GET /api/announcements`
2. `POST /api/announcements`
3. `POST /api/announcements/{id}/submit`
4. `POST /api/announcements/{id}/approve`
5. `POST /api/announcements/{id}/publish`
6. `POST /api/announcements/{id}/acknowledge`

State machine:

`draft → pending_approval → approved → published`

- Draft creation and submission require `announcement.manage`.
- Approval requires `announcement.approve`.
- Publication requires `announcement.publish`.
- A submitter cannot approve their own announcement.
- Only pending announcements may be approved.
- Only approved and non-expired announcements may be published.
- A recipient may acknowledge only if they have a recipient row and acknowledgement is required for the published announcement.

### 5.3 Recipient selection and authorization

Draft creation requires 1–1000 unique explicit user IDs. Every recipient is checked for active membership in the current tenant. At publication the controller checks active membership and active user account again; NotificationService independently rechecks active tenant, user and membership.

A user with `announcement.manage` can see the tenant announcement register. Other users are limited to published announcements with a recipient row for themselves. The route does not enforce `announcement.view` as API middleware; the contract describes it as a frontend navigation capability. Do not confuse this with an API permission check.

Current seeded role grants:
- `secretary`: `announcement.manage`
- `president`: `announcement.approve`, `announcement.publish`
- `manager`, `secretary`, `president`: `announcement.view`
- Existing `platform_admin` and `tenant_admin` `scope:tenant` grants and `super_admin` wildcard also apply under the existing role model.

These are seed definitions, not proof of a current individual's role assignment, office, appointment, delegation or lawful corporate authority.

### 5.4 Notification and acknowledgement behavior

On publication the controller transaction:
- Updates announcement status and publication metadata.
- Marks recipient rows with `delivered_at`.
- Calls NotificationService with event key `communication.announcement.published`.
- Uses a stable announcement dedupe key.
- Writes a list route action and an announcement resource reference.

The test suite contains a simulated notification insert failure and expects the announcement publication and delivery markers to roll back. It also contains tests for active-recipient validation, tenant isolation, lifecycle transitions, self-approval rejection, publication, acknowledgement and permission grants. These are test definitions, not execution results.

### 5.5 Announcement-specific gaps

1. **No approval-submission notification:** submit transition does not publish a notification to an approver or resolve an approver.
2. **No approval-decision notification:** approval transition does not notify the submitter or next workflow actor.
3. **Acknowledgement is not integrated in the App:** API endpoint exists, but the inspected App adapter and view contain no acknowledgement function or button. No acknowledgement report/monitor was located.
4. **Read/acknowledgement state is split:** acknowledgement writes recipient `acknowledged_at` and recipient `read_at`; notification read is stored separately in `platform_notifications.read_at`. The acknowledgement endpoint does not mark the platform notification read.
5. **Localization defect:** notification body construction places the same announcement content into both Amharic and English keys and does not use `content_locale`. This can misrepresent a single-language announcement as bilingual.
6. **Priority compression:** `important` maps to notification `normal`; `urgent` and `critical` both map to `high`. The central notification priority only supports low/normal/high.
7. **No exact deep link:** notification action route is the announcement list route; resource reference uses `announcement_id`, while the App detail resolver expects `id` for detail routes. Current behavior falls back to the list rather than the exact announcement.
8. **No workflow event outbox:** the publication transaction is atomic with the central notification insert, but no durable event/outbox covers every announcement lifecycle transition for independent workflow notification/retry.
9. **No database foreign keys on announcement recipient references:** migration uses indexes and unique constraints but no foreign-key constraints tying recipient rows to announcement, tenant and user records. Application checks exist; referential integrity and deletion/retention behavior need a deliberate decision.
10. **UI controls are status-based, not permission-filtered:** the App may show Approve/Publish controls to a user lacking the required permission; API checks remain authoritative and should deny the operation. This is a UI/contract completeness gap, not proof of API bypass.
11. **Manual recipient entry:** App composer accepts comma-separated numeric user IDs; no dynamic role, committee, unit, stakeholder or workflow resolver.
12. **No expiry/scheduled publish UX evidence:** schema has scheduled/expiry fields, but the inspected view does not provide a verified scheduled/expiry workflow or publish scheduler for announcements.

D21 remains **PARTIAL**, not SUPPORTED as a full communication/stakeholder engagement domain.

---

## 6. Calendar reminder diagnostic

Sources:
- `mases_core/src/Calendar/Services/CalendarReminderDispatcher.php`, blob `22519502b52437aaa13dde47eac8be1a5e57b71e`.
- `mases_core/src/Migrations/2026_11_01_000002_create_calendar_lifecycle_tables.php`, blob `473f053c26328ccb9f1e97d08c555bd24360d89a`.
- `routes/console.php`, blob `6111b83f49d7608da70b67fa806cca20075615b8`.
- `tests/Feature/CalendarReminderDispatcherTest.php` and Calendar lifecycle/security tests.

The Calendar path is the strongest current outbox-to-notification example:
- Durable `calendar_reminder_outbox` rows include tenant, event, recipient, delivery time, attempt state and unique idempotency key.
- Dispatcher materializes recurrence reminders, rechecks event/occurrence state, cancellation and attendee membership, and handles stale rows.
- Outbox status transition and notification insert share a transaction.
- Stable idempotency prevents duplicate notification on retry.
- Scheduled command `calendar:dispatch-reminders --limit=100` is registered every minute with `withoutOverlapping()`.

This is a **bounded Calendar-owned reminder adapter**, not a universal dispatcher. Repository source proves command registration, not that the production scheduler process is running, cache locks are functioning or reminders are delivered in the live environment. No live queue/outbox inspection was performed.

---

## 7. Finance outbox diagnostic

Sources:
- `FinanceServiceProvider.php`, blob `84ce71513b5afbb9923b4c63e86153043539b8bd`.
- `PersistFinanceJournalPostedOutbox.php`, blob `cca9edcb54c2e9b3a1fdbbdc3a56a62a222df307`.
- `FinanceOutboxDispatcher.php`, blob `d5756828c4539bc4f97e40d90eb6bf5c187cee6b`.
- `FinanceGeneralLedgerTest.php`, blob `d552104cfe49f44f0c00659dc3f0fbd8f922d021`.

Finance registers lifecycle audit listeners and a `JournalPosted` listener that persists a versioned tenant/legal-entity-scoped outbox record in the journal posting transaction. The dispatcher exposes lease/claim, expired-lease recovery, claim-token acknowledgement and bounded retry primitives.

**No scheduled consumer, NotificationService bridge or enabled external delivery handler was found in the inspected source search.** Keep Finance classified OUTBOX ONLY for UEINE. Finance outbox payload may contain journal lines, account identifiers and amounts. A future notification adapter must emit a minimal authorized reference and must never expose raw journal payload to general recipients.

---

## 8. Other domain event and outbox inventory

| Domain | Located evidence | Classification |
|---|---|---|
| D03 Governance | Body/position/term/appointment/membership and election lifecycle events → audit listeners | **AUDIT ONLY** |
| D10 Shareholder | Shareholder registered/updated, shareholding changed, certificate issued → audit listener | **AUDIT ONLY** |
| D11 Finance | JournalPosted → transactional Finance outbox + audit listener | **OUTBOX ONLY** |
| D13 Loan | Loan lifecycle events → AuditLoanEvent | **AUDIT ONLY** |
| D14 Wallet | Wallet/obligation/payment/allocation/reversal events → AuditWalletEvent | **AUDIT ONLY** |
| D20 Documents | Document record lifecycle aggregate and evidence-capture reconciliation | **WORKFLOW/AUDIT; NO NOTIFICATION BRIDGE VERIFIED** |
| D21 Communication | Announcement lifecycle; publication calls NotificationService | **BOUNDED PUBLISHER** |
| D22 Calendar | Calendar reminder outbox and dispatcher | **BOUNDED PUBLISHER** |
| Other D01–D30 | No suitable event-to-notification path located in inspected source search | **NOT VERIFIED / NO SOURCE EVIDENCE** |

Do not convert audit events, queue messages or outbox rows into user-facing alerts by blind fan-out. Each event requires owner, schema, classification, recipient policy, confidentiality projection and source-resource authorization.

---

## 9. Frontend diagnostic

### 9.1 Current notification experience

Inspected App files:
- `src/layouts/AppShell/NotificationsBell/NotificationsBell.vue`, blob `f19ccc4111019ff9abc265456de6b0febb37cb61`.
- `src/features/notifications/NotificationsCenter.vue`, blob `9c1e9255c590d494297853a3787c54bd32a55ea2`.
- `src/features/notifications/api.ts`, blob `260c1d744ea418b969b9ea1381aaff9ef1e8f48c`.
- `src/stores/notifications.ts`, blob `98784f688182db8b14ddf055ce688c866693dded`.

Present:
- App shell notification bell and unread badge.
- Personal paginated inbox (20/page).
- Read one/read all.
- Loading, retry, empty and error states.
- Amharic/English display localization.
- Allow-listed internal route resolution; raw URLs/resource references are not treated as arbitrary destinations.
- In-memory state; tenant changes clear/invalidate stale results.

Missing or not verified:
- Real-time push or polling scheduler.
- User preferences, subscription management, quiet hours and digests.
- Email/SMS/mobile push delivery controls.
- Business acknowledgement/snooze/escalate/delegate actions in the generic inbox.
- Approval center, workflow action cards, event timeline and complete delivery history.
- Admin policy/rule configuration and analytics.
- Announcement acknowledgement action/report.

The bell fetches unread count on mount. The store defines a 60-second cache TTL, but a TTL is not itself a polling mechanism. No scheduled refresh or realtime subscription was found in the inspected component. The center fetches when opened/refreshed; live freshness is not established.

### 9.2 Communication frontend

The App has `src/features/communications/api.ts` and `AnnouncementsView.vue`; the workspace has Communications Hub and Announcements routes. The announcement view supports draft creation, explicit numeric recipient entry, submission, approval and publication controls. It does not expose the API acknowledgement route. The server remains the authorization boundary.

The App integration contract `docs/ARNA_APP_SHELL_SERVICE_INTEGRATION_CONTRACT_v1.0.md` proposes notification-specific permission middleware. Current API main uses authenticated user + active tenant membership + recipient row ownership. This mismatch must be resolved by contract decision, not assumed away.

---

## 10. Authorization and workflow assessment

### 10.1 Universal recipient contract: current vs required

**Current:** explicit persisted User objects, active account, active tenant and active membership. Inbox is current-user/current-tenant scoped.

**Required for UEINE:** recipient resolution by effective-dated individual assignment, role, organization unit, branch, committee appointment, legal entity, workflow actor, meeting attendee, shareholder/stakeholder relationship or explicit subscription, followed by authorization revalidation.

A future resolver must:
1. Resolve candidate recipients from an authoritative source-domain relationship.
2. Revalidate tenant membership, active account, effective dates, permission, legal-entity and organizational scope at dispatch.
3. Check source-resource visibility and confidentiality before producing the notification projection.
4. Deduplicate users reached by multiple routes.
5. Fail closed on expired, revoked, ambiguous, cross-tenant or unauthorized recipients.
6. Keep notification access separate from source-resource access.
7. Distinguish read, business acknowledgement, approval, task completion and financial execution.
8. Retain source event ID, correlation ID, policy version, recipient resolution evidence, delivery outcome and retry history.

### 10.2 Workflow authority boundary

The 30-domain matrix maps the existing authorization anchor and unresolved approval boundary per domain. Key principles:
- RBAC permissions are application access controls, not corporate legal authority.
- A job title, department membership or role name does not prove a current appointment, delegation, quorum, signatory mandate or statutory power.
- Domain owner remains authoritative for business state changes.
- UEINE may notify, assign or route only when the owning domain's workflow contract authorizes that action.
- AI may interpret and recommend; deterministic policies and explicit authorization govern consequential actions.

The domains without accepted source contracts must remain fail-closed for approval routing.

---

## 11. Queue, scheduler and runtime readiness

### 11.1 Queue configuration

`config/queue.php` blob `79c2c0a23cd06bcb6d22ea0a2b218e22a6d51198` configures database queue as the default fallback when `QUEUE_CONNECTION` is unset, with `retry_after=90`, `after_commit=false`, and database UUID failed-job storage by default.

This is configuration source only. It does not prove the production environment value, database queue tables, running workers, worker restart policy, supervisor/systemd process health, failed-job alerting or throughput. The two current direct publishers write notification rows synchronously in their database transactions; they are not evidence of a working asynchronous notification worker.

### 11.2 Scheduler configuration

Calendar reminder dispatch is registered every minute with `withoutOverlapping()`. Loan accrual/arrears and public content publishing commands are also scheduled. No production scheduler heartbeat or process status was inspected. Finance outbox dispatcher has no verified scheduled consumer.

### 11.3 Migration state

Source migrations exist for platform notifications, announcements/recipients, Calendar reminder outbox and Finance outbox. Repository source alone does not establish that they have been applied to the target production database. No production database was queried or modified in this A2 pass.

---

## 12. Tests and CI evidence

### 12.1 Test definitions found

API:
- `tests/Feature/CentralNotificationsTest.php`: current-user inbox isolation, foreign notification denial, cross-tenant denial, inactive/stale tenant and user rejection, invalid recipient rollback, read-all scoping.
- `tests/Feature/CommunicationAnnouncementsTest.php`: 10 named tests covering permission catalogue and role grants, create authorization, tenant isolation, active recipient validation, atomic draft/recipient persistence, self-approval rejection, explicit-recipient publication, publication revalidation and notification-failure rollback.
- `tests/Feature/CalendarReminderDispatcherTest.php`: Calendar reminder outbox and dispatcher behavior.
- Finance General Ledger feature tests cover outbox claim/ack/retry primitives.

### 12.2 Workflow definition found

`.github/workflows/arna-clean-install-mysql.yml`, blob `b54008e7390c746583d711842fc95e6732ce3239`:
- Supports push, pull_request and manual `workflow_dispatch`.
- Manual dispatch accepts an exact 40-character commit SHA.
- Creates a disposable MySQL 8.0 test database.
- Runs migrations and includes explicit central-notification and announcement feature-test steps.
- Includes Finance outbox and Calendar test steps.

### 12.3 Execution status

For the exact API main SHA `058dc27c27173006bafba7658d80e5cbacc7c440` and App main SHA `f694eb13ba822440c6842cb135a66f89218bc454`:
- GitHub combined status query returned empty status arrays.
- The connector's commit-associated workflow-run lookup returned no PR-triggered runs for these commits.
- No workflow was dispatched and no tests were run in this A2 pass.
- Empty status results are not proof that no historical or differently triggered workflow run exists.

Therefore automated verification is **NOT RUN / NOT VERIFIED**. Do not infer PASS from test source or workflow configuration.

---

## 13. Consolidated gap register

| ID | Severity | Gap | Consequence / closure evidence |
|---|---|---|---|
| UEINE-G01 | Critical | No universal event gateway or canonical registry | Define event schema, identity/version, producer ownership, correlation and lifecycle. |
| UEINE-G02 | Critical | Only two bounded direct notification publishers confirmed | Complete call-graph and adapter inventory for every domain/event/outbox. |
| UEINE-G03 | Critical | No generalized role/org/committee/workflow recipient resolver | Approve recipient source contracts and fail-closed resolver model. |
| UEINE-G04 | Critical | Finance outbox has no verified NotificationService bridge | Identify authorized consumer or retain OUTBOX ONLY. |
| UEINE-G05 | High | Audit listeners can be mistaken for notifications | Classify event dispositions and prevent blind fan-out. |
| UEINE-G06 | High | Announcement submit/approve do not notify workflow actors | Define approved approver/submitter resolution and notification triggers. |
| UEINE-G07 | High | No preference/subscription/quiet-hour/digest/channel framework | Reconcile API, persistence and App capability inventory. |
| UEINE-G08 | High | Notification permission contract diverges from API | Owner decision on personal inbox authorization and permission slugs. |
| UEINE-G09 | High | No generic approval/workflow action experience | Define authorized action cards and source-domain mutation boundaries. |
| UEINE-G10 | High | Priority model is too coarse | Define event severity, urgency, deadline and impact model without bypassing owner policies. |
| UEINE-G11 | Medium | Announcement resource reference is not a detail deep link | Align resource reference schema and route resolver. |
| UEINE-G12 | High | No end-to-end event→decision→recipient→delivery→ack audit lineage | Define correlation and append-only decision/delivery evidence. |
| UEINE-G13 | High | No exact-head automated verification | Run authorized no-cost workflow and record exact run/job IDs. |
| UEINE-G14 | High | Complete 30-domain and App recursive source-tree verification remains incomplete | Complete exact tree and event/call graph review. |
| UEINE-G15 | High | Finance outbox contains sensitive journal payload | Define minimal notification projection and legal-entity authorization. |
| UEINE-G16 | Medium | Bell unread freshness is mount-time/interaction-driven | Define polling/realtime freshness and offline/session behavior. |
| UEINE-G17 | High | Announcement acknowledgement API is not exposed in App | Add only after approved scope; test recipient acknowledgement and reporting. |
| UEINE-G18 | Medium | Announcement body localization and priority mapping lose meaning | Correct mapping under controlled implementation after A2. |
| UEINE-G19 | Medium | Announcement persistence lacks database FK constraints | Reconcile lifecycle, referential integrity, retention and deletion policy. |
| UEINE-G20 | High | Runtime worker/scheduler/migration state unknown | Obtain environment evidence without exposing secrets or modifying production. |
| UEINE-G21 | High | Domain workflow authority is incomplete across D01–D30 | Reconcile approved instruments, appointments, delegations and domain owner contracts. |

---

## 14. A2 closure criteria and readiness decision

| A2 acceptance criterion | Evidence | Status |
|---|---|---|
| Frozen A1 baseline and scope identified | Owner-approved A1 v1.2.0; no A1 edits | **PASS** |
| Canonical 30-domain catalogue retrieved | PR #212 source branch | **PASS** |
| Owner-approved domain dispositions reconciled | PR #213 decision record | **PASS** |
| All domains mapped to API provider/route/event/outbox and App features | Companion matrix; absent surfaces explicitly labelled | **PARTIAL — source search not exhaustive** |
| Every event producer and outbox consumer identified | Two direct publishers and Finance outbox identified; indirect tree/call graph incomplete | **NOT PASS** |
| Recipient authorization fully reconciled | Explicit-user path understood; generalized resolver absent | **NOT PASS** |
| Domain approval/workflow authority fully reconciled | Several domains have no accepted contract or verified appointment evidence | **NOT PASS** |
| Announcement workflow fully reconciled | Lifecycle/publisher understood; missing notifications, App acknowledgement, localization and deep-link gaps | **PARTIAL** |
| Notification migration/index/retention reviewed | Source migration inspected; retention policy and applied DB state unknown | **PARTIAL** |
| Queue/scheduler operational assumptions verified | Config and command registration inspected; runtime unknown | **NOT PASS** |
| Exact-head API/App tests and CI evidence | No exact-head run result; tests not run | **NOT PASS** |
| All material gaps dispositioned and Owner decisions recorded | Gap register created; several decisions remain open | **PARTIAL** |
| Final A2 closure report ready for Owner decision | Critical acceptance criteria still open | **NOT READY** |

**A2 closure decision: NOT READY.** This is a formal closure-readiness assessment, not a closure declaration and not a request to close the stage.

---

## 15. Remaining A2 work plan

### A2-R1 — Source completeness
- Obtain a recursive, commit-pinned tree inventory for API and App.
- Enumerate every event class, Event::listen registration, subscriber, listener, queued job, command, scheduler, outbox table and consumer.
- Search all NotificationService call sites and indirect dispatch paths.
- Reconcile each App feature, route, store, API adapter, permission guard and entrypoint against D01–D30.

### A2-R2 — Authorization and authority
- Inspect the current canonical RBAC and organization/committee/appointment/delegation models and their exact effective-date semantics.
- Build permission-by-operation tables for every implemented domain.
- Map initiator, responsible owner, reviewer, approver, delegate, escalation authority and final authority for every workflow.
- Reconcile current governance authority instruments and appointments; keep unresolved authority fail-closed.

### A2-R3 — Event and delivery contract
- Specify event identity/version, producer ownership, payload minimization, correlation, classification and transactional boundary.
- Decide notification-specific permissions vs current personal recipient inbox model.
- Define channel preferences, digest, quiet hours, retry, dead-letter, delivery receipts and retention.
- Define separate acknowledgement, approval, read and completion states.
- Resolve announcement acknowledgement UI/report, localization, severity and deep-link contract.

### A2-R4 — Runtime and verification
- Verify migration status using an approved non-destructive evidence path.
- Verify scheduler heartbeat, worker process, queue connection, failed-job monitoring and environment values without revealing secrets.
- Execute API/App tests against exact SHAs using a no-cost authorized workflow path; capture workflow run IDs, job names, results and artifact/log links.
- Do not dispatch paid or billable services; no local environment substitution for GitHub target evidence.

### A2-R5 — Closure package
- Update requirement-to-evidence traceability.
- Close or explicitly accept every gap with Owner decision and evidence.
- Produce final A2 closure report with exact SHAs, test evidence, unresolved exceptions and recommendation.
- Submit for Owner closure decision. A3 remains a separate authorization gate.

---

## 16. Stage-gate and change-control record

- **A1:** APPROVED / FROZEN — preserved unchanged.
- **A2:** PARTIAL / OPEN — this report is diagnostic evidence, not closure.
- **A3:** NOT AUTHORIZED / NOT STARTED.
- **A4:** NOT STARTED.
- **A5:** NOT AUTHORIZED.
- **Merge to main:** NOT AUTHORIZED.
- **Application source/schema/migrations/seeders/runtime:** unchanged by this report.
- **Production database/queue/provider:** not accessed or modified.
- **Tests/CI:** not run by this pass.

**Final conclusion:** The existing ARNA platform provides valuable bounded foundations, especially the personal inbox, Announcement publication and Calendar reminder delivery. The full universal engine, recipient resolver, cross-domain workflow authority, operational delivery guarantees and exact-head verification have not yet been established. Keep A2 open and continue the bounded evidence work above. Do not amend A1 or start A3 without the relevant explicit Owner decision.
