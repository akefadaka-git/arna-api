# ARNA API/App A2 Integration Reconciliation and Closure Package

**Assessment date:** 2026-11-08 (UTC)  
**Repositories:** `akefadaka-git/arna-api`, `akefadaka-git/arna-app`  
**Assessment mode:** Read-only source and GitHub metadata review. This document is an A2 evidence/disposition package; it does not authorize A3 implementation, merge, migration execution, settings changes or deployment.

## 1. Executive disposition

**Package status: PARTIAL — reconciliation register published; A2 technical closure is not yet VERIFIED-CLOSED.**

The exact current default-branch refs were checked through GitHub's refs API:

| Repository | Exact current `main` SHA | Result |
|---|---|---|
| API | `ff290b353f1290cc91534fd4d694544e83c58d82` | PASS — ref resolves to this commit |
| App | `76fb67a421b1a54e0529a3479e551a1d1e0c0d0f` | PASS — ref resolves to this commit |

Open pull request inventory at assessment time: **47 API PRs + 22 App PRs = 69 open PRs**. This includes PR #96, the previously created documentation-only unified integration plan. The earlier inventory of 68 PRs is now superseded by the addition of #96.

The critical result is that the current main SHAs are unchanged from the pinned starting points in PR #96. However, many candidate PRs are heavily divergent from current main. The reviewed branches must therefore be treated as source material to reconcile, not as merge-ready integration units.

The assessment confirms material reuse already present in main: canonical MASES authentication and authorization, file storage/service and files migration, People schema/profile UI, Organization schema, Finance ledger domain, Governance core and attendance, notification services/UI, Communications announcements/UI, and Calendar date/context UI. New work must extend these foundations rather than create parallel models or duplicate user-facing modules.

**No PR is approved for merge by this package.** A3 implementation and each merge remain separate Owner decisions. A5 deployment remains out of scope.

## 2. Evidence and method

Read-only evidence gathered:
- GitHub refs for both default branches.
- Complete open-PR lists (47 API, 22 App) including PR numbers, titles, draft state, head refs/SHAs, base refs/SHAs and update times.
- GitHub compare results against the exact current main SHAs for the critical integration PRs listed in §5.
- Changed-file inventories for the principal Identity/SSO, File Management, Organization, People, Documents, Finance, Calendar, Governance, Communication and OCR PRs.
- Workflow runs associated with selected exact PR head SHAs. Workflow links are included below.
- Recursive repository trees at both exact main SHAs to confirm existing modules and canonical assets.

Limitations:
- This is a metadata, tree, changed-file and selected workflow-evidence review. It is not a line-by-line independent code review of every changed line in all 69 PRs.
- GitHub compare metadata does not establish semantic correctness or safe migration replay.
- Workflow success is evidence only for the exact head SHA and the workflow that ran; it is not proof of full production readiness.
- No database was created or migrated, no production data was accessed, and no runtime integration was executed in this A2 pass.
- PR #31 API returned no workflow runs for its exact queried head in the available workflow lookup. Absence of surfaced runs is NOT RUN/UNKNOWN, not a pass or fail.
- Some branch-specific file content and full review-thread discussions remain to be independently inspected before a formal technical closure.

## 3. Ordered integration decision

Use coordinated API/App work packages. API schema, authorization and contracts are reconciled first; App consumes the resulting stable contract. Rebuild from current main or a clean integration branch. Do not merge old stacked branches directly.

| Order | Work package | API source candidates | App source candidates | A2 decision |
|---:|---|---|---|---|
| 0 | Baseline and shared controls | Main + #96 plan | Main | Keep exact SHAs pinned; resolve common route, provider, permission and migration conflicts before A3 |
| 1 | Identity, tenancy, RBAC and SSO | #85 preferred identity candidate; #84 competing duplicate; #65 SSO | #37 preferred admin UI candidate; #36 competing duplicate; #15 SSO | Reconcile into one API identity/tenant contract and one App admin/SSO flow. Refresh/rebuild; no direct merge |
| 2 | File Management | #51 | Existing App Files feature | Rebuild API domain boundary from current main; preserve existing files table, File model/service and access controls; do not create a second file store |
| 3 | Organization | #49 | #6 | Reconcile against current organization_units schema and existing service/provider; API first, then App contract |
| 4 | People | #31; #57/#11 documentation references | #5; #8 documentation reference | Reuse canonical Person profile and explicit tenant access; review migration relocation, provider registration and workflow permissions; API first |
| 5 | Documents and numbering | #53 | Existing Files feature; no separate App Documents PR in current open inventory | Rebuild only after File Management, Organization and People contracts are stable; preserve transactional numbering and audit authority |
| 6 | Finance | #54 preferred focused remediation candidate; #55 superseded/overlapping; #32–#46 stack; #58; #81/#83 dividends; #90 reference; #48 CI runner | #33 parent portal; #35 dividend completion; #42 loan permission | Consolidate against existing Finance domain/CoA/GL on main. No branch-stack merge; validate migrations and accounting invariants as one coordinated release |
| 7 | Calendar | #71 → #73 → #75; #67–#69 A1/A2 references | #27; #25 contract; #21–#23 A1/A2 references | Rebuild the dependency chain from current main. Preserve existing date utilities and Calendar context; verify privacy, recurrence, timezone and reminder idempotency |
| 8 | Governance and elections | #52 meeting foundation; #77 election foundation | #7 meetings/elections UI; #31 election UI | Split meeting lifecycle from election lifecycle; reconcile overlapping election schema before selecting one canonical migration set |
| 9 | Communication Hub | #74 | #26 | Extend existing announcements, conversations and Notification infrastructure; do not create a second notification subsystem |
| 10 | OCR / Document Intelligence | #76 | #30 | Defer until File Management and Documents contracts are stable; isolate worker/queue and require human verification of extracted content |

