# ARNA People Domain — Phase 2 Implementation Register

**Status:** Phase 2.1–2.4 implementation increment submitted for review  
**Scope:** Destination repository `akefadaka-git/arna-api`  
**Base:** `main` at `e853874ee41130d47be07d2ffc097a41ecb69a9b`

## Phase boundaries

### P2.1 — Tenant-scoped directory search (this increment)
- Search the six existing Amharic/English name components on the server.
- Preserve active-person filtering and explicit active `person_tenant_accesses` filtering before search results are paginated.
- Validate `search` to 100 characters and `per_page` to 1–100.
- Keep the response allow-list and pagination envelope unchanged.
- Add API integration coverage for English and Amharic matching, empty results, bounds, and pagination.

### P2.2 — Contact points and profile completeness (implemented; verification integration pending)
- Added tenant-scoped contact-point storage with application-layer encryption, normalized lookup hash, primary designation, source, effective dates, and lifecycle state.
- Contact responses expose masked values only. New values remain unverified until an approved verification flow is integrated; no verification is inferred from entry.
- Primary designation is transactionally exclusive within the profile's active contact points.

### P2.3 — Relationships and household (implemented; policy refinement pending)
- Added tenant-scoped typed, dated person-to-person relationships, active duplicate rejection, and reason-required deactivation history.
- Both people must have active tenant visibility. Relationship data grants no identity, login, role, or legal authority.
- Sensitive household/minor field-level rules and reciprocal relationship semantics remain to be specified.

### P2.4 — Participation (implemented as a bounded record; domain integrations pending)
- Added tenant-scoped ministry, volunteer, committee, event-attendance, training, qualification, and generic participation records with effective dates and reason-required deactivation.
- These records do not grant organization membership, governance authority, permissions, or employment. Existing domain systems remain authoritative for those concepts.

### P2.5 — Lifecycle and records
- Integrate onboarding, training, credentials/qualifications, and review with Organization, Documents, Calendar, and Reporting through explicit contracts.
- Establish field-level authorization, retention, export auditing, and concurrency tests.

## Security invariants
- Tenant ID comes only from validated tenant middleware context, never from request input.
- Search cannot bypass active tenant grants.
- Natural-person identity, account credentials, membership, employment, ownership, and governance authority remain distinct.
- No production database operations, data backfill, deployment, or merge are included in this PR.

## Acceptance evidence
- Integration tests cover contact encryption/masking, person-relationship tenant visibility/duplicate rejection/deactivation, participation lifecycle, and directory search.
- Per your instruction, no CI runs are being retried or investigated in this increment. Runtime and workflow verification remain **NOT RUN / UNKNOWN** until existing evidence is reviewed separately.
- Verification flow integration, profile UI wiring, field-level privacy policies, and broader audit/retention requirements remain open; this increment is not production-certified.
