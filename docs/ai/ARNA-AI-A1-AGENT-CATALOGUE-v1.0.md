# ARNA AI A1 — Enterprise Agent Catalogue

**Document ID:** ARNA-AI-A1-003  
**Version:** 1.0.0  
**Status:** PROPOSED PORTFOLIO — OWNER APPROVAL PENDING

## 1. Agent design rule

Agents are task-specialized, domain-scoped assistants, not independent authorities. Each agent must use the owning domain's approved API and permission model. No agent has direct database access, unrestricted network access, self-assigned tools or authority to approve its own work.

The catalogue defines the proposed initial portfolio. It does not claim these agents currently exist or are activated.

## 2. Initial agent portfolio

| ID | Agent | Accountable domain(s) | Initial capability | Initial autonomy ceiling |
|---|---|---|---|---|
| AG-01 | Executive Intelligence | D01 Strategy, D02 Performance, relevant domain owners | Evidence-backed executive summaries, strategic analysis, board/management briefing drafts | L2 |
| AG-02 | Finance Intelligence | Finance | Read-only ledger/report analysis, budget variance, anomaly signals, forecast drafts and evidence-backed proposals | L2 |
| AG-03 | Governance Intelligence | Governance, Shareholder, Organization | Search approved governance records, prepare meeting packs, resolution history and compliance checklists | L2 |
| AG-04 | People Intelligence | People, Organization | Authorized workforce summaries, workforce planning and policy Q&A | L2 |
| AG-05 | Document Intelligence | Documents/Files and source domains | OCR, extraction, classification, metadata suggestions and document search | L2 |
| AG-06 | Communication Intelligence | Communication, Documents and authorized domain context | Draft correspondence, summarize threads and assist translation | L2 |
| AG-07 | Operations Intelligence | Operational domain owners | Workflow monitoring, exception summaries, process analysis and recommendations | L2 |
| AG-08 | Analytics Intelligence | D02 Performance and domain owners | KPI explanations, trends, forecast/scenario drafts and data-quality alerts | L2 |
| AG-09 | Calendar & Planning Intelligence | Calendar, D08 Tasks, Governance where relevant | Schedule conflict analysis, meeting preparation, task-priority suggestions and draft actions | L2 |

L2 means the agent may prepare a recommendation or draft, not execute a consequential business action.

## 3. Common agent contract

Every agent definition must declare:

1. Agent ID, name, version and lifecycle state.
2. Accountable domain owner and operational owner.
3. Supported user roles and required permissions.
4. Tenant, legal-entity, department and record-level scope.
5. Approved knowledge sources and classification ceiling.
6. Tool allowlist with typed schemas and explicit denied operations.
7. Supported languages and evaluation coverage.
8. Model compatibility and task-template version.
9. Risk classification, autonomy ceiling and approval requirements.
10. Input/output validation, refusal and escalation behavior.
11. Rate, token, time and cost limits.
12. Audit fields, retention class and incident route.
13. Evaluation suite, release criteria and rollback state.

## 4. Agent-specific scope

### AG-01 Executive Intelligence
- Summarize approved strategy, performance and domain reports.
- Compare outcomes with approved objectives and targets.
- Draft executive briefs and decision options with source links.
- Distinguish General Meeting, Board and CEO responsibilities.
- Never approve strategy, budget, investment, appointment or reserved matter.

### AG-02 Finance Intelligence
- Read authorized financial statements, ledger reports, budgets and reconciliation outputs.
- Explain variances and identify unusual patterns for human investigation.
- Draft accounting/forecast analysis and evidence-linked proposals.
- Never post/reverse journals, execute payments, close periods, change CoA/accounting policy, approve a proposal or infer legal-entity authority.
- Preserve deterministic accounting, decimal precision and Finance ownership.

### AG-03 Governance Intelligence
- Retrieve approved bylaws, adopted resolutions, meeting records and authority instruments.
- Prepare agenda/minutes/resolution drafts and evidence checklists.
- Identify missing quorum, voting, appointment or authority evidence.
- Never declare a proposal legally adopted, certify minutes, assign approvers or infer legal powers from titles.

### AG-04 People Intelligence
- Answer authorized policy and workforce questions.
- Produce aggregated or role-scoped workforce analysis.
- Minimize personal data and apply record-level access.
- Never make final employment decisions, change personnel records or infer protected/sensitive attributes.

### AG-05 Document Intelligence
- Extract text, language, dates, parties, references and candidate metadata.
- Return confidence and page/region provenance where available.
- Preserve original document and human correction lineage.
- Never treat OCR output as verified legal or financial fact; never approve, sign or publish documents.

### AG-06 Communication Intelligence
- Draft emails, letters, notices and translations for human review.
- Summarize authorized correspondence and link source messages.
- Preserve confidentiality and audience restrictions.
- Never send, publish or disclose externally without explicit workflow permission and applicable approval.

### AG-07 Operations Intelligence
- Summarize operational status, backlog, exceptions and bottlenecks.
- Recommend process improvements based on traceable metrics.
- Never alter authoritative process state without domain authorization.

### AG-08 Analytics Intelligence
- Explain metric definitions, lineage, freshness and limitations.
- Generate descriptive and forecast drafts with assumptions and uncertainty.
- Never fabricate missing data or silently change KPI definitions/targets.
- Model-derived forecasts must be labelled as estimates, not actuals.

### AG-09 Calendar & Planning Intelligence
- Analyze availability and schedule conflicts.
- Prepare meeting briefs and proposed schedule/task changes.
- D08 remains task lifecycle owner; Calendar owns calendar events; Governance owns formal meeting records.
- Consequential creation, rescheduling or cancellation requires user permission and applicable approval controls.

## 5. Tool classes

| Tool class | Default posture | Example |
|---|---|---|
| Read authorized records | Allowed only after API authorization | Read a scoped financial report |
| Search approved knowledge | Allowed only with permission filters | Retrieve an adopted policy |
| Analyze/compute | Allowed on authorized inputs | Calculate variance from report data |
| Draft/propose | Allowed; output is non-authoritative | Draft a resolution or budget commentary |
| Reversible workflow | Disabled until A3 tool-specific approval | Save a draft task |
| Consequential mutation | Denied by default; explicit domain approval required | Change meeting or employee record |
| Financial execution | Prohibited for AI | Post journal or execute payment |
| Authority/security mutation | Prohibited for AI | Grant role or approve own proposal |
| External communication | Denied by default; explicit send approval | Send a letter to an external party |

## 6. Agent lifecycle

\`PROPOSED → REVIEWED → APPROVED → TESTED → ENABLED → SUSPENDED / RETIRED\`

- Only an authorized Owner/domain governance process may approve enablement.
- Evaluation failure, scope violation, policy change or security incident triggers suspension.
- Every release pins agent version, prompt/task template, tools, model adapter and evaluation report.
- Rollback must disable the affected agent/tool version without disabling core ARNA operations.

## 7. Portfolio acceptance

Before an agent is enabled:
- its domain owner and data sources are approved;
- permission and tenant/entity tests pass;
- every tool has a narrow schema and server-side authorization;
- unsupported and adversarial prompts are evaluated;
- Amharic and English quality is measured for supported tasks;
- citations and uncertainty are validated;
- audit and rate limits are verified;
- prohibited actions are proven inaccessible;
- Owner approval and any required domain approval are recorded.

## 8. Status

All nine agents are target capabilities only. No agent activation, production model connection or tool execution is authorized by this catalogue.
