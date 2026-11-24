# ARNA-ECC-A3-AUTH-001 — Owner Implementation Authorization

**Date:** 24 November 2026  
**Owner:** Akefa Ayele Daka  
**Parent plan:** [ARNA-ECC-A3-PLAN-001 v1.0.0](ARNA-ECC-A3-PLAN-001-v1.0.0.md)  
**PR:** #217  
**Decision:** **APPROVED — P0 ONLY**

## Owner authorization

The Owner's instruction, “Yes add and save,” following the recommendation to authorize P0 only and incorporate the controlled PR review clarifications, is recorded as authorization for A3.0–A3.3 only.

### Authorized work

| Work package | Scope |
|---|---|
| A3.0 | Execution baseline, live SHA reconciliation, branch/PR boundaries, baseline test evidence and preservation safeguards |
| A3.1 | KPI governance foundation, 30-domain readiness/ownership register, and KPI definitions only where authoritative sources and accountable owners exist |
| A3.2 | Shared versioned provider/data contract, status semantics, scope and provenance requirements |
| A3.3 | Bounded dashboard/Executive Overview authorization and security controls; investigate and remediate RISK-A4-01 only within an assessed and approved scope |

### P0 constraints

- Preserve A1 and A2 frozen baselines.
- Preserve canonical RBAC schema and independent widget permissions.
- No wildcard or broad permission grants.
- Preserve tenant and legal-entity boundaries.
- No unapproved financial consolidation.
- No fabricated or unsupported KPI definitions.
- No unrestricted platform-wide authorization rewrite.
- No paid-service or billing commitments.
- Preserve unrelated repository changes.

### Not authorized

A3.4–A3.8, P1/P2 domain-provider and dashboard expansion, merge to `main`, production database/configuration changes, A4 acceptance, and A5 deployment are not authorized by this decision. P1/P2 require a new explicit Owner decision after P0 handover evidence is reviewed. A4 and A5 remain separate gates.

## P0 handover requirements

Before requesting further authorization, submit:
1. Exact API/app base and head SHAs and affected files.
2. 30-domain readiness and ownership register.
3. KPI catalogue with source, formula, steward and approval status.
4. Versioned provider contract and compatibility notes.
5. RISK-A4-01 impact assessment and bounded remediation evidence.
6. Tests and workflow evidence.
7. Migration/rollback assessment where applicable.
8. PASS/PARTIAL/MISSING/UNRESOLVED/UNKNOWN/NOT RUN status per work item.
9. Remaining risks and recommendation for P1.

**Authorization status:** P0 IMPLEMENTATION AUTHORIZED. P1/P2 NOT AUTHORIZED. MERGE NOT AUTHORIZED. A4 NOT STARTED. A5 NOT AUTHORIZED.
