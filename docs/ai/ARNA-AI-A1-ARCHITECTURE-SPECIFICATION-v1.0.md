# ARNA AI — Enterprise Architecture & Specification

**Document ID:** ARNA-AI-A1-001  
**Version:** 1.0.0  
**Status:** A1 REVIEW CANDIDATE — OWNER APPROVAL PENDING  
**Classification:** Internal architecture  
**Owner:** ARNA Owner  
**Date prepared:** 2026-11-27  
**Scope:** Complete ARNA AI ecosystem; Amharic-first  
**Related package:** [Document register](README.md)

## 1. Executive architecture statement

ARNA AI is the governed intelligence and orchestration layer of the ARNA Enterprise Operating System. It provides contextual assistance, enterprise knowledge retrieval, domain-specific agents, analytical support, decision preparation and controlled workflow assistance.

ARNA AI is not:
- a replacement for ARNA domain systems of record;
- a second accounting, governance, HR, shareholder or document-record authority;
- an unrestricted autonomous corporate actor;
- a substitute for human or legally competent approval;
- a standalone chatbot detached from ARNA identity, tenant, authorization and audit controls;
- an IROKA component or dependency.

The design objective is to make AI useful while preserving authoritative domain ownership, data isolation, evidence lineage, deterministic business controls and Owner-governed autonomy.

## 2. Goals

1. Deliver a unified AI capability across the ARNA enterprise domains.
2. Make Amharic the primary interaction and product language, with English support.
3. Retrieve and explain authorized enterprise knowledge with traceable sources.
4. Provide domain-specific agents with narrowly scoped capabilities.
5. Support analysis, recommendations, forecasting and draft preparation.
6. Integrate with ARNA Core identity, RBAC, tenancy, audit, files, calendar and domain APIs.
7. Keep model providers replaceable and provider-specific logic behind adapters.
8. Support controlled cloud and self-hosted/hybrid deployment profiles.
9. Prevent AI from bypassing human approval or domain authorization.

## 3. Non-goals

- Autonomous exercise of shareholder, General Meeting, Board, committee, CEO or other corporate authority.
- Autonomous financial posting, payment, journal reversal, period close or accounting-policy change.
- Autonomous approval, signing, certification or publication of formal corporate documents.
- Autonomous changes to users, roles, permissions, tenant boundaries or legal-entity scope.
- Direct writes to domain databases outside approved domain APIs.
- Training a foundation model from confidential ARNA data as an implicit consequence of use.
- Provider selection, paid subscription, spend commitment or external data transfer in A1.
- Replacing deterministic rules with probabilistic model decisions.
- Claiming Maale-language model capability without data, linguistic review and evaluation evidence.

## 4. Architectural principles

| Principle | Requirement |
|---|---|
| Domain authority | Owning domain remains the sole authoritative source for its business records and decisions |
| API enforcement | Authorization and business invariants are enforced server-side, not in prompts or UI |
| Least privilege | Every user, agent, tool and retrieval operation receives the minimum required scope |
| Evidence first | Material answers and proposals identify source records, versions and retrieval time |
| Human accountability | Consequential actions require the applicable authorized human approval |
| Determinism | Accounting, access, legal-state and other critical business rules remain deterministic |
| Provider neutrality | Models are replaceable behind a gateway; no domain agent is hard-wired to a provider |
| Amharic first | Language, script, dates, numerals and document processing are designed for Amharic at inception |
| Fail closed | Missing permission, source, authority, confidence threshold or policy means deny or abstain |
| Auditability | Requests, retrieval, tool calls, approvals and outcomes have attributable audit lineage |
| Data minimization | Send only the minimum authorized context to a model; external egress is disabled until approved |
| Reversible by design | Initial workflow actions are drafts or reversible low-risk operations; irreversible actions remain gated |

## 5. System context

### 5.1 Logical context

