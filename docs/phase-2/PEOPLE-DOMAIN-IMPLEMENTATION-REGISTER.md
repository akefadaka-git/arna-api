# ARNA People Domain — Phase 2 Implementation Register

**Status:** Phase 2.1–2.4 code expanded; structural reconciliation committed; runtime verification pending  
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

### Domain structure added in this increment
- Added `Config/people.php`, backed enums for contact/relationship/participation vocabulary, a profile repository contract and tenant-scoped Eloquent repository.
- Added a registered `PeopleServiceProvider`, a fail-closed tenant-aware `PersonPolicy`, FormRequests for contact/relationship/participation creation and record deactivation, and a profile JSON resource.
- Added Amharic and English translation catalogues, an explicit no-demo-PII `PeopleDomainSeeder`, and lifecycle events/listener wired to the platform audit service.
- Added a structural feature test for vocabulary stability, repository binding and bounded configuration. This test is committed but has not been run.

### Architecture alignment — domain ownership

- People now follows the same domain-owned layout used by Shareholder and Wallet: `Config/`, `Database/Migrations/`, `Database/Seeders/`, `Enums/`, `Events/`, `Http/Controllers/`, `Http/Requests/`, `Http/Resources/`, `Listeners/`, `Models/`, `Policies/`, `Providers/`, `Repositories/`, `Resources/lang/`, `Routes/api.php`, `Services/`, and `Tests/Feature/`.
- `PeopleServiceProvider` owns the config merge, repository binding, policy and audit-listener registration, domain migration discovery, and route mounting. The configured route contract is `api/people` with `api`, `auth:sanctum`, and `tenant.membership` middleware; per-action `people.manage` permissions remain on the route definitions.
- People controllers, eight People-owned migrations, the People seeder, and four People feature tests were moved under the domain. The platform-level `routes/api.php` no longer declares People endpoints; their public URI and route-name contract is retained in the domain route file.
- This was a path/ownership refactor, not a schema redesign or data operation. Existing migration filenames and migration contents were preserved. No local tests or CI workflows were run, per Owner instruction; runtime route discovery, fresh-install migration discovery, and the domain test suite remain **NOT RUN**.

### P2.5 — Lifecycle and records
- Integrate onboarding, training, credentials/qualifications, and review with Organization, Documents, Calendar, and Reporting through explicit contracts.
- Establish field-level authorization, retention, export auditing, and concurrency tests.

## Security invariants
- Tenant ID comes only from validated tenant middleware context, never from request input.
- Search cannot bypass active tenant grants.
- Natural-person identity, account credentials, membership, employment, ownership, and governance authority remain distinct.
- No production database operations, data backfill, deployment, or merge are included in this PR.
- Resolved the duplicate table-creation path by removing `create_people_extended_profile_tables.php` and retaining one migration per contact, relationship, and participation table. The individual migrations were aligned to the fields consumed by the contact controller/model.
- Updated API routes to use the dedicated contact, relationship and participation controllers; profile directory pagination now uses the registered repository contract.

## Acceptance evidence
- Integration tests cover contact encryption/masking, person-relationship tenant visibility/duplicate rejection/deactivation, participation lifecycle, and directory search.
- Per your instruction, no CI runs are being retried or investigated in this increment. Runtime and workflow verification remain **NOT RUN / UNKNOWN** until existing evidence is reviewed separately.
- Verification flow integration, complete service-layer extraction for each lifecycle, resource adoption by all controllers, field-level privacy policies, seeder orchestration, and broader audit/retention requirements remain open; this increment is not production-certified.
