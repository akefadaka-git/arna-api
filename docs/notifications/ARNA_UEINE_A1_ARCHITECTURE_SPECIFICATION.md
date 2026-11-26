# ARNA Universal Event Intelligence & Notification Engine (UEINE)
## A1 — Owner-Approved Target Architecture Specification

| Control | Value |
|---|---|
| Document ID | ARNA-UEINE-A1-001 |
| Version | 1.0 |
| Status | **A1 APPROVED / ARCHITECTURE FROZEN** |
| Approval basis | Owner approval in ChatGPT conversation, 2026-11-26 |
| Scope | Target architecture and requirements only |
| Canonical repository | `akefadaka-git/arna-api` |
| Companion client | `akefadaka-git/arna-app` |
| Next gate | A2 read-only reconciliation |
| Implementation authority | Not granted; A3 requires separate gate completion/approval |

> **Governance note:** This is an architecture and requirements freeze, not a claim that the system is implemented, fully integrated, production-ready, or deployed. A2 may identify evidence-based corrections or contradictions; any material change to this frozen target must be recorded as a controlled change rather than silently altering the baseline.

## 1. Purpose and vision

Establish one enterprise-wide, tenant-aware event intelligence and notification capability for ARNA. Events originating across ARNA domains must be captured through governed integration contracts, classified, interpreted in context, routed to the correct people or work queues, and converted into an appropriate notification or authorized business action.

The experience must be coherent across personal workspaces, role workspaces, approvals, workflows, communication, calendar and meetings, reminders, reports, plans, performance, surveys, and future ARNA domains. It must support reusable modular UI components rather than isolated notification implementations in each feature.

The system is an orchestration and communication capability. It does not become the owner of source-domain business records, permissions, approval authority, or legal/governance decisions.

## 2. Architectural principles

1. **Domain ownership remains authoritative.** Each source domain owns its records, state transitions, business rules, and authorization decisions.
2. **One governed event path.** Domain adapters publish canonical events through a shared event contract; domain-specific integrations must not create incompatible notification pipelines.
3. **Every event is accounted for, not necessarily displayed.** Every accepted event receives a documented disposition: actionable, informational, audit-only, telemetry/non-user-facing, or policy-suppressed/ignored. Only relevant events create user-facing notifications.
4. **Actionable events receive an appropriate outcome.** The engine must notify, assign, queue, remind, escalate, or initiate an authorized workflow according to deterministic policy. It must not silently drop actionable events.
5. **Authorization precedes disclosure and action.** A recipient route, role membership, subscription, or notification link never grants access to protected source data or authority to execute an action.
6. **Reliable and idempotent processing.** Event publication, routing, notification creation, delivery attempts, and acknowledgements must be traceable and safe under retries and duplicate delivery.
7. **Human accountability for consequential decisions.** AI may classify, summarize, explain, and recommend. Financial approvals, governance decisions, permissions, legal notices, and other consequential transitions remain governed by deterministic rules and authorized human/domain workflows.
8. **Amharic-first and accessible.** Support Amharic-first localization, English as an additional language, calendar/time-zone correctness, responsive mobile-first experience, dark mode, and WCAG 2.2 AA-aligned accessibility.
9. **Reusable by design.** Shared contracts, services, UI primitives, and domain adapters are preferred over duplicated domain-specific notification logic.
10. **Observable and auditable.** The system must preserve event-to-decision-to-action-to-recipient-to-delivery lineage.

## 3. Scope

### 3.1 Included capability

- Universal event intake and schema validation.
- Canonical event identity, correlation, causation, tenant and source-domain context.
- Event classification and contextual interpretation.
- Policy/rule evaluation and action orchestration.
- User, role, organization, workflow, relationship, and subscription recipient resolution.
- Notification creation, prioritization, localization, in-app inbox and supported channel dispatch.
- Reminders, digests, escalation, delegation and acknowledgement tracking.
- Contextual action links that revalidate authorization at execution time.
- Reusable frontend notification and action components.
- Audit history, operational telemetry, delivery analytics and administrative policy controls.
- Domain adapters and integration contracts for ARNA domains.

### 3.2 Explicit exclusions

- Replacing source-domain business workflows or records.
- Granting approval authority to the notification engine or AI.
- Automatically approving or executing financial transactions.
- Changing shareholder, board, committee, or General Meeting decisions.
- Treating a notification as legal service or formal notice unless the relevant approved legal/process policy expressly says so.
- Bypassing tenant boundaries, domain authorization, data classification, retention or consent rules.
- A3 code implementation, migrations, production configuration, paid provider subscriptions, PR merge, or deployment under this A1 approval.

