# ARNA Communication Hub Expansion — Backend Contract v1.0

**Status:** Owner-authorized expansion specification; implementation and runtime acceptance remain pending.
**Base:** Communication Hub A4 merge `8f51fe9e2f2daae119adc2910a127dae47d9dcc1`
**Branch:** `feat/communication-hub-expansion`
**Scope:** Direct/group messaging, attachments, domain events, external delivery channels, search, receipts, analytics and push.

## 1. Architecture invariants

1. ARNA Core remains authoritative for identity, tenant context, memberships, roles, permissions, audit identity and notification persistence.
2. Every conversation, message, attachment reference, subscription, delivery record and analytic query is tenant-scoped and fail-closed.
3. A user may read a conversation only while an active membership exists and the user is an active participant. Department membership is resolved through Organization; it is not a bypass around participant authorization.
4. Reuse the existing Platform Notification service. Do not introduce a second notification inbox or notification store.
5. Reuse Documents/File Management for file bytes, malware scanning, classification, retention and access control. Communication stores only validated document references and attachment metadata.
6. Calendar and Governance remain owners of their records and business rules. Communication consumes domain events and links to canonical records; it does not duplicate or mutate those records.
7. External transports are adapters behind an outbox. Provider credentials, sender identities, consent, delivery policy and operational configuration are environment-specific and are not committed to source.
8. No production migrations, grants, provider credentials, external messages or deployment are authorized by this specification.

## 2. Capability scope

### 2.1 Direct and group messaging
- One-to-one conversations.
- Group and Organization department conversations.
- Participant add/remove and group metadata changes with authorization.
- Text messages, reply/thread references, edit/delete policy, pagination and chronological ordering.
- Message state transitions: accepted, delivered, read; delivery/read receipts are participant-scoped.
- No cross-tenant participants. Removed members lose future access; historical visibility follows an explicit retention policy.
- Conversation creation and message submission require idempotency keys to prevent duplicate sends.

### 2.2 Attachments
- Uploads are delegated to the Documents/File Management service.
- Communication stores a document ID/reference, tenant ID, uploader, message ID, display metadata, scan status and timestamps.
- Download authorization is rechecked against tenant, conversation membership, document policy and scan status.
- Reject unscanned, quarantined, expired, foreign-tenant or unauthorized files.
- No raw file bytes in communication tables or notification payloads.

### 2.3 Domain integrations
- Governance: publish meeting notices, agenda links, decisions and action notices from authorized Governance events.
- Organization: resolve authorized department/group recipients from active Organization assignments; snapshot membership at dispatch and revalidate at delivery.
- People: use canonical person/user identities; do not create a second contact directory.
- Calendar: link canonical event IDs, deliver invitations/reminders through Calendar-owned events, and route responses back to Calendar APIs.
- Documents: attach canonical document references and respect Documents ACLs.
- Other domains may publish typed events through a stable integration contract; no direct cross-domain table writes.

### 2.4 Email, SMS and push
- Provider-neutral `ChannelAdapter` contract with capability checks, send result, provider message ID, retry classification and redacted diagnostics.
- Email/SMS/push preferences, consent, quiet hours, locale and channel eligibility are checked before dispatch.
- In-app notifications remain the durable source of truth. External channels are secondary delivery paths.
- SMS/email/push delivery failures must not roll back a committed message or domain event.
- Push subscription lifecycle includes registration, rotation, revocation and user/tenant ownership validation.
- Provider selection and secrets require separate environment/operations approval.

### 2.5 Search, analytics and reporting
- Tenant- and participant-scoped message search; no global search leakage.
- Search filters: conversation, participant, date range, message type and attachment presence.
- Search results reveal only records the caller can currently access.
- Analytics include message volume, active conversations, delivery/read rates, channel outcomes and acknowledgement completion.
- Analytics are permission-gated, aggregated by default and exclude message body content and sensitive attachment metadata.
- Retention and deletion policy must be explicit before production indexing/analytics is enabled.

## 3. Proposed persistence model

Final names and foreign-key strategy must be reconciled with existing ARNA conventions before migrations are authored.