### Integration rules

1. Never merge a stale feature branch solely because its historical checks passed.
2. Rebase/reconstruct from the exact current main SHAs; retain useful commits and tests, not obsolete parent history.
3. One canonical migration per business table and one authoritative model/service per domain.
4. Keep authorization centralized in existing MASES authorization and tenant services; do not duplicate role or user models.
5. Route, provider, permission-catalogue and seeder edits must be integrated in one reviewed change set per work package.
6. API/App work that changes a contract is a paired integration: API contract + API tests first, then App adaptation + App tests.
7. Preserve unrelated main functionality and all existing branches. No branch deletion is authorized.
8. Synthetic fixtures only in integration CI; no production data.
9. Exact-head CI evidence is required after every rebuilt integration candidate.
10. Merges require separate Owner authorization. Deployment requires a separate A5 decision.

## 4. Existing MASES / ARNA assets to reuse

The following assets were present in the recursive tree at the pinned main SHAs. They are the reuse baseline, not permission to assume every edge case is already complete.

| Capability | Existing canonical asset(s) in API/App main | Reuse rule |
|---|---|---|
| User identity/authentication | `mases_core/src/Auth/Models/User.php`; `mases_core/src/Auth/Services/AuthenticationService.php`; AuthController; Sanctum-backed app auth | Keep one User identity. SSO is session integration, not a second account system |
| Authorization/RBAC | `mases_core/src/Authorization/Models/*`; AuthorizationService and middleware; `database/seeders/Data/PermissionCatalogue.php`; `RolePermissionGrants.php` | Extend central permission catalogue/grants; no parallel role/permission engine |
| Tenant/membership | Existing tenant membership/authorization services and tenant-isolation gates; PR #85/#84 candidate changes | One tenant membership authority; verify all object access against tenant context |
| Files | `database/migrations/2026_09_07_000012_create_files_table.php`; existing FileController/FileService; App `src/features/files/*` | Preserve files table identity and existing storage/access contract; refactor service boundary without duplicate migration |
| Organization | `database/migrations/2026_10_28_000002_create_organization_units_table.php`; current organization-domain contract and provider assets | Reconcile schema and service boundaries; preserve stable organization-unit identifiers |
| People | `database/migrations/2026_10_27_000001_create_people_table.php`; `2026_10_27_000002_add_person_id_to_people_domains.php`; App `src/features/people/*` | Canonical Person profile distinct from User, shareholder, employee or governance membership; tenant access is explicit |
| Shareholders | Existing ShareholderRegister/ShareholderSeeder and shareholder migrations; App `src/features/shareholders/*` and portal | Extend shareholder register and portal; never create a competing ownership ledger |
| Finance | `mases_core/src/Domains/Finance/*`, canonical CoA config, ledger migrations, journals, legal entities, reports, audit and governance settlement services | Reconcile new PR work against the existing ledger; maintain double-entry and legal-entity isolation |
| Governance | `mases_core/src/Domains/Governance/*`, bodies, positions, terms, appointments, memberships, authority grants, seats and attendance; App governance views | Extend current governance domain. Meetings/elections must not duplicate existing membership, authority or attendance models |
| Calendar | App `src/core/dates/calendar.ts`, CalendarContext and preference controls; API Calendar service/controller assets | Reuse date/calendar shell; formalize tenant, timezone, recurrence and reminder contracts |
| Notifications | API NotificationController and platform-notifications migration; App `src/features/notifications/*`, stores and bell | Extend shared notification delivery and preference rules |
| Communication | API announcement controller/migration and App `src/features/communications/*` | Extend announcements into conversations; do not replace the existing hub |
| Documents/OCR | Existing Files infrastructure and document-numbering contracts; no OCR subsystem on main tree at baseline | OCR consumes authorized File/Document references and writes traceable, reviewable results |

## 5. Critical PR divergence and exact-head workflow evidence

Compare direction below is measured against the exact current main SHA, not the PR's historical base. It is commit divergence, not a conflict count.

### API critical candidates