\`\`\`text
ARNA Users / Authorized Roles
          |
          v
ARNA App (workspace / admin / shareholder surfaces)
          |
          v
ARNA API — Identity, Tenant Context, RBAC, Policies, Audit
          |
          +--------------------+
          |                    |
          v                    v
ARNA AI Control Plane     Domain APIs / Services
          |                    |
          |                    +-- Finance (authoritative ledger)
          |                    +-- Governance / Organization
          |                    +-- People / Shareholder
          |                    +-- Documents / Files
          |                    +-- Communication / Calendar
          |                    +-- Other approved ARNA domains
          |
          +--> Model Gateway --> Approved model adapter(s)
          |
          +--> Knowledge/RAG --> Permission-filtered source references
          |
          +--> Agent Runtime --> Allowlisted domain tools
          |
          +--> Audit / Evaluation / Observability
\`\`\`

The diagram is conceptual. It does not assert that all components or integrations currently exist in code.

### 5.2 Responsibility boundaries

| Layer | Owns | Must not own |
|---|---|---|
| ARNA Core | Identity, tenant context, RBAC, policy enforcement, shared audit and platform services | Domain business decisions |
| Domain API | Authoritative records, validation, lifecycle, persistence and domain audit | AI model inference |
| ARNA AI control plane | Orchestration, prompt/task templates, agent identity, model routing, retrieval policy, tool authorization and AI audit | Independent domain authority or direct DB writes |
| Knowledge/RAG | Index metadata, chunks, embeddings, source pointers, retrieval and provenance | Canonical business facts |
| Model provider | Inference under approved terms and configuration | ARNA identity, authorization or source-of-truth authority |
| ARNA App | User interaction, review, approval presentation, accessibility and workflow navigation | Security enforcement or hidden business authorization |

### 5.3 Domain ownership

Each ARNA domain owns its records, lifecycle, validation and business decisions. AI may read authorized domain information, explain it, analyze it and prepare proposals. Any mutation must return through the owning domain's approved API and authorization process.

Finance is the authoritative owner of the general ledger, chart of accounts, accounting periods, journal lifecycle and accounting audit trail. Operational domains remain authoritative for their source transactions. AI cannot post or reverse journals, execute payments, change accounting policy, approve its own proposal or close periods.

Governance owns formal General Meeting, Board and committee records. Calendar may display or remind users about those records but cannot change their legally approved details. AI may prepare analysis and recommendations but cannot establish or certify a governance decision.

## 6. Logical subsystems

| ID | Subsystem | Responsibilities |
|---|---|---|
| AI-01 | AI Control Plane / Core | Request lifecycle, orchestration, policy evaluation, context assembly and execution coordination |
| AI-02 | Model Gateway | Provider adapters, model catalogue, routing, timeouts, quotas, fallback and usage accounting |
| AI-03 | Knowledge Engine | Source registration, ingestion, parsing, classification, versioning and lifecycle |
| AI-04 | Retrieval & RAG | Permission-aware search, ranking, citation mapping, freshness and answer grounding |
| AI-05 | Agent Runtime | Agent definitions, scoped tools, task execution, state, handoffs and limits |
| AI-06 | Decision Intelligence | Analytical assistance, forecasting, scenario comparison and recommendation drafts |
| AI-07 | Workflow Intelligence | Human-reviewed proposals and approved workflow orchestration |
| AI-08 | Language Intelligence | Amharic/English processing, localization, translation, OCR and language evaluation |
| AI-09 | Governance & Assurance | AI policy, audit, evaluation, risk classification, incident handling and approval evidence |

These are logical architecture boundaries, not a decision to create nine microservices. Physical deployment boundaries are deferred to A2/A3 design.

## 7. Core request lifecycle

1. Authenticate the user through ARNA's existing identity path.
2. Resolve tenant, membership, application and relevant legal-entity/domain scope server-side.
3. Authorize the requested AI capability and each data source/tool independently.
4. Classify request risk, sensitivity, language and action intent.
5. Retrieve only authorized source material; retain source IDs, versions and timestamps.
6. Construct minimized context and invoke an approved model adapter, if one is enabled.
7. Validate output against policy, schema, source support and tool allowlists.
8. Return answer with citations, limitations and uncertainty; or create a proposal awaiting review.
9. For an approved workflow, re-check authority and current domain state at execution time.
10. Persist attributable audit and evaluation metadata without leaking secrets or unnecessary sensitive content.

A prompt instruction is not an authorization control. Authorization is re-evaluated by the server for every tool call and at execution time.

## 8. Agent capability model

An agent is a governed configuration consisting of:
- stable agent ID and version;
- accountable domain owner;
- purpose, supported tasks and prohibited tasks;
- permitted user roles and tenant/entity scope;
- approved knowledge sources and retrieval filters;
- allowlisted tools and typed input/output contracts;
- model/task-template compatibility;
- risk classification and autonomy ceiling;
- rate, token, time and cost ceilings;
- evaluation suite and release status;
- audit and incident policy.

Agents cannot grant themselves tools, broaden their scopes, alter policies, create authority or approve their own proposals.

## 9. Knowledge and evidence architecture

Knowledge classes and retrieval controls are defined in [AI-A1-004](ARNA-AI-A1-KNOWLEDGE-DATA-GOVERNANCE-v1.0.md). Retrieval must enforce authorization before and after candidate selection, and at source access time. A vector index is a derived search structure, never the authoritative record.

Every material answer must distinguish:
- source-backed facts;
- model-generated interpretation;
- inference or forecast;
- assumptions;
- missing or conflicting evidence.

If adequate evidence is unavailable, the agent must abstain or state the limitation.

## 10. Language architecture

Amharic-first requirements are specified in [AI-A1-005](ARNA-AI-A1-LANGUAGE-ARCHITECTURE-v1.0.md). Amharic is the default interaction language; English is supported. Ge'ez Unicode handling, Ethiopian calendar correctness and bilingual content are baseline requirements. Additional languages require separately evaluated language packs and source-data readiness.

## 11. Security, autonomy and governance

The control model is specified in [AI-A1-006](ARNA-AI-A1-SECURITY-GOVERNANCE-AUTONOMY-v1.0.md). It inherits ARNA identity, RBAC, tenant isolation, domain authority, Finance maker-checker, audit and fail-closed governance controls. L0-L3 are proposed capability levels; L4 is disabled and reserved. No AI action can bypass a domain API or approval requirement.

## 12. Integration architecture

The integration contract is specified in [AI-A1-007](ARNA-AI-A1-INTEGRATION-CONTRACTS-v1.0.md). The Laravel API remains the business enforcement boundary and the existing App remains the presentation/workflow surface. AI must not establish a parallel identity or authorization system. Provider integration is adapter-based and disabled until separately approved.

## 13. Deployment architecture

Hybrid deployment and observability requirements are specified in [AI-A1-008](ARNA-AI-A1-DEPLOYMENT-OBSERVABILITY-v1.0.md). Domain logic must remain provider-independent. Local deterministic ARNA operations must not depend on external model availability. Provider egress, data residency, retention, secrets and cost are explicit approval gates.

## 14. Non-functional requirements

| ID | Requirement | A1 target / acceptance direction |
|---|---|---|
| NFR-01 | Authorization | 100% of source and tool access checked server-side; no cross-tenant/entity leakage in adversarial tests |
| NFR-02 | Evidence | Every material factual answer identifies source references or explicitly abstains |
| NFR-03 | Audit | Every model request, retrieval, tool attempt, approval and execution has attributable metadata |
| NFR-04 | Availability | AI failure must not block deterministic core ARNA business workflows |
| NFR-05 | Provider resilience | Timeouts, quotas, circuit breaking and controlled fallback; no unapproved provider substitution |
| NFR-06 | Language | Amharic-first end-to-end evaluation; English parity on supported workflows |
| NFR-07 | Accessibility | ARNA App target WCAG 2.2 AA; AI interactions included in keyboard, screen-reader and contrast testing |
| NFR-08 | Data minimization | Minimize prompt context; redact secrets; no provider egress before approval |
| NFR-09 | Reproducibility | Record model, adapter, prompt/task-template, retrieval and policy versions |
| NFR-10 | Cost control | Per-user/tenant/agent quotas and budget ceilings; no paid service commitment in A1 |
| NFR-11 | Maintainability | Versioned contracts, adapter isolation, documented evaluation and migration paths |
| NFR-12 | Portability | Provider-neutral orchestration and hybrid deployment option |

Numeric latency, availability, retention, RPO/RTO and cost targets are not invented in A1. They require measured baseline, service criticality and Owner approval.

## 15. Architecture decisions proposed for Owner review

| ID | Proposed decision | Status |
|---|---|---|
| ADR-AI-001 | ARNA AI is a governed enterprise intelligence/orchestration layer, not a system of record | PROPOSED; consistent with inherited Finance boundary |
| ADR-AI-002 | Use a logical control-plane architecture; do not prematurely mandate microservices | PROPOSED |
| ADR-AI-003 | All model access occurs through a provider-neutral gateway | PROPOSED |
| ADR-AI-004 | All retrieval is tenant-, role-, entity-, domain- and confidentiality-aware | PROPOSED; required by inherited ARNA security principles |
| ADR-AI-005 | Agents are domain-scoped, versioned and tool-allowlisted | PROPOSED |
| ADR-AI-006 | Initial operational posture is read-only analysis and draft proposals | PROPOSED; aligns with Finance AI baseline |
| ADR-AI-007 | Consequential actions require the owning domain's authorization and applicable human approval | INHERITED / PROPOSED generalization |
| ADR-AI-008 | Amharic-first and English-supported; no unverified Maale capability claim | OWNER DIRECTION / PROPOSED detailed contract |
| ADR-AI-009 | Provider egress and model activation remain disabled until separately approved | INHERITED Finance control / PROPOSED platform-wide rule |
| ADR-AI-010 | No IROKA runtime or identity dependency | OWNER DIRECTION |

## 16. A1 limitations and unresolved matters

- No A2 line-by-line API/App contract reconciliation has been performed under this package.
- The precise current implementation state of general AI core, agents, retrieval, provider adapters and AI UI remains NOT VERIFIED.
- The exact canonical repository path for the Owner-approved ARNA ESPPM A1 v1.2.0 source document was not located during this initial search; its approval is retained as an inherited Owner decision and must be linked during source-register completion.
- Existing roadmap material uses different phase numbering for intelligence (historical P4 versus the current Owner-directed P7 workstream). This package does not silently rewrite either baseline; phase mapping is an Owner review item.
- Provider, hosting, model, embedding model, vector store, retention, data residency and budget decisions remain open.
- Legal approval authority remains subject to the governing instruments and the existing fail-closed authority matrix.
- Language model quality for Amharic and Maale must be measured, not assumed.

## 17. A1 exit

A1 exits only when the Owner approves this exact document set and resolves or explicitly accepts the listed open decisions. Approval freezes architecture and requirements only. It does not authorize implementation, expenditure, model activation, merge or deployment.
