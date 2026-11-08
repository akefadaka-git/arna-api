# ARNA OS — Unified API/App Integration Plan

**Plan version:** 1.0  
**Prepared:** 2026-11-08  
**Status:** Proposed integration baseline; A2 reconciliation plan  
**Authority:** Owner-governed; A3 implementation and each merge require a separate explicit authorization  
**Scope:** `akefadaka-git/arna-api` + `akefadaka-git/arna-app`

## 1. Immutable starting point

This plan is anchored to the following verified GitHub `main` refs at preparation time:

| Repository | Base branch | Exact base SHA |
|---|---|---|
| `akefadaka-git/arna-api` | `main` | `ff290b353f1290cc91534fd4d694544e83c58d82` |
| `akefadaka-git/arna-app` | `main` | `76fb67a421b1a54e0529a3479e551a1d1e0c0d0f` |

The plan was committed on an API documentation branch created from the exact API SHA above. The App SHA is a pinned cross-repository baseline, not a claim that the App repository was modified.

**Freshness rule:** before every implementation batch, fetch both live `main` refs again. If either differs from this baseline, stop integration, compare the new head against the pinned SHA, and record a new baseline/plan revision. Never silently rebase or change the source baseline.

## 2. Objectives and non-goals

### Objectives
1. Produce one canonical API contract and one matching App experience for each capability.
2. Reuse existing MASES/ARNA domain functionality; do not duplicate identity, Person, shareholder, wallet, loan, file, notification or governance registries.
3. Integrate changes in dependency order, with API migrations/contracts verified before App activation.
4. Preserve tenant isolation, least privilege, auditability, migration upgrade safety and backward compatibility.
5. Maintain an auditable exact-SHA record for every integration candidate, test run, decision and merge authorization.

### Non-goals
- No direct merge of stale/diverged feature branches into `main`.
- No automatic PR merging, branch deletion, production database change, production data migration or deployment under this plan.
- No payment, top-up, billing or paid-service prerequisite. Use existing free GitHub workflows and available verification environments; if a gate cannot run, mark it NOT RUN rather than requesting a deposit.
- No replacement of IROKA or coupling ARNA AI to IROKA.
- No new authentication, Person, file-storage, wallet or loan system where a canonical MASES/ARNA implementation already exists.

## 3. Integration method and branch topology

Create one controlled integration branch per repository from the exact current `main` SHA. API and App branches carry the same integration-wave identifier and are reviewed as a coordinated pair where a feature crosses the boundary.

Recommended branch names:
- API: `integration/arna-core-wave1-2026-11`
- App: `integration/arna-core-wave1-2026-11`

Each wave follows this sequence:
1. Re-fetch and record API/App `main` SHAs.
2. Build an API integration slice from selected source commits/patches; do not merge a PR branch wholesale unless its complete diff has been reconciled.
3. Resolve duplicate migrations, route registrations, service providers, permissions, seeders and tests in that slice.
4. Run API static, unit, feature, migration and security gates against the exact integration SHA.
5. Publish/update the API contract and fixtures consumed by the App.
6. Build the matching App slice; reconcile shared routes, stores, shells, labels and styles.
7. Run App typecheck, unit/contract, production build, accessibility and route authorization gates.
8. Run cross-repository contract and browser tests against the exact API/App SHA pair.
9. Produce an integration evidence record and request explicit Owner merge authorization for the specific PR pair/group.
10. Merge only after authorization and required reviews/checks; rebase/retest downstream groups after every mainline change.

Do not create a single mega-PR containing every domain. Keep one PR per independently reviewable integration wave, with explicit API/App dependencies. A cross-repo group is not complete until both PRs are merged or the Owner explicitly accepts a documented API-only stage.

## 4. Ordered integration waves

### Wave 0 — Baseline, governance and shared contracts

**Purpose:** freeze the exact inputs and prevent concurrent PRs from modifying shared contracts unpredictably.

Actions:
- Reconfirm the two base SHAs and all selected PR head SHAs.
- Establish one shared API route registration map, service-provider map, migration inventory, permission catalogue and App route registry.
- Confirm branch protection/rulesets for both `main` branches; prior census reported both as unprotected. Do not change settings without Owner authorization.
- Define a cross-repository compatibility rule: additive API changes first; App may not depend on an unmerged or unverified endpoint.
- Adopt exact-SHA verification workflows that do not mutate feature branches or push mainline changes.