| PR | Head SHA (short) | Ahead / behind current API main | Exact-head workflow evidence | A2 disposition |
|---:|---|---:|---|---|
| #65 SSO | `82da8f8` | 20 / 170 | 11 surfaced workflows successful, including clean-install MySQL, tenant isolation, auth/MFA and membership | Candidate only; rebuild/review CORS, cookie, CSRF, routes and session lifecycle |
| #85 Identity | `ef26a85` | 29 / 78 | 10 surfaced workflows successful, including clean-install MySQL, tenant isolation and auth | Preferred over #84 for reconciliation; not merge-ready |
| #84 Identity duplicate | `0f8e3e6` | 6 / 78 | Competing branch; no selected-head workflow evidence captured in this pass | Supersede/absorb only after unique changes are compared; preserve branch |
| #51 File Management | `b8912c8` | 67 / 170 | 14 surfaced workflows successful, including clean-install MySQL and tenant/auth gates | Reconstruct from main; migration relocation and service/provider wiring require review |
| #49 Organization | `91d2e8b` | 128 / 170 | 14 surfaced workflows successful, including clean-install MySQL and tenant/auth gates | Reconstruct from main; 33 files and shared route/provider/permission changes |
| #31 People | `335c980` | 198 / 243 | No workflow runs surfaced for queried exact head | Do not merge; review 82-file scope, migrations, sync workflow and canonical Person reuse |
| #53 Documents | `92f8d14` | 343 / 170 | Clean-install MySQL workflow successful | Stacked on old integration base; rebuild after waves 2–4 |
| #54 Finance remediation | `c0cf148` | 23 / 239 | Four surfaced workflows successful, including clean-install MySQL | Preferred focused remediation candidate, but rebuild/review on current main |
| #55 Finance remediation duplicate | `a79e13a` | 3 / 239 | Three workflows successful; clean-install MySQL failed | Superseded/overlapping with #54; do not merge |
| #58 Finance Phase 2 | `28c4339` | 266 / 248 | Not independently revalidated in this pass | Heavily diverged stacked branch; consolidate selected changes, not branch merge |
| #71 Calendar core | `32d2991` | 27 / 169 | Eight surfaced workflows successful, including clean-install MySQL | First candidate in chain; rebuild from main |
| #73 Calendar lifecycle | `9e0e798` | 51 / 169 | Clean-install MySQL workflow failed | Do not merge; remediate and rebase after #71 contract review |
| #75 Calendar reminders | `2f1b70e` | 84 / 169 | Exact-head workflow result not captured in this pass | Keep as downstream source; rebase after lifecycle gates |
| #52 Governance meetings | `2907f3f` | 69 / 239 | Not independently revalidated in this pass | Meeting slice only; reconcile election migration overlap with #77 |
| #77 Board Election | `63e61e1` | 102 / 163 | No exact-head workflow result captured in this pass | Do not merge; 33-file A3 candidate, election schema/security review outstanding |
| #74 Communication | `127d36e` | 27 / 164 | Not independently revalidated in this pass | Defer until Notification and Files/People contracts are settled |
| #76 OCR | `7ea70eb` | 98 / 163 | Not independently revalidated in this pass | Defer until Files/Documents; worker isolation and human verification gates |

### App critical candidates

| PR | Head SHA (short) | Ahead / behind current App main | Exact-head workflow evidence | A2 disposition |
|---:|---|---:|---|---|
| #15 SSO | `706251a` | 9 / 126 | Frontend verification successful | Candidate only; rebuild against current main and API contract |
| #37 Admin UI | `e3a92a7` | 11 / 72 | Frontend verification successful | Preferred over #36 for one admin foundation UI; rebuild |
| #36 Admin UI duplicate | `ff05ecf` | 4 / 72 | No separate workflow result captured | Absorb unique changes only; preserve branch |
| #6 Organization UI | `27ddfd5` | 31 / 159 | Frontend verification failed | Rebuild after API Organization contract; remediate failure |
| #5 People UI | `faf16e7` | 13 / 159 | Frontend verification failed | Existing People UI already on main; adapt/reconcile rather than replace |
| #7 Governance UI | `2c79eed` | 33 / 159 | Frontend verification successful | Split meeting UI from election UI; coordinate with #31 |
| #31 Election UI | `f4f53b6` | 26 / 124 | No exact-head workflow result captured | Coordinate/split with #7; no independent merge |
| #27 Calendar UI | `098997a` | 21 / 124 | Frontend verification successful | Rebuild after API calendar contract |
| #26 Communication UI | `56b1ab7` | 13 / 124 | Frontend verification successful | Extend current Communication Hub |
| #30 OCR UI | `5fadf1c` | 21 / 124 | Frontend verification failed | Defer; fix frontend gate and wait for API OCR contract |
| #33 Dividend portal | `36eb8e8` | 5 / 73 | Not independently revalidated in this pass | Parent for #35; preserve current shareholder portal |
| #35 Dividend completion | `d6c29d9` | 53 / 73 | Not independently revalidated in this pass | Reconcile as a paired Finance/Shareholder release |
| #42 Loan permission fix | `a8d4807` | 1 / 0 | Frontend verification successful | Small isolated candidate, but API permission slug/contract must match before merge |

Workflow references:
- API #65: [clean-install MySQL](https://github.com/akefadaka-git/arna-api/actions/runs/41205631051), [tenant isolation](https://github.com/akefadaka-git/arna-api/actions/runs/41205631047), [browser session/auth test group](https://github.com/akefadaka-git/arna-api/actions/runs/41205631088).
- API #85: [clean-install MySQL](https://github.com/akefadaka-git/arna-api/actions/runs/41728884242), [tenant isolation](https://github.com/akefadaka-git/arna-api/actions/runs/41728883869).
- API #51: [clean-install MySQL](https://github.com/akefadaka-git/arna-api/actions/runs/41167907469), [tenant authorization](https://github.com/akefadaka-git/arna-api/actions/runs/41167913688).
- API #49: [clean-install MySQL](https://github.com/akefadaka-git/arna-api/actions/runs/41076115687), [governance authorization](https://github.com/akefadaka-git/arna-api/actions/runs/41076115610).
- API #54: [clean-install MySQL](https://github.com/akefadaka-git/arna-api/actions/runs/40936994988).
- API #55: [failed clean-install MySQL](https://github.com/akefadaka-git/arna-api/actions/runs/40936484389).
- API #71: [clean-install MySQL](https://github.com/akefadaka-git/arna-api/actions/runs/41266244260).
- API #73: [failed clean-install MySQL](https://github.com/akefadaka-git/arna-api/actions/runs/41275437169).
- App #15: [frontend verification](https://github.com/akefadaka-git/arna-app/actions/runs/41204827966).
- App #37: [frontend verification](https://github.com/akefadaka-git/arna-app/actions/runs/41728377171).
- App #6: [failed frontend verification](https://github.com/akefadaka-git/arna-app/actions/runs/40924149477).
- App #5: [failed frontend verification](https://github.com/akefadaka-git/arna-app/actions/runs/40728793536).
- App #7: [frontend verification](https://github.com/akefadaka-git/arna-app/actions/runs/40994352832).
- App #27: [frontend verification](https://github.com/akefadaka-git/arna-app/actions/runs/41278852595).
- App #26: [frontend verification](https://github.com/akefadaka-git/arna-app/actions/runs/41277520619).
- App #30: [failed frontend verification](https://github.com/akefadaka-git/arna-app/actions/runs/41392948962).
- App #42: [frontend verification](https://github.com/akefadaka-git/arna-app/actions/runs/42309382136).

