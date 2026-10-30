# Organization Domain — Approved Implementation Contract

**Decision authority:** Owner authorization recorded in ARNA OS conversation on 2026-10-28.  
**Status:** Implementation contract approved for bounded implementation; production acceptance remains evidence-gated.

## Approved decisions

- **ORG-D1 — Scope:** Each organizational unit belongs to exactly one tenant. Cross-tenant sharing is prohibited; future cross-tenant relationships require a separate contract.
- **ORG-D2 — Legal entity:** Organization may reference a legal-entity identifier but does not become the authority for accounting registration, posting, or consolidation policy. Finance remains authoritative for accounting behavior.
- **ORG-D3 — Taxonomy:** Initial structural types are `company`, `division`, `branch`, `department`, and `team`. Parent-child compatibility must be validated in a central policy, not scattered through controllers. A company is a root; division is under company; branch is under company or division; department is under company, division, or branch; team is under department. This taxonomy must be configurable through a reviewed policy change, not user-supplied arbitrary types.
- **ORG-D4 — Assignments:** A person may hold multiple concurrent organizational assignments. Each assignment is explicit, effective-dated, status-bearing, and linked to the canonical People domain. Assignments do not create user accounts or imply employment, ownership, governance authority, or permissions.
- **ORG-D4a — Assignment query contract:** Assignment lists may be filtered by lifecycle status (`scheduled`, `active`, `ended`, `cancelled`), canonical `person_id`, and `effective_on`. Effective-date filtering is inclusive at both ends (`starts_on <= effective_on` and `ends_on IS NULL OR ends_on >= effective_on`); all assignment rows remain constrained to the resolved tenant and requested unit. Creation requires active People tenant access; revocation preserves the Organization assignment record but removes the revoked person's profile fields from the assignment response.
- **ORG-D4b — Assignment classifications:** Creation accepts exactly `primary`, `acting`, `delegated`, and `support`, matching the app contract. These are operational placement classifications only; `acting` and `delegated` do not create or imply Governance authority.
- **ORG-D5 — Hierarchy/lifecycle:** Reject self-parenting, cycles, cross-tenant parents, invalid parent-type combinations, and unsafe mutations that strand active descendants or assignments. Deactivation is reasoned and audited; referenced units are not destructively deleted.
- **ORG-D6 — Governance:** Governance remains authoritative for governance bodies, positions, terms, appointments, memberships, seats, occupancies, acting assignments, and authority grants. Links to organizational units must be explicit.
- **ORG-D7 — Access:** Unit membership is not authorization. Every endpoint requires authenticated tenant membership plus explicit action-level authorization; unresolved or conflicting tenant context fails closed.
- **ORG-D8 — Localization:** Store stable machine identifiers and type codes separately from localized labels. Amharic is the primary display locale with English fallback. Canonical translations must be maintained centrally.

## Implementation boundaries

1. Do not create a second person/account identity registry.
2. Do not migrate Governance bodies into Organization.
3. Do not infer legal entity, finance ownership, consolidation, role, or permission from a unit's hierarchy.
4. Do not perform production database operations, data backfills, or deployment in this gate.
5. Preserve existing code and unrelated branches; target destination repository `akefadaka-git/arna-api`.

## Required production acceptance

- Tenant A cannot read, enumerate, search, mutate, or infer Tenant B units or assignments.
- Missing, inactive, conflicting, or revoked tenant context fails closed.
- Hierarchy cycles, invalid type edges, self-parenting, and cross-tenant parent references are rejected, including concurrent mutation cases.
- Person assignments reference canonical People records and support multiple effective-dated assignments without implicit authorization.
- Reparenting/deactivation checks active descendants and assignments, requires a reason, and records actor, timestamp, and history.
- Stable identifiers and uniqueness/FK constraints are verified against the supported production MySQL engine.
- Fresh disposable MySQL migration + seed, repeat migration, API tests, and negative tenant tests pass on exact candidate SHAs. App contract/type/build checks are required when an app consumer exists or is changed in the same release.
- The pull request is reviewed and merged only after required checks pass. The issue/gate must not be closed while any acceptance evidence is missing or failing.

## Gate disposition

The Owner has authorized implementation. This document records the bounded decisions; it does **not** itself establish production readiness. Production acceptance remains pending until implementation, automated verification, and review evidence are attached to the implementation pull request.


## Cross-repository integration boundary — updated 2026-10-30

- The Organization app consumer exists in `akefadaka-git/arna-app` PR #6 under `src/features/organization/`; the previous PR #25 assessment is obsolete.
- API PR #49 is the active API implementation candidate. App PR #6 remains a separate, open, unmerged consumer candidate.
- The App feature owns Organization views and typed API contracts. Shared router/navigation files remain composition points only.
- App assignment type values are `primary`, `acting`, `delegated`, and `support`; the API accepts the same closed set.
- App runtime, type-check, build, and live API integration have not been verified by this implementation pass.