Exit gates:
- Baseline file and migration inventory committed.
- Duplicate route/provider/permission owners named.
- No unresolved ambiguity about the source SHA for the first integration slice.

### Wave 1 — Identity, tenancy and SSO

**Source candidates:** API #65 (SSO), #85 (tenant/admin identity foundation), #84 (platform admin/RBAC); App #15 (SSO), #37 (tenant admin), #36 (platform admin).

**Order:**
1. Reconcile existing Auth, Sanctum, Authorization and Tenancy services.
2. Integrate API #65 browser-session SSO contract and middleware.
3. Integrate API #85 tenant membership/tenant administration.
4. Integrate API #84 platform-wide identity/RBAC as a distinct authority layer, after fixing the clean-install permission-count assertion (expected 57, actual 60).
5. Integrate App #15 SSO bootstrap and session handling.
6. Integrate App #37 and #36 into one canonical admin route registry; avoid duplicate `/admin/tenants` routes and conflicting guards.

Security requirements:
- Tenant context must be derived and validated server-side.
- Platform-admin and tenant-admin authority must remain distinct.
- Cookie domain, Secure, HttpOnly, SameSite, CORS, stateful domains, CSRF and session invalidation must be explicit.
- No client-supplied tenant ID may bypass server-side membership authorization.

Required gates:
- API authorization matrix (anonymous, member, tenant admin, platform admin, cross-tenant).
- MySQL clean install and existing-schema upgrade.
- Browser tests for Workspace ↔ Portal cross-subdomain login, CSRF, logout and session expiry.
- App typecheck/build and route-guard tests.
- Exact API/App SHA pair recorded.

Exit: identity/tenancy/SSO is the single shared authority for all subsequent waves.

### Wave 2 — File Management

**Source candidate:** API #51. The #53 integration branch contains a stale File/Organization base and must not be merged wholesale.

Actions:
- Preserve the existing `files` table and canonical `FileService`; relocate domain ownership without creating a second table or binary storage service.
- Reconcile file routes, provider registration, policies and permission catalogue once.
- Define file owner, tenant, visibility, version, retention and linked-record authorization semantics.
- Ensure Person-linked identity documents are not downloadable through a public route solely because `is_public` is true.
- Keep storage adapter details behind the canonical service.

Required gates:
- MySQL clean install and upgrade from the current schema.
- Cross-tenant access denial, public/private download policy, linked-Person authorization, version integrity and deletion/retention tests.
- Upload size/type validation, path traversal and content-disposition/security tests.
- App file-browser contract tests if an existing App file surface is modified.

Exit: one FileService and one authoritative `files` table with tenant-aware access control.

### Wave 3 — Organization

**Source candidates:** API #49; App #6.

Actions:
- Build the Organization domain around the existing `organization_units` schema and preserve migration identity.
- Define legal entity, organization unit, parent-child hierarchy, effective dates, status and assignment ownership.
- Enforce tenant-consistent parent-child relationships and prohibit cycles.
- Integrate Organization authorization with Wave 1 identity and Wave 2 Files.
- Repair App #6 failed frontend verification before integrating.

Required gates:
- MySQL clean install and existing-schema upgrade.
- Parent-cycle, cross-tenant parent, concurrent hierarchy update, effective-date and audit immutability tests.
- App typecheck, build, route authorization and Organization API contract tests.

Exit: one organization hierarchy and assignment model, with no duplicate organization-unit registry.

### Wave 4 — People

**Source candidates:** API #31; App #5; supporting requirements API #57 and App #8.

Actions:
- Extend canonical Person identity and `person_tenant_accesses`; do not create a second Person or credential/User model.
- Keep authentication identity separate from human/business Person identity.
- Add contact points, relationships and participation only where absent from the canonical domain.
- Reconcile Person-to-Shareholder, Person-to-Employee, Person-to-Organization and Person-to-File links.
- Remove the automatic branch-sync workflow that grants `contents: write`, merges main into the feature branch and pushes. CI must be read-only.
- Repair App #5 frontend verification and validate API contract compatibility.