## 4. Target logical architecture

The target consists of six logical layers. These are logical responsibilities and do not prescribe premature microservice boundaries.

### 4.1 Domain Event Producers and Adapters

Each owning domain emits events when relevant business state changes. An adapter maps the domain event into the canonical envelope, verifies required context, and publishes it reliably. Domain code must not write directly into another domain's tables.

### 4.2 Universal Event Gateway

Responsibilities:
- Authenticate/validate producer identity and tenant context.
- Validate event schema, version, required identifiers and source ownership.
- Assign or validate event ID, correlation ID and causation ID.
- Enforce idempotency and reject malformed or unauthorized events.
- Persist or hand off events using a durable transactional outbox/inbox pattern.
- Record intake outcome and schema/version metadata.

### 4.3 Event Intelligence and Classification Engine

Responsibilities:
- Determine event family, business meaning, severity, urgency and relevance.
- Resolve applicable tenant, organization, source entity, lifecycle state and time/deadline context.
- Select a governed event disposition.
- Produce structured facts and explanations; preserve source facts separately from inferred interpretation.
- Use deterministic classification for mandatory security, compliance, financial and governance rules.
- Use AI only as an assistive layer, with confidence, provenance and human-review thresholds where appropriate.

### 4.4 Decision and Action Orchestrator

Responsibilities:
- Evaluate versioned, deterministic policies and workflow definitions.
- Decide whether the outcome is notify, assign, queue, request approval, remind, escalate, delegate, create a task, or initiate a domain-owned workflow.
- Enforce action allowlists, preconditions, role/permission checks and domain authorization.
- Record decision inputs, policy version, selected outcome and any human decision.
- Revalidate source-domain state and authorization immediately before a contextual action executes.

The orchestrator may request a domain action; the owning domain remains responsible for validating and committing the state transition.

### 4.5 Universal Notification Dispatcher

Responsibilities:
- Resolve eligible recipients and remove duplicate routes.
- Apply confidentiality, preference, subscription, quiet-hours and channel policies.
- Render localized content and safe action links.
- Persist in-app notifications and dispatch enabled external channels through adapters.
- Retry transient failures, quarantine poison messages, and expose delivery/acknowledgement state.
- Prevent unauthorized content disclosure in previews, email, push, logs and digests.

### 4.6 ARNA Intelligent Experience Layer

Responsibilities:
- Provide one coherent notification center and reusable contextual components.
- Present personal, role-based and work-queue views without confusing them with authorization.
- Support acknowledgement, read/unread, snooze, dismiss, assignment, escalation and authorized contextual actions.
- Deep-link to the owning domain's canonical record or workflow.
- Expose user preferences and authorized administrative policy views.

## 5. Canonical event contract

The exact serialization and schema registry implementation are A2/A3 design details. The minimum logical event envelope is:

| Field | Requirement |
|---|---|
| event_id | Globally unique stable identifier |
| event_type | Namespaced, versioned event key |
| schema_version | Explicit contract version |
| occurred_at | Source event time in UTC |
| published_at | Gateway acceptance/publication time |
| tenant_id | Required tenant boundary |
| source_domain | Owning ARNA domain |
| source_entity_type / source_entity_id | Canonical source reference |
| actor_id / actor_type | Authenticated initiating actor or system |
| correlation_id | End-to-end business process correlation |
| causation_id | Immediate triggering event, when applicable |
| idempotency_key | Stable deduplication identity |
| classification | Source classification and sensitivity |
| payload | Versioned, minimal business facts; avoid unnecessary personal data |
| trace_context | Operational trace metadata |

Events must be immutable facts. Corrections and reversals are represented by new events, not silent mutation of historical events.

## 6. Event disposition and processing lifecycle

Each event is evaluated and assigned exactly one primary disposition:
- **ACTIONABLE:** creates one or more authorized actions and/or notifications.
- **INFORMATIONAL:** produces a user-visible update without requiring action.
- **AUDIT_ONLY:** retained for traceability; not presented as a user notification.
- **TELEMETRY:** operational/system signal routed to monitoring, not the user inbox.
- **SUPPRESSED/IGNORED:** excluded under an explicit, versioned policy with a recorded reason.

An event may create multiple linked outcomes, but it must have one traceable classification decision. Suppression must never be used to conceal a mandatory security, compliance, or governance event.

Lifecycle:
1. Produced by owning domain.
2. Persisted to source outbox or equivalent reliable publication boundary.
3. Accepted and schema-validated by gateway.
4. Classified and context-enriched.
5. Evaluated against versioned policies.
6. Recipients and authorized actions resolved.
7. Notification/action work persisted idempotently.
8. Delivered or queued through selected channels.
9. Read, acknowledged, acted upon, expired or escalated.
10. Retained under audit and data-retention policy.