## 6. File-level collision and ownership register

| Shared path / object | PRs or existing baseline | Risk | Required reconciliation |
|---|---|---|---|
| `routes/api.php` | API #65, #85, #84, #49, #31, #53, #52, #77, #74, #76 | High: route registration collisions, middleware omissions, duplicate endpoints | Rebuild routes from main; move domain routes into providers; one route contract test per domain |
| `bootstrap/providers.php` / service providers | API #51, #49, #31, #53, #76 | High: duplicate/missing provider registration and boot-order problems | Central provider inventory; verify each provider is registered exactly once and migrations are discovered |
| `PermissionCatalogue.php`, `RolePermissionGrants.php`, permission seeders | API #51, #49, #31, #84, #53, #73, #74, #76 | High: missing grants, slug drift, accidental overgrant | One canonical permission registry; least-privilege diff; tenant-scope tests; seed idempotency |
| Files table and FileService | Main `database/migrations/2026_09_07_000012_create_files_table.php`; API #51, #31, #53 | Critical: duplicate migration discovery, data loss or storage authorization regression | Preserve migration identity; prove exactly one migration owns `files`; keep compatibility adapter if moving service; test private storage and tenant isolation |
| Organization units | Main `database/migrations/2026_10_28_000002_create_organization_units_table.php`; API #49/#31 | High: duplicate table migration or divergent foreign keys | Preserve one table and identifiers; compare renamed migration lineage; clean-install + upgrade-path test |
| People/person access | Main People migrations; API #31; App #5 | Critical: duplicate Person identity, tenant access bypass, migration path collision | Canonical Person only; preserve explicit `person_tenant_accesses`; distinguish User/shareholder/employee/governance links; prove no implicit privilege inheritance |
| Document numbering | API #51/#53; existing numbering contracts | High: duplicate registry, sequence race, authority mismatch | One numbering registry; transactional locking/idempotency; verify QR/public lookup does not disclose private data |
| Finance ledger/CoA/dividend | API #32–#46/#54/#55/#58/#81/#83; App #33/#35 | Critical: duplicate ledger/CoA migrations, inconsistent accounting contracts, multi-entity leakage | Reconcile all selected changes to main's existing ledger; single canonical CoA; double-entry, reversal, period-close, ETB and entity isolation tests |
| Governance meeting/election schema | API #52/#77; App #7/#31; existing Governance core/attendance | Critical: overlapping election schema, duplicate route/model/permission ownership | Separate meeting and election slices; table-by-table schema map; only one canonical election migration chain |
| Calendar migration/service | API #71/#73/#75; App #27 | High: same migration edited across stacked branches; lifecycle/worker behavior drift | Rebuild ordered chain from main; one migration owner; recurrence/timezone/privacy/idempotency tests |
| Communication and notifications | API #74; App #26; existing announcement/notification modules | High: parallel notification delivery and inconsistent unread/read state | Extend existing Notification service and hub; one event/dispatch path; idempotency and tenant authorization |
| App admin routes | App #36/#37/#35 | High: competing admin pages and route guards | Select one admin foundation; merge unique content only; central route and permission matrix |
| App workspace routes and labels | App #6/#7/#27/#31/#26/#30 | High: route-key and localized-label collisions | Integrate one domain route at a time; verify Amharic/English labels and access guards |
| App auth entrypoints/service | App #15; current auth bootstrap/service | High: split session state between Workspace and Portal | One shared Sanctum session client; CSRF, cookie domain, logout and expiry contract tests |
| OCR worker/config/queue | API #76; App #30 | High: unbounded resource use, unsafe file processing, false extraction confidence | Isolated worker, size/type limits, queue retry/dead-letter policy, provenance and mandatory human verification |

## 7. PR disposition register — all currently open PRs

Disposition labels:
- **KEEP / RECONCILE**: useful candidate or reference; must be rebuilt/reviewed against current main.
- **ABSORB / SUPERSEDE**: competing or stacked branch; preserve it but do not merge as a separate unit.
- **DEFER**: valid downstream capability whose prerequisites are not yet stable.
- **REMEDIATE**: surfaced failing or unresolved gate.
- **DOC / REFERENCE**: retain as decision/spec evidence, not feature merge.
- **UNKNOWN / PARTIAL**: insufficient exact-head or source review evidence for a final technical decision.

### API — 47 open PRs

