# ARNA AI A1 — Approved Decision Reconciliation Register

**Document ID:** ARNA-AI-A1-002  
**Version:** 1.0.0  
**Status:** A1 OWNER-APPROVED — v1.0.0 frozen; inherited decisions remain intact

This register distinguishes inherited Owner-approved ARNA decisions from proposed ARNA AI decisions, implementation observations and unresolved authority. Existing source documents remain authoritative.

## 1. Inherited Owner-approved decisions

| ID | Existing decision | Status / scope | ARNA AI consequence |
|---|---|---|---|
| DEC-01 | ARNA is the master name for the wider enterprise platform; MASES remains valid in historical/technical contexts; IROKA is separate | OWNER-DIRECTED | ARNA AI is an ARNA capability. No IROKA dependency or brand merger |
| DEC-02 | Integrated Unified ARNA Enterprise OS with 30 business domains D01-D30 | OWNER-APPROVED; ARNA ESPPM A1 v1.2.0, approved 2026-11-24; historical v1.0 24-domain baseline preserved and v1.1 amendment preserved | AI agents must map to domain ownership and must not invent a parallel domain taxonomy |
| DEC-03 | Domain-owned authoritative records/business decisions; shared capabilities cannot compete as sources of truth | OWNER-APPROVED architecture direction, ARNA ESPPM A1 v1.2.0 | AI knowledge indexes and generated content are derived, not authoritative |
| DEC-04 | API owns rules, records, persistence, tenant isolation and authorization; App owns workflows, navigation and presentation | OWNER-APPROVED architecture direction, ARNA ESPPM A1 v1.2.0 | AI permissions and tool execution must be enforced by API; App presents the review/approval workflow |
| DEC-05 | D01 coordinates enterprise strategy; D02 owns performance measurement; General Meeting is ultimate authority for corporate strategic direction; Board governs/oversees; CEO executes approved strategy | OWNER-APPROVED architecture direction; legal/bylaws reconciliation remains separate | Executive/strategy agent may summarize and analyze but cannot create strategy authority or approve strategic changes |
| DEC-06 | Corporate Strategy Approval and Change-Control Policy: multi-year strategy approved by General Meeting; annual strategic plan/budget by Board; major strategic changes by General Meeting; documented versions, amendments, impact and traceability | OWNER-APPROVED architecture direction; legal/bylaws reconciliation remains separate | Strategy agent must identify required approval path and abstain if legal authority is unresolved |
| DEC-07 | ARNA Finance A1 v1.0 approved 2026-11-12: Finance owns authoritative accounting; domains own operations; ARNA AI is intelligence/orchestration only | OWNER-APPROVED | AI never becomes a ledger, subledger or transaction authority |
| DEC-08 | Finance deterministic posting, one authoritative ledger, entity isolation, maker-checker, explicit approvals for posting/payment/period close/CoA, immutable audit, evidence-first AI lineage | OWNER-APPROVED Finance A1; detailed ADRs recorded in Finance A3 implementation baseline | Finance agent read-only first; proposals require human review; no autonomous financial execution |
| DEC-09 | Ethiopian-first localization; E.C. default with G.C. support; ETB default where approved per entity; multi-currency and independent legal-entity books | OWNER-APPROVED in relevant ARNA and Finance baselines | Language and temporal handling must be canonical; never infer financial currency or accounting treatment |
| DEC-10 | Calendar A1 freeze record ARNA-CAL-A1-FRZ-001 v1.0: D08 owns task lifecycle; shared appointment capability; Governance owns formal GA/Board/committee records; AI may analyze/recommend/prepare but needs user permission and applicable approval for consequential changes | OWNER-APPROVED; frozen A1 record | Calendar/task/meeting agent uses domain APIs and preserves record ownership |
| DEC-11 | Current API main RBAC schema is the authoritative starting point for fresh-install target: roles, permissions, user_has_roles, user_has_permissions, role_has_permissions | OWNER-APPROVED architecture direction | AI must reuse existing identity/RBAC; do not reconstruct a historical RBAC model or create parallel roles |
| DEC-12 | Approved cookie-based Sanctum SSO Workspace + Shareholder Portal; existing cross-subdomain SSO contract limits stateful hosts to app and portal | OWNER-APPROVED contract; current contract text | ai.maalearna.com.et appears in CORS allow-list but is not thereby SSO-enabled or a stateful SPA; no AI-host authentication assumption |
| DEC-13 | Governance provisional control matrix: segregation of duties, evidence retention, bounded delegation, audit, tenant scope and deny unverified authority; actual business approver assignments remain unresolved pending source instruments | OWNER-APPROVED control principles; legal authority unresolved | AI must not invent approvers, limits, quorum or signing authority |
| DEC-14 | A1/A2/A3/A4/A5 are separately controlled; A2 read-only, A3 implementation, A4 verification, A5 deployment requires separate approval | OWNER-GOVERNED delivery rule | This package is A1 documentation only; later gates remain separate |
| DEC-15 | No paid service expenditure, deposits or top-ups without explicit approval; prefer public/manual workflows where applicable | OWNER CONSTRAINT | No provider selection, paid subscription, model spend or billing commitment in A1 |

