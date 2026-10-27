# ARNA OS Phase 1 — Full Implementation Strategy and Engineering Plan

**Document ID:** ARNA-P1-IMPLEMENTATION-PLAN-001  
**Version:** 1.0.0  
**Status:** Owner review — planning baseline, not blanket implementation authorization  
**Date:** 2026-10-26  
**Repositories:** `akefadaka-git/arna-api`, `akefadaka-git/arna-app`  
**Related control document:** [P1.0 Baseline Implementation Control Contract](P1.0-BASELINE-IMPLEMENTATION-CONTROL-CONTRACT.md)

## 1. Purpose and intended outcome

This document defines the Phase 1 implementation order, architectural boundaries, likely code files to create or amend, structural-change criteria, dependencies, verification strategy, and acceptance gates for ARNA OS.

The goal is to complete the system by extending and reconciling the destination repositories—not to rebuild modules that already work, import entire candidate branches without review, or create duplicate sources of truth.

The path inventory below is a **planned review/change manifest**, not a statement that every listed file is missing or must change. Before each implementation unit, inspect the current destination branch and exact file content, record the baseline SHA, and narrow the manifest to changes justified by evidence.

## 2. Baseline, scope and change authority

Previously observed baselines (recheck before work begins):

- API `main`: `bb58bd9e03e26a5a5ca33e015ca19b182c8d76b4`
- App `main`: `076c12e8137270f3ade37b3ad16994143daaef97`

These SHAs are historical observations, not guaranteed current HEADs.

### 2.1 Owner control

The Owner retains authority over scope, domain boundaries, design decisions, acceptance criteria, risk acceptance, migrations, structural moves, branch merges, repository settings, deployment and closure. A phase approval is limited to its stated scope. A documentation request does not authorize application changes; a test pass does not authorize merge; a merge does not authorize production deployment.

### 2.2 Global invariants

1. Tenant-owned data must be scoped by validated tenant context from request entry through database queries, queued jobs, events, exports and caches.
2. Authentication identifiers must resolve unambiguously and within the correct tenant context; a missing or conflicting trusted tenant context fails closed.
3. Authorization must be checked server-side on every protected operation; hiding a frontend button is not authorization.
4. Financial mutations must be transactional, auditable and idempotent where clients or infrastructure may retry. Concurrency claims require concurrency tests on the relevant database engine.
5. Monetary values use explicit decimal/currency rules, never binary floating-point arithmetic for ledger amounts.
6. Migrations must be ordered, discoverable, reversible where safe, and have one authoritative copy. Existing migration registration patterns must be verified before adding or moving files.
7. Existing functionality, unrelated working changes, user data and production environments must be preserved.
8. Amharic-first localization, mobile-first usability, accessibility, and low-bandwidth resilience are acceptance requirements.
9. ARNA remains separate from IROKA and MYFIN.
10. Every deliverable is labelled Verified, Reported, Unknown, Blocked or Not Run, with exact revision and evidence.

## 3. Phase 1 gate sequence

| Gate | Scope | Exit condition |
|---|---|---|
| P1.0 | Baseline, architecture, traceability and implementation contract | Owner accepts baseline and requirement-to-code/test traceability |
| P1.0a | Read-only integration and structural reconciliation | File-specific reuse/amend/defer/reject decisions; migration and identity boundaries confirmed |
| P1.1 | Platform security and tenant contract | Tenant/authz/security regression suite passes on candidate revision |
| P1.2 | Unified People Profile and identity | Identity relationships, uniqueness, lifecycle and permissions verified end-to-end |
| P1.3 | Organization and governance | Hierarchy, appointment, acting-role, committee and authority rules tested |
| P1.4 | Financial integrity and accounting foundation | Ledger invariants, reconciliation and genuine concurrent MySQL tests pass |
| P1.5 | Procurement and sales | End-to-end order and approval flows, authorization and financial postings pass |
| P1.6 | Public website and app integration | Approved public content, branding, localization and role-based app flows verified |
| P1.7 | Reports and operational readiness | Report totals reconcile; security, performance, recovery and release evidence reviewed |

Dependencies are deliberate: P1.1 precedes new protected domains; P1.2 precedes person-linked governance and employee workflows; P1.4 establishes accounting contracts before procurement/sales posting is wired; P1.6 integrates only approved API contracts.

## 4. P1.0 — baseline and traceability

