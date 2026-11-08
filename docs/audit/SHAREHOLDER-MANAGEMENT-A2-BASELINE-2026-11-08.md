# ARNA Shareholder Management — A2 Read-Only Implementation Baseline

- **Assessment date:** 2026-11-08
- **Assessment stage:** A2 — read-only reconciliation
- **Status:** PARTIAL — source inventory established; runtime/database and exact-head test evidence remain outstanding
- **API repository:** `akefadaka-git/arna-api`
- **API baseline:** `main` — `ff290b353f1290cc91534fd4d694544e83c58d82`
- **App repository:** `akefadaka-git/arna-app`
- **App baseline:** `main` — `76fb67a421b1a54e0529a3479e551a1d1e0c0d0f`
- **Change boundary:** Documentation-only A2 record. No application source, migration, workflow, production data, or main-branch change is included.

## 1. Executive finding

ARNA does not need a second shareholder-management domain. The current API main branch already contains a substantial MASES Core Shareholder domain, and the App main branch already contains shareholder administration and shareholder self-service experiences.

The canonical API domain is `mases_core/src/Domains/Shareholder/`. It already owns shareholder identity, share classes, shareholding projections, an append-only shareholding ledger, certificates, domain services, repositories, policies, resources, requests, routes, seeders and tests. The App already consumes these capabilities through `src/features/shareholders/` and `src/features/portal/`.

The principal missing capabilities are not safely reducible to “add more CRUD”. Several advanced capabilities are being developed on unmerged branches, and the authoritative Phase 2 shareholder charter is explicitly marked **DRAFT — NOT APPROVED — Phase 2 implementation BLOCKED**. Those facts must govern the next implementation slice.

## 2. Exact repository baseline

### API — `main`

- Default branch: `main`
- Exact commit: `ff290b353f1290cc91534fd4d694544e83c58d82`
- Repository visibility: public
- PHP constraint in `composer.json`: `^8.3`
- Framework: Laravel 12
- Shareholder route configuration documents the API prefix as `/api/shareholders`.

### App — `main`

- Default branch: `main`
- Exact commit: `76fb67a421b1a54e0529a3479e551a1d1e0c0d0f`
- Repository visibility: public
- Frontend: Vue 3 / TypeScript
- Four independent application entry points are an explicit repository architecture; the shareholder portal must remain a separate build surface from Workspace, Admin and the public site.

These SHAs are the comparison baseline for this assessment. Any subsequent implementation must record its own base and head SHAs.

## 3. API capabilities already present on main

| Capability | Source evidence | A2 disposition |
|---|---|---|
| Shareholder register | `Shareholder` model, repository, service, controller and resource | REUSE |
| Individual and legal-entity shareholder types | `ShareholderType` enum and `ShareholderService` invariants | REUSE; verify source/legal policy |
| Authorized representative linkage | `user_id` and `representative_user_id` model contract | REUSE |
| Share classes | `ShareClass` model, service, controller, policy, resource and seeder | REUSE |
| Current shareholding | `ShareHolding` projection | REUSE |
| Ownership history | `ShareHoldingEntry` append-only ledger | REUSE |
| Ledger mutation path | `ShareHoldingService` | REUSE; preserve as sole writer |
| Share certificates | `ShareCertificate`, `CertificateService`, controller and lifecycle enum | REUSE |
| Tenant-aware shareholder queries | `ShareholderRepository` | REUSE; verify all call paths |
| Authorization | Shareholder, ShareHolding, ShareClass and Certificate policies | REUSE; regression-test own-vs-admin access |
| Authenticated own-shareholder lookup | `GET /api/shareholders/me` via `MyShareholderController` | REUSE |
| Audit/domain events | Registration, update, holding-change and certificate events | REUSE |
| Finance shareholder payable linkage | `ShareholderDividendPayable` model | REUSE; do not duplicate in Shareholder domain |

### Existing route surface

The current Shareholder API route file declares:
- `GET /api/shareholders/me`
- Shareholder list, create, show and update routes.
- Share-class list and detail routes.
- Shareholding list and detail routes.
- Shareholding ledger-entry creation.
- Certificate list, detail, issue and cancel routes.

The route group inherits authentication and permission controls. Object-level policies are used for shareholder, holding and certificate access. The exact authorization behavior still requires targeted tests against the baseline SHA; source presence alone is not a security certification.

### Canonical ownership invariant

