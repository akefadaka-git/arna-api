# ARNA Finance A3 Implementation Baseline

**Status:** A3.1 implementation-control baseline  
**Owner-approved architecture:** ARNA Finance A1 (12 November 2026)  
**Source:** A2 read-only reconciliation (12 November 2026)  
**Repository:** `akefadaka-git/arna-api`  
**Base:** `main` at `779ef612589c32d81c68faad091032043ba78652`  
**Branch:** `feat/arna-finance-a3-current-main-foundation`

## 1. Scope and authority

This is a fresh implementation lineage created from the verified API `main` head, not a stale Finance feature branch. A3 implementation is authorized on dedicated branches. Merge, deployment, production migration execution, production financial transactions, and autonomous AI financial execution remain separately gated and are not authorized.

No historical Finance branch is to be merged wholesale. Historical code or test evidence may be selectively reused only after file-level comparison against current `main`, dependency review, and exact-head verification.

## 2. Binding architecture decisions

### ADR-FIN-001 — One authoritative ledger
Finance owns the authoritative general ledger, chart of accounts, accounting periods, journal lifecycle, and accounting audit trail. Operational domains remain authoritative for their business transactions. They publish versioned accounting events; Finance resolves source ownership, tenant, legal entity, account mapping, and idempotency before posting. A source event is financially posted only after the Finance transaction commits. Finance must not duplicate operational subledgers or trust caller-supplied entity/account identity without server-side resolution.

### ADR-FIN-002 — Deterministic accounting boundary
Posting eligibility, balancing, currency arithmetic, period controls, and ledger transitions remain deterministic. AI may investigate, classify, forecast, detect anomalies, and draft proposals. AI cannot independently post/reverse journals, execute payments, alter accounting policy, approve its own proposal, or close periods. AI proposals must be versioned, attributable, evidence-linked, and subject to the same authorization and validation controls as human-originated proposals.

### ADR-FIN-003 — API compatibility
Existing `/api/finance` routes remain the compatibility baseline. Do not introduce a parallel `/api/v1/finance` family until API versioning and client migration are explicitly decided. New endpoints must use existing authentication, tenant context, legal-entity authorization, policy checks, validation, and response conventions.

### ADR-FIN-004 — Ethiopia-first calendar
Reuse the canonical Core Ethiopian calendar conversion and localization mechanism; do not create a second Finance calendar engine. Persist unambiguous canonical dates/timestamps and expose E.C./G.C. representations explicitly with calendar identifiers and timezone context. Fiscal-year and period rules are configurable per legal entity and approved accounting policy. E.C. display must not silently reinterpret stored Gregorian dates.

### ADR-FIN-005 — Currency and precision
ETB remains the default where approved as the entity's base currency. Use decimal-safe or integer-minor-unit arithmetic, never binary floating point. Keep transaction, functional/base, and reporting currency distinct. Foreign-currency posting, realized/unrealized FX, translation, and consolidation require explicit rate source, effective time, approval, rounding, and audit rules. Manual FX proposal support is not completed FX accounting.

### ADR-FIN-006 — Multi-entity isolation and consolidation
Every journal and line is tenant- and legal-entity-scoped; a journal cannot mix legal entities. Entity books remain independently balanced and reportable. Consolidation is derived reporting, not a cross-entity posting shortcut. Consolidated output must disclose FX translation, intercompany eliminations, ownership/minority interest, tax, and adjustment status. Unadjusted aggregation must be labelled unadjusted.

### ADR-FIN-007 — Event delivery and subledger integration
Use transactional outbox publication where available. Finance consumers must be idempotent and replay-safe. Require source event ID/version, tenant, legal entity, event type, transaction/effective date, amount, currency, counterparty/reference, evidence reference, and idempotency key. Resolve source identity and entity ownership server-side. Unmapped/failed events are quarantined for controlled resolution; never silently drop, guess, or post to suspense without approved policy. Reconcile source-domain totals/status with Finance outcomes.

### ADR-FIN-008 — Evidence-first audit
Preserve source record/event/version, journal and line references, actor, authorization decision, policy version, timestamps, and correction/reversal lineage. AI conclusions additionally require model/version, task-template version, evidence references, retrieval freshness, confidence/uncertainty, limitations, and proposal/approval/execution lineage. Model-generated explanations are not source evidence.

### ADR-FIN-009 — Hybrid deployment
Keep domain logic and accounting invariants provider-independent. AI, OCR, bank, payment, and FX providers sit behind adapters with data-minimization and egress controls. Self-hosted mode supports local deterministic Finance operation and controlled AI-provider configuration. Offline commands remain attributable and idempotent; offline mode cannot bypass authorization or period controls.

## 3. A2 current-main facts

