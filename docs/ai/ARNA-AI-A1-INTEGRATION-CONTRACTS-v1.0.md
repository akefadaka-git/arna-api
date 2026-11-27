# ARNA AI A1 — Integration Contracts

**Document ID:** ARNA-AI-A1-007  
**Version:** 1.0.0  
**Status:** PROPOSED CONTRACTS — OWNER APPROVAL PENDING

## 1. Integration objective

Integrate AI into the existing ARNA API and App without bypassing domain ownership, identity, authorization or audit. All contracts in this document are logical A1 contracts; exact routes, schemas and implementation compatibility require separately authorized A2 read-only reconciliation.

## 2. API integration boundary

The ARNA API remains the authoritative business boundary for:
- authentication and identity context;
- tenant membership and authorization;
- legal-entity and record scope;
- domain validation and lifecycle;
- persistence and transaction integrity;
- audit and security events;
- approval and execution.

AI components must not write directly to application tables or domain databases. AI tool calls must invoke a domain-owned API/service that re-checks authorization and invariants.

## 3. Logical API capabilities

| Capability | Logical operation | Contract requirements |
|---|---|---|
| AI conversation/task | Submit a user request | Authenticated actor, locale, request ID, tenant context derived server-side |
| AI status | Retrieve task state | Actor and tenant scope checked |
| Knowledge search | Search approved sources | Classification, source scope and permission filters enforced |
| Agent catalogue | List enabled agents | Only agents visible to current user/application |
| Proposal review | Retrieve/approve/reject proposal | Owning domain authority, separation of duties, version check |
| Tool execution | Execute approved proposal | Fresh authorization, idempotency, current-state validation |
| AI audit | Inspect authorized history | Strict audit permission and data minimization |
| Usage/limits | Retrieve usage and quota | Tenant/user scope; no secret/provider credential exposure |

These are capability names, not approved URL paths. No new route family is authorized by A1.

## 4. Request envelope (logical)

Required request attributes:
- request ID / correlation ID;
- authenticated actor from server context;
- user-selected locale (default Amharic);
- task intent and message;
- optional referenced ARNA record/document IDs;
- explicit user-confirmed action intent where relevant;
- client metadata needed for diagnostics, excluding secrets.

Tenant ID, legal entity, roles and permissions must be derived or validated server-side. A client-supplied value is a selector at most, never proof of authorization.

## 5. Response envelope (logical)

A response should provide:
- request ID and task state;
- answer or proposal payload;
- locale;
- source citations with source ID/version and permitted locator;
- factual/inference/forecast/draft labels;
- confidence or uncertainty and limitations;
- refusal/abstention reason where applicable;
- required next approval, if the task is a proposal;
- model/agent metadata exposed according to policy.

Never expose provider secrets, hidden system prompts, internal credentials or another user's context.

## 6. Domain tool contract

Every tool must declare:
- tool ID/version and owning domain;
- purpose and denied use cases;
- typed input/output schema;
- required permissions and scope;
- idempotency and concurrency semantics;
- data classification and egress implications;
- approval requirements;
- audit fields;
- timeout, retry and error behavior.

A model cannot invent tool names or call unregistered tools. Tool registry updates require controlled review and versioning.

## 7. Domain integration map

| Domain | AI interaction | Authority boundary |
|---|---|---|
| Finance | Read reports/ledger outputs; analyze; draft proposals | Finance owns ledger and deterministic posting; AI cannot post/pay/close |
| Governance | Retrieve approved records; prepare meeting packs and drafts | Governance and competent bodies own formal records/authority |
| Strategy / Performance | Analyze approved plans and KPIs | Existing D01/D02 and corporate approval policy remain authoritative |
| People / Organization | Scoped workforce and structure analysis | People/Organization own records; sensitive access is restricted |
| Documents / Files | Read authorized files; OCR and metadata proposals | Documents/source domain owns original and approval lifecycle |
| Communication | Summarize and draft correspondence | No external send/publish without explicit approval |
| Calendar / D08 Tasks | Analyze schedules; prepare draft changes | Calendar owns events; D08 owns tasks; Governance owns formal meetings |
| Other domains | Agent-specific read and proposal workflows | Each domain owns its own records and authorization |

## 8. Model Gateway contract

The gateway should abstract:
- provider adapter and endpoint allowlist;
- model identifier and version;
- supported language/task capability;
- request/response schema;
- timeout, retry and rate limit;
- token/context limits;
- usage and cost metadata;
- data classification allowed for the provider;
- region, retention and training-use settings;
- safety/output validation and error mapping.

No provider or model is selected in A1. No provider key, endpoint, subscription or egress is activated.

## 9. App integration contract

The App presents:
- Amharic-first AI entry points;
- explicit agent/task context;
- source citations and evidence preview;
- draft/proposal review and approval states;
- uncertainty, limitation and stale-source warnings;
- accessible feedback, retry and escalation;
- clear distinction between AI draft and authoritative record.

The App must not decide whether a user is authorized. It must not hide an approval step or imply that a proposal has been executed.

Existing ARNA GUI direction requires Amharic/English, Noto Sans Ethiopic-first typography for Amharic, WCAG 2.2 AA, responsive layouts and shared theme/shell contracts. AI-specific UI must reuse these rather than introduce parallel design systems.

## 10. Identity / SSO caveat

The inspected cross-subdomain SSO contract identifies app and portal as the currently stateful cookie-authenticated SPA hosts. The AI subdomain is present in the CORS allow-list but is not thereby an SSO-enabled host. Do not assume that \`ai.maalearna.com.et\` can authenticate through the shared cookie. A separate approved identity/host decision and implementation verification are required.

## 11. Compatibility and versioning

- Preserve existing ARNA API contracts unless a separately approved change is made.
- Add versioned AI contracts without breaking existing domain clients.
- Version agent definitions, tool schemas, prompts/task templates and response schemas independently.
- Include deprecation windows and compatibility tests.
- Treat domain schema/API changes as domain-owned changes, not AI-only changes.

## 12. Failure handling

- Provider unavailable: return a controlled AI-unavailable response; core ARNA functions continue.
- Retrieval unavailable: do not fabricate; report unavailable evidence.
- Permission denied: disclose no protected metadata.
- Domain tool unavailable: retain proposal state and do not claim execution.
- Duplicate request: enforce idempotency and report existing result safely.
- Stale source/version: re-fetch or require user review.
- Unrecognized/unsafe request: abstain or escalate.

## 13. A2 reconciliation requirements

A2 must inspect exact API routes, middleware, service boundaries, auth and RBAC contracts, App routing/state, calendar/localization, file/document permissions, audit events, SSO host configuration and existing AI-related code. No route, schema, provider or App contract is declared implemented by this A1 document.
