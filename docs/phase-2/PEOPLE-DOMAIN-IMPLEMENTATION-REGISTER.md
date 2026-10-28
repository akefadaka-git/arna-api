# ARNA People Domain — Phase 2 Implementation Register

**Status:** Phase 2.1 implementation increment submitted for CI review  
**Scope:** Destination repository `akefadaka-git/arna-api`  
**Base:** `main` at `e853874ee41130d47be07d2ffc097a41ecb69a9b`

## Phase boundaries

### P2.1 — Tenant-scoped directory search (this increment)
- Search the six existing Amharic/English name components on the server.
- Preserve active-person filtering and explicit active `person_tenant_accesses` filtering before search results are paginated.
- Validate `search` to 100 characters and `per_page` to 1–100.
- Keep the response allow-list and pagination envelope unchanged.
- Add API integration coverage for English and Amharic matching, empty results, bounds, and pagination.

### P2.2 — Contact points and profile completeness
- Add additive contact-point records only after reviewing existing user phone/email verification and privacy contracts.
- Separate contact value, verification state, primary designation, source, and effective dates.
- Never expose private contact values to directory endpoints by default.
- Define uniqueness and normalization rules before migration.

### P2.3 — Relationships and household
- Model typed, dated person-to-person relationships with tenant visibility and restricted minor/dependent data.
- Make corrections auditable; do not infer legal identity or grant permissions from relationships.

### P2.4 — Membership and participation
- Reuse existing membership and governance authorities; do not create a second role/authority source.
- Define separate ministry/volunteer participation records only after confirming no existing domain owns the behavior.

### P2.5 — Lifecycle and records
- Integrate onboarding, training, credentials/qualifications, and review with Organization, Documents, Calendar, and Reporting through explicit contracts.
- Establish field-level authorization, retention, export auditing, and concurrency tests.

## Security invariants
- Tenant ID comes only from validated tenant middleware context, never from request input.
- Search cannot bypass active tenant grants.
- Natural-person identity, account credentials, membership, employment, ownership, and governance authority remain distinct.
- No production database operations, data backfill, deployment, or merge are included in this PR.

## Acceptance evidence
- The new integration test is committed but must be reported as **NOT RUN** until the destination GitHub workflow reports its result.
- The phase is not production-certified until CI, route-contract review, and relevant security checks pass.