| PR | Domain / title shorthand | Disposition |
|---:|---|---|
| #96 | Unified API/App integration plan | DOC / REFERENCE; this closure package is complementary evidence |
| #95 | Accelerated ARNA OS roadmap | DOC / REFERENCE; Owner-approved plan, no implementation authorization |
| #94 | Shareholder A2 baseline | KEEP / RECONCILE; baseline marked partial, not technical closure |
| #93 | Wallet/Loan A2 production preflight | KEEP / RECONCILE; SQL/runtime checks not executed |
| #92 | Wallet/Loan A1 readiness | DOC / REFERENCE; refresh stale base before relying on claims |
| #90 | Wallet/cryptocurrency dependency matrix | DOC / REFERENCE; align to current Finance domain and do not implement token scope |
| #85 | Admin identity/RBAC/tenant API | KEEP / RECONCILE; preferred over #84, refresh/rebuild |
| #84 | Competing admin control-plane API | ABSORB / SUPERSEDE after unique-diff review; preserve branch |
| #83 | Dividend engine completion | DEFER; dependent on #81; consolidate with current ledger |
| #81 | Annual dividend engine | KEEP / RECONCILE; parent candidate, rebase and verify accounting invariants |
| #77 | Board Election API | REMEDIATE / DEFER; election schema overlap and exact-head workflow evidence missing |
| #76 | OCR / Document Intelligence API | DEFER until Files/Documents and worker controls |
| #75 | Calendar reminders/worker | DEFER; downstream of #71/#73; exact-head evidence incomplete |
| #74 | Communication Hub API | DEFER until Notification contract reconciliation |
| #73 | Calendar lifecycle/privacy | REMEDIATE; clean-install MySQL failed |
| #71 | Calendar temporal/core API | KEEP / RECONCILE; rebuild from current main |
| #69 | Calendar A2 API inventory | DOC / REFERENCE; consolidate into one calendar baseline |
| #68 | Calendar A1 architecture | DOC / REFERENCE; supersede with reconciled baseline where appropriate |
| #67 | Calendar A1 implementation branch | ABSORB / SUPERSEDE; do not merge beside #71 |
| #65 | Browser-session SSO API | KEEP / RECONCILE; candidate after cookie/CORS/CSRF review |
| #58 | Finance Phase 2 CoA integrity | ABSORB / RECONCILE; 266 ahead / 248 behind, no direct merge |
| #57 | People domain P0 strategy | DOC / REFERENCE |
| #56 | Phase 0 exact-SHA security CI | KEEP / RECONCILE; inspect workflow permissions before adoption |
| #55 | Finance migration/proposal hardening | ABSORB / SUPERSEDE by #54 unless unique fix proven; clean-install failed |
| #54 | Finance migration/proposal hardening | KEEP / REMEDIATE; preferred focused remediation source, rebuild on current main |
| #53 | Documents numbering/issuance | DEFER / REBUILD after Files, Organization and People |
| #52 | Governance meetings foundation | KEEP / RECONCILE meeting slice only; election schema must be separated |
| #51 | File Management domain boundary | KEEP / REBUILD from main; preserve existing file table/service contract |
| #49 | Organization domain | KEEP / REBUILD from main; preserve organization_units migration identity |
| #48 | Finance Phase 0 regression runner | KEEP / REVIEW; inspect workflow token permissions and approved-SHA protections |
| #46 | Integrated Finance foundation/reporting/year-end | ABSORB / RECONCILE; stacked integration candidate |
| #45 | Finance Phase 0 local acceptance | DOC / REFERENCE; local-only runner is not remote verification |
| #44 | Finance enhancement roadmap | DOC / REFERENCE |
| #43 | Financial statements/cash flow | ABSORB into selected Finance integration; do not merge separately before base |
| #42 | Year-end close readiness | DEFER until ledger/reporting integration |
| #40 | Finance accounting/CoA reconciliation | ABSORB into canonical CoA/ledger integration |
| #39 | Finance accounting completion | ABSORB into canonical Finance integration |
| #38 | Finance cash-flow classification | DEFER until journal/reporting contract is stable |
| #37 | Control-account reconciliation | DEFER until canonical CoA and posting controls are stable |
| #36 | Financial statements | DEFER until canonical ledger/reporting integration |
| #35 | CoA ownership/metadata safeguards | KEEP / RECONCILE as candidate for canonical CoA |
| #34 | Control-account ownership register | ABSORB into canonical CoA/Finance integration |
| #33 | Finance production-readiness gates | DOC / REFERENCE; rerun against final integrated exact SHA |
| #32 | Canonical Chart of Accounts | KEEP / RECONCILE as canonical CoA source, not standalone stale branch merge |
| #31 | People contacts/relationships API | KEEP / REBUILD; no workflow run surfaced for queried SHA; inspect sync workflow and migration lineage |
| #30 | Finance GL domain contract | DOC / REFERENCE; refresh against current Finance domain |
| #11 | P1.2 Unified People Profile contract | DOC / REFERENCE; compare with existing P1.2 completion evidence |

### App — 22 open PRs

| PR | Domain / title shorthand | Disposition |
|---:|---|---|
| #42 | Loan permission slug fix | KEEP / RECONCILE; isolated one-file change; verify API contract |
| #37 | Admin identity/RBAC/tenant UI | KEEP / RECONCILE; preferred over #36 |
| #36 | Competing admin control-plane UI | ABSORB / SUPERSEDE after unique-diff review |
| #35 | Dividend admin and shareholder portal completion | DEFER / RECONCILE with API dividend engine and #33 |
| #33 | Annual shareholder dividend portal | KEEP / RECONCILE; parent candidate |
| #31 | Board Election UI | DEFER / RECONCILE with #7; split election slice |
| #30 | OCR Document Intelligence workspace | REMEDIATE / DEFER; frontend verification failed; wait for API contract |
| #27 | Amharic-first Calendar workspace | KEEP / REBUILD after API Calendar contract |
| #26 | Communication Hub UI | KEEP / REBUILD to extend current hub and notification contracts |
| #25 | Calendar integration contract | DOC / REFERENCE |
| #23 | Calendar A2 App inventory | DOC / REFERENCE; consolidate |
| #22 | Calendar A1 experience architecture | DOC / REFERENCE |
| #21 | Calendar A1 implementation | ABSORB / SUPERSEDE after #27 contract reconciliation |
| #19 | Design System v2 completion | KEEP / RECONCILE; 14-commit branch is 126 behind; verify design tokens and accessibility before reuse |
| #18 | Design System v2 foundation | ABSORB into #19 where unique; preserve branch |
| #17 | GUI A1 design specification | DOC / REFERENCE |
| #15 | Sanctum session SSO App | KEEP / RECONCILE; frontend check passed; rebase and verify with API #65 |
| #13 | Frontend architecture/environment/release guides | DOC / REFERENCE |
| #8 | People App P0 ownership/reporting | DOC / REFERENCE |
| #7 | Governance meeting workspace | KEEP / RECONCILE meeting UI; split election UI from #31 |
| #6 | Organization domain UI | REMEDIATE / REBUILD; frontend verification failed |
| #5 | People contact/relationship workflows | REMEDIATE / REBUILD; frontend verification failed and existing People UI must be preserved |