### Work
- Capture current default branch SHAs, repository status, open PRs relevant to Phase 1, workflow state and existing docs.
- Inventory routes, middleware, policies, domain providers, models, services, migrations, seeders, frontend routes, API clients, stores and tests.
- Map each business requirement to an existing capability, a confirmed gap, a test and an owner decision.
- Record known limitations, including the difference between sequential idempotency tests and genuine concurrent database behavior.

### Likely documentation files
- Create: `docs/phase-1/P1.0-BASELINE-IMPLEMENTATION-CONTROL-CONTRACT.md` (existing candidate to review; do not duplicate).
- Create: `docs/phase-1/PHASE-1-FULL-IMPLEMENTATION-STRATEGY.md` (this plan).
- Amend only if needed: `docs/ARNA-INTEGRATION-RECONCILIATION-PLAN-001.md`.
- Create after the inventory: `docs/phase-1/REQUIREMENT-TO-CODE-TRACEABILITY.md`.
- Create after acceptance criteria are agreed: `docs/phase-1/PHASE-GATE-ACCEPTANCE-REGISTER.md`.

### Exit
Every Phase 1 requirement has a disposition: reuse, amend, create, defer or reject. Every claimed current behavior links to code and a test or is explicitly marked unverified.

## 5. P1.0a — read-only integration and structure reconciliation

This gate makes no application-code or schema changes. Review existing identity, employee, shareholder, wallet, governance, migration, branding and candidate-branch work.

### Known starting points requiring targeted review
- API: `app/Models/User.php`, `mases_core/src/Auth/Models/User.php`, `database/migrations/2026_10_22_000001_create_employees_table.php`, shareholder identity models/repositories, wallet account identifier models/services and authentication services.
- API migration discovery: `database/migrations/`, domain migration directories, domain service providers, `tests/Feature/LoanRepaymentIdempotencyMigrationDiscoveryTest.php`, and `tests/Feature/ShareholderMigrationDiscoveryTest.php`.
- Governance: `mases_core/src/Domains/Governance/` and its models, services, policies, routes and tests.
- App emblem candidate: compare `main` with `fix/arna-public-emblem`, especially `src/layouts/PublicSiteLayout.vue` and `src/public-site/PublicHome.vue`; inspect exact diff and related assets/styles before deciding.
- Existing integration plan: `docs/ARNA-INTEGRATION-RECONCILIATION-PLAN-001.md`.

### Required output
For every candidate path, record exact source and destination SHA, dependency, behavior differences, tests, decision and reason. Decisions must be one of: reuse as-is, amend in place, selectively port, defer, or reject. No whole-branch merge based solely on commit count or branch name.

### Exit
Owner reviews the reconciliation report and authorizes a bounded implementation unit. No schema is introduced based on assumptions about migration discovery.

## 6. P1.1 — platform security, tenant context and shared contracts

### Objectives
- One validated tenant-context contract across HTTP, authentication, CLI/jobs, events, notifications, files and caches.
- Consistent authorization policies and role/permission semantics.
- Secure request validation, rate limiting, error handling, audit trails, and protected file access.
- Avoid logging credentials, recovery codes, tokens, or unnecessary personal data.

### API files to inspect and amend where justified
- Existing: `app/Http/Kernel.php` or the actual Laravel 12 middleware configuration location; `bootstrap/app.php`; `bootstrap/providers.php`; `config/auth.php`; `config/cache.php`; `config/queue.php`; `config/logging.php`; `routes/api.php`.
- Existing tenant middleware and services under `mases_core/src/Tenancy/`.
- Existing authorization code under `mases_core/src/Authorization/`, policies and permission catalogues.
- Existing authentication services under `mases_core/src/Auth/` and controllers under `app/Http/Controllers/Auth/`.
- Existing audit/security-event models, listeners and middleware.
- Existing tests covering tenant resolution, membership, authorization, auth lifecycle, route middleware and cross-tenant isolation.

### Create only if no adequate equivalent exists
- A focused shared tenant-context contract/test helper.
- Missing policy/feature tests for cross-tenant read, write, export, download, queued-job and cache isolation.
- Security regression tests for invalid/conflicting tenant headers, inactive tenants, suspended memberships, ambiguous identifiers, expired credentials and unauthorized object access.
- A documented error response contract if existing API errors are inconsistent.