The A2 inspection found the Finance domain under `mases_core/src/Domains/Finance/`, with legal entities, accounts, periods, journals/lines, posting/reversal, trial balance, source-reference validation, and audit lifecycle components. CoA v1.0.0 documentation and a 79-account opt-in seed catalogue exist. Existing API prefix is `/api/finance`. Source-reference mapping is limited and is not a complete operational posting-adapter framework. Foreign-currency journal posting and cross-currency consolidation are incomplete. Consolidation output is explicitly unadjusted. A dedicated Finance App workspace and Finance AI agent/orchestration layer were not found on inspected `main` heads. App `main` at A2: `76fb67a421b1a54e0529a3479e551a1d1e0c0d0f`.

These are source-inspection findings, not production runtime verification.

## 4. A3 implementation packages and exit criteria

### A3.1 — Current-main lineage
- [x] Create dedicated branch from verified API `main`.
- [ ] Complete exact source/test inventory and branch dependency map.
- [ ] Establish a fresh PR path without merging stale branches.
**Exit:** reproducible current-main lineage and no unresolved source-of-truth ambiguity for the next package.

### A3.2 — Ledger/control hardening
Verify DB constraints and transaction invariants for journals, lines, periods, account eligibility, reversals, idempotency, tenant/entity isolation, posted-journal immutability, audit lifecycle, maker-checker, exact decimal arithmetic, and concurrency. **Exit:** current-head positive, negative, retry, concurrency, and cross-tenant regression tests pass.

### A3.3 — Ethiopia-native accounting
Integrate canonical Core E.C. conversion into Finance period/report contracts; add explicit calendar identifiers and safe E.C./G.C. serialization; define entity fiscal calendars and boundary/leap-year tests. **Exit:** deterministic conversion/period tests pass without a duplicate calendar engine.

### A3.4 — Multi-currency/consolidation
Define transaction/functional/presentation currency; implement approved FX provenance, valuation, realized/unrealized FX and rounding; implement translation and intercompany elimination only after policy approval. **Exit:** foreign-currency journals and consolidated reports reconcile to independently calculated fixtures; incomplete output remains labelled.

### A3.5 — Operational subledger adapters
Define versioned posting-event contract and adapter registry; implement source ownership/entity resolution; add outbox/inbox, idempotency, replay, retry, quarantine and reconciliation; onboard each source domain only against its own contract. **Exit:** duplicate, out-of-order, replayed, malformed, unauthorized, and cross-entity events fail safely or reconcile deterministically.

### A3.6 — ARNA AI Finance
Implement intelligence gateway separated from accounting engine; start with read-only evidence retrieval and proposal generation; define agent identities/scopes/tool allowlists/approval policies/audit; persist evidence-backed conclusions and proposal lifecycle; require explicit human authorization for financial execution. **Exit:** adversarial tests prove AI cannot bypass tenant/entity scope, fabricate evidence references, self-approve, or execute disallowed tools.

### A3.7 — Finance App
Establish typed API contracts from actual backend routes; build Amharic-first/mobile-first workspace and E.C./G.C. display; implement role-aware ledger, journals, periods, reports, reconciliation, and AI proposal review; test accessibility and API integration. **Exit:** App tests pass against verified backend contracts.

### A3.8 — Enterprise controls/hybrid readiness
Add reconciliation/observability; define backup/restore, key management, secrets, retention, data residency, provider egress; verify cloud and self-hosted profiles; complete independent security and accounting-policy review. **Exit:** reproducible deployment/recovery evidence, threat-model closure, and independent review. Deployment remains A5.

## 5. Historical branch/PR handling

A2 found multiple Finance branches substantially diverged from current `main`, including Phase 0–3, CoA/GL integrity, financial statements, production readiness, and migration-hardening work. Do not wholesale merge, delete, or force-update historical branches during A3. Reuse changes only after exact file/behavior comparison. Historical workflow evidence remains tied to its original SHA and cannot verify a new head. Any PR must state exact base/head SHA, file scope, test evidence, migration impact, and unresolved risks.

## 6. Cross-cutting acceptance gates

- Every posted journal has equal debits and credits.
- No cross-tenant or cross-legal-entity access.
- No duplicate posting under retry/event replay.
- No unauthorized posting, reversal, period mutation, or approval.
- No posted-journal mutation outside controlled correction/reversal.
- No binary floating-point money arithmetic.
- Material financial changes/conclusions have source-linked audit evidence.
- AI cannot autonomously execute a financial action.
- E.C./G.C. conversion is explicit, deterministic, and tested.
- Incomplete FX/consolidation output is disclosed.
- Current-head tests, not historical branch tests, are acceptance evidence.

## 7. Governance boundary

Merge: **NOT AUTHORIZED**  
Production migration execution: **NOT AUTHORIZED**  
Deployment: **NOT AUTHORIZED**  
Autonomous AI financial execution: **NOT AUTHORIZED**  
A4 independent verification: **NOT STARTED**

A3 packages require exact-head review and verification. A5 deployment requires separate Owner approval.
