# ARNA AI — Architecture & Specification v1.0

**Programme:** ARNA Enterprise Intelligence  
**Document set:** ARNA-AI-A1  
**Version:** 1.0.0 (Owner-approved frozen A1 baseline)  
**Owner:** ARNA Owner  
**Stage:** A1 — assessment, architecture and specification  
**Decision status:** OWNER-APPROVED / FROZEN — 2026-11-27 (G.C.)  
**Language direction:** Amharic-first; English-supported  
**Repository authority:** \`akefadaka-git/arna-api\` (platform-wide documentation); companion application: \`akefadaka-git/arna-app\`

## Purpose

This directory contains the controlled A1 documentation package for the complete ARNA AI ecosystem. It defines an enterprise intelligence layer integrated with ARNA's existing platform and domain architecture. It is not an implementation specification approved for execution until the Owner records approval.

ARNA AI is distinct from IROKA. No IROKA dependency, shared identity, shared roadmap, or public brand linkage is implied.

## Document register

| ID | Document | Purpose | Status |
|---|---|---|---|
| AI-A1-001 | [Architecture & Specification](ARNA-AI-A1-ARCHITECTURE-SPECIFICATION-v1.0.md) | System context, layers, components, boundaries and architecture decisions | APPROVED / FROZEN |
| AI-A1-002 | [Approved Decision Reconciliation](ARNA-AI-A1-DECISION-REGISTER-v1.0.md) | Existing approved decisions, inherited constraints, conflicts and open decisions | APPROVED; PROP-10 DEFERRED |
| AI-A1-003 | [Agent Catalogue](ARNA-AI-A1-AGENT-CATALOGUE-v1.0.md) | Agent responsibilities, tools, sources, exclusions and acceptance | APPROVED / FROZEN |
| AI-A1-004 | [Knowledge & Data Governance](ARNA-AI-A1-KNOWLEDGE-DATA-GOVERNANCE-v1.0.md) | Knowledge classes, RAG, provenance, lifecycle and retrieval controls | APPROVED / FROZEN |
| AI-A1-005 | [Amharic-first Language Architecture](ARNA-AI-A1-LANGUAGE-ARCHITECTURE-v1.0.md) | Amharic, English, Ge'ez, dates, numerals, OCR and evaluation | APPROVED / FROZEN |
| AI-A1-006 | [Security, Governance & Autonomy](ARNA-AI-A1-SECURITY-GOVERNANCE-AUTONOMY-v1.0.md) | Identity, permissions, risk tiers, approvals, audit and threat controls | APPROVED / FROZEN |
| AI-A1-007 | [Integration Contracts](ARNA-AI-A1-INTEGRATION-CONTRACTS-v1.0.md) | API/App/domain/model/provider integration boundaries | APPROVED / FROZEN |
| AI-A1-008 | [Deployment & Observability](ARNA-AI-A1-DEPLOYMENT-OBSERVABILITY-v1.0.md) | Hybrid profiles, provider egress, operations, resilience and monitoring | APPROVED / FROZEN |
| AI-A1-009 | [Acceptance & Verification Plan](ARNA-AI-A1-ACCEPTANCE-VERIFICATION-v1.0.md) | A1 acceptance, future A2/A3/A4 gates and evidence requirements | APPROVED / FROZEN |
| AI-A1-010 | [A1 Review Record](ARNA-AI-A1-REVIEW-RECORD-v1.0.md) | Owner decisions, approval evidence, freeze and change control | APPROVED / FROZEN |

## Status vocabulary

- **APPROVED / FROZEN:** explicit Owner approval recorded against this exact version.
- **INHERITED — APPROVED:** an existing approved ARNA decision constrains this design; its original record remains authoritative.
- **PROPOSED:** a new ARNA AI design choice awaiting Owner approval.
- **UNRESOLVED:** evidence or authority is insufficient; fail closed.
- **NOT VERIFIED:** repository/runtime evidence has not been inspected or tested.
- **NOT AUTHORIZED:** implementation, merge, production configuration or deployment is outside the current gate.

## Stage boundary

This package is A1 documentation only and was explicitly approved by the Owner on 2026-11-27 (G.C.). Approval freezes architecture and requirements; it does not authorize API/App code changes, migrations, model/provider activation, external data egress, paid services, agent execution, PR merge, production configuration, or deployment. A2 read-only repository reconciliation, A3 implementation, A4 verification and A5 deployment each require separate Owner authorization.

## Source-of-truth rules

1. Existing approved ARNA decisions remain authoritative in their original records.
2. This package must not rewrite, supersede or silently reinterpret those records.
3. Domain-owned business records remain authoritative in their owning domains.
4. The API is authoritative for business rules, records, persistence, tenant isolation and authorization; the App owns workflows, navigation and presentation.
5. A model output, vector index, prompt, generated summary or AI explanation is never an authoritative business record or source evidence.
6. Conflicting, missing or legally unverified authority is represented as unresolved and denied by default.

## Repository placement

The A1 package is centralized under \`docs/ai/\` in the API repository because it defines platform-wide architecture and cross-domain contracts. The App repository is a consumer of the approved contracts; no duplicate copy is created there in A1. App-specific implementation contracts will be reconciled during separately authorized A2.

## Change control

Preserve v1.0.0 as the review candidate. Owner-approved changes must create a new version or an explicitly numbered addendum with a decision record, rationale, impact analysis, affected documents, and approval evidence. Never edit a frozen version in place.


## Approval reference

- Owner decision: approve A1 v1.0.0; no additional comments (2026-11-27 G.C.).
- Approved package baseline: `7bfb2a95fe357091aecab6d7338c381c3ffc28c4`.
- Formal decision record: [ARNA-AI-A1-REVIEW-RECORD-v1.0.md](ARNA-AI-A1-REVIEW-RECORD-v1.0.md).
- Roadmap terminology item PROP-10 remains deferred; all other new A1 proposals are approved.
- A2–A5 remain unauthorized pending separate Owner decisions.