### Do not
- Add another authorization service or tenant resolver without comparing current implementations.
- Make frontend route guards the only security control.
- Treat static route inventory as proof that all authorization bypasses are impossible.

### Acceptance
Run targeted security tests on the candidate SHA. Include both allowed and denied cases, verify tenant context cleanup between requests/jobs, and inspect logs for sensitive data. Any security weakness is documented with severity and evidence.

## 7. P1.2 — Unified People Profile and identity lifecycle

### Objectives
Provide a coherent person/profile view across employee, shareholder, governance participant and authenticated user contexts while preserving domain-specific ownership. A user account is not automatically the same thing as a person record, and a person may hold multiple roles.

### Existing code to reconcile first
- User/auth models and authentication identifier resolution.
- Employee migration/model/service and employee-number login behavior.
- Shareholder models, repositories, number reconciliation, shareholding relationships and tenant constraints.
- Wallet account identifier and wallet ownership logic.
- Governance membership and appointment references to people/users.
- Frontend auth stores, profile screens, shareholder screens and employee-related screens/services.

### Candidate API files to create if confirmed missing
Use the existing domain convention and namespace only after P1.0a confirms it:
- `mases_core/src/Domains/People/Models/PersonProfile.php`
- `mases_core/src/Domains/People/Services/PersonProfileService.php`
- `mases_core/src/Domains/People/Repositories/PersonProfileRepository.php`
- `mases_core/src/Domains/People/Http/Controllers/PersonProfileController.php`
- `mases_core/src/Domains/People/Http/Requests/StorePersonProfileRequest.php`
- `mases_core/src/Domains/People/Http/Requests/UpdatePersonProfileRequest.php`
- `mases_core/src/Domains/People/Http/Resources/PersonProfileResource.php`
- `mases_core/src/Domains/People/Policies/PersonProfilePolicy.php`
- `mases_core/src/Domains/People/Routes/api.php`
- `mases_core/src/Domains/People/Providers/PeopleServiceProvider.php`
- Domain feature tests for tenant isolation, profile lifecycle, linked identities, privacy and authorization.
- A migration only after schema design and migration registration are approved; filename and directory must follow the verified repository convention.

These names are proposals, not instructions to create a second master identity table. If a suitable canonical person model already exists, amend that model and its bounded interfaces instead.

### Candidate app files to create or amend
Adapt to the actual Vue project conventions:
- `src/features/people/pages/PeopleDirectoryPage.vue`
- `src/features/people/pages/PersonProfilePage.vue`
- `src/features/people/components/PersonProfileForm.vue`
- `src/features/people/components/PersonIdentityLinks.vue`
- `src/features/people/services/peopleService.ts`
- `src/features/people/stores/peopleStore.ts` only if state genuinely needs centralization.
- Router registration, permission metadata, navigation and localization files.
- Existing auth/profile/shareholder/employee screens to use the same profile contracts rather than duplicate profile logic.

### Data and business rules
- Preserve unique tenant-scoped identifiers and enforce uniqueness in the database where applicable.
- Support multiple roles and relationships without duplicating a person per role.
- Model identity linking and unlinking with explicit authorization and audit history.
- Handle two verified phone numbers if approved by the identity specification; do not assume a phone is verified because it exists.
- Do not expose sensitive identity or contact fields to roles without permission.
- Preserve shareholder numbering compatibility and wallet identifiers; never regenerate existing IDs to fit a UI format.

### Acceptance
Test identity resolution across username, email, phone, shareholder number, employee number and wallet account number where supported; ambiguous or conflicting identities fail safely. Test linking, revocation and relinking, tenant boundaries, profile field permissions and historical relationships.

## 8. P1.3 — organization, governance, committees and authority

### Existing code to reuse
The API already has a substantial Governance domain. Review its bodies, positions, terms, appointments, memberships, services, policies, routes, resources, events and tests before proposing new governance tables.

### Candidate enhancements
- Organization hierarchy and scope resolution for enterprise, tenant, branch, project and investment where not already represented.
- Committee types and membership rules for procurement, sales, control/audit and board nominating functions.
- Effective dates, appointment instruments, acting assignments, revocation/supersession and audit history.
- Authority matrix and approval thresholds scoped by entity, transaction type, amount/currency and validity interval.
- Meeting agendas, attendance, minutes, resolutions and linked documents, with access control.
- Explicit conflict-of-interest declarations and separation-of-duties checks where required by governance policy.