## 7. Recipient resolution

Supported recipient strategies:
- **Individual:** explicitly identified user.
- **Role:** eligible users holding a relevant role in the correct tenant and organizational scope.
- **Organizational:** department, branch, committee, legal entity or other authorized organizational unit.
- **Workflow:** current/next approver, reviewer, assignee, responsible actor or escalation target.
- **Relationship:** meeting participants, shareholders, collaborators or stakeholders with a valid relationship to the source record.
- **Subscription/policy:** users who opted into an authorized event category or administrative subscription.

Rules:
- Resolve recipients at dispatch/action time using current authoritative membership and eligibility.
- Deduplicate users reached through multiple routes while preserving the reason(s) they were selected.
- Enforce tenant, organization, record-level access, sensitivity and purpose-of-use restrictions.
- Role membership alone does not authorize access to confidential payloads or source records.
- Support exclusions, delegation validity windows, inactive accounts and revoked membership.
- Do not expose recipient lists or sensitive metadata to unauthorized recipients.

## 8. Notification model and delivery

A notification is a user-facing representation of an event or workflow outcome; it is not the event itself and is not the source record.

Minimum notification attributes:
- notification ID, tenant, recipient, event reference and source reference;
- category, priority, sensitivity and localized title/body;
- created/available/expiry timestamps;
- read, acknowledged, snoozed, dismissed and action status as distinct states;
- safe route/deep link and action descriptors;
- deduplication key, correlation ID and delivery history.

Priority classes must be policy-defined (for example: critical, high, normal, low) with explicit urgency/deadline semantics. Priority must not be inferred solely from AI output.

In-app delivery is the baseline. Email, push, SMS, chat or other channels are optional adapters subject to availability, user preference, consent, security and operational approval. Channel delivery is not proof that a user read or legally received a notice.

Digest modes include immediate, scheduled daily/weekly, and policy-triggered summaries. Digests must preserve individual event links, priority, deadlines and actionability; critical events must not be hidden inside a low-frequency digest.

## 9. Reminder, escalation and workflow rules

- Reminders are scheduled from authoritative deadlines, calendar occurrences or workflow timers.
- Rescheduling/cancellation must reconcile pending reminders without deleting delivered history.
- Escalation requires explicit policy: trigger, timing, recipient, reason, maximum stages and stop condition.
- Delegation must be time-bounded, authorized, visible in the audit record and reversible according to source-domain policy.
- Acknowledgement, read receipt, task completion and approval are distinct events and must not be conflated.
- The owning domain validates any approval or state transition; the engine only coordinates the request and notification.
- Avoid duplicate or contradictory reminders by stable occurrence-aware idempotency keys.

## 10. Reusable frontend component contract

The application should compose shared primitives into domain experiences. Initial target component catalogue:

- `UniversalNotificationCenter`
- `NotificationBell`
- `NotificationInbox`
- `NotificationFeed`
- `NotificationCard`
- `EventTimeline`
- `ApprovalActionCard`
- `WorkflowActionCard`
- `ReminderCard`
- `MeetingNotificationCard`
- `ReportNotificationCard`
- `PerformanceAlertCard`
- `SurveyInvitationCard`
- `NotificationDigest`
- `NotificationPreferences`
- `NotificationActivityHistory`

Component requirements:
- Responsive mobile/desktop layouts; keyboard and screen-reader support.
- Amharic-first localization, bidirectional-safe text handling where needed, and localized dates/times.
- Dark-mode support and consistent ARNA design tokens.
- Clear distinction between informational content and required action.
- Explicit priority, deadline, source, actor and current status where disclosure is authorized.
- Contextual action controls that call owning-domain APIs and handle stale/expired/unauthorized states.
- Loading, empty, offline, retry, partial-failure and error states.
- No client-side assumption that a hidden button or notification link is a security boundary.

## 11. Security, privacy and governance controls

- Tenant isolation at intake, persistence, query, recipient resolution, dispatch, search and analytics.
- Server-side RBAC/ABAC and source-record authorization on every read and action.
- Data minimization and sensitivity-aware rendering; no secrets or unnecessary personal data in event payloads.
- Encryption in transit and at rest according to ARNA platform standards.
- Auditable access to notification content and administrative policy changes.
- Policy versioning, approval and rollback controls for routing, escalation and channel configuration.
- Rate limits, abuse prevention, producer authentication and payload size limits.
- Retention, deletion, export and legal-hold behavior aligned with applicable ARNA policy and law.
- No AI-generated action may bypass deterministic authorization, approval thresholds or source-domain validation.