## 2. Repository evidence inspected

The following records were read from the default branches during this A1 preparation. These are repository observations, not a substitute for a separately authorized full A2 reconciliation.

| Repository record | Observed evidence | Interpretation |
|---|---|---|
| \`docs/finance/ARNA-FINANCE-A3-IMPLEMENTATION-BASELINE.md\` | Finance ADR-FIN-001..009; AI Finance A3.6 read-only evidence retrieval/proposals; explicit human financial authorization | Strong inherited boundary for Finance AI |
| \`docs/finance/ARNA-FINANCE-A3.8-HYBRID-CONTROLS-BASELINE.md\` | AI provider disabled by default; no provider secret, prompt egress or model call active; no AI posting/payment/reversal/period-close route | Provider activation and egress remain gated |
| \`docs/calendar/ARNA_CALENDAR_A1_FREEZE_RECORD.md\` | Owner decisions D01-D03; domain boundaries; ARNA AI may recommend and prepare, not perform consequential changes without permission/approval | Binding Calendar integration boundary |
| \`docs/architecture/ARNA-CROSS-SUBDOMAIN-SSO-CONTRACT.md\` | app and portal are stateful; nine-host CORS allow-list includes ai subdomain; only app/portal are cookie-authenticated SPA hosts | CORS must not be mistaken for SSO approval |
| \`docs/PHASE_1_GATE_REPORT.md\` | Core platform includes tenant context, RBAC, audit, security events, Amharic-first localization and Ethiopian calendar | Foundational capability evidence; historical report, not proof of current-head state |
| \`docs/governance/document-approval-authority-matrix.md\` | Provisional controls approved; business approver assignments remain blocked where no source instrument is evidenced | Fail-closed governance requirement |
| \`docs/operations/ARNA-OS-OVERALL-PROGRESS.md\` | Historical roadmap labels AI/intelligence as P4 and estimates early maturity | Phase naming and maturity estimate are historical planning evidence, not current implementation verification |
| \`docs/ARNA_GUI_DESIGN_SPEC_v1.1.md\` in App | Amharic/English, Noto Sans Ethiopic, WCAG 2.2 AA, responsive and shell contracts | App design constraints; AI-specific UI requires separate A2 validation |

## 3. Existing Finance ADRs inherited

The following Finance ADRs must remain intact:

- ADR-FIN-001 — one authoritative ledger.
- ADR-FIN-002 — deterministic accounting boundary; AI may investigate/classify/forecast/detect anomalies/draft proposals, but cannot post/reverse journals, execute payments, alter accounting policy, self-approve or close periods.
- ADR-FIN-003 — API compatibility; existing \`/api/finance\` remains baseline.
- ADR-FIN-004 — reuse canonical Core Ethiopian calendar; persist unambiguous dates/timestamps and expose explicit E.C./G.C. representations.
- ADR-FIN-005 — decimal-safe money arithmetic and explicit currency/rate/rounding provenance.
- ADR-FIN-006 — tenant/legal-entity isolation and consolidation as derived reporting.
- ADR-FIN-007 — transactional outbox, idempotency, replay safety and controlled quarantine.
- ADR-FIN-008 — evidence-first audit; AI lineage includes model/version, task-template version, evidence, retrieval freshness, uncertainty and proposal/approval/execution lineage.
- ADR-FIN-009 — hybrid/provider-independent domain logic; provider adapters and data-egress controls.

## 4. New ARNA AI decisions requiring approval

| ID | Proposed decision | Why needed | Status |
|---|---|---|---|
| PROP-01 | Approve AI Control Plane as logical architecture boundary | Prevents provider and agent logic from leaking into domains | APPROVED — Owner decision 2026-11-27 |
| PROP-02 | Approve provider-neutral Model Gateway | Supports provider substitution and controlled egress | APPROVED — Owner decision 2026-11-27 |
| PROP-03 | Approve permission-aware Knowledge/RAG architecture | Prevents cross-tenant, cross-entity and confidentiality leakage | APPROVED — Owner decision 2026-11-27 |
| PROP-04 | Approve nine-agent catalogue as initial target portfolio | Establishes accountable capabilities and scope | APPROVED — Owner decision 2026-11-27 |
| PROP-05 | Approve L0-L3 autonomy model; L4 disabled/reserved | Makes permitted autonomy explicit | APPROVED — Owner decision 2026-11-27 |
| PROP-06 | Approve initial read-only and proposal-only operating posture | Reduces risk while evidence and controls mature | APPROVED — Owner decision 2026-11-27 |
| PROP-07 | Approve Amharic-first/English-supported AI product contract | Makes language a foundational capability | APPROVED — Owner decision 2026-11-27 |
| PROP-08 | Approve AI provider egress disabled until explicit provider/data review | Protects confidential information and avoids spend commitments | APPROVED — Owner decision 2026-11-27 |
| PROP-09 | Approve AI audit/evaluation metadata requirements | Enables traceability and quality control | APPROVED — Owner decision 2026-11-27 |
| PROP-10 | Confirm phase mapping: Owner-directed P7 Intelligence versus historical repository P4 intelligence label | Prevents roadmap reporting ambiguity | DEFERRED — preserve P7/P4 discrepancy; no mapping decision supplied |

## 5. Conflicts and unresolved matters

### 5.1 Phase nomenclature
Current Owner-directed programme context identifies P7 as Intelligence/AI. The repository's older \`ARNA-OS-OVERALL-PROGRESS.md\` describes AI/intelligence as P4 within a P0-P5 planning frame. Treat these as different roadmap taxonomies until the Owner explicitly maps or supersedes one. Do not rewrite the older record.

### 5.2 Domain strategy source reference
Owner-approved ARNA ESPPM A1 v1.2.0 (30 domains) is inherited. The exact canonical path/blob for the original approved specification was not located by the initial code search. Link it to this register during a later source-reference verification; do not fabricate a path or approval record.

### 5.3 Governance/legal authority
The architecture may enforce a fail-closed workflow, but it cannot determine legal authority without the governing instrument, adopted resolution or valid delegation. Keep unresolved approver assignments blocked.

### 5.4 SSO for AI host
The presence of \`ai.maalearna.com.et\` in CORS configuration does not make it a stateful SPA. AI host authentication, cookie SSO and production routing require a separate explicit decision and verified implementation contract.

### 5.5 Provider and data residency
No model provider, model, region, embedding service, vector store, retention period or budget is approved by this register.

## 6. A1 evidence limitations

- Repository access and selected document reads are verified.
- A full A2 branch/PR/schema/route/test reconciliation has not been performed.
- The latest commits visible during preparation were API \`7157ea014e5ccc9d7b6c54da182cb69c47c565b7\` (2026-11-27 nightly assessment) and App \`f694eb13ba822440c6842cb135a66f89218bc454\` (2026-11-25 Calendar fix). These are search-observed commits, not asserted as exact A2 baselines.
- No production runtime, provider account, secret, database or deployed environment was accessed.

## 7. Review outcome

On 2026-11-27 (G.C.), the Owner approved ARNA AI A1 v1.0.0 with no additional comments. PROP-01 through PROP-09 are approved. PROP-10 (mapping Owner-directed P7 Intelligence nomenclature to the historical repository P4 label) is deferred and remains unresolved. Existing inherited approvals are not reopened by this document.

The approved A1 baseline is package commit `7bfb2a95fe357091aecab6d7338c381c3ffc28c4`. The formal approval and freeze record is `ARNA-AI-A1-REVIEW-RECORD-v1.0.md`. A1 approval does not authorize A2, A3, A4 or A5; each remains subject to separate explicit Owner authorization.