The model contracts explicitly separate:
- `Shareholder`: identity of the registered owner.
- `ShareHolding`: current-state projection for a shareholder/share-class pair.
- `ShareHoldingEntry`: append-only source ledger for every balance change.
- `ShareCertificate`: attestation derived from the holding, not an independent ownership ledger.

Do not add another shareholder balance table or allow controllers to update `current_quantity` directly. New issuance, transfer, reinvestment or correction workflows must use the canonical service and ledger, with transaction and idempotency protections.

### KYC limitation

The Shareholder model documentation expressly states that KYC data is separate and is to be defined by a future profile after the D12 field-matrix decision. Do not create an improvised KYC table or treat the existing shareholder identity fields as a completed KYC workflow.

## 4. App capabilities already present on main

### Workspace / administration

The App main branch contains:
- Shareholder API contracts and service orchestration.
- Shareholder list, detail and create/edit forms.
- Share-class list view.
- Holding-entry form.
- Certificate issue form and cancellation dialog.
- Dedicated routes for shareholder list, detail and form workflows.

### Shareholder self-service portal

The App main branch contains:
- Shared `useMyShareholder` identity resolution through `GET /api/shareholders/me`.
- Portal home / identity summary.
- Own shareholdings page, including holding-history drill-down.
- Own certificates page.
- Portal wallet and loan pages.

The own-shareholder composable does not accept a client-supplied shareholder ID, does not search by caller-provided name/email, and does not treat a representative relationship as automatic ownership. Preserve this boundary.

### UI integration rule

New shareholder screens must live in the existing shareholder or portal feature areas and use the existing API client/service conventions. Do not create another portal entry point, duplicate shared identity state, or spread shareholder logic into unrelated feature folders.

## 5. Governance and Finance integrations

### Governance already on main

The API main branch contains a Governance meeting attendance controller and migration for meeting attendance, proxies and penalties. The App main branch contains governance attendance and Finance settlement workspace code. The attendance controller states that penalty assessment is an authorized action and that settlement rows are requests for Finance review; the attendance workflow must not directly mutate dividends or financial ledgers.

The governance attendance workflow is therefore an existing integration to reuse, not a reason to create duplicate meeting or penalty tables inside Shareholder.

### Dividend work not yet integrated into main