Required gates:
- Tenant-isolation and person-visibility matrix.
- Contact encryption/masking and HMAC lookup tests where applicable.
- Duplicate identity resolution, concurrent update, relationship integrity and audit tests.
- Person-linked file authorization tests.
- MySQL clean install/upgrade; App typecheck/build and contract tests.

Exit: one canonical Person registry with explicit tenant access and stable links to existing MASES domains.

### Wave 5 — Documents and numbering

**Source candidate:** API #53, rebuilt from current main after Waves 2–4. There is no dedicated formal Documents App PR in the reviewed set.

Actions:
- Add formal document metadata, document types, numbering, issuance, incoming registration and verification.
- Store binaries only through Wave 2 FileService.
- Scope numbering by tenant, legal entity and approved organizational scope; enforce uniqueness and idempotency.
- Preserve Person/Organization/Shareholder relationships through canonical foreign keys.
- Resolve migration ordering where Documents numbering references `organization_units` and `legal_entities`.

Required gates:
- MySQL clean install and upgrade; foreign-key and index-order verification.
- Concurrent number allocation and uniqueness tests.
- Idempotent issuance, cancellation/reissue, QR verification and audit-history tests.
- File version and tenant authorization integration tests.

Exit: one formal Documents domain over the canonical File Management service.

### Wave 6 — Finance

**Source candidates:** API #54, #32–#46, #58, #81, #83; App #33 and #35 for dividend workflows.

**Order:**
1. Apply the preferred migration/proposal hardening from #54 after comparing the competing #55 patch; supersede #55.
2. Consolidate #32–#46 into one Finance foundation from current main: canonical CoA, GL, control-account ownership, reconciliation, statements, cash flow and year-end readiness.
3. Integrate #58 only after the selected CoA/GL foundation.
4. Integrate dividend API #81, then remediate #83 tax and GA-evidence failures.
5. Integrate App #33, then #35 against the verified dividend contract.

Accounting invariants:
- ETB is the base currency; legal entities maintain separate books and support controlled consolidation.
- Posted journals are immutable; reversals are explicit and linked.
- Every journal balances; posting is idempotent and period-aware.
- Wallet, Loan and Shareholder remain operational source domains; Finance consumes approved events and does not duplicate their operational balances/schedules.
- Dividend weights must come from approved, independently evidenced authority records; caller-supplied weights are not authoritative.

Required gates:
- MySQL clean install and upgrade.
- CoA integrity, journal balance, posting/reversal, period close, duplicate event, concurrency and reconciliation tests.
- Multi-entity and consolidation tests.
- Tax calculation and approved GA evidence tests.
- App/API dividend contract, authorization and end-to-end tests.

Exit: a reconciled Finance ledger and operational-source integration, not parallel Wallet/Loan accounting systems.

### Wave 7 — Calendar

**Source candidates:** API #71 → #73 → #75; App #27.