- `communication_conversations`: tenant, type (direct/group/department), title, creator, lifecycle, timestamps.
- `communication_conversation_participants`: tenant, conversation, user, role, joined/left timestamps, notification preferences.
- `communication_messages`: tenant, conversation, sender, body, body locale, message type, reply-to, idempotency key, edited/deleted timestamps.
- `communication_message_attachments`: tenant, message, canonical document reference, uploader, scan/availability snapshot.
- `communication_message_receipts`: tenant, message, recipient, delivered/read timestamps, unique recipient/message constraint.
- `communication_delivery_outbox`: tenant, event/message reference, channel, destination reference, idempotency key, status, attempts, next attempt, redacted failure code.
- `communication_channel_subscriptions`: tenant, user, channel, provider-neutral subscription reference, state, locale, consent and revocation timestamps.
- `communication_audit_events`: tenant, actor, subject, action, correlation ID and minimal before/after metadata.
- Analytics should use authorized aggregate queries or materialized projections; they must not become an alternate source of truth.

Indexes must support tenant + conversation + chronological message reads, participant membership checks, idempotency uniqueness, receipt uniqueness and retryable outbox scans. Foreign keys and unique constraints must be verified against the actual MySQL schema and existing tenancy model.

## 4. API contract outline

All routes must be registered inside the existing authenticated tenant-scoped API group and follow the standard ARNA JSON envelope.

- `GET/POST /api/communication/conversations`
- `GET /api/communication/conversations/{conversation}`
- `PATCH /api/communication/conversations/{conversation}`
- `GET/POST /api/communication/conversations/{conversation}/messages`
- `PATCH/DELETE /api/communication/messages/{message}`
- `POST /api/communication/messages/{message}/read`
- `POST /api/communication/messages/{message}/delivered`
- `POST /api/communication/conversations/{conversation}/participants`
- `DELETE /api/communication/conversations/{conversation}/participants/{user}`
- `POST /api/communication/messages/{message}/attachments` (document reference only)
- `GET /api/communication/search`
- `GET /api/communication/analytics`
- `GET/PUT /api/communication/preferences`
- `POST/DELETE /api/communication/push-subscriptions`

Routes are proposed, not registered or available until implementation and tests prove them.

## 5. Authorization model

Proposed tenant permissions, subject to reconciliation with the canonical permission catalogue:
- `communication.view`
- `communication.message.send`
- `communication.conversation.manage`
- `communication.participant.manage`
- `communication.attachment.manage`
- `communication.search`
- `communication.analytics.view`
- `communication.channel.manage`

Permission grants must follow least privilege. Permission alone does not override participant membership, tenant boundaries, document ACLs, Calendar/Governance domain policies or active membership checks. No implicit tenant-wide message broadcast.

## 6. Reliability and security requirements

- Transactional message + outbox creation.
- At-least-once external delivery with idempotent provider dispatch; exactly-once delivery is not claimed.
- Retry with bounded exponential backoff, dead-letter state and operator-visible diagnostics.
- Independent-connection MySQL concurrency tests for duplicate sends, participant changes, receipts and outbox claiming.
- Failure-atomicity tests for message persistence, attachment linking and event publication.
- Rate limits for message creation, search, attachment linking, push registration and group membership changes.
- Output encoding, content validation, abuse controls, audit events and privacy-safe logs.
- No message body, tokens, provider secrets or private attachment URLs in logs or analytics.

## 7. Delivery slices and acceptance gates

1. **E1 — Messaging foundation:** direct/group/department conversations, participants, messages, receipts, API/UI, tenant isolation and MySQL concurrency tests.
2. **E2 — Attachments and search:** Documents integration, scanning/access checks, scoped search, pagination and indexing/retention contract.
3. **E3 — Domain events and reminders:** Governance and Calendar event subscriptions, canonical deep links, retries and duplicate-event protection.
4. **E4 — External channels:** preferences/consent, outbox workers, provider-neutral email/SMS/push adapters, subscription lifecycle and delivery monitoring. Live providers remain disabled until separately configured and authorized.
5. **E5 — Analytics and operations:** permission-gated aggregate reporting, delivery health, audit views and retention controls.

Each slice requires focused tests, clean-install MySQL verification, tenant isolation and concurrency/failure-atomicity regressions, exact-head CI, A4 review and Owner merge authorization. Deployment and production database operations remain separate A5 decisions.

## 8. Explicit non-claims

This contract does not assert that the above endpoints, tables, workers, providers, screens or integrations already exist. It is the controlling backend design contract for the expansion branch until replaced by reviewed implementation evidence.