## 8. R1 acceptance matrix for subsequent A3/A4 work

No feature is accepted for integration solely because its source branch has a green historical workflow. The rebuilt API/App pair must pass the applicable gates below on exact SHAs.

| Gate | Acceptance criteria | Required evidence |
|---|---|---|
| Identity/SSO | Single canonical User; secure session lifecycle; cookie/CORS/CSRF boundaries; logout/revocation/expiry; no session split between Workspace/Portal | API auth feature tests; browser/session tests; App route/session tests; negative cross-tenant tests |
| Tenant/RBAC | Fail-closed tenant scoping; least privilege; explicit membership; no privilege inheritance from Person/shareholder/employment links | Tenant isolation suite; permission catalogue snapshot; forbidden-access matrix |
| Files | Single files migration/table; private storage; tenant authorization; safe content-type/size validation; signed URL expiry; upload/download/delete audit | Clean-install + upgrade migration tests; FileSecurity tests; storage adapter tests |
| Organization | Stable organization unit identifiers; hierarchy cycle prevention; assignment constraints; audit immutability; tenant isolation | MySQL hierarchy/concurrency tests; API contract tests; App role/empty/error states |
| People | One Person profile; explicit tenant access; distinct User/shareholder/employee/governance relationships; safe link/unlink; contact/relationship lifecycle audit | Migration discovery tests; Person identity and MySQL concurrency tests; authorization matrix; App profile tests |
| Documents | Transactional numbering; no duplicate numbers under concurrency; authority validation; idempotent issuance; QR verification privacy | MySQL concurrency and retry tests; numbering contract tests; immutable issuance audit |
| Finance | ETB base currency; legal-entity isolation; double-entry balance; canonical CoA; period lock; reversal/cancellation controls; idempotent postings; approval segregation | Clean-install/upgrade migration; ledger invariant tests; multi-entity tests; concurrency tests; audit evidence |
| Calendar | Ethiopian calendar default and Gregorian preference; timezone-safe events; recurrence bounds; privacy; reminder idempotency and retry | Date conversion contract tests; tenant/privacy tests; recurrence tests; worker retry/idempotency tests |
| Governance | Shareholder electorate and share-weighted voting rules; physical/digital/proxy modes; quorum and deadlines; committee authority; immutable ballot/audit trail | Election policy tests; idempotency/concurrency tests; permission matrix; audit-chain tests |
| Communication | Tenant-scoped announcements/conversations; notification deduplication; read/unread consistency; access control and retention | API messaging tests; event delivery/idempotency tests; App route/accessibility tests |
| OCR | Isolated worker; upload reference validation; queue retry/dead-letter handling; page/type/size limits; confidence/provenance; human review before authoritative use | Worker build/test; synthetic OCR fixtures; security/resource-limit tests; App correction/verification tests |
| Cross-cutting | Amharic-first labels, mobile-first UX, keyboard accessibility, WCAG 2.2 AA, API error contract consistency, auditability | Frontend build/test, accessibility checks, API contract tests, exact-SHA evidence register |

## 9. Blocking findings and required actions

| ID | Severity | Finding | Required action | Status |
|---|---|---|---|---|
| A2-01 | Critical | 69 open PRs include heavily divergent and stacked branches; direct merge is unsafe | Use this ordered integration plan and reconstruct candidate branches from current main | OPEN |
| A2-02 | Critical | Shared API routes, providers, permission catalogues and seeders are modified by multiple domains | Establish shared-file ownership and integrate one API domain at a time | OPEN |
| A2-03 | Critical | File, Organization and People migration history is relocated/renamed in candidate PRs while current main already owns these migrations | Prove one migration owner per table and preserve migration ledger/history | OPEN |
| A2-04 | Critical | People identity/tenant-access semantics must not duplicate or weaken P1.2 canonical Person | Reconcile #31 against existing P1.2 completion evidence and explicit tenant access | OPEN |
| A2-05 | Critical | Governance #52 and #77 contain separate election/meeting schema proposals | Produce table-by-table election schema map and choose one canonical chain | OPEN |
| A2-06 | High | Finance work is distributed across a long, heavily divergent PR stack | Select canonical CoA/GL and dividend changes; run migration and accounting regression on rebuilt candidate | OPEN |
| A2-07 | High | Calendar #73 clean-install MySQL workflow failed | Diagnose failure, remediate, and rerun all gates on new exact SHA | OPEN |
| A2-08 | High | App #5 and #6 frontend verification failed | Rebuild on current main, preserve existing views, remediate and rerun |
| A2-09 | High | App #30 OCR frontend verification failed | Defer until API contract and worker boundary are stable; remediate frontend gate |
| A2-10 | High | API #55 Finance clean-install MySQL workflow failed | Do not use as merge candidate; compare unique fix with #54 |
| A2-11 | High | API #31 queried head has no workflow run evidence surfaced | Add/reconstruct remote exact-SHA test path; no local-only success claim |
| A2-12 | High | API #31 adds `.github/workflows/sync-people-integration-branch.yml` | Review workflow token permissions and branch-write scope; remove unsafe write scope unless strictly required and protected |
| A2-13 | High | Main branch protection was reported absent in earlier baseline census | Owner decision needed to configure protections; no settings changed in A2 |
| A2-14 | Medium | Some candidate workflows are historical and branch checks do not cover integrated API/App behavior | Re-run unified exact-SHA gates after every rebuilt integration |
| A2-15 | Medium | No database/runtime integration or independent security review was executed during this A2 pass | Keep A2 technical closure PARTIAL until required evidence is supplied |

