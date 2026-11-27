# ARNA AI A1 — Security, Governance & Autonomy

**Document ID:** ARNA-AI-A1-006  
**Version:** 1.0.0  
**Status:** PROPOSED — OWNER APPROVAL PENDING

## 1. Security objective

ARNA AI must inherit ARNA's identity, tenant, authorization, audit and domain controls. It must not create a parallel trust system or use model instructions as a substitute for enforcement.

## 2. Identity and authorization

- Authenticate users through the existing ARNA identity and authentication architecture.
- Reuse the current authoritative RBAC schema and authorization services; do not reconstruct a historical schema or create an AI-only identity database.
- Resolve tenant context, membership, application, legal entity and organizational scope on the server.
- Authorize access to each source record, retrieval result and tool independently.
- Enforce record-level confidentiality in addition to role permissions.
- Re-check permissions at execution time; do not rely on authorization performed when a draft was created.
- Agent service identities, if required, must be non-human, narrowly scoped, revocable and unable to impersonate an Owner, Board, shareholder, auditor or other signatory.
- A tool cannot accept caller-supplied tenant/entity identity as authoritative without server-side resolution.

## 3. Trust boundaries

| Boundary | Required control |
|---|---|
| User → App | Existing authentication, session/token protections, CSRF/CORS rules and secure UI |
| App → API | Existing API auth, tenant context and authorization middleware |
| API → AI control plane | Authenticated internal contract, scoped context and request correlation |
| AI → retrieval | Permission-filtered search and source revalidation |
| AI → model provider | Explicit adapter allowlist, minimized payload, approved region/terms and egress gate |
| AI → domain tool | Typed schema, tool allowlist, domain API authorization and idempotency |
| AI → human approval | Authenticated approver, current authority, separation of duties and explicit decision |
| AI → audit | Attributable, tamper-evident event lineage with sensitive-data filtering |

## 4. Autonomy levels

These levels are proposed for Owner approval. They describe capability, not a grant of corporate authority.

| Level | Meaning | Example | Initial status |
|---|---|---|---|
| L0 | Read/retrieve authorized data | Search an approved policy | Proposed allowed |
| L1 | Analyze/explain | Explain a financial variance | Proposed allowed |
| L2 | Draft/recommend | Draft a report or proposed task | Proposed allowed |
| L3 | Execute a narrowly scoped, reversible workflow after explicit user confirmation and domain authorization | Save a draft or create an approved low-risk reminder | Disabled pending A3 tool-specific approval |
| L4 | Autonomous consequential or irreversible action | Payment, journal posting, formal approval, permission grant | Disabled; not part of initial ARNA AI authority |

No autonomy level overrides domain policy, law, delegated authority, maker-checker or user permission. A user confirmation is not a substitute for a legally or organizationally required approval.

## 5. Mandatory prohibited actions

ARNA AI must not:
- approve its own proposal or act as its own maker-checker;
- post/reverse financial journals, execute payments or close periods;
- change chart of accounts, accounting policy or financial authorization limits;
- create, amend, certify or publish formal governance records as an authority;
- determine or invent quorum, voting rights, approvers, legal powers or delegation;
- grant/revoke roles, permissions, tenant membership or security credentials;
- bypass tenant, legal-entity, confidentiality or record-level access;
- write directly to domain databases or bypass domain APIs;
- disable audit, hide evidence, delete audit lineage or tamper with records;
- transmit confidential data to an unapproved external provider;
- send external correspondence or publish public content without an approved send/release workflow;
- perform destructive or irreversible action based only on a model response.

## 6. Human approval and maker-checker

A consequential proposal must contain:
- proposal ID and immutable version;
- originating user and agent identity/version;
- target domain, tenant and legal-entity scope;
- proposed operation and before/after state;
- source evidence and retrieval timestamps;
- model/task-template/policy versions;
- risk classification, uncertainty and limitations;
- required approval role/authority source;
- reviewer identity, decision, reason and timestamp;
- execution result and domain transaction reference, if later executed.

The approver must be authorized by the owning domain and applicable governance instruments. AI cannot infer authority from a job title, role name, UI access or historical pattern.

## 7. Audit requirements

Record at minimum:
- request ID, actor, tenant and application context;
- agent ID/version and task-template version;
- model provider/adapter/model version where applicable;
- policy and tool-registry versions;
- source references, versions, classification and retrieval freshness;
- tool requested, authorization result, validated arguments hash and outcome;
- approval proposal/reviewer/decision lineage;
- execution reference, idempotency key and domain response;
- refusal, abstention, error, rate-limit and incident signals.

Avoid logging secrets, credentials, raw sensitive prompts or entire source documents unless an approved retention and access policy requires it. Audit design must support investigation without creating a second uncontrolled repository of sensitive data.

## 8. Threat model

| Threat | Required mitigation |
|---|---|
| Cross-tenant retrieval | Server-side tenant scope; isolation tests; source revalidation |
| Confused-deputy agent | Per-tool authorization, narrow service identity and execution-time checks |
| Prompt injection in files | Treat retrieved text as untrusted data; isolate instructions and tool execution |
| Data exfiltration | Egress disabled by default; allowlisted endpoints; minimization and redaction |
| Hallucinated source/citation | Citation validation against source registry; abstention |
| Self-approval | Separate maker/checker identities; policy-enforced transition |
| Tool argument manipulation | Typed schemas, validation, allowlists and deterministic server rules |
| Replay/duplicate action | Idempotency keys, state/version checks and domain transaction boundaries |
| Model/provider compromise | Adapter isolation, key rotation, output validation and kill switch |
| Sensitive logs | Redaction, least-privilege audit access and retention policy |
| Stale permissions/index | Revalidate source access and version at query time |
| Untrusted output | HTML/script sanitization, schema validation and safe rendering |

## 9. Incident response

Required capabilities:
- global and per-agent kill switch;
- provider egress disablement;
- tool revocation without changing core identity;
- suspension of affected agent/model/prompt version;
- traceable incident record and evidence preservation;
- tenant-scoped impact assessment;
- source/index/cache invalidation;
- recovery and re-enable only after authorized review.

## 10. Governance gates

- A1: approve architecture and policy.
- A2: reconcile current identity, RBAC, tenant, audit and provider configuration read-only.
- A3: implement isolated tools and controls on approved branches.
- A4: adversarial, privacy, access-control and approval-bypass testing.
- A5: separately approved deployment and operational activation.

## 11. Legal and authority caveat

This design is a technical control specification, not a legal opinion. Any authority not established by the governing documents, adopted resolution or valid delegation remains unresolved and must be denied by default.