**Order:**
1. Calendar Core (#71).
2. Privacy/lifecycle changes (#73), after shortening the overlong MySQL unique-index name.
3. Reminder/outbox lifecycle (#75).
4. App workspace (#27) against the final API contract.

Required gates:
- MySQL clean install/upgrade and recurrence exception uniqueness.
- Tenant-private event visibility, permissions, timezone and Ethiopian calendar behavior.
- Reminder idempotency, worker retry, delivery failure and recurrence exception tests.
- App route, date rendering and API contract tests.

Exit: tenant-safe Calendar with reliable recurrence and reminder delivery.

### Wave 8 — Governance and elections

**Source candidates:** API #52 meeting slice, API #77 election implementation; App #7 meeting slice and App #31 election UI. Shareholder baseline API #94 is evidence, not proof of closure.

Actions:
- Integrate meeting, attendance, proxy and resolution functionality separately from election schema.
- Use one canonical election schema. Do not apply both #52 and #77 election migrations: they create overlapping `governance_elections`, nominations and ballots tables.
- Prefer #77 as the source for shareholder-based electorate and share-weighted voting, subject to schema/security remediation.
- Validate election eligibility against frozen shareholder register evidence and authoritative authority records.
- Enforce nomination, proxy, ballot secrecy/custody, tally, challenge, certification and GA ratification as distinct auditable states.
- Split App #7 meeting routes from App #31 election routes.

Required gates:
- Migration uniqueness and foreign-key integrity.
- Shareholder eligibility and share-weight calculations.
- Duplicate-vote prevention, ballot confidentiality/custody and tamper-evident audit.
- Role separation, independent certification and ratification tests.
- Meeting proxy deadlines and attendance/resolution audit tests.
- API/App end-to-end governance workflows.

Exit: one meeting lifecycle and one independently controlled election lifecycle, with no duplicate election tables.

### Wave 9 — Communication Hub

**Source candidates:** API #74 and App #26.

Actions:
- Extend the existing central Notification service rather than introducing a parallel notification engine.
- Integrate tenant-scoped conversations, participants, messages, receipts and announcements.
- Reconcile Communication and OCR permission changes in one canonical permission catalogue.
- Review broad role grants for `communication.message.send`; use least privilege.

Required gates:
- Tenant and participant isolation.
- Role-based send/read/manage tests.
- Idempotent message submission, receipt lifecycle, rate limiting and abuse controls.
- Notification dispatch retry and failure handling.
- App/API contract and end-to-end tests.

Exit: Communication Hub reuses shared identity, tenancy and notification services.

### Wave 10 — OCR and Document Intelligence

**Source candidates:** API #76 and App #30.

Actions:
- Integrate OCR as a new capability consuming Files and formal Documents; do not introduce another file store.
- Fix the backend clean-install workflow SHA preflight so it validates the intended full commit SHA.
- Fix App TypeScript errors: unused `previewName` and possibly undefined `previewFile`.
- Keep OCR permissions excluded from broad roles until a dedicated least-privilege role is approved.
- Treat OCR output as unverified extraction; require human confirmation before it changes authoritative Person, Shareholder, Governance or Finance records.

Required gates:
- MySQL migration clean install and upgrade.
- Tenant/file authorization and worker resource limits.
- PDF/type/size limits, queue retry, failure handling and provenance.
- Amharic/English extraction evaluation and confidence thresholds.
- Human verification and correction audit.
- App typecheck/build and document workflow end-to-end tests.

Exit: secure OCR processing with provenance and human-verified downstream use.

## 5. Cross-wave collision controls

The following files/objects have a single integration owner. Every PR patch touching them must be reconciled into one final version; do not accept independent copies from multiple branches.

| Shared object | Integration control |
|---|---|
| `routes/api.php` | One route registry; explicit middleware, naming and version ownership. |
| `bootstrap/providers.php` | One registration per canonical domain provider. |
| `PermissionCatalogue.php` | One unique-slug catalogue; compute assertions from the final catalogue, not stale hard-coded counts. |
| `RolePermissionGrants.php`, permission/role seeders | Least-privilege grants; no broad OCR/admin/communication grants by accident. |
| `files` table and `FileService` | Preserve existing table/service; no duplicate file schema. |
| `organization_units` | Preserve migration identity; one Organization domain owner. |
| Person identity and tenant-access tables | One Person registry; no duplicate User/Person identity model. |
| Governance election tables | One election migration lineage; exclude duplicate #52 election schema if #77 is selected. |
| Finance journal migrations | Resolve #54/#55 competing patches and test existing database upgrade. |
| App `routes-admin.ts` | One canonical admin route registry across #36/#37. |
| App workspace/portal entrypoints and theme | Reconcile #15 SSO and #18/#19 design-system changes without overwriting bootstrap behavior. |
| Communication/OCR permission regression tests | Derive expected counts and role grants from final unique permission data. |

Migration rules:
- Never rename an already-deployed migration solely to improve ordering.
- Never reuse an existing migration filename for different schema semantics.
- New migrations must be additive and forward-safe; destructive changes require explicit data migration, rollback/restore plan and Owner approval.
- Test both clean install and upgrade from the current production-like schema.
- A migration that passes SQLite but fails MySQL is not accepted; MySQL is the required database verification target.

## 6. Required shared regression gates

Every integration wave must pass the applicable gates on exact SHAs:

1. **Source integrity:** branch is based on recorded current main; no unreviewed commits or generated secrets.
2. **Static quality:** formatter, linter, PHP static analysis and frontend typecheck/build.
3. **API tests:** unit, feature, authorization, validation and contract tests.
4. **Database:** MySQL clean install, upgrade from prior schema, foreign keys, indexes, rollback/restore rehearsal where applicable.
5. **Security:** authentication, tenant isolation, role/permission matrix, IDOR, file access, CSRF/session and audit controls.
6. **Concurrency:** deterministic tests for unique numbering, journal posting, duplicate submissions, election ballots and reminder/message dispatch.
7. **App:** production build, route guards, API contracts, accessibility and Amharic/English behavior.
8. **Cross-repository:** API/App contract and browser end-to-end tests against the exact paired SHAs.
9. **Evidence:** workflow run/job URLs, exact commit SHA, result, failures and unresolved items captured in the integration record.

A missing workflow, cancelled job or unavailable database is NOT RUN/UNRESOLVED, never PASS.

## 7. PR disposition policy

Existing PRs are source material, not automatically mergeable units. Use the A2 PR disposition register as the source inventory.

- **Preserve and selectively cherry-pick/rebuild:** #65, #85, #84, #51, #49, #31, #53, #74, #71, #73, #75, #52 meeting slice, #77 after remediation, #54, #32–#46 Finance source changes, #58, #81/#83 after remediation, #76 after remediation.
- **Coordinated App counterparts:** #15, #37/#36, #6, #5, #30, #26, #27, #7 meeting slice, #31 election slice, #33/#35.
- **Prefer standalone only after contract check:** App #42 Loan permission correction.
- **Documentation/evidence:** API #94/#93/#92/#57/#56/#69/#90; App #8/#13/#17/#23/#25.
- **Supersede or absorb rather than merge independently:** API #55 into #54; Calendar A1 proposals #67/#68; App #18 into #19; App Calendar A1 proposals #21/#22 into #23/#27; competing Finance and election schema branches as described above.
- **Blocked pending remediation:** API #73, #76 workflow, #83, #84, #52/#77 election collision/security, API #31 automatic write workflow; App #30, #5, #6.
- **No branch deletion** until its unique changes are proven absorbed and the Owner authorizes retirement.

## 8. Release and merge authorization

Each wave has two separate decisions:
- **Technical readiness:** exact-SHA checks, security review, migration verification and API/App compatibility pass.
- **Owner authorization:** explicit approval of the named PR(s), base SHA(s), head SHA(s) and merge strategy.

This plan itself does not authorize implementation, merging, production migrations or deployment. The Owner must separately authorize A3 implementation. Every merge remains separately authorized unless the Owner later provides a clearly scoped standing authorization. A5 deployment remains a separate release decision.

## 9. Integration evidence record template

For each wave, record:

- Wave ID and scope.
- API base SHA, API integration/head SHA, API PR URL.
- App base SHA, App integration/head SHA, App PR URL.
- Source PRs and exact source head SHAs.
- Files changed and migration names.
- Duplicate/overlap decisions and rationale.
- API, App, MySQL, security, concurrency and browser test results.
- Workflow run/job URLs and timestamps.
- Known limitations, NOT RUN gates and residual risks.
- Technical readiness decision.
- Explicit Owner merge authorization reference.
- Merge commit SHAs after an authorized merge.

## 10. Current plan status

- Exact API/App starting SHAs: **VERIFIED**.
- Integration order and cross-repo dependencies: **DEFINED**.
- Major known migration/file/route/permission collisions: **REGISTERED**.
- Selected source PRs: **IDENTIFIED; not rebased or merged**.
- Integration branches: **PLAN DOCUMENT ONLY; no feature integration performed**.
- A3 implementation: **NOT AUTHORIZED BY THIS DOCUMENT**.
- A4 integration verification: **NOT RUN**.
- A5 deployment: **NOT AUTHORIZED**.

**Immediate next step:** after separate A3 authorization, re-fetch both main SHAs and begin Wave 0, then Wave 1 SSO/identity. Do not start File Management until the shared identity/tenant contract is stable.