## 10. A2 closure decision

**A2 inventory and integration disposition: COMPLETE for the 69-PR inventory and the selected dependency-critical source set.**  
**A2 technical closure: PARTIAL / NOT VERIFIED-CLOSED.**

Why not VERIFIED-CLOSED:
1. This assessment has not completed a line-by-line review of every changed file in all 69 open PRs.
2. Database clean-install and upgrade paths were not executed in this read-only phase.
3. API #73, API #55, App #5 and App #6/#30 have surfaced failing workflow evidence requiring remediation on rebuilt candidates.
4. Governance election migration overlap, Files/Organization/People migration lineage and Finance stack consolidation require explicit schema-level reconciliation.
5. Independent security review and exact-head end-to-end API/App integration evidence remain outstanding.

### Required A2 residual closure evidence

Before recording A2 as VERIFIED-CLOSED, complete:
- Full source/diff review for all selected PR commits after reconstruction against current main.
- Migration lineage map with exactly one migration owner for every shared table.
- Route/provider/permission ownership map with duplicate registration checks.
- Review of workflow permissions, especially branch-sync and any `contents: write` token.
- Exact-head clean-install and upgrade tests for the selected integration baseline (synthetic data only).
- App build, contract, accessibility and negative authorization tests.
- Independent security review of identity/tenant, Files and Governance/election boundaries.
- Updated evidence register with run links, exact SHAs and PASS/FAIL/NOT RUN for every gate.

## 11. Authorization boundary and next action

This package is documentation and reconciliation only. It does not:
- implement A3 features;
- approve, merge, close or delete any PR or branch;
- modify `main`, repository settings or workflow permissions;
- run migrations against any database;
- access or alter production data;
- deploy or perform A5 work.

**Recommended next action:** Owner review of this A2 disposition package. After the remaining source/schema/security evidence is closed, request separate A3 authorization to rebuild Wave 1 (Identity/SSO) from the pinned main SHAs. Each PR merge and A5 deployment remains separately authorized.


---

## 12. A2 focused source-review addendum — 2026-11-08

This addendum records direct file-content and GitHub compare evidence obtained after the initial package. It supersedes any broader or less precise migration/workflow statements above.

### 12.1 Migration lineage: verified findings

| PR | Migration | GitHub compare result | Exact source evidence | Decision |
|---|---|---|---|---|
| #51 Files | `2026_09_07_000012_create_files_table.php` | Renamed; no content changes | Main and domain-path blob SHA both `428b8cbe5abbc3abac959f7349564fa1a0a910b5` | PASS for migration identity/content preservation. Still require provider discovery and upgrade-path execution. |
| #31 People | `2026_10_27_000001_create_people_table.php` | Renamed; no content changes | Main and domain-path blob SHA both `db2fd2086639f4581ae0d735434cb8a4bcb9d256` | PASS for identity/content preservation. |
| #31 People | `2026_10_27_000002_add_person_id_to_people_domains.php` | Renamed; no content changes | Main and domain-path blob SHA both `8aff1d4aebdb147c654965397213e617b69a1027` | PASS for identity/content preservation. |
| #31 People | `2026_10_27_000003_create_person_tenant_accesses_table.php` | Renamed; no content changes | Main and domain-path blob SHA both `fd280e9b01fa8d7a6b42148950e29e66d8dc5c0d` | PASS for identity/content preservation. |
| #31 Organization | `2026_10_28_000002_create_organization_units_table.php` | Renamed with content changes (5 additions, 1 deletion) | Main root-path blob SHA `f7ada0d938218a06b76d97acf3879e393fe5de1f`; PR #31 domain-path blob SHA `8d7ffbd6571986ee6efaeb1b12f3997a782ecd04` | BLOCKED: migration rewrite changes deployed-schema semantics. |
| #49 Organization | Same migration | Renamed with content changes (5 additions, 1 deletion) | Same changed blob as #31 | BLOCKED: #49 and #31 carry the same altered migration; do not integrate either rewritten copy as the original migration. |

**Organization migration semantic delta:** current main's `organization_assignments.assignment_type` default is `member` and the migration constrains `person_id` to `people`. The candidate changes the default to `primary` and adds a composite foreign key from `(person_id, tenant_id)` to `person_tenant_accesses`. This is a meaningful schema/behavior change under an already-existing migration name. On databases where the original migration has already run, editing that migration will not apply the new constraint/default. The safe remediation is to preserve the original migration byte-for-byte and introduce a new, forward-only migration for the additional constraint/default with a verified backfill and rollback strategy. That code remediation is not performed in A2.

The Files and core People migrations are true path relocations with unchanged blob content, not duplicate definitions. PR #31's additional People migrations are distinct full migration names, although two files share the `2026_10_28_000001` timestamp prefix. They create `person_document_links` and add MySQL-only active-person uniqueness constraints respectively. The unique full names avoid a duplicate migration-name collision; clean-install ordering and MySQL execution still require exact-head CI.