### Candidate API paths
Prefer existing `mases_core/src/Domains/Governance/` files when responsibility already belongs there:
- Existing models/services/requests/resources/policies for bodies, positions, terms, appointments and memberships.
- Add bounded services/requests/resources for committee decisions, meetings or authority rules only after confirming those capabilities do not exist.
- Add tests for one effective primary incumbent where the rule applies, permitted acting appointments, overlapping terms, cross-tenant references, revoked appointments and authority limits.
- Add migrations only for approved, demonstrably missing schema.

### Candidate app paths
- `src/features/governance/pages/GovernanceDirectoryPage.vue`
- `src/features/governance/pages/GovernanceBodyPage.vue`
- `src/features/governance/pages/GovernancePositionPage.vue`
- `src/features/governance/pages/GovernanceMeetingsPage.vue`
- `src/features/governance/pages/GovernanceDecisionsPage.vue`
- `src/features/governance/components/AppointmentForm.vue`
- `src/features/governance/components/AuthorityRuleEditor.vue`
- Existing governance service, router, permissions, stores and Amharic/English localization files.

### Acceptance
Test appointment lifecycle, effective dates, acting status, primary-incumbent constraints, committee membership, authority threshold decisions and cross-tenant access. The frontend must reflect API permission decisions but cannot substitute for them.

## 9. P1.4 — financial integrity, ledger and accounting foundation

### Objectives
Preserve current wallet and loan behavior while establishing a reliable accounting contract for financial transactions, reconciliations and later procurement/sales integration.

### Existing code to inspect and preserve
- Wallet domain models/services for wallets, obligations, payments, allocations, reversals and entries.
- Loan domain repayment, disbursement, interest, penalties, restructuring, write-off and reconciliation logic.
- Existing idempotency schema/service and its tests.
- Existing migrations, event dispatch, audit listeners, currency handling and seeders.

### Required work
1. Write the accounting domain specification: chart of accounts, account types, normal balances, journal headers/lines, posting rules, fiscal periods, currencies, exchange rates, reversals and close controls.
2. Establish ledger invariants: total debits equal total credits per journal and currency policy; posted entries are immutable; corrections use reversal/adjustment entries; closed periods reject unauthorized postings.
3. Define idempotency scope, payload matching, transaction boundaries, retry behavior and conflict responses.
4. Review lock ordering across wallet, payment, obligation, allocations and loan rows. Keep the documented order consistent with implementation.
5. Build a genuine concurrent MySQL regression test using independent database connections/processes where feasible; assert no duplicate posting, lost update, over-allocation or inconsistent loan balance.
6. Add reconciliation reports comparing source subledger balances with ledger/control totals.
7. Define rounding and FX rules explicitly; do not use PHP/JavaScript binary floats for money arithmetic.

### Candidate API paths, conditional on current architecture
- Existing Wallet and Loan services/tests: amend only the relevant transaction and invariant logic.
- If no accounting domain exists after inventory, propose:
  - `mases_core/src/Domains/Accounting/Models/Account.php`
  - `mases_core/src/Domains/Accounting/Models/JournalEntry.php`
  - `mases_core/src/Domains/Accounting/Models/JournalLine.php`
  - `mases_core/src/Domains/Accounting/Services/JournalPostingService.php`
  - `mases_core/src/Domains/Accounting/Services/PeriodCloseService.php`
  - `mases_core/src/Domains/Accounting/Services/LedgerReconciliationService.php`
  - `mases_core/src/Domains/Accounting/Http/Controllers/AccountController.php`
  - `mases_core/src/Domains/Accounting/Http/Controllers/JournalController.php`
  - `mases_core/src/Domains/Accounting/Http/Resources/JournalEntryResource.php`
  - `mases_core/src/Domains/Accounting/Policies/JournalEntryPolicy.php`
  - `mases_core/src/Domains/Accounting/Routes/api.php`
  - migrations, provider, permissions and seeders only after explicit schema/registration review.
- Tests: journal balancing, immutable posting, reversal, period closure, FX rounding, duplicate request replay, concurrent posting and subledger reconciliation.

### Candidate app paths
- `src/features/accounting/pages/ChartOfAccountsPage.vue`
- `src/features/accounting/pages/JournalEntriesPage.vue`
- `src/features/accounting/pages/LedgerPage.vue`
- `src/features/accounting/pages/ReconciliationPage.vue`
- `src/features/accounting/services/accountingService.ts`
- Form components, validation, router, permission metadata and translations.