The API contains open, unmerged dividend implementation work:
- [API PR #81 — Build annual shareholder dividend distribution engine](https://github.com/akefadaka-git/arna-api/pull/81), based on an earlier main SHA. It describes year-specific distribution records, immutable eligibility snapshots derived from the canonical shareholding ledger, decimal-safe pro-rata allocation, maker-checker approval and self-service dividend statements. Its own description says distribution accounting accrual, tax remittance, bank/provider execution, payment reconciliation and dividend-specific tests were outstanding at that PR head.
- [API PR #83 — Complete dividend accounting, tax, payment and cancellation controls](https://github.com/akefadaka-git/arna-api/pull/83), stacked on PR #81. It adds accounting, tax, payment, cancellation and reinvestment controls, but its recorded A4 status is **NOT CLOSED**: a clean-install feature-test run failed (9 passed, 5 failed), a repair SHA was recorded, and the rerun was pending in the PR description.

The App progress report also identifies open work for dividend administration, the shareholder dividend statement portal and dividend portal integration. These are not evidence of merged functionality on App main.

**Disposition:** Do not reimplement dividend distribution or create a competing dividend payable model. Reconcile PR #81/#83 and the corresponding App branches against the current main SHAs, verify exact heads, and continue only the remaining gaps.

## 6. Board elections and GA voting

The current repositories contain governance meeting and attendance functionality. Board-election API and App work is described in the repository progress report as WIP/open work rather than a verified, completed main-branch capability.

The following election details remain a legal/business-rule dependency and must not be guessed: electorate cutoff, eligible-share snapshot, quorum, majority/tie rules, ballot secrecy, challenge/appeal process, certification and approval authority.

The previously established Owner direction that shareholder voting weight is based on shares (not person/entity headcount) should be retained as a design constraint. Its operational formula and interaction with company articles, resolutions and statutory rules still require a frozen, authoritative specification.

## 7. Authoritative charter conflict / implementation blocker

The API main branch contains `docs/PHASE_2_SHAREHOLDER_CHARTER.md`, version 0.4.0, dated 2026-09-10. Its header explicitly says:

- Status: **DRAFT — NOT APPROVED**
- Phase 2 implementation: **BLOCKED**
- The document is a decision register, not a complete specification.
- Decisions D1–D20 for Shareholder Registry & Capital, D21–D44 for Transfers/Dividends/Meetings/Voting, D57–D104 for Governance & Board and further cross-cutting decisions remain unresolved or deferred in the register.
- The charter's source-of-truth hierarchy puts Ethiopian law and regulations, the Articles of Association / Memorandum / bylaws, and approved company policies above the charter and implementation.
- KYC field requirements and several voting, transfer, dividend and governance rules depend on authoritative decisions.

This is a material conflict with a blanket instruction to implement every missing shareholder feature. Code may be extended only for requirements already supported by an approved source and a testable contract. Unresolved business/legal rules must remain DEFERRED / UNRESOLVED; no defaults may be invented.

## 8. Existing tests and verification evidence

Source-level test files found on API main include:
- `mases_core/src/Domains/Shareholder/Tests/Unit/ShareholderServiceTest.php`
- `mases_core/src/Domains/Shareholder/Tests/Unit/ShareHoldingServiceTest.php`
- `mases_core/src/Domains/Shareholder/Tests/Feature/ShareHoldingTest.php`
- `mases_core/src/Domains/Shareholder/Tests/Feature/CertificateTest.php`

The repository progress report dated 2026-11-08 records a failed/action-required shareholder seed-number initialization workflow (#1030) among the sampled recent failed workflows. This assessment did not fetch its logs or rerun it.

Combined commit status queries for the two baseline SHAs returned no status entries. This means no status-check evidence was returned by that query; it does **not** prove the test suites pass.

No tests, migrations, live database inspection, browser session, or production operation were executed as part of this read-only assessment.

## 9. Candidate gap register for A3 planning

| Candidate | Current evidence | Proposed disposition |
|---|---|---|
| Shareholder identity and register CRUD | Existing API + Workspace UI | REUSE; no duplicate CRUD |
| Share-class and holding ledger | Existing API + UI + unit/feature tests | REUSE; verify invariants and regression |
| Certificate lifecycle | Existing API + UI + feature test | REUSE; verify authorization and lifecycle |
| KYC/document verification | Model says future profile; D12 field matrix unresolved | UNRESOLVED until approved field/policy contract |
| Dividend distribution, accounting and tax | API PR #81/#83 open; App dividend PR work open | RECONCILE existing branches; do not rebuild |
| Dividend self-service portal | Not found on App main; corresponding PR work exists | RECONCILE existing App branch; verify API contract |
| GA meetings, attendance, proxies, penalties | Existing Governance API/App integration | REUSE; verify shareholder eligibility and legal rules |
| Shareholder voting / board election | WIP/open implementation; election rules unresolved | DEFER until authoritative rule freeze |
| Board and committee governance | Governance domain exists; scope/authority rules need reconciliation | REUSE existing Governance domain; no duplicate Shareholder tables |
| Shareholder reports and statements | Partial through portal/Finance work | RECONCILE with Finance and existing document/report services |

## 10. A2 conclusion and next implementation boundary

**A2 result: PARTIAL — a reliable source-level baseline is established, but not yet a full acceptance/production baseline.**

The next safe sequence is:

1. Reconcile the exact current heads and changed-file inventories of API PR #81/#83 and the corresponding App dividend branches against the baseline SHAs recorded above.
2. Inspect the shareholder seed-number workflow #1030 and the existing shareholder unit/feature test coverage; diagnose, but do not change unrelated failures.
3. Complete a targeted route/policy/tenant-isolation review for shareholder-own versus administrative access.
4. Record unresolved charter decisions and source-document dependencies separately from implementation defects.
5. Prepare a bounded A3 change list using **REUSE / AMEND / CREATE / DEFER / UNRESOLVED** dispositions. Any code change must be isolated to Shareholder, its existing Finance/Governance integration points, and the corresponding App feature folders.

### Protected boundaries

- Do not add a second Shareholder domain, duplicate ownership ledger, duplicate dividend payable model, or duplicate meeting/proxy records.
- Do not change canonical Core identity, tenant, authorization, Finance, Governance or unrelated domain behavior outside a specifically evidenced integration requirement.
- Do not rewrite historical shareholder data or run production migrations in A2.
- Do not merge or deploy as part of A2/A3/A4 without the separate gate approval.
- Preserve all unrelated branches, PRs and in-progress work.

**A3 is not declared started or complete by this A2 record.** The charter's unapproved status and unresolved business decisions are explicit implementation blockers for the affected features. A3 can proceed only on requirements whose authority and acceptance criteria are already established.