## 12. Reliability, scale and observability

Required design properties:
- Transactional outbox at source-domain publication boundaries where source state and event publication must be atomic.
- Durable event intake and dispatch queues.
- At-least-once processing with idempotent consumers and deduplicated side effects; do not claim exactly-once delivery.
- Bounded retries with backoff, dead-letter/quarantine handling and controlled replay.
- Ordering guarantees only where explicitly required and scoped by aggregate/key.
- Backpressure, batch processing and fair scheduling across tenants.
- Metrics for intake, schema rejection, classification, disposition, routing, queue age, delivery success/failure, retries, dead letters, read/acknowledgement, action completion and escalation.
- Correlation/tracing across source event, policy decision, notification, channel delivery and action.
- Operational dashboards and alert thresholds defined during A3/A4.

## 13. Domain integration strategy

A2 must inventory every in-scope ARNA domain and map its existing events, outboxes, publishers, direct notification calls, recipient rules, permissions, UI and tests to this target.

Integration is incremental and adapter-based. The first implementation wave, subject to A2 evidence and separate A3 authorization, should establish the shared contracts and reliability/security foundation, then integrate representative domains before scaling across the domain portfolio.

Known starting evidence to revalidate in A2:
- API central notification persistence/service and its feature tests.
- Communication announcement publisher and its tests.
- Calendar reminder dispatcher/outbox and its tests.
- Finance journal-posted outbox as a candidate event source; an outbox alone does not prove central notification integration.
- Existing App notification center/bell and their responsive/contract tests.

These are baseline leads, not a declaration that the listed domains are fully integrated or production-verified.

## 14. Non-functional acceptance targets

A2 must determine existing platform baselines and propose measurable thresholds for Owner review. A3/A4 must test them. At minimum:
- no cross-tenant notification or event leakage;
- no unauthorized source-record disclosure through content, preview, digest or deep link;
- idempotent processing under duplicate and retry conditions;
- durable recovery from worker interruption and transient channel failure;
- auditable event-to-outcome traceability;
- correct localization, timezone and Ethiopian Calendar/Gregorian Calendar behavior where applicable;
- accessible responsive user experience;
- predictable queue behavior and tenant fairness under load;
- policy changes are versioned and attributable;
- source-domain permissions remain authoritative.

No numeric throughput, latency, availability or retention target is frozen by this document; those require A2 baseline evidence and explicit target selection.

## 15. Stage gates and deliverables

### A1 — Specification and architecture freeze
**Status: APPROVED / FROZEN.** This document defines the target direction and requirements. It is not implementation authorization.

### A2 — Read-only reconciliation
Authorized next. Inspect both canonical repositories and all relevant domains without changing source files, default branches, workflows, configuration or production systems. Deliver:
- repository and commit baseline;
- domain-by-domain integration inventory;
- evidence-backed PASS / PARTIAL / MISSING / UNRESOLVED / NOT VERIFIED matrix;
- current event/notification contracts and UI inventory;
- security, tenancy, authorization, reliability and localization gap register;
- duplicate/overlapping mechanisms and migration risks;
- proposed phased A3 plan, dependencies, acceptance tests and estimates;
- explicit list of unresolved Owner decisions.

A2 must not implement fixes or modify `main`.

### A3 — Implementation
Not authorized by this document. Requires A2 closure and explicit Owner authorization. Implementation must preserve existing behavior and use reviewed branches/PRs.

### A4 — Verification
Integration, security, tenant-isolation, reliability, accessibility, performance and end-to-end verification against frozen acceptance criteria.

### A5 — Deployment and production activation
Separate Owner approval required. No deployment or activation is authorized by A1 or A2.

## 16. A1 exit criteria

- [x] Owner approved the target architecture direction.
- [x] Purpose, principles, scope and exclusions are defined.
- [x] Logical architecture and event lifecycle are defined.
- [x] Recipient, notification, workflow and reusable UI requirements are defined.
- [x] Security, governance, reliability and AI boundaries are defined.
- [x] A2 read-only deliverables and subsequent stage gates are defined.
- [ ] A2 repository/domain reconciliation completed — next stage, not an A1 criterion.

## 17. Change control

This version is the A1 baseline. Any proposed change must identify the affected requirement, reason, evidence, impact on domain ownership/security/compatibility, and required approval. A2 may record contradictions and recommendations but must not silently rewrite this specification. A material architecture change returns to Owner review before A3.

---
**End of A1 specification.**