### Acceptance
No financial workflow is called complete without domain tests, database constraints, tenant-isolation tests, audit evidence, and reconciliation. Sequential SQLite tests are insufficient evidence for MySQL concurrency safety.

## 10. P1.5 — procurement and sales

### Procurement capabilities
- Purchase requisitions, budget checks, vendor records, quotation/tender comparison if required, approval routing, purchase orders, receipt of goods/services, invoice matching, payment authorization and document history.
- Procurement committee dashboard, meeting/decision records and role/authority checks.
- Separation of request, approval, receipt and payment responsibilities.

### Sales capabilities
- Customer records, quotations, orders, pricing/discount controls, fulfillment/delivery, invoices, receipts/credit notes and sales reporting.
- Sales committee decisions and approval limits where required.
- Integration with inventory only if an inventory domain exists or is separately approved; do not invent stock semantics.

### Candidate API paths if no equivalent exists
- `mases_core/src/Domains/Procurement/Models/`, `Services/`, `Repositories/`, `Http/Controllers/`, `Http/Requests/`, `Http/Resources/`, `Policies/`, `Routes/api.php`, `Providers/`, `Tests/Feature/`.
- Equivalent bounded structure under `mases_core/src/Domains/Sales/`.
- Add migrations, permission catalogue entries, audit events, notifications and seeders only as justified.
- Reuse governance/authority and accounting services; never duplicate their business rules inside order controllers.

### Candidate app paths
- `src/features/procurement/pages/RequisitionsPage.vue`
- `src/features/procurement/pages/PurchaseOrdersPage.vue`
- `src/features/procurement/pages/ReceivingPage.vue`
- `src/features/procurement/pages/ProcurementCommitteeDashboard.vue`
- `src/features/sales/pages/CustomersPage.vue`
- `src/features/sales/pages/QuotationsPage.vue`
- `src/features/sales/pages/SalesOrdersPage.vue`
- `src/features/sales/pages/InvoicesPage.vue`
- Matching services, request/response types, validation components, routes, permissions and localization.

### Acceptance
Test the entire lifecycle from draft through approval to fulfillment and financial posting. Include unauthorized transitions, approval-limit breaches, duplicate submissions, tenant isolation, partial fulfillment, cancellation, reversal and audit history.

## 11. P1.6 — public website, brand system and app integration

### Objectives
- A public site that displays only approved, publishable content.
- Consistent emblem, brand tokens, typography, colors, layout and responsive behavior.
- App pages consume versioned API contracts and share established UI patterns.
- Amharic-first navigation and content with English support where provided.

### API review targets
- Existing public-site/content/announcement/product/advertisement endpoints and models.
- Admin approval/publishing workflows, media/file validation, public caching and cache invalidation.
- Ensure drafts and unapproved materials cannot leak through direct endpoints, search, feeds or cached responses.

### App review targets
- Existing `src/layouts/PublicSiteLayout.vue`, `src/public-site/PublicHome.vue`, router and shared components.
- Existing brand tokens/styles and emblem assets; reconcile candidate branch changes by exact diff, not by importing a whole branch.
- Existing API client, auth/session store, permission checks, localization files and feature routes.

### Candidate enhancements
- Centralized design tokens and documented semantic roles for colors, spacing, typography, focus, disabled and error states.
- Reusable loading, empty, error, unauthorized and offline states.
- Accessible forms, keyboard navigation, visible focus, contrast and mobile touch targets.
- Public content service/types, publish-state handling, safe media rendering and route-level metadata.
- Avoid hard-coded text in components where established localization conventions exist.

### Acceptance
Review at narrow mobile and desktop breakpoints, keyboard navigation, contrast, loading and error behavior, Amharic text wrapping, long names/numbers, public/private content boundaries and approved emblem fidelity. Run existing app CI plus targeted component/service tests.

## 12. P1.7 — reporting, observability and operational readiness

### Reporting
- Daily, weekly and monthly operational summaries.
- Finance, wallet, loan, procurement, sales, shareholder and governance reports as each source domain becomes authoritative.
- Explicit date range, timezone, tenant, currency, status and permission semantics.
- Export totals must reconcile with source records; large exports must be bounded and audited.

