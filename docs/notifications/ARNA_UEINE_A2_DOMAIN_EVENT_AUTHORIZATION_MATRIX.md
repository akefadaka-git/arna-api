# ARNA UEINE — A2 Domain Event, API/App and Authorization Matrix

- **Record:** ARNA-UEINE-A2-DOMAIN-MATRIX-001
- **Version:** 1.0.0 — read-only evidence addendum
- **Date:** 2026-11-26
- **Status:** **A2 PARTIAL / OPEN — NOT A CLOSURE SUBMISSION**
- **Frozen architecture:** ARNA ESPPM A1 v1.2.0, reconstructed successor, Owner-approved/frozen 2026-11-24
- **API main inspected:** `akefadaka-git/arna-api` @ `058dc27c27173006bafba7658d80e5cbacc7c440`
- **App main inspected:** `akefadaka-git/arna-app` @ `f694eb13ba822440c6842cb135a66f89218bc454`
- **A1 catalogue blob:** `e9cd1f4a087a3d7f285ccda7786149d64db6a4bc` (PR #212 head `631b7a0eb7f027c17751b7af39bbc826fd70124e`)
- **Owner-approved A2 disposition blob:** `722589724b77f5330b4f592f36108ace0309bedc` (PR #213 head `ded7b487544972588f163a0dd7b14a9f02f6fe69`)
- **Related consolidated A2 report:** PR #219, branch `docs/esppm-a2-closure-report-001`, v1.0.4 blob `67a7b5272cee27aeb30461bcc2d43e4cd58cf387`
- **UEINE A2 evidence branch:** `docs/a2-ueine-readonly-reconciliation`

## 1. Purpose and evidence discipline

This addendum maps every frozen D01–D30 business domain to the API provider/route surface, known domain events/outboxes, App feature/route evidence, and the source-domain authorization/workflow boundary relevant to UEINE notifications.

It is a **read-only source inventory**, not a claim that every feature is accepted, production-ready or fully integrated. The frozen A1 catalogue is a business ownership map, not a list of Laravel modules or Vue screens. Shared platform capabilities (identity, tenant context, authorization, audit, notification transport, workflow primitives, file storage, localization and observability) are not additional business domains.

Status meanings:
- **PRESENT:** source path/provider/route/feature was directly located.
- **PARTIAL:** a bounded implementation exists but does not establish the full frozen domain.
- **NOT FOUND IN INSPECTED MAIN:** no suitable path was located in the inspected source/route/provider scope. This is not proof of absence from other branches, external systems or historical records.
- **AUDIT ONLY:** event listener writes audit evidence; it is not a user notification publisher.
- **OUTBOX ONLY:** durable event record exists, but no enabled notification bridge/transport was verified.
- **NOT VERIFIED:** no complete event, recipient or workflow integration evidence was found.

The source-level inventory uses:
- API provider registry: `bootstrap/providers.php` blob `6482927ae5c5373cfcb0d6f16ee5a75a9a24b2b0`.
- API route registry: `routes/api.php` blob `f84dba507f88922088c9681ec718d4537275ebc8`.
- API scheduled commands: `routes/console.php` blob `6111b83f49d7608da70b67fa806cca20075615b8`.
- App workspace routes: `src/router/routes-workspace.ts` blob `580b1c39004623778dadf7f88c25b51f62e33132`.
- App portal routes: `src/router/routes-portal.ts` blob `8068cce3c6c6ebc4bba523d93afc48fe5c6a73b6`.
- App admin routes: `src/router/routes-admin.ts` blob `5b3b648a31dad0ba19967387fa8d503bb4996572`.
- App README: `README.md` blob `e0a341263168f177e455f313d0ece04ff55eb839`.

## 2. Frozen 30-domain mapping

| ID / frozen domain | API provider, route or source surface | Events, listeners and outboxes located | App feature / route evidence | UEINE event and notification disposition |
|---|---|---|---|---|
| **D01 Corporate Strategy & Enterprise Planning** | No dedicated D01 provider, API route group or accepted strategy contract located. Governance/Organization/People/Documents are source anchors only, not a strategy API. | No D01 business event or outbox located. | No dedicated D01 feature/route located. Dashboard/Executive Overview is not strategy management. | **NOT VERIFIED.** Do not notify on a strategy approval until an adopted strategy version and competent decision/authority reference exist. |
| **D02 Enterprise Performance Management** | Executive Overview controller at `GET /dashboard/v1/overview`; route requires `dashboard.executive.view`; controller separately authorizes each Finance widget. No complete KPI/EPM provider or lifecycle route. | No D02 objective/KPI event or outbox located. | `src/features/executive-overview/`, `src/features/dashboard/`; routes `/executive-overview`, `/dashboard`. | **PARTIAL.** Dashboard data is not proof of KPI target/review workflow. Notify only from D02-owned approved KPI/target/review transitions once contract and owner exist. |
| **D03 Corporate Governance & Board Management** | `GovernanceServiceProvider`; `mases_core/src/Domains/Governance/Routes/api.php`; prefix configured as `api/governance`; policies for bodies, positions, terms, appointments and memberships. | Governance body/position/term/appointment/membership events plus election lifecycle event feed `AuditGovernanceEvent` / `AuditGovernanceElectionLifecycleEvent`. **AUDIT ONLY**; no central notification publisher or outbox verified. | `src/features/governance/`; overview, attendance, bodies, people and membership views/routes. | **PARTIAL.** Notify only from source-authorized lifecycle transitions. Governance authority/appointment validity must be proven from current instruments; RBAC permission does not create corporate authority. |
| **D04 Enterprise Risk Management** | No dedicated D04 provider, API route group or accepted Risk contract located. Draft PSC references only. | No D04 domain event/outbox located. | No dedicated risk feature/route located. | **NOT VERIFIED.** No risk acceptance/escalation recipient may be inferred from a draft policy or job title. |
| **D05 Compliance & Internal Control Management** | No dedicated D05 provider or accepted domain route/contract located; provisional PSC control material is not an adopted operating system. | No D05 event/outbox located. | No dedicated compliance/control feature/route located. | **NOT VERIFIED.** Findings/attestations/escalations require approved control owner, reviewer, evidence and authority matrix. |
| **D06 Legal & Regulatory Affairs** | Election-specific legal evidence/reconciliation documents; no full Legal Affairs API/provider or case lifecycle found. | No D06 business event/outbox located. | No dedicated Legal Affairs feature/route located. | **PARTIAL.** Legal deadlines may be reminders only after matter owner, counsel authority, confidentiality and current instrument are validated. |
| **D07 Project, Programme & Portfolio Management** | No accepted D07 provider, project portfolio route group or source contract located. | No D07 project/portfolio event/outbox located. | No dedicated project/programme feature/route located. Generic tasks are not proof of a project portfolio domain. | **NOT VERIFIED.** No stage-gate, milestone or change approval notification until project authority and responsible roles are configured. |
| **D08 Organization & Structure Management** | Organization controller routes under `/organization`; tenant membership plus explicit operation-level domain permission; Owner-approved bounded Organization contract. No dedicated Organization provider is listed in current `bootstrap/providers.php`. | No Organization business event/outbox or central notification publisher located in inspected source. | No Organization route/feature located in inspected current workspace route composition. A1 contract explicitly requires a future permission-aware App consumer and says unit assignment is not authorization. | **SUPPORTED CONTRACT / APP NOT VERIFIED.** Unit membership never grants notification visibility or approval authority. Recipient expansion must use effective-dated assignment plus separate user/tenant authorization. |
| **D09 People & Human Capital Management** | People endpoints in `routes/api.php` under `/people`; PersonPolicy read checks and `people.manage` on sensitive mutations; identity/account links have separate authorization. | No People lifecycle event/outbox or central notification publisher located in inspected source search. | `src/features/people/`; directory and profile routes. | **PARTIAL.** Person, employment, tenant access and governance appointment are distinct relations. Do not notify an HR/manager role without effective assignment, purpose and record-level access. |
| **D10 Shareholder & Ownership Management** | `ShareholderServiceProvider`; `mases_core/src/Domains/Shareholder/Routes/api.php`; configured prefix `api/shareholders`; Shareholder/ShareHolding/Certificate/ShareClass policies. | `ShareholderRegistered`, `ShareholderUpdated`, `ShareHoldingChanged`, `CertificateIssued` → `AuditShareholderEvent`. **AUDIT ONLY**; no notification outbox located. | `src/features/shareholders/`; shareholder list/detail/new/classes/edit; portal holdings/certificates. | **PARTIAL.** Shareholder communication must use the canonical register, legal share rights, record-date entitlements and verified proxy status. A shareholder record or share count is not an application role. |
| **D11 Finance & Accounting Management** | `FinanceServiceProvider`; Finance-owned routes under `/api/finance`; model policies for legal entities, accounts, periods, journals and source mappings. | `JournalPosted` → audit listener and `PersistFinanceJournalPostedOutbox`; `FinanceOutboxDispatcher` supports claim/lease/ack/release/retry primitives. **OUTBOX ONLY** for the universal notification purpose; no enabled notification bridge/consumer or external transport verified. | No dedicated `src/features/finance/` workspace located. Finance reports/widgets appear through Executive Overview/dashboard. | **PARTIAL.** Never copy journal lines/account values into notifications. Notify a minimal event reference only after Finance policy and legal-entity scope authorize recipient; posting/accounting decisions remain Finance-owned. |
| **D12 Treasury & Cash Management** | No independent Treasury provider, API route group or accepted Treasury contract located. Finance CoA and narrow Governance penalty settlement are not Treasury. | No Treasury-owned bank/cash event or outbox located. Finance outbox is not a bank outbox. | No Treasury feature/route located. | **MISSING.** No payment, bank mandate, cash movement, reconciliation or signatory notification until verified Treasury authority and source records exist. |
| **D13 Loan & Credit Management** | `LoanServiceProvider`; `mases_core/src/Domains/Loan/Routes/api.php`; configured prefix `loans`; Loan/LoanProduct/Collateral policies; daily accrue-interest and detect-arrears commands. | 13 Loan lifecycle events (application submitted, approved/rejected, disbursed, repayment, installment settled, arrears, penalty, restructured, written-off, settled, closed, collateral released) → `AuditLoanEvent`. **AUDIT ONLY**; no notification outbox/publisher located. | `src/features/loans/`; list/detail/schedule/statement/product routes; portal `src/features/portal/` my-loans/detail. | **PARTIAL.** Borrower self-view requires `loan.view_own` plus ownership; staff access uses LoanPolicy/permissions. Credit approval authority and customer disclosure remain Loan-owned. |
| **D14 Wallet & Digital Financial Services** | `WalletServiceProvider`; `mases_core/src/Domains/Wallet/Routes/api.php`; configured prefix `wallets`; Wallet/Obligation/Payment policies. | Wallet opened, obligation recorded, payment received/allocated, allocation reversed, adjusted and entry reversed → `AuditWalletEvent`. **AUDIT ONLY**; no central notification publisher/outbox located. | `src/features/wallet/`; list/detail/statement; portal `src/features/portal/` my-wallet. | **PARTIAL.** `wallet.view_own` requires actual ownership; payment allocation is not bank settlement. Payment, refund and reversal messages must preserve Wallet/Treasury/Finance boundaries. |
| **D15 Procurement & Supplier Management** | No dedicated provider, accepted API route group or accepted D15 contract located. Draft PSC policy only. | No D15 event/outbox located. | No dedicated procurement/supplier feature or route located. | **MISSING.** Do not create approver routing from draft thresholds, committee titles or inferred purchasing roles. |
| **D16 Inventory & Supply Chain Management** | No dedicated provider, accepted API route group or accepted D16 contract located. | No D16 stock/receipt/movement event or outbox located. | No dedicated inventory/supply-chain feature or route located. | **MISSING.** Stock receipt, custody, valuation and loss events cannot be fabricated from purchase orders or invoices. |
| **D17 Sales, Marketing & Customer Relationship Management** | No dedicated Sales/CRM provider, API route group or accepted contract located. Public-site inquiry/submission endpoints are public-content intake, not CRM lifecycle. | No D17 sales/customer/order event or outbox located. | No dedicated sales/CRM feature/route located. Public-site features are not a Sales domain. | **MISSING.** No quote/discount/credit/order/refund notifications until adopted commercial policy and approval authority exist. |
| **D18 Operations & Service Delivery Management** | No dedicated Operations provider, accepted API route group or service-delivery contract located. | No D18 service/work-order event or outbox located. | No dedicated operations/service-delivery feature/route located. | **UNVERIFIED.** Generic tasks and Calendar bookings do not establish fulfilment authority or completion evidence. |
| **D19 Enterprise Asset & Facilities Management** | No dedicated Asset/Facilities provider, API route group or accepted contract located. | No asset custody/maintenance event or outbox located. | No dedicated asset/facilities feature/route located. | **MISSING.** Distinguish operational assets (D19) from investment holdings (D30); no assignment/maintenance alerts without an authoritative asset record. |
| **D20 Documents & Records Management** | `mases_core/src/Domains/Documents/` and shared `FileManagement` storage; `/files` endpoints in `routes/api.php`; document evidence capture reconciliation command. Formal record lifecycle contract remains draft/partial. | Documents workflow aggregate has tenant/issuer ownership, actor permissions, revision concurrency and lifecycle events in the inspected evidence; no verified Documents-to-NotificationService publisher/outbox located. Scheduled evidence-capture reconciliation is cleanup, not a user-notification outbox. | `src/features/files/`; file list/detail. No separate formal Records Management UI route established in inspected workspace routes. | **PARTIAL.** File upload is not record approval; approval and publication are separate. Approval routes/approvers must remain blocked until per-document-type authority is approved. |
| **D21 Communication & Stakeholder Engagement** | App-level `CommunicationAnnouncementController`; `/announcements` routes under auth + tenant membership; permissions `announcement.manage`, `announcement.approve`, `announcement.publish`; no CommunicationServiceProvider in active provider registry. | `communication.announcement.published` → central NotificationService for explicit, active, tenant-member recipients after approved workflow. No universal communication event bus or multi-channel outbox located. | `src/features/communications/`; Communication Hub and Announcements routes. Notification UI is shared platform infrastructure. | **PARTIAL / VERIFIED PUBLISHER.** Draft → pending approval → approved → published. Self-approval prohibited; manage/approve/publish are distinct. Explicit recipient IDs are revalidated at publication. No role/group recipient resolver. |
| **D22 Calendar, Events & Scheduling Management** | Core Calendar controller routes under `/calendar`; explicit `calendar.view/create/update/reserve` capabilities; owning-domain resource authorizer. | Calendar reminder outbox + `CalendarReminderDispatcher` → central NotificationService; recurrence occurrence identity, cancellation/staleness checks, retry/dedupe and transactional acknowledgement. **VERIFIED PUBLISHER.** | `src/features/calendar/` and `src/features/appointments/`; Calendar and Appointments workspace routes. | **SUPPORTED CONTRACT / VERIFIED PUBLISHER.** Calendar event/reminder recipient must be a valid attendee/recipient; linked resource authorization is delegated to owning domain and rechecked. Calendar link never grants resource access. |
| **D23 Enterprise Reporting & Business Intelligence** | Executive Overview and Finance reporting services; no enterprise BI provider/semantic layer contract established. | No D23 report-ready/metric-review event or outbox located. | `src/features/dashboard/`, `src/features/executive-overview/`; no enterprise BI feature/route identified. | **PARTIAL.** Report visibility follows source metric/data permissions and audience policy; dashboard access does not confer access to underlying source records. |
| **D24 Information Technology & Digital Systems Management** | Shared Core/Auth/Tenancy/Policy/Notifications/File/Observability services; admin audit/security routes require `app.admin.access` plus `audit.view` or `security-event.view/resolve`. No ITSM service catalogue/incident/change provider found. | Platform audit/security events exist; no ITSM domain event/outbox mapped to D24. | `src/features/auth/`, `src/features/launcher/`, `src/features/locale/`; admin dashboard, public-content and audit-security routes. | **UNVERIFIED.** Shared infrastructure is not an ITSM domain. Security alerts require platform security policy, confidentiality classification and scoped responder authorization. |
| **D25 Investment & Capital Management** | No dedicated provider, API route group, investment contract or current implementation evidence located. | No investment appraisal/capital allocation event/outbox located. | No dedicated investment/capital feature/route located. | **MISSING.** No investment approval/commitment notification until mandate, committee appointment, valuation and capital authority are source-backed. |
| **D26 Business Development & Strategic Partnerships** | Public-site inquiry intake only; no dedicated partnership API/provider or accepted contract. | No partnership opportunity/agreement event/outbox located. | No dedicated partnership feature/route located. | **MISSING.** Public inquiries must not be treated as qualified opportunities, partner approvals or agreements. |
| **D27 Corporate Development, Mergers & Acquisitions** | No dedicated provider, route group, accepted contract or implementation evidence located. | No M&A transaction/due-diligence event/outbox located. | No dedicated corporate-development feature/route located. | **MISSING.** Deal-stage notifications require confidentiality ring, board/GA authority, legal and Finance controls. |
| **D28 Product, Service & Innovation Management** | No dedicated product/service lifecycle provider or accepted contract located. Loan product catalogue is a Loan-owned credit product, not enterprise product portfolio. | No product discovery/release event/outbox located. | No enterprise product/innovation feature/route located. | **MISSING.** Loan product lifecycle events stay D13; do not repurpose them as D28 product events. |
| **D29 Grants, Funding & Financing Management** | No dedicated grants/funding provider, route group or accepted contract located. | No grant application/award/condition/drawdown event or outbox located. | No dedicated grants/funding feature/route located. | **MISSING.** Funding eligibility, restrictions, donor reporting and Finance accounting require distinct owners and verified authority. |
| **D30 Enterprise Investment Asset Portfolio** | No dedicated investment-holdings provider, API route group or accepted contract located. Operational assets and Finance accounts do not establish investment holdings. | No portfolio valuation/return/impairment/disposal event or outbox located. | No dedicated investment-portfolio feature/route located. | **MISSING.** Notify only from authoritative D30 holding records; D25 owns investment decisions and D11 owns accounting. |

## 3. API provider and route reconciliation

### 3.1 Active provider registry

The active `bootstrap/providers.php` registry explicitly registers these domain providers:
- Shareholder — `ShareholderServiceProvider`
- Wallet — `WalletServiceProvider`
- Loan — `LoanServiceProvider`
- Governance — `GovernanceServiceProvider`
- Finance — `FinanceServiceProvider`

Core and application providers supply shared platform services. Organization and People have tenant-scoped API routes and policies but are not listed as dedicated providers in the current registry. Communication announcements are app-level controller/routes, not a registered CommunicationServiceProvider. Calendar is a Core domain/capability with routes and scheduled reminder dispatcher.

The ten business-domain roots recorded by the broader A2 exact-tree inventory at its earlier snapshot were Documents, FileManagement, Finance, Governance, Loan, Organization, People, PublicContent, Shareholder and Wallet. Current route/provider evidence above confirms the core set but does not imply that these ten roots are the full 30-domain enterprise catalogue.

### 3.2 Route surface and security

Current API route map includes:
- Public corporate-site and public-content routes, with pending-review submission and `public-content.review` administration.
- Auth/session/MFA/tenant context endpoints.
- Executive Overview `/dashboard/v1/overview`.
- Notifications `/notifications`.
- Communication announcements `/announcements`.
- People `/people`.
- Files `/files`.
- Organization `/organization`.
- Admin audit/security `/admin`.
- Tasks `/tasks`.
- Calendar/events/appointments/resources/reservations `/calendar`.
- Domain-provider-mounted Shareholder, Wallet, Loan, Governance and Finance route groups.

Route presence does not establish the full A1 domain contract. In particular, `/files` is file storage and metadata, not the full D20 formal records lifecycle; public content submissions are not D17 CRM; `/tasks` is not a D07 portfolio-management domain.

## 4. Event publisher and outbox inventory

### 4.1 Central notification publishers confirmed

| Publisher | Event / trigger | Recipient resolution | Delivery / durability | UEINE result |
|---|---|---|---|---|
| Communication announcement controller | `communication.announcement.published` after approval and publication | Explicit user IDs selected in announcement; active account and tenant membership revalidated | Central `PlatformNotification` rows in same transaction as publication/delivery marker; idempotent event/dedupe fields | **VERIFIED bounded publisher** |
| Calendar reminder dispatcher | `calendar.event.reminder` when due reminder outbox row is claimed | Explicit reminder recipient user; active tenant/user membership and event/occurrence state rechecked | Reminder outbox, retry/skip/delivery state, stable dedupe, notification and acknowledgement in one transaction | **VERIFIED bounded publisher** |

No other direct `NotificationService::publish` call site was established by the current source search. This is a source-search finding, not proof that no indirect provider or external integration exists elsewhere.

### 4.2 Other domain event and outbox mechanisms

| Domain | Event/outbox evidence | Listener/consumer | UEINE meaning |
|---|---|---|---|
| D03 Governance | Governance body/position/term/appointment/membership and election lifecycle events | Audit listeners | Audit evidence, not notification |
| D10 Shareholder | Shareholder registered/updated, shareholding changed, certificate issued | Audit listener | Audit evidence, not notification |
| D11 Finance | JournalPosted plus FinanceOutboxMessage | Audit listener + transactional outbox persistence; dispatcher claim/ack/release | Durable source event exists; no enabled NotificationService bridge or scheduled consumer verified |
| D13 Loan | Thirteen loan lifecycle events | AuditLoanEvent | Audit evidence, not notification |
| D14 Wallet | Wallet/obligation/payment/allocation/reversal events | AuditWalletEvent | Audit evidence, not notification |
| D20 Documents | Formal record lifecycle aggregate events; evidence-capture cleanup command | Documents workflow and reconciler | Workflow/audit and cleanup evidence; no central notification adapter verified |
| D22 Calendar | Calendar reminder outbox | CalendarReminderDispatcher | Central notification publisher verified |
| D21 Communication | Announcement state transitions | Announcement controller | Central notification publisher verified on publication only |

No domain outbox or central publisher was located for the other domains in the inspected scope. Outbox persistence, an audit listener, a queued event or a scheduler registration must not be described as user delivery without an end-to-end recipient and delivery consumer.

## 5. Recipient authorization and workflow boundary matrix

### 5.1 Platform-wide recipient-resolution rules

The current central NotificationService is an explicit-recipient persistence service, not a role or workflow resolver.

**Current publish contract**
- Revalidates persisted active tenant and active persisted canonical User recipients.
- Requires each recipient to have active membership in the target tenant.
- Persists notification rows transactionally and supports tenant/user/event/dedupe context.
- Accepts explicit recipients; it does not broadcast to arbitrary tenant users or resolve roles, departments, committees, legal entities, workflow approvers, meeting relationships or subscriptions.

**Current inbox contract**
- Notification API is inside authenticated `auth:sanctum` + `tenant.membership` route group.
- Inbox/unread/read/read-all operations are scoped to the authenticated user and active tenant.
- There is no separate `notifications.view` / `notifications.manage` middleware on the current API routes. A prior App integration contract proposed these slugs; that document is marked proposed and differs from the enabled current implementation. Reconcile the contract rather than claiming those permissions are enforced.

**Mandatory future UEINE controls**
1. Resolve a candidate recipient only from a source-domain-authorized relationship: current effective assignment, role membership, committee appointment, legal-entity scope, workflow step, explicit attendee/participant, or explicit subscription.
2. Revalidate active user, active tenant membership, effective dates, role/permission grant, tenant/legal-entity/organizational scope and source-record visibility at dispatch time.
3. Deduplicate a user reached through multiple recipient paths; do not duplicate inbox deliveries.
4. Role membership alone never grants access to confidential notification payloads.
5. Notification visibility never grants access to the source resource. Every contextual action reauthorizes through the owning domain when opened and again on mutation.
6. Separate notification read, business acknowledgement, task completion, approval/rejection and payment/financial execution as different state transitions.
7. AI may classify, summarize and recommend. It must not create authority, approve a business decision, sign, post, transfer money or bypass deterministic domain policies.
8. Missing, expired, revoked, ambiguous, cross-tenant or wrong-entity authorization must fail closed.
9. Keep raw sensitive payloads out of notifications; publish minimal metadata plus an authorized deep link/resource reference.
10. Every event/action path must retain correlation, source event identity, policy decision, resolved recipients, dispatch outcome, retries and acknowledgement evidence.

### 5.2 Domain-specific authorization and workflow boundaries

| Domain | Existing authorization anchor | Workflow/authority boundary for notification | Recipient readiness |
|---|---|---|---|
| D01 | No accepted D01 API/contract; current strategy approval authority unresolved. | Do not infer GA/Board authority from title, draft strategy or calendar publication. | **NOT READY** |
| D02 | `dashboard.executive.view`; controller checks each Finance widget separately. | KPI owner, target approval, variance commentary and corrective-action authority require D02 contract. | **PARTIAL** |
| D03 | Governance model policies and authority-grant service; tenant-scoped routes. | Current corporate appointments, delegation, quorum and legal authority must be independently valid at event time. Governance RBAC is not corporate authority. | **PARTIAL / FAIL CLOSED** |
| D04 | No accepted Risk policy/route. | Risk owner, risk acceptance level, control linkage and escalation authority not established. | **NOT READY** |
| D05 | No adopted compliance/control workflow. | Finding owner, independent tester, remediation approver and breach escalation need approved control matrix. | **NOT READY** |
| D06 | Election-specific legal evidence only. | Legal advice privilege/confidentiality, counsel appointment, conflict/recusal and matter authority unresolved. | **NOT READY** |
| D07 | No accepted project/portfolio policy. | Sponsor, PM, gate approver, budget owner and benefits owner require an accepted contract and authority. | **NOT READY** |
| D08 | Tenant membership plus explicit Organization operation permission; Organization contract states assignment is not authorization. | Effective-dated unit assignment may inform routing but cannot grant application permission or approval authority. | **PARTIAL; no App consumer verified** |
| D09 | PersonPolicy read path; `people.manage` for sensitive identity mutations; active PersonTenantAccess controls tenant visibility. | Person, employee, tenant access and governance appointment are distinct; HR confidentiality and purpose limitation apply. | **PARTIAL** |
| D10 | Shareholder/holding/certificate policies; portal self-service access is ownership-based. | Statutory register, share class rights, record date, voting entitlements and proxy validity control recipient scope. | **PARTIAL / LEGAL DEPENDENCY** |
| D11 | Finance model policies and tenant/legal-entity scoped Finance routes. | Finance owns posting and accounting policy; source domains own their subledgers. Journal event notification must be minimal and recipient-authorized by legal entity. | **PARTIAL; outbox bridge absent** |
| D12 | No independent Treasury policy/route/mandate. | Bank mandate, signatory schedule, payment authority and reconciliation roles not established. | **NOT READY** |
| D13 | LoanPolicy; borrower self-view requires `loan.view_own` plus ownership; privileged access is permission/policy controlled. | Application/credit approval, disbursement, restructuring and write-off remain Loan source-domain decisions. | **PARTIAL; audit only** |
| D14 | WalletPolicy; `wallet.view_own` plus ownership for self-service; staff access policy controlled. | Wallet obligation/payment/allocation/reversal is distinct from bank settlement (D12) and accounting (D11). | **PARTIAL; audit only** |
| D15 | No adopted Procurement policy or accepted route. | Supplier selection, evaluation, award, PO signature, receipt and payment are separate authority steps. Draft PSC thresholds cannot be configured. | **NOT READY** |
| D16 | No accepted Inventory policy/route. | Stock custody/receipt/issue/count approval requires designated warehouse/custody authority. | **NOT READY** |
| D17 | No adopted Sales/CRM policy/route. | Price, discount, credit, order acceptance, return/refund and revenue decisions require source-backed authority. | **NOT READY** |
| D18 | No accepted Operations policy/route. | Work allocation, service completion, customer acceptance and SLA breach roles are not established. | **NOT READY** |
| D19 | No accepted Asset/Facilities policy/route. | Custodian, maintenance approver, impairment/disposal authority and D11 accounting hand-off require contract. | **NOT READY** |
| D20 | File access uses tenant membership and file-level authorization; formal record contract remains draft. | Upload ≠ approval; approval ≠ publication; document-type approver matrix and retention/hold rules unresolved. | **PARTIAL / APPROVAL BLOCKED** |
| D21 | Announcement permissions: manage, approve, publish; self-approval prohibited; recipients revalidated as active tenant members. | Draft → pending approval → approved → published. Publication sends to explicit selected recipients; announcement acknowledgement is separate from notification read. | **PARTIAL / BOUNDED PUBLISHER** |
| D22 | Calendar permissions `calendar.view/create/update/reserve`; CalendarDomainResourceAuthorizer invokes owning-domain policies and fails closed. | Calendar owns event/reminder lifecycle, not linked resource access. Reminder delivery rechecks occurrence and membership. | **SUPPORTED / BOUNDED PUBLISHER** |
| D23 | Dashboard audience permission and source widget authorization; no enterprise BI authorization contract. | Dataset certification, metric definitions, freshness and audience permissions must be owned and revalidated. | **PARTIAL** |
| D24 | Admin audit/security routes require `app.admin.access` plus specific audit/security permissions. | Security events must route only to authorized responders; IT service/incident ownership not yet established. | **PARTIAL PLATFORM ONLY** |
| D25 | No investment mandate/contract/committee appointment. | Appraisal, capital allocation and commitment approvals must use source-backed investment authority. | **NOT READY** |
| D26 | No partnership policy/contract. | Partner due diligence, negotiation, agreement, renewal and termination authority unverified. | **NOT READY** |
| D27 | No M&A mandate/contract. | Confidential deal room, due diligence, Board/GA approval and legal/Finance gates required. | **NOT READY** |
| D28 | No enterprise product/innovation policy. | Product stage-gate and release authority unverified; Loan product catalogue remains D13. | **NOT READY** |
| D29 | No grant/funding contract. | Funder conditions, restricted-fund owner, drawdown authority and donor reporting must be explicit. | **NOT READY** |
| D30 | No investment holding contract. | Holding-level valuation, impairment, risk and disposal roles must be separated from D25 investment approval and D11 accounting. | **NOT READY** |

### 5.3 Role/accountability qualification

The Owner-approved A2 decision record contains proposed accountable role families for D01–D30 (for example Strategy Executive, CFO/Finance Executive, Treasurer, Credit Executive, Corporate Communications Executive and Calendar Product Owner). These are **proposed accountability roles**, not verified current appointments, not permission grants and not necessarily approvers. A future recipient resolver must use the canonical user-role/permission tables plus effective-dated appointments/delegations and domain authority records. It must not derive an approver from a job title or organization-unit hierarchy.

The current RBAC model exposes permissions granted directly to a user and permissions inherited from assigned roles. This is application authorization. It does not establish corporate signatory authority, GA/Board delegation, legal appointment, procurement thresholds, bank mandates or other business authority.

## 6. Contradictions and stale-document reconciliation

1. **A1 archive qualification:** A1 v1.2.0 is explicitly an Owner-approved reconstructed successor because the full historical v1.0/v1.1 wording could not be recovered verbatim. Preserve it as the controlling frozen catalogue; do not call it a verbatim restoration.
2. **A1/A2 archival PRs:** PRs #212 and #213 are OPEN / DRAFT / UNMERGED in the retrieved PR metadata. Their content is Owner-approved baseline/decision evidence, but it is not merged into API main. Do not imply repository-main archival is complete.
3. **A2 disposition counts:** The Owner-approved decision record controls: SUPPORTED 2 (D08, D22); PARTIAL 11 (D02, D03, D06, D09, D10, D11, D13, D14, D20, D21, D23); UNVERIFIED 6 (D01, D04, D05, D07, D18, D24); MISSING 11 (D12, D15, D16, D17, D19, D25–D30).
4. **D01/D02 ownership:** R11 clarifies D01 owns strategy/planning baseline while D02 owns authoritative objective hierarchy, KPI definitions and performance measures. D01 keeps alignment references. D01 remains UNVERIFIED; D02 remains PARTIAL.
5. **D15/D17 proposed upgrades:** Rejected by the Owner. Both remain MISSING. Draft PSC policy and provisional matrix are not adopted procurement/commercial policies.
6. **Documents contract divergence:** The draft document-record lifecycle contract says generic notification database/API was missing in its earlier inspected tree. Current main now has a central notification service/API. Treat that sentence as stale for the platform notification capability; it does not establish a Documents-domain publisher or change D20 status.
7. **App Shell permission divergence:** A proposed App integration contract names `notifications.view/manage`, while current API routes use tenant membership and authenticated-recipient ownership without those permission middleware. Record as a contract divergence for Owner decision; do not assert an authorization bypass or invent enforcement.
8. **Finance outbox:** Durable Finance outbox persistence and lease/retry primitives exist, but no enabled notification bridge or scheduler consumer was verified. Do not mark it as an integrated notification publisher.
9. **Current source heads vs prior A2 package:** This addendum inspected API main `058dc27c...` and App main `f694eb13...`. Prior ESPPM A2 report snapshots used API `6e274c25...` and App `ffe0495c...`; those older SHA-bound results are not silently upgraded to current-head verification. The route/provider files listed above were fetched from current main; no test suites were run.

## 7. UEINE A2 gap register — expanded

| ID | Priority | Gap | A2 closure evidence required |
|---|---|---|---|
| UEINE-A2-01 | Critical | No universal canonical event registry/gateway or event disposition catalogue. | Define event identity/version/schema, producer ownership, correlation, transaction boundary and actionable/informational/audit-only/telemetry disposition for each candidate event. |
| UEINE-A2-02 | Critical | Only Communication publication and Calendar reminders are verified direct central-notification publishers. | Complete source call-graph review for all direct/indirect publishers and all registered domain events/outboxes. |
| UEINE-A2-03 | Critical | Finance outbox has no verified enabled consumer/notification bridge. | Identify consumer/transport and exact integration tests or retain OUTBOX ONLY classification. |
| UEINE-A2-04 | High | No generic role/org/committee/workflow recipient resolver. | Reconcile role, tenant membership, effective-dated org assignment, governance appointment/delegation and source-resource authorization. |
| UEINE-A2-05 | High | Role/accountability labels are not verified current appointments or authority. | Obtain current appointment/delegation source records and map domain approval matrices; never infer from title. |
| UEINE-A2-06 | High | No cross-domain workflow/approval matrix for 30 domains. | Identify initiator, responsible owner, reviewer, approver, delegated actor, escalation authority, final decision owner and state machine per domain; mark MISSING domains blocked. |
| UEINE-A2-07 | High | App features cover only a subset of 30 domains; many A1 domains have no dedicated UI route. | Complete full App feature tree, route registry and API adapter/store mapping across workspace, portal, admin and public-site entrypoints. |
| UEINE-A2-08 | High | No notification preferences, digest, quiet hours, channel adapters, acknowledgement lifecycle or escalation policies found in the inspected feature. | Search full App/API tree and provider integrations; document exact current capability and contract gaps. |
| UEINE-A2-09 | High | Notification inbox ownership authorization differs from proposed App permission contract. | Owner decision on personal inbox vs shared/role inbox and whether permission slugs are required; update contract only through controlled change. |
| UEINE-A2-10 | High | Source-resource access is not equivalent to recipient delivery. | Define click-time reauthorization and confidentiality projection for every resource type. |
| UEINE-A2-11 | High | Existing audit listeners are not user-facing publishers. | Classify each event and prevent blind fan-out of audit/security/financial/personnel payloads. |
| UEINE-A2-12 | Medium | No end-to-end event→policy→recipient→delivery→acknowledgement lineage. | Define correlation and audit model; distinguish read, business acknowledgement, approval and completion. |
| UEINE-A2-13 | Medium | A1 v1.2.0 and A2 decision archive PRs are unmerged draft records. | Preserve source branch/commit identity; separately decide archival merge only if required and explicitly authorized. |
| UEINE-A2-14 | Medium | Test execution absent for this read-only pass. | Run exact-head API/App tests and workflow checks in a later authorized A2 verification pass; capture run IDs/jobs and SHAs. |

## 8. A2 completion status and next actions

| Work item | Status |
|---|---|
| Retrieve frozen A1 catalogue | **COMPLETE** — 30 domains identified from PR #212 source |
| Retrieve Owner-approved A2 domain dispositions | **COMPLETE** — 30 rows and 2/11/6/11 counts |
| Map all 30 domains to inspected API provider/routes/source surfaces | **COMPLETE AT SOURCE-INVENTORY LEVEL** — missing/absent surfaces explicitly labelled |
| Map all 30 domains to App feature/route evidence | **COMPLETE AT ROUTE/FEATURE INVENTORY LEVEL** — absent feature surfaces explicitly labelled |
| Map located events/listeners/outboxes | **COMPLETE FOR LOCATED SOURCE EVIDENCE** — search limitations retained |
| Reconcile universal recipient authorization | **PARTIAL** — central personal inbox and bounded publishers reconciled; shared-role resolver absent |
| Reconcile all 30 domain approval/workflow authorities | **PARTIAL** — source-owner boundaries recorded; numerous domains have no accepted contract/authority |
| Exact-head automated test execution | **NOT RUN** |
| A2 closure | **NOT READY / NOT REQUESTED** |

### Remaining bounded A2 actions

1. Inspect complete API/App source trees and route/feature entrypoints for any non-indexed event publishers, queue listeners, external transports, preference services and workflow adapters; this pass did not use a complete recursive tree export.
2. Fetch the current domain policies and route middleware for every implemented domain (including the mounted Shareholder, Wallet, Loan, Governance and Finance route files) and build exact permission-by-action tables with positive/negative test references.
3. Reconcile the 30-domain authority matrix against the Owner-approved governance source instruments and current effective appointments. Keep unresolved legal/financial authority fail-closed.
4. Inspect notification migrations, indexes, retention, queue/worker/scheduler deployment assumptions and frontend store refresh behavior.
5. Re-run targeted API/App tests on the exact current heads after confirming no-cost execution paths; no source modifications are required.
6. Reconcile the new UEINE evidence against the A2 report and the Owner's existing **KEEP A2 OPEN — DO NOT CLOSE** decision. Do not request closure while criteria remain unmet.

## 9. Stage and change-control disposition

- A1 v1.2.0: **OWNER-APPROVED / FROZEN; PRESERVED**.
- A2: **PARTIAL / OPEN — NOT VERIFIED-CLOSED**.
- A3: **NOT AUTHORIZED / NOT STARTED**.
- A4: **NOT STARTED**.
- A5: **NOT AUTHORIZED**.
- Merge to `main`: **NOT AUTHORIZED**.
- Application code, schema, migrations, seeders, runtime configuration and production data: **UNCHANGED**.
- Tests/CI: **NOT RUN** in this addendum.

**Conclusion:** The 30-domain source map is now available at the provider/route/event/outbox/feature inventory level. It confirms that only two bounded domain publishers currently reach the central notification service. The wider recipient resolver, workflow authority matrix, full event catalogue, and end-to-end verification remain incomplete. Preserve the frozen A1 baseline and the existing Owner instruction to keep A2 open. This addendum is evidence for continued A2 work, not a closure request.


## 10. Detailed D21 Communication Announcement workflow reconciliation

### 10.1 Current API lifecycle and route contract

Current API source:
- Controller: `app/Http/Controllers/CommunicationAnnouncementController.php`, blob `02c2eba99d093fff3c535fd2322a32fb7eb013cf`.
- Route registry: `routes/api.php`, blob `f84dba507f88922088c9681ec718d4537275ebc8`, announcement routes at lines 612–621.
- Persistence migration: `database/migrations/2026_11_01_000001_create_communication_announcements_tables.php`, blob `d00ad41b3b17ba2e8df38f850ab29ade22ceaa93`.
- API tests: `tests/Feature/CommunicationAnnouncementsTest.php`, blob `b2067c76c3a99f9873734ea7228c813777c18403`.
- Contract: `docs/communication/ANNOUNCEMENTS-001.md`, blob `dfba76a9634f90295c411e2443d4eef037efcdce`.

All announcement endpoints are inside the enclosing `auth:sanctum` + `tenant.membership` group. Routes:
- `GET /api/announcements`
- `POST /api/announcements`
- `POST /api/announcements/{id}/submit`
- `POST /api/announcements/{id}/approve`
- `POST /api/announcements/{id}/publish`
- `POST /api/announcements/{id}/acknowledge`

Lifecycle is `draft → pending_approval → approved → published`. The controller checks `announcement.manage` for draft creation/submission, `announcement.approve` for approval, and `announcement.publish` for publication. A submitter cannot approve their own announcement. Only an approved, non-expired announcement can be published.

### 10.2 Recipient and authorization behavior

- Draft creation requires an explicit `recipient_ids` array (1–1000 unique user IDs). No implicit tenant-wide broadcast exists.
- Every selected recipient must have active membership in the current tenant at draft creation.
- Publication rechecks that every recipient still has active tenant membership and an active User account.
- NotificationService independently revalidates persisted tenant, persisted active User and active TenantMembership before inserting notifications.
- A user without `announcement.manage` sees only published announcements whose recipient row names that user. A user with `announcement.manage` sees the tenant announcement register.
- `announcement.view` is described by the contract as a frontend navigation capability; the current API route itself has no `auth.permission:announcement.view` middleware. Recipient row ownership constrains ordinary reads. Do not represent this as a dedicated API view permission.
- Canonical role grants currently place `announcement.manage` with `secretary`; `announcement.approve` and `announcement.publish` with `president`; `announcement.view` with `manager`, `secretary` and `president`. Existing broad `scope:tenant` grants also apply to `platform_admin` and `tenant_admin`; `super_admin` has wildcard. These are seeded role grants, not evidence of any specific person's current assignment or corporate appointment.

### 10.3 Atomicity and evidence

Publication runs the announcement status update, recipient `delivered_at` update and central notification inserts inside a database transaction. The feature tests include a forced notification insert failure and assert rollback of publication and delivery markers. The test suite also contains cases for tenant isolation, invalid recipients, atomic draft/recipient creation, self-approval denial, explicit-recipient publication, membership revalidation and recipient acknowledgement.

These are **test definitions**, not test execution results. The current-head GitHub combined status query returned no status entries for the inspected API SHA; no A2 test run was initiated in this pass.

### 10.4 Material implementation gaps and risks

1. **No approval-submission or approval-decision notifications verified.** The controller's submit and approve methods change state but do not call NotificationService or create a dedicated outbox. Only publication emits `communication.announcement.published`. There is no verified resolver for the approver, submitter or next workflow actor.
2. **Acknowledgement is API-only in the inspected App feature.** API route and controller exist; acknowledgement writes `acknowledged_at` and recipient `read_at`. The App announcement API adapter and `AnnouncementsView.vue` contain no acknowledgement API function or acknowledgement action. The shared notification inbox's read state is a separate `platform_notifications.read_at` field; acknowledgement does not update that field. A recipient can therefore have business acknowledgement and notification-read state that are not synchronized.
3. **No acknowledgement monitoring/report view was found.** The contract itself lists acknowledgement reporting and deep-link verification as pending evidence.
4. **Notification body localization is not correct for bilingual content.** Publication constructs the notification body by placing the same stripped `content` string into both `am` and `en` keys. It does not select the body according to `content_locale`. If the announcement body is authored in one language, it is presented as though it were available in both languages.
5. **Priority semantics are compressed.** Announcement `important` becomes notification `normal`; `urgent` and `critical` both become notification `high`. The notification priority schema has only `low|normal|high`. The source announcement priority remains richer than the inbox priority.
6. **Action reference is not a detail deep link.** The notification action route is `announcements` and the resource reference key is `announcement_id`. The current App resolver supports the announcements list route and expects `id` for detail-route parameters. It therefore safely falls back to the list, but does not open the exact announcement.
7. **No announcement-specific event/outbox record for each workflow transition.** The central notification insert is synchronous and transactionally coupled to publication. There is no separate durable workflow event/outbox covering draft, submit, approve, publish, withdraw/archive, acknowledgement and subsequent retries across all stages.
8. **Persistence referential integrity is application-enforced.** The announcement migration uses indexed unsigned tenant/user IDs and indexed announcement UUIDs, but does not declare foreign-key constraints for announcement/recipient tenant, user or announcement references. This should be assessed against the owning domain's deletion and retention rules; it is not by itself proof of exploitable cross-tenant access.
9. **Frontend action affordances are not permission-filtered in the component.** The view renders submit/approve/publish controls based on status, not the current user's permission set. Server-side authorization remains the security boundary, but unauthorized controls can be shown and fail at the API. This is a usability and contract completeness gap, not evidence of an API bypass.
10. **Recipient entry is manual numeric IDs.** There is no directory picker, role, committee, department, stakeholder or workflow-step recipient resolver in the inspected composer.

### 10.5 Announcement-specific A2 status

- Draft and explicit recipient persistence: **PRESENT / TESTS DEFINED**.
- Submit and approval state transitions: **PRESENT / TESTS DEFINED**.
- Self-approval prevention: **PRESENT / TEST DEFINED**.
- Publication with recipient revalidation: **PRESENT / TESTS DEFINED**.
- Atomic publication + notification insertion: **PRESENT / TEST DEFINED**.
- Publication notification: **PRESENT / VERIFIED SOURCE PATH**.
- Approver/submitter notification: **NOT FOUND IN INSPECTED CONTROLLER**.
- Acknowledgement endpoint: **PRESENT**.
- App acknowledgement action/report: **NOT FOUND IN INSPECTED APP FEATURE**.
- Correct body localization and full priority preservation: **GAP**.
- Announcement detail deep link: **GAP**.
- Runtime delivery, migration-applied state and test execution: **NOT VERIFIED**.

D21 remains **PARTIAL** under the Owner-approved A2 disposition. These findings do not amend A1 or authorize implementation.