### 12.2 People identity and tenant access: static source review

PR #31's `PeopleServiceProvider` loads the domain migration directory and registers the Person policy. The inspected `PersonIdentityService`:
- checks actor tenant access and permission before creating/linking/unlinking;
- creates an explicit active `person_tenant_accesses` row with the Person;
- locks Person/User rows during account linking;
- rejects linking an inactive or already-associated account and checks tenant compatibility;
- uses a fixed domain-record lock order during tenant-access revocation.

The inspected `PersonPolicy` requires active tenant access and the `people.view` or `people.manage` permission. These are positive source-level findings, not proof of all API paths or database enforcement. They do not close the missing exact-head workflow, concurrency, cross-tenant negative-test or independent security-review requirements.

### 12.3 PR #31 workflow permissions: confirmed critical blocker

Two workflow changes in PR #31 conflict with the Owner's branch-preservation and least-privilege requirements:

1. **New `.github/workflows/sync-people-integration-branch.yml`:** triggers on PR opened/synchronize/reopened/ready-for-review, grants top-level `contents: write`, merges current `main` into `feat/people-domain-phase-2-api`, and pushes the result to that branch. This is an automatic branch mutation driven by a PR event.
2. **Modified `.github/workflows/arna-clean-install-mysql.yml`:** adds a post-success cleanup job with job-level `contents: write` and a `git.deleteRef` operation for merged source branches. The current-main version has only `contents: read` and no branch-cleanup job.

**Disposition:** PR #31 is blocked from merge until both automatic write behaviors are removed or redesigned as explicitly authorized, non-destructive, least-privilege operations. The existing branch must be preserved. No branch deletion or automatic branch synchronization is authorized. This A2 assessment does not edit either workflow.

### 12.4 Governance/election migration conflict: confirmed schema incompatibility

Direct migration inspection confirms that PR #52 and PR #77 both attempt to create the same canonical election tables, including:
- `governance_elections`
- `governance_election_nominations`
- `governance_election_ballots`

Their supporting schemas are not interchangeable:
- PR #52 adds `governance_election_eligible_voters` and `governance_election_audit_events`; the inspected foreign keys tie voter/nomination records to `governance_memberships`.
- PR #77 adds `governance_election_register_snapshots`, `governance_election_voters` and `governance_election_tallies`; its A1 contract describes a record-date register reconstructed from the shareholder ledger and per-share-class entitlements.

**Recommended A2 source disposition:** retain PR #52's meeting lifecycle slice only; exclude its competing election-table/controller/permission slice from the unified integration. Use PR #77's shareholder-ledger/register architecture as the election design candidate, subject to the requirements and unresolved authority gates below. Do not merge either election migration chain as currently written, and do not combine both.

PR #77's own A1/A2 documents explicitly keep electronic voting disabled pending election-specific legal authority, security and operational acceptance. They also record unresolved company-specific proxy/disqualification rules, challenge/appeal authority and deadline evidence, and register approval/freeze work that remains to be implemented. The Owner's policy record separately sets quorum, majority/runoff and independent-certification rules; the implementation must preserve those exact controls. Therefore the schema choice is clarified, but election readiness/legal clearance is NOT CLOSED.

### 12.5 Updated blocker disposition

| Blocker | Updated result | Evidence/status |
|---|---|---|
| Files migration identity | RESOLVED at source-diff level | Same blob SHA after rename; runtime/provider discovery still NOT RUN |
| Core People migration identity | RESOLVED at source-diff level | Three canonical migrations renamed with identical blob SHAs |
| Organization migration rewrite | OPEN / CRITICAL | Same migration filename, different blob; preserve old migration and add a new forward migration in a separately authorized implementation stage |
| People identity/tenant-access architecture | PARTIAL | Static service/policy review positive; exact-head CI and full endpoint/security tests outstanding |
| Automatic People branch sync | OPEN / CRITICAL | New workflow has `contents: write` and pushes on PR events |
| Automatic merged-branch cleanup | OPEN / CRITICAL | Modified clean-install workflow adds `contents: write` and branch deletion; main does not contain this job |
| Governance #52/#77 election collision | RESOLVED as an A2 disposition decision; NOT implemented | Keep #52 meeting slice; use #77 as the shareholder-ledger election design candidate; do not integrate either election migration chain yet |
| Independent security review | NOT RUN | Requires a genuinely independent reviewer; internal static inspection is not a substitute |
| Clean-install and upgrade execution | NOT RUN on a unified rebuilt candidate | Requires a separate exact-SHA workflow run against synthetic disposable databases |
| Main branch protections | OWNER GOVERNANCE DECISION REQUIRED | No repository settings changed |

### 12.6 What A2 can close versus what it cannot

A2 can now close the source-selection ambiguity for Files and core People migration relocation, identify the Organization migration rewrite as unsafe, block the two automatic branch-write behaviors, and select a single Governance election design candidate for subsequent reconciliation.

A2 cannot legitimately claim that the Organization migration has been repaired, workflows have been changed, branch protection is enabled, MySQL clean-install/upgrade behavior is verified, or independent security review is complete. Those require either separately authorized implementation/settings actions or external/runtime evidence. They remain explicit blockers; none is silently treated as PASS.

**Updated A2 status:** source-level reconciliation materially advanced; A2 technical closure remains **PARTIAL / NOT VERIFIED-CLOSED**. No A3 implementation, merge, branch deletion, settings change, migration execution or deployment was performed by this addendum.