### Candidate API work
- Reuse existing report/reconciliation services where present.
- Add report query objects/services, authorized endpoints, resources and tests only for confirmed gaps.
- Add asynchronous export jobs only when volume requires it; enforce tenant context and permission revalidation inside the job.
- Record report generation, filters, requester, result status and access to sensitive exports without logging sensitive payloads.

### Candidate app work
- Reporting dashboards and filters, export status, accessible tables and responsive summaries.
- Avoid calculating authoritative financial totals independently in the browser.

### Operations
- Health/readiness checks with non-sensitive output.
- Structured logging and correlation IDs.
- Queue retry and failed-job handling, scheduled task idempotency, backup/restore evidence, dependency/security review, performance checks and release rollback plan.
- Production configuration/seeder audit: never seed default production passwords, demo identities, permissive roles or test financial data.

### Acceptance
Report totals reconcile to domain records and accounting controls. Export authorization, job tenant isolation, timeout/retry behavior and audit trail are verified. Production readiness is not declared until environment-specific deployment and recovery evidence exists.

## 13. Frontend and API code inventory by action

This is the practical change classification to use in pull requests.

### Create (only where confirmed missing)
- People Profile domain contracts and UI slices.
- Accounting ledger domain and UI slices if no suitable implementation exists.
- Procurement and Sales domains and UI slices if absent.
- Focused migrations for approved new schema.
- Missing policy, request validation, resource, audit event and feature tests.
- Requirement traceability, API contract and phase acceptance documentation.
- Tests for concurrency, cross-tenant isolation, workflow transitions, reconciliation and accessibility gaps.

### Edit / enhance
- Tenant resolution, membership and authorization logic where tests prove a gap.
- Authentication identifier resolution, MFA lifecycle and profile linking.
- Existing employee/shareholder/wallet/governance relationships.
- Existing governance services for committee/appointment/authority rules where ownership already belongs there.
- Wallet/loan transaction boundaries, idempotency and reconciliation.
- API routes, request validators, resources, policies and service providers when adding approved endpoints.
- Existing Vue router, API clients, feature services, stores and pages to consume canonical API contracts.
- Brand tokens, emblem usage, public layout, localization and responsive components.
- CI workflows and test fixtures only when required to run deterministic, relevant checks on changed code.

### Amend / reconcile
- Permission catalogue and seeders to match approved domain permissions without granting excess access.
- Existing API response shapes only with compatibility review and updated consumers/tests.
- Migration registration to ensure exactly-once discovery and ordering.
- Existing duplicate or overlapping model/service responsibilities by selecting one canonical owner and adapting consumers.
- Existing docs that conflict with verified implementation or the approved architecture.

### Move / rename / restructure — exceptional
Do not move or rename files simply to make the tree look uniform. Consider only when there is demonstrated ambiguity, a framework constraint, a security issue, or a sustained maintenance cost. Before any move:
1. Inventory imports, namespaces, service-provider discovery, tests, workflow paths, autoload mappings and documentation links.
2. Define one canonical destination and compatibility plan.
3. Check migrations are not accidentally duplicated or renamed after execution in any environment.
4. Make the smallest mechanical move, then update references in the same bounded PR.
5. Run static checks and all affected tests.
6. Preserve history where possible and document why the move is necessary.
7. Obtain Owner approval before applying the structural change.

### Delete / deprecate
Only after proving the target is unused, superseded, non-authoritative and safe to remove. Prefer a deprecation period for externally consumed APIs. Never delete migrations that may have run in a shared or production environment without an explicit migration strategy and Owner approval.

## 14. Recommended file-by-file delivery pattern

Each vertical slice should follow this sequence:

1. Specification and invariants.
2. API contract and authorization matrix.
3. Schema/migration review and approval where applicable.
4. Domain model/repository/service changes.
5. Request validation, policy, resource, routes and provider wiring.
6. Audit events, notifications and background jobs if required.
7. Feature and unit tests, including negative and tenant-boundary cases.
8. Frontend types/API client, service, page/components and localization.
9. UI tests/accessibility/responsive review.
10. CI verification on the exact candidate SHA.
11. Review evidence, residual risks, rollback/migration notes.
12. Owner acceptance of the gate before the next dependent implementation.

Avoid a single PR spanning identity, accounting, procurement, brand and unrelated cleanup. Keep one primary domain responsibility per PR, with shared dependencies called out explicitly.

## 15. Test and verification strategy

