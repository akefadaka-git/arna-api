# ARNA Shareholder Domain — A2 Integration and Gap Reconciliation

- **Record:** ARNA-SHD-A2-RECON-001
- **Assessment date:** 2026-11-27 (EAT)
- **Stage:** A2 — read-only repository reconciliation
- **Disposition:** **SOURCE RECONCILIATION COMPLETE; A2 CLOSURE RECOMMENDED WITH OPEN BLOCKERS**
- **API repository:** `akefadaka-git/arna-api`
- **API main baseline:** `cd13c555976ed74050920bb69dd5c0d013ec9509`
- **App repository:** `akefadaka-git/arna-app`
- **App main baseline:** `648815b69b6419554b656ff91bf5b53df211aebb`
- **Companion App inventory:** [ARNA Shareholder App A2 inventory](https://github.com/akefadaka-git/arna-app/blob/docs/shareholder-a2-reconciliation-2026-11-27/docs/shareholder/ARNA-SHAREHOLDER-APP-A2-INVENTORY-2026-11-27.md)
- **Change boundary:** Documentation only. No application code, migrations, seeders, workflows, production data, database, main branch, PR merge, or deployment was changed.

## 1. Executive disposition

ARNA already has a substantive Shareholder domain. Rebuilding it would duplicate canonical identity, share-class, holdings, ledger, certificate, authorization and audit structures. The correct direction is to preserve and extend the existing domain.

The domain is **not end-to-end complete**. The largest gaps are:
1. The statutory shareholder register is not yet proven complete or independently verified.
2. Share transfer is represented by ledger entry types, but there is no complete transfer request/approval/settlement aggregate and HTTP lifecycle.
3. Certificate replacement is implemented at service level but not exposed as an API operation; rendering/signing/delivery is not part of the current API contract.
4. Import review and approval are persisted, but no HTTP controller/route or import execution operation exists.
5. Dividend engine and portal statements remain on open, stacked PRs, not on either current main branch.
6. Main contains a limited Governance meeting/attendance foundation. Full GA notice, agenda, voting, resolution and minutes lifecycle is on unmerged API PR #235; corresponding App work is also unmerged.
7. Shareholder Portal has own-holdings and own-certificates pages, but no shareholder profile, dividends, GA or unified portfolio route on current App main.
8. Admin Console has no shareholder routes on current App main.
9. Current API and App main verification runs both fail, so current-main production readiness is not demonstrated.

**A2 finding:** the source inventory and integration gap register are complete for the two inspected main snapshots. A2 should not be described as runtime-verified or production-ready. The two current-main CI failures and legal-evidence dependencies remain explicit blockers for later A4/operational acceptance.

## 2. Method and evidence rules

This assessment inspected the recursive Git trees, source files, route declarations, migrations, seeders, permission catalogues, tests, open PR metadata and available workflow results at the exact main SHAs above.

Status meanings:
- **PRESENT** — source is on the inspected main branch.
- **PARTIAL** — a foundation exists but the full business lifecycle or integration is absent.
- **PR-ONLY** — implementation exists only on an unmerged branch/PR.
- **MISSING** — no implementation was found on the inspected main snapshots.
- **UNVERIFIED** — source presence does not prove runtime behavior; required exact-head evidence is missing or failing.
- **BLOCKED** — must not be activated until the specified legal, source, security or operational prerequisite is satisfied.

This is not a live database inspection, a production-data reconciliation, a legal opinion, a security penetration test, or a successful execution of the full test suite.

## 3. API domain inventory

Canonical domain root:

`arna_core/src/Domains/Shareholder/`

The current API main tree contains:
- **Models:** `Shareholder`, `ShareClass`, `ShareHolding`, `ShareHoldingEntry`, `ShareCertificate`, `ShareholderImportSubmission`, `ShareholderImportDecision`.
- **Controllers:** `ShareholderController`, `MyShareholderController`, `ShareClassController`, `ShareHoldingController`, `CertificateController`.
- **Services:** `ShareholderService`, `ShareholderNumberReconciler`, `ShareClassService`, `ShareHoldingService`, `CertificateService`, CSV preview/validation and import-review services.
- **Repository:** `ShareholderRepository`.
- **Policies:** `ShareholderPolicy`, `ShareClassPolicy`, `ShareHoldingPolicy`, `CertificatePolicy`.
- **Requests/resources:** store/update request classes and shareholder, share-class, holding and certificate resources.
- **Events/audit:** shareholder registered/updated, holding changed and certificate issued events, wired to `AuditShareholderEvent`.
- **Configuration/locales:** shareholder configuration plus Amharic and English type/status catalogues.
- **Seeders:** share classes, shareholder permissions, shareholder roles, and root shareholder register/bootstrap seeders.

The Shareholder provider is registered in `bootstrap/providers.php` (provider registry line 447 in the inspected main snapshot). It merges config, loads translations, binds services, registers four policies, connects audit listeners, and loads the domain migrations and routes when enabled. The config defaults `ARNA_SHAREHOLDER_ENABLED` to true.

**Documentation inconsistency:** the provider's historical deployment-sequencing comment says the provider is not registered, while the current provider registry does register it. Refresh this stale comment during a documentation-maintenance change; do not change provider behavior as part of A2.

## 4. Complete Shareholder API route inventory on API main

The domain route file is `arna_core/src/Domains/Shareholder/Routes/api.php`, loaded under the configured `/api/shareholders` prefix and authenticated tenant-membership middleware.

There are **14 route declarations / 15 HTTP method-path combinations**:

| Method | Path | Purpose | Main status |
|---|---|---|---|
| GET | `/api/shareholders/me` | Resolve caller's own directly linked individual shareholder | PRESENT |
| GET | `/api/shareholders/classes` | List share classes | PRESENT |
| GET | `/api/shareholders/classes/{shareClass}` | View share class | PRESENT |
| GET | `/api/shareholders/holdings?shareholder_id={id}` | List a shareholder's holdings | PRESENT |
| GET | `/api/shareholders/holdings/{shareHolding}` | View one holding | PRESENT |
| POST | `/api/shareholders/holdings/entries` | Append a holding ledger entry | PRESENT |
| GET | `/api/shareholders/certificates?shareholder_id={id}` | List certificates | PRESENT |
| GET | `/api/shareholders/certificates/{certificate}` | View certificate metadata | PRESENT |
| POST | `/api/shareholders/certificates` | Issue certificate | PRESENT |
| POST | `/api/shareholders/certificates/{certificate}/cancel` | Cancel certificate | PRESENT |
| GET | `/api/shareholders` | Paginated/filterable shareholder register | PRESENT |
| POST | `/api/shareholders` | Register shareholder | PRESENT |
| GET | `/api/shareholders/{shareholder}` | View shareholder detail | PRESENT |
| PATCH, PUT | `/api/shareholders/{shareholder}` | Update shareholder | PRESENT |

Important boundaries:
- Tenant identity is resolved server-side; controllers cross-check tenant ownership and return not-found for cross-tenant resource IDs.
- `/me` resolves by the authenticated user's direct shareholder link and intentionally does not accept a client-supplied shareholder ID. A representative link is not treated as personal ownership.
- Holdings use an append-only ledger; `share_holdings.current_quantity` is a projection. Writes are intended to be transactional and row-locked through `ShareHoldingService`.
- There is no certificate replacement HTTP route, no shareholder delete route, and no dedicated transfer aggregate/approval route.
- The certificate service has a replacement method, but the controller explicitly excludes it from the frozen HTTP manifest. Certificate rendering, signing and delivery are not provided by this API surface.
- No shareholder-import preview/review/approve HTTP routes exist on main.

A separate public endpoint exists: `POST /api/public/document-verifications/verify`. It verifies a public share-certificate reference without disclosing shareholder identity or tenant-scoped data and is rate-limited. It is not a substitute for an authenticated shareholder certificate-management API.

## 5. Database migration inventory

Eight migration files are directly relevant to Shareholder:

| Location | Migration | Purpose |
|---|---|---|
| Domain | `2026_09_11_000001_create_share_classes_table.php` | Share-class reference data |
| Domain | `2026_09_11_000002_create_shareholders_table.php` | Shareholder register |
| Domain | `2026_09_11_000003_create_share_holdings_table.php` | Current-state holding projection |
| Domain | `2026_09_11_000004_create_share_holding_entries_table.php` | Append-only holding ledger |
| Domain | `2026_09_11_000005_create_share_certificates_table.php` | Certificate records/lifecycle |
| Domain | `2026_10_21_000001_reconcile_existing_shareholder_numbers.php` | Reconcile existing shareholder numbers |
| Root | `2026_10_18_000006_add_shareholder_business_number.php` | Add shareholder business number |
| Root | `2026_11_25_000004_create_shareholder_import_review_tables.php` | Persist import submissions and decisions |

Migration reconciliation findings:
- The migration-discovery test asserts that the domain migration path is registered, numbering migrations are discovered once and in timestamp order, and the shareholder-number schema migration is not duplicated under the domain path.
- The main branch has **no statutory-register metadata migration** for registered address, register-entry date, source provenance or verification metadata. Those fields are proposed in open API PR #158 only.
- No transfer-request/approval aggregate schema was found in the Shareholder domain.
- The import-review migration creates review persistence, not import execution or a complete import HTTP boundary.
- The GA A3 migrations are on API PR #235, not current main.
- Migration presence is not proof that the migrations successfully apply to a clean database. The current-main clean-install run below is failing.

## 6. Shareholding and statutory-register integrity

### 6.1 Existing ownership architecture

The intended model is:
- `ShareHolding` = current-state projection for one shareholder/share-class pair.
- `ShareHoldingEntry` = append-only ledger and authoritative history.
- Every balance mutation must preserve `balance_before + quantity = balance_after`.
- A holding is not deleted merely because its balance reaches zero.
- The service is responsible for transactional updates and row locking.

This is a sound foundation and must be preserved.

### 6.2 Missing statutory-register completeness

Current main does not establish a complete statutory register. The source inventory does not show all required statutory fields and independently verified source evidence. The open PR #158 proposes:
- registered address distinct from the linked user's profile address;
- nullable register-entry date;
- source type/reference;
- explicit `unverified` state and verifier metadata;
- regression protection against self-promoting a record to verified.

PR #158 does not invent unknown dates or addresses and is the correct bounded direction to reconcile. It does not by itself authenticate the original register or prove statutory completeness.

### 6.3 Transfer lifecycle

The ledger vocabulary includes transfer-in/transfer-out entry types, but the current API does not expose a complete shareholder-to-shareholder transfer lifecycle with:
- request and source evidence;
- seller/buyer validation;
- independent approval;
- share-class/serial and quantity controls where legally required;
- paired debit/credit ledger entries in one transaction;
- certificate cancellation/reissue linkage;
- consideration/payment linkage to Finance;
- audit and dispute/correction handling.

Do not implement transfers as arbitrary operator-entered ledger adjustments. Reconcile the proposed lifecycle and legal instruments first.

## 7. Import workflow

The current main has CSV preview/validation and a persisted review/approval service. The documented workflow requires:
1. preview-only parsing and structural validation;
2. tenant-scoped submission with payload digest and source references;
3. full-row review by a second user with evidence references;
4. approval by a third distinct user;
5. append-only decisions and mandatory audit persistence.

**Critical boundary:** `approved_for_import` is an approval state only. It does not create shareholders, allocate shares, post subscriptions/payments or execute imports. The contract explicitly says no routes, controllers, upload storage or import execution are included.

The three permissions `shareholder.import.submit`, `shareholder.import.review`, and `shareholder.import.approve` are in the canonical permission catalogue. The contract says no role grants are added; authorized administrators must assign them. Keep the submitter, reviewer and approver distinct.

## 8. Permission and role reconciliation

### 8.1 Shareholder domain permissions (13)

The domain permission seeder manages these 13 tenant-scoped slugs:

| Permission | Meaning |
|---|---|
| `shareholder.view_own` | View own shareholder record |
| `shareholder.view` | View shareholder register/records |
| `shareholder.create` | Create shareholder |
| `shareholder.update` | Update shareholder |
| `share_class.view` | View share classes |
| `share_class.manage` | Manage share classes |
| `share_holding.view_own` | View own holdings |
| `share_holding.view` | View holdings |
| `share_holding.entry` | Record holding ledger entry |
| `certificate.view_own` | View own certificates |
| `certificate.view` | View certificates |
| `certificate.issue` | Issue certificate |
| `certificate.cancel` | Cancel certificate |

### 8.2 Import permissions (3)

| Permission | Current state |
|---|---|
| `shareholder.import.submit` | Catalogued; no default role grant |
| `shareholder.import.review` | Catalogued; no default role grant |
| `shareholder.import.approve` | Catalogued; no default role grant |

### 8.3 Current role matrix

The ShareholderRolesSeeder documents this matrix:

| Role | Shareholder-domain grants |
|---|---|
| `super_admin` | All 13 |
| `platform_admin` | All except `certificate.cancel` (12) |
| `tenant_admin` | 7 administrative grants: shareholder view/create/update, share-class view, holding view, certificate view/issue |
| `manager` | Three own-record grants |
| `shareholder` | Three own-record grants: shareholder.view_own, share_holding.view_own, certificate.view_own |

The seeder uses composite synchronization to preserve grants from other domains. The self-service policies use direct-link checks and tenant scoping; the own-record endpoint itself is intentionally server-resolved.

**Reconciliation note:** some seeder/controller comments still describe own-holding/certificate grants as inert or describe route-level permission middleware that is not present on every corresponding route. The executable policy/controller checks are the operative contract. Update stale comments and add a permission-to-policy-to-route contract test in A3; do not alter grants without a reviewed RBAC matrix.

No dedicated permission currently exists for certificate replacement, statutory-register verification, transfer approval, dividend viewing/management, GA notices, proxy approval or ballot submission. Those must be designed with domain ownership and separation-of-duties controls before implementation.

### 8.4 Adjacent Governance permissions used by the GA/election integration

These are Governance-owned permissions, not Shareholder permissions. They are present in `GovernancePermissionsSeeder` and must remain owned by Governance:

- Meetings: `governance.meeting.view`, `governance.meeting.create`, `governance.meeting.manage`, `governance.meeting.approve`.
- Agendas: `governance.agenda.view`, `governance.agenda.manage`.
- Attendance: `governance.attendance.view`, `governance.attendance.manage`.
- Minutes: `governance.minute.view`, `governance.minute.manage`, `governance.minute.approve`.
- Resolutions: `governance.resolution.view`, `governance.resolution.create`, `governance.resolution.manage`, `governance.resolution.approve`.
- Elections: `governance.election.view`, `governance.election.manage`, `governance.election.authorize`, `governance.election.schedule`, `governance.election.suspend`, `governance.election.cancel`, `governance.election.archive`.

These 22 adjacent permissions do not by themselves authorize statutory activity. Route-level and policy checks, authenticated legal evidence, independent approvals and the GA fail-closed gates remain mandatory. No new GA-specific permissions are to be invented during UI work without an approved API contract.

## 9. Frontend integration summary

The complete route/component inventory is in the companion App A2 record. The main findings are:
- Workspace has five shareholder routes: list, create, share classes, detail and edit.
- Shareholder feature components include list, form, detail, share-class list, holding-entry form, certificate-issue form and certificate-cancel dialog.
- Portal has only two shareholder routes: own holdings and own certificates. Both consume the shared `GET /api/shareholders/me` identity state.
- Portal has no shareholder profile-edit, dividend, GA, proxy, voting, resolution-history or consolidated portfolio route on main.
- Admin route tree has only the admin landing/dashboard and compatibility redirects. It has no shareholder route.
- Notifications Center is a generic inbox; there is no proven shareholder event-to-delivery integration.
- App main contains no shareholder-specific component/route contract test file.

## 10. Cross-domain integration contracts

| Concern | Authoritative owner | Shareholder integration required |
|---|---|---|
| Shareholder identity/register | Shareholder | Canonical shareholder and linked-user/representative relationships |
| Shares/classes/ownership history | Shareholder | Ledger-backed holdings and entitlements |
| Share transfers/subscriptions | Shareholder + Finance | Paired ledger operation and Finance settlement evidence |
| Dividends/tax/payment | Finance | Read-only shareholder statement/portfolio projection |
| Ordinary/extraordinary GA | Governance | Electorate snapshot from verified share ledger; notices, proxies, attendance, voting and resolutions |
| Notifications | Notification/Communication | Event-triggered recipient inbox, delivery channel and evidence |
| Documents/certificates | Documents + Shareholder | Secure certificate artefact, verification, version/history and access control |
| Login/SSO | Auth | Authenticated Portal/Workspace/Admin sessions and tenant membership |
| Admin control plane | Admin | Privileged oversight and configuration, not corporate approval authority |

The shareholder portfolio must be a read model, not a second ledger. It should aggregate authoritative Shareholder, Finance, Wallet and Governance records and show source timestamps/statuses.

## 11. General Assembly reconciliation

Current API main contains a limited Governance meeting foundation:
- meeting list/create/show;
- attendance;
- proxy submission/review;
- penalty assessment/settlement;
- attendance corrections and disputes.

Current main does not contain the complete statutory GA lifecycle for notices, ordered agenda freeze, motions, weighted ballots, tally/resolution certification and signed/versioned minutes.

API PR #235 is an unmerged API-only A3 increment that adds substantial fail-closed legal evidence and GA lifecycle controls. Its own description states:
- legal form, operative constitutional instruments/amendment chain, current authoritative register and independent Ethiopian corporate-law opinion remain unauthenticated/not obtained;
- secret ballots are not implemented;
- notice issuance records portal recipients but does not send email/SMS;
- ETB 50/300 penalties are not legally activated and no automatic Finance posting is enabled;
- no App frontend screens are included.

Therefore:
- ordinary and extraordinary GA classification and lifecycle remain **PARTIAL / PR-ONLY**;
- legal parameters remain **BLOCKED** pending authenticated source evidence and independent legal review;
- the App GA workspace remains a separate integration task;
- no statutory notices, votes, penalties or resolutions may be activated merely because a code path exists.

## 12. Notifications, dividends and portfolio

### 12.1 Notifications

API main has a generic tenant/user-scoped inbox:
- `GET /api/notifications`
- `GET /api/notifications/unread-count`
- `POST /api/notifications/{id}/read`
- `POST /api/notifications/read-all`

App main has a generic Notifications Center and API adapter. This proves inbox read/mark-read integration, not delivery of shareholder lifecycle events. A queued GA portal recipient is not evidence of email/SMS delivery. A3 must define shareholder event mapping, notification templates, channel adapters, outbox/retry/failure evidence and legal-notice delivery semantics.

### 12.2 Dividends

API main contains a Finance `ShareholderDividendPayable` model, but no `/api/shareholders/me/dividends` route was found on main. Dividend engine API PRs #81 and #83 are open and stacked. App PRs #33 and #35 are open and stacked. These contain significant dividend calculation, tax, accounting, payment-control and portal-statement work, but they are not delivered on current main.

### 12.3 Portfolio

Current portal surfaces are own holdings and certificates, with wallet and loan self-service pages owned by their respective domains. There is no complete shareholder portfolio aggregate or dividend statement page on main. Portfolio should be delivered as a typed read model that aggregates authoritative domain data and never writes duplicated balances.

## 13. API test inventory

Current API main contains these Shareholder-focused tests:

**Domain feature tests**
- `arna_core/src/Domains/Shareholder/Tests/Feature/ShareholderRegistryTest.php`
- `arna_core/src/Domains/Shareholder/Tests/Feature/ShareHoldingTest.php`
- `arna_core/src/Domains/Shareholder/Tests/Feature/CertificateTest.php`
- `arna_core/src/Domains/Shareholder/Tests/Feature/TenantIsolationTest.php`

**Domain unit tests**
- `arna_core/src/Domains/Shareholder/Tests/Unit/ShareholderServiceTest.php`
- `arna_core/src/Domains/Shareholder/Tests/Unit/ShareHoldingServiceTest.php`
- `arna_core/src/Domains/Shareholder/Tests/Unit/ShareholderImportPreviewServiceTest.php`
- `arna_core/src/Domains/Shareholder/Tests/Unit/ShareholderImportReviewPolicyTest.php`

**Application-level feature tests**
- `tests/Feature/ShareholderImportReviewPersistenceTest.php`
- `tests/Feature/ShareholderMigrationDiscoveryTest.php`
- `tests/Feature/ShareholderNumberReconciliationTest.php`
- `tests/Feature/ShareholderSeedNumberInitializationTest.php`

The PHPUnit Domain suite recursively discovers domain tests. Existing coverage establishes useful registry, holdings, certificate, tenant isolation, service, import-review and migration-discovery contracts. Gaps remain for:
- certificate replacement HTTP flow and artefact lifecycle;
- complete shareholder transfer request/approval and paired ledger invariants;
- statutory register evidence verification and history;
- actual import execution (once separately authorized);
- API/UI contract compatibility across Workspace, Portal and Admin;
- shareholder notification event delivery;
- dividend statement API and Finance integration;
- GA electorate snapshot reconciliation and full frontend lifecycle;
- end-to-end tests for each supported application scope.

## 14. Current-main verification evidence

| Repository | Exact main SHA | Latest inspected run | Result |
|---|---|---|---|
| API | `cd13c555976ed74050920bb69dd5c0d013ec9509` | [Run #45955595166](https://github.com/akefadaka-git/arna-api/actions/runs/45955595166) | **FAIL** — fresh disposable MySQL migration step |
| App | `648815b69b6419554b656ff91bf5b53df211aebb` | [Run #45955185107](https://github.com/akefadaka-git/arna-app/actions/runs/45955185107) | **FAIL** — type-check and build all applications |

The available job metadata identifies the failing steps but does not establish the underlying root cause. Do not attribute either failure to Shareholder without examining the corresponding logs and reproducing against the exact SHA.

## 15. Open PR reconciliation register

Open PRs were searched in both repositories. The following are the shareholder, governance, dividend, identity/SSO, notification, Admin and migration items that materially affect this domain. PR state and head SHAs are repository snapshots at assessment time.

### API repository

| PR | Subject | A2 disposition |
|---|---|---|
| [#96](https://github.com/akefadaka-git/arna-api/pull/96) / [#97](https://github.com/akefadaka-git/arna-api/pull/97) | Earlier unified API-App integration plan and A2 closure package | Historical integration/A2 lineage. Treat as prior evidence only; the exact current-main inventories and unresolved gaps in this record supersede stale baselines. |
| [#94](https://github.com/akefadaka-git/arna-api/pull/94) | Earlier Shareholder A2 baseline, 2026-11-08 | Historical/partial baseline. Its API/App SHAs are stale; this record supersedes its inventory after Owner acceptance. |
| [#158](https://github.com/akefadaka-git/arna-api/pull/158) | Statutory register identity metadata | Relevant bounded PR-only work. Rebase/reconcile against current main; preserve unknown values as NULL/unverified; no invented legal facts. |
| [#129](https://github.com/akefadaka-git/arna-api/pull/129) | Shareholding ledger/projection reconciliation for AGE | Stacked on an AGE feature branch, not main; CI/DB validation noted as not run. Do not merge independently. Reconcile its invariant into the canonical ledger/GA design. |
| [#155](https://github.com/akefadaka-git/arna-api/pull/155) | Earlier self-service ownership tests | Source branch says its current-main port is in #193/#195; do not merge independently. |
| [#192](https://github.com/akefadaka-git/arna-api/pull/192) | Current-main self-service ownership regression tests | Open overlapping test PR. Compare exact diff with tests already present on main and merged #195 lineage before deciding whether any residual tests remain. |
| [#154](https://github.com/akefadaka-git/arna-api/pull/154) | Earlier notification atomicity/tenant-isolation test increment | Source lineage consolidated into #193/#195; do not merge independently. Verify current-main notification test coverage. |
| [#190](https://github.com/akefadaka-git/arna-api/pull/190) | Separate Finance governance-settlement duties | Open Finance security dependency for any future GA penalty/settlement integration; preserve maker/checker/executor separation and do not activate penalty posting without legal approval. |
| [#191](https://github.com/akefadaka-git/arna-api/pull/191) | Notification recipient atomicity/tenant-isolation tests | Source branch is superseded by the security integration lineage; verify current-main coverage rather than merging stale source. |
| [#193](https://github.com/akefadaka-git/arna-api/pull/193) | API security and tenant-isolation hardening | Explicitly superseded by merged #195. Do not merge this stale integration branch. |
| [#81](https://github.com/akefadaka-git/arna-api/pull/81) | Annual shareholder dividend engine | Open base-on-main feature; substantial partial engine, with accounting/payment/test gaps stated in PR. |
| [#83](https://github.com/akefadaka-git/arna-api/pull/83) | Dividend accounting, tax, payment and cancellation controls | Stacked on #81; not independently mergeable to main. PR reports prior failed dividend tests and pending rerun/evidence. |
| [#235](https://github.com/akefadaka-git/arna-api/pull/235) | GA statutory evidence and lifecycle gates | Open draft, API-only A3; legal evidence blockers and no App UI are explicitly recorded. Reconcile latest head and exact-head evidence before any integration. |
| [#65](https://github.com/akefadaka-git/arna-api/pull/65) | Browser session SSO endpoints | Cross-cutting Portal/Workspace dependency; verify current-main auth contract before integration. |
| [#224](https://github.com/akefadaka-git/arna-api/pull/224) | Admin master control-plane API | Cross-cutting Admin dependency; open draft. Coordinate with App #137; no shareholder Admin routes are present on main. |
| [#203](https://github.com/akefadaka-git/arna-api/pull/203) | Unified database migration and safe seed lifecycle | Cross-cutting migration/seed risk. Reconcile with the current migration inventory and clean-install failure; no blind merge. |
| [#234](https://github.com/akefadaka-git/arna-api/pull/234) | Current-main API release preparation | Cross-cutting release baseline; open draft and separate from this A2. Its exact current head needs clean-install reconciliation. |
| [#209](https://github.com/akefadaka-git/arna-api/pull/209) / [#211](https://github.com/akefadaka-git/arna-api/pull/211) | Bootstrap identities and shareholder-data preservation | #211 is stacked on #209. PR #211 itself says the parent 102-identity provisioning conflicts with the Owner-approved reference-only baseline; do not merge either as a shareholder-data shortcut. |
| [#228](https://github.com/akefadaka-git/arna-api/pull/228) | Non-destructive default seed baseline | Cross-cutting seed safety; reconcile against the authoritative ShareholderRegister/ShareholderSeeder behavior before any bootstrap or A5 operation. |

### Governance / GA / AGE electorate dependencies

The following open Governance PRs materially affect the shareholder register as the source for meeting electorate, record-date holdings, proxy and election eligibility. They are Governance-owned dependencies, not a reason to duplicate the shareholder register inside Governance.

| PR | Subject | A2 disposition |
|---|---|---|
| [#52](https://github.com/akefadaka-git/arna-api/pull/52) | Tenant-scoped meeting lifecycle foundation | Open legacy foundation. Current main has only a limited meeting/attendance/proxy/penalty surface; reconcile remaining deltas with #235 before selecting a candidate. |
| [#77](https://github.com/akefadaka-git/arna-api/pull/77) | Earlier Board Election API foundation | WIP/legacy election lineage; do not merge wholesale. Reconcile current-main election work and the approved AGE scope. |
| [#126](https://github.com/akefadaka-git/arna-api/pull/126) | AGE A2 source/register baseline | Governance evidence dependency; preserve its source dispositions and reconcile with the current authoritative register evidence. |
| [#127](https://github.com/akefadaka-git/arna-api/pull/127) | AGE candidate intake eligibility | Election dependency; eligibility rules must not be inferred from incomplete shareholder metadata. |
| [#128](https://github.com/akefadaka-git/arna-api/pull/128) | Voter-register preparation, reconciliation and freeze | Depends on verified shareholder register/ledger inputs; preserve immutable record-date snapshot semantics. |
| [#130](https://github.com/akefadaka-git/arna-api/pull/130) | Isolate voter-register management permission | Governance RBAC dependency; reconcile with the current permission catalogue and role grants. |
| [#131](https://github.com/akefadaka-git/arna-api/pull/131) | Bind voter-register metadata into integrity hash | Integrity dependency for electorate snapshots. |
| [#132](https://github.com/akefadaka-git/arna-api/pull/132) / [#133](https://github.com/akefadaka-git/arna-api/pull/133) | Server-derived inputs and lifecycle/rollback tests | Governance test dependencies; re-run on the selected current-main candidate. |
| [#134](https://github.com/akefadaka-git/arna-api/pull/134) / [#135](https://github.com/akefadaka-git/arna-api/pull/135) | Lock voter rows during reconciliation/freeze and approval | Concurrency/integrity dependencies; retain row-lock semantics and verify on disposable MySQL. |
| [#136](https://github.com/akefadaka-git/arna-api/pull/136) / [#139](https://github.com/akefadaka-git/arna-api/pull/139) | Frozen-register immutability and monotonic lifecycle | Required controls for trustworthy record-date electorate. |
| [#137](https://github.com/akefadaka-git/arna-api/pull/137) / [#138](https://github.com/akefadaka-git/arna-api/pull/138) | Physical ballot custody gate and witnessed manual count | Election operations, not shareholder CRUD; depend on a frozen, verified register. |
| [#140](https://github.com/akefadaka-git/arna-api/pull/140) / [#141](https://github.com/akefadaka-git/arna-api/pull/141) / [#142](https://github.com/akefadaka-git/arna-api/pull/142) | Register HTTP authorization, role grants and lifecycle attestation | Governance register access/integrity dependencies; verify role separation and legal source evidence. |
| [#143](https://github.com/akefadaka-git/arna-api/pull/143) / [#144](https://github.com/akefadaka-git/arna-api/pull/144) / [#145](https://github.com/akefadaka-git/arna-api/pull/145) / [#146](https://github.com/akefadaka-git/arna-api/pull/146) | AGE receipt anti-rollback, integration contracts, adapter and transactional outbox | Receipt/evidence infrastructure; not a substitute for shareholder register source validation. |
| [#148](https://github.com/akefadaka-git/arna-api/pull/148) | Reconcile Board Election branches with current main | Documentation/branch-lineage dependency; use to avoid duplicate or stale election branch integration. |
| [#149](https://github.com/akefadaka-git/arna-api/pull/149) / [#151](https://github.com/akefadaka-git/arna-api/pull/151) | Election committee security and election-core integration | Governance-owned election workflow; current-main refresh and independent verification required. |
| [#152](https://github.com/akefadaka-git/arna-api/pull/152) / [#189](https://github.com/akefadaka-git/arna-api/pull/189) | Independent proxy review / prevent proxy submitter self-review | Security-sensitive proxy separation; reconcile the latest fix with merged security lineage and test no self-review. |
| [#157](https://github.com/akefadaka-git/arna-api/pull/157) | Evidence-neutral election dispute foundation | Election dispute dependency; do not treat as GA meeting resolution workflow. |
| [#161](https://github.com/akefadaka-git/arna-api/pull/161) | Election authority foundation | Depends on authenticated authority evidence and approved Governance scope. |
| [#172](https://github.com/akefadaka-git/arna-api/pull/172) | A5 election lifecycle remediation verification | A5/verification lineage only; not an A3 implementation source or authorization to deploy. |

These PRs are all open at assessment time. Their presence does not mean they are compatible with current main or approved for integration. Select one canonical candidate per capability, reconcile its full diff against current main, and preserve the separate Governance/Shareholder ownership boundary.

### App repository

| PR | Subject | A2 disposition |
|---|---|---|
| [#15](https://github.com/akefadaka-git/arna-app/pull/15) | Sanctum session SSO in Workspace and Portal | Cross-cutting Portal/Workspace authentication dependency; open draft. Verify current-main API pairing with API #65. |
| [#33](https://github.com/akefadaka-git/arna-app/pull/33) | Annual shareholder dividend statement portal | Open draft, depends on API #81 and adds `/my/dividends`; not on main. |
| [#35](https://github.com/akefadaka-git/arna-app/pull/35) | Dividend administration and portal integration | Stacked on #33; A4 acceptance and integration evidence explicitly not closed. |
| [#7](https://github.com/akefadaka-git/arna-app/pull/7) | Original Governance meetings workspace | Stacked on an integration branch; preserve as lineage, not a current-main merge target. |
| [#113](https://github.com/akefadaka-git/arna-app/pull/113) | Governance meeting/election UI reconciliation | Open draft; older current-main candidate. |
| [#120](https://github.com/akefadaka-git/arna-app/pull/120) | Governance meetings/elections current-main UI | Open draft; newer of #113/#120, but its tested base is stale relative to current main. Select one candidate, rebase and test; do not merge both. |
| [#137](https://github.com/akefadaka-git/arna-app/pull/137) | Admin master console frontend | Open draft; current Admin control-plane work, paired with API #224. It is not yet a shareholder Admin module and must not create duplicate corporate approval authority. |
| [#93](https://github.com/akefadaka-git/arna-app/pull/93) | Notification locale and safe routing | Cross-cutting notification UX; open draft. It does not alone establish shareholder event delivery. |

PRs are evidence of candidate work, not evidence of merged functionality. Stacked branches, stale bases, earlier successful runs and PR descriptions must not be counted as current-main delivery.

## 16. Prioritized implementation backlog — no A3 work authorized by this record

The backlog below is the recommended sequence after A2 acceptance. Each item requires its own bounded A3 scope and acceptance tests.

| ID | Priority | Work package | Required completion evidence / dependency |
|---|---|---|---|
| SHD-P0-01 | P0 | Restore green exact-main verification | Diagnose API MySQL migration failure and App type-check/build failure on the exact SHAs; rerun exact-head CI. No domain implementation until baseline is understood. |
| SHD-P0-02 | P0 | Statutory register completeness and verification | Reconcile PR #158; authenticate original register/Articles/amendments; preserve source provenance, unknown NULLs and unverified state; independent reviewer evidence. |
| SHD-P0-03 | P0 | Ownership ledger and transfer lifecycle | Define and test transfer request, independent approval, paired ledger entries, idempotency, source evidence, certificate linkage and Finance settlement boundary. |
| SHD-P0-04 | P0 | Shareholder RBAC and tenant security contract | Verify all 16 Shareholder/import slugs plus the 22 adjacent Governance meeting/election slugs, role grants, policy paths, route guards, own-record isolation, representative access and segregation of duties. Resolve stale comments and add automated contract tests. |
| SHD-P0-05 | P0 | GA statutory lifecycle integration | Reconcile API #235 with main; authenticate legal evidence; add App meeting/notice/agenda/proxy/attendance/voting/resolution/minutes surfaces; keep all legal gates fail-closed. |
| SHD-P0-06 | P0 | Domain migration and seed integrity | Resolve clean-install failure; verify all 8 shareholder-related migrations, migration discovery/idempotence, non-destructive seed behavior and tenant isolation on disposable MySQL. |
| SHD-P1-01 | P1 | Certificate lifecycle completion | Add authorized replacement API/UI, immutable replacement chain, secure PDF/render/sign/download, public verification and cancellation/reissue tests. |
| SHD-P1-02 | P1 | Import API boundary and execution design | Expose preview/review/approve APIs only after contract review; design a separately authorized, idempotent import executor with immutable execution log, conflict strategy and rollback/correction controls. |
| SHD-P1-03 | P1 | Shareholder notifications | Map lifecycle events to Amharic-first templates; integrate inbox, email/SMS adapters, outbox/retry/failure states and evidence of issuance/delivery. |
| SHD-P1-04 | P1 | Dividend engine and shareholder statements | Reconcile API #81/#83 and App #33/#35 in dependency order; verify tax/legal evidence, class weights, balanced journals, maker-checker, settlement and statement reconciliation. |
| SHD-P1-05 | P1 | Shareholder portal completion | Add profile/KYC status, dividend statements, GA notices/documents, proxy and attendance status, resolution history, transfer requests and certificate downloads. Integrate SSO separately. |
| SHD-P1-06 | P1 | Unified shareholder portfolio read model | Aggregate ownership, certificates, dividends, wallet, loans and governance entitlements from their owning domains; no duplicated balances or write authority. |
| SHD-P1-07 | P1 | Admin Console shareholder oversight | Add narrowly scoped read/oversight and controlled exception routes only after API #224/#App #137 contract is stable; corporate decisions remain with corporate authorities, not technical admins. |
| SHD-P2-01 | P2 | KYC/UBO and representative lifecycle | Reconcile the pending KYC field matrix and legal requirements; define document evidence, beneficial ownership, representative authority, expiry and review without enabling unapproved policy. |
| SHD-P2-02 | P2 | Operational statements and reporting | Add shareholder statement exports, register snapshots, audit reports, reconciliation dashboards and data-quality exception queues. |

### Sequencing constraints

1. SHD-P0-01 and SHD-P0-06 establish a trustworthy technical baseline.
2. SHD-P0-02 and SHD-P0-03 establish trustworthy ownership evidence before GA electorate, dividends or portfolio values rely on it.
3. SHD-P0-04 must precede exposing new self-service/admin operations.
4. SHD-P0-05 depends on verified register/ledger inputs and authenticated legal evidence.
5. Dividend, notifications, portal and Admin integrations consume the stable domain contracts; they must not create duplicate ownership or financial records.
6. A5 deployment remains a separate approval stage and is not authorized by this A2 record.

## 17. Acceptance criteria for A2 closure

A2 source reconciliation is complete when the Owner accepts:
- the two exact main baselines and their source-of-truth status;
- the route, migration, permission, role, test and frontend inventories in this record and its App companion;
- the classification of PR-only work versus main-branch functionality;
- the prioritized backlog and domain ownership boundaries;
- the unresolved technical, legal and operational blockers.

A2 acceptance does **not** mean:
- any PR is approved or merged;
- any migration is run against production;
- the statutory register is legally verified;
- GA rules or penalties are activated;
- dividend amounts/tax treatment are approved;
- notifications are delivered;
- A3 implementation or A5 deployment is authorized.

## 18. Final A2 recommendation

**Recommendation: ACCEPT this source-level A2 reconciliation as the baseline for Owner review, with the listed blockers retained as open.**

Preserve the existing Shareholder domain. Do not rebuild it. Do not begin A3 until the Owner explicitly accepts/closes A2 and the P0 sequencing is authorized. No main-branch modification, PR merge, operational database action or deployment is included in this record.