### API
- Unit tests for pure domain rules and calculations.
- Feature tests for HTTP behavior, authentication, policies, lifecycle and tenant isolation.
- Database tests for constraints, migration discovery/order, rollback where safe and seeder idempotency.
- MySQL-specific concurrency tests using independent connections/processes for financial races.
- Static analysis, formatting, dependency checks and targeted CI workflows.

### App
- Unit tests for stores/services and validation.
- Component/page tests for loading, empty, error, unauthorized and success states.
- Contract tests for API request/response assumptions.
- Responsive, keyboard, focus, contrast and Amharic localization checks.
- Production build and existing CI workflows on the exact candidate SHA.

### Cross-domain
- End-to-end critical journeys: sign-in with tenant context; person/employee/shareholder access; governance appointment; loan repayment; journal posting/reconciliation; procurement approval/payment; sales order/receipt; report generation/export.
- Verify denied cases as deliberately as allowed cases.
- Do not claim an end-to-end test passed unless the test actually exercises the named journey.

## 16. Pull request and release controls

Every implementation PR must contain:
- Goal and non-goals.
- Baseline SHA and candidate SHA.
- Exact files changed and reason for each.
- Schema impact, migration ordering/discovery and rollback implications.
- Security/tenant impact.
- Tests run with exact workflow links and outcomes.
- Known limitations and remaining risks.
- Manual verification steps, when automation cannot cover the behavior.
- Explicit statement of what was not run.
- No unrelated formatting or cleanup.

Merging is a separate Owner decision. Production migrations, configuration changes, backup/restore operations and deployment are separate gates requiring explicit authorization.

## 17. Suggested execution order and dependency graph

1. P1.0 baseline and traceability.
2. P1.0a read-only reconciliation and candidate decisions.
3. P1.1 security/tenant contract and required regression tests.
4. P1.2 canonical People Profile and identity linking.
5. P1.3 governance and authority extensions.
6. P1.4 accounting specification, financial integrity fixes and concurrent MySQL proof.
7. P1.5 procurement vertical slice, then sales vertical slice, both integrated with governance and accounting.
8. P1.6 public site/app integration, emblem and design-system reconciliation.
9. P1.7 reporting, operational hardening and release evidence.

A dependency may be split into smaller PRs, but a downstream feature must not bypass an unresolved upstream security or financial invariant. Branding/UI work may proceed independently only when it does not change API semantics or interfere with the reconciliation gate.

## 18. Risks and controls

| Risk | Control |
|---|---|
| Duplicate person/employee/shareholder identity masters | P1.0a relationship map and one approved canonical identity strategy |
| Nested migration is not discovered or is discovered twice | Verify provider registration, migration paths, ordering and exactly-once discovery tests |
| Bulk merge introduces unrelated regressions | Selective path-level porting and one bounded PR per unit |
| Tenant context leaks across requests/jobs | Fail-closed context resolution, cleanup and cross-tenant regression tests |
| Concurrent repayments double-apply | Independent-connection MySQL tests and financial invariants |
| Accounting ledger diverges from wallet/loan subledgers | Transactional posting and reconciliation tests |
| Frontend route guard mistaken for authorization | Server-side policies and denial tests |
| Public site leaks draft/private content | Publish-state authorization, cache invalidation and direct-endpoint tests |
| Production seeders create unsafe defaults | Production-specific seeder audit and explicit safe defaults |
| Restructuring breaks autoloading or migration history | Structural change plan, impact inventory and Owner approval |

## 19. Status and completion reporting

For every gate, report:
- **Verified:** directly established by source, test or workflow evidence.
- **Reported:** stated in docs or prior reports but not independently rechecked.
- **Unknown:** evidence is insufficient.
- **Not Run:** no verification was performed.
- **Blocked:** a dependency or approval is missing.

Include exact SHAs, workflow links, changed paths, tests and residual risks. Do not express readiness as a single percentage unless the scoring rubric, denominator and evidence for every scored item are provided.

## 20. Current next action

**Next action: complete P1.0a read-only reconciliation.** Produce a path-by-path decision report covering identity/employee/shareholder/wallet links, migration registration, existing governance reuse, candidate integration diffs and the app emblem branch. Only after that report is reviewed should the Owner authorize the first P1.1 implementation unit.

This plan does not authorize application code changes, schema migrations, file moves/renames, branch merges, repository setting changes, or production deployment. Those require separate bounded authorization.
