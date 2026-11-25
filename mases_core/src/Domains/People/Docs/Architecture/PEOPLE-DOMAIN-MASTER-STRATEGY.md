# ARNA People Domain — Master Implementation & Enhancement Strategy

- Document ID: PD-STR-001
- Version: 1.0.0
- Date: 2026-10-30
- Status: Owner-approved strategy; P0 documentation baseline in progress
- Authority: Owner-governed ARNA OS
- Scope: People Domain API, application, data, security, integration, documentation, testing, operations and reporting

## 1. Purpose and governing decision

The People Domain is the authoritative ARNA bounded context for the natural-person master and people-specific lifecycle relationships. It will evolve from the current unified Person foundation into a complete enterprise People and Human Capital Management (HCM) capability.

**Mandatory ownership decision:** People-specific business code, data structures, migrations, seeders, factories, tests, localization, scripts, specifications, manuals, implementation registers and reports must be maintained beneath the People Domain root in each repository. The API root is `mases_core/src/Domains/People/`; the application root is `src/features/people/`.

Framework-required integration points remain exceptions only where the framework requires a fixed path (for example, executable GitHub Actions definitions in `.github/workflows/`, Laravel bootstrap provider registration, or top-level route discovery). Such files must contain minimal registration/wiring only. People business logic, People-specific test logic, scripts and documentation must remain domain-owned. A path exception must be documented in the People ownership register.

## 2. Non-negotiable principles

1. One canonical Person identity; credentials, employment, share ownership and governance authority are separate concepts.
2. Tenant context is derived from validated middleware/session context, never trusted from request payloads.
3. Every tenant-visible Person operation requires explicit tenant access and least-privilege authorization.
4. Domain ownership is explicit: People references other domains but does not own their records.
5. Historical people decisions are preserved through effective-dated records, immutable audit evidence and non-destructive corrections.
6. Sensitive data is purpose-limited, minimized, encrypted where appropriate, masked by default and access-audited.
7. Amharic-first UX and localization, with English support; Ethiopian and Gregorian calendar support where relevant.
8. MySQL is the production database contract; concurrency, locking, idempotency and transactional integrity must be verified against it.
9. Documentation and acceptance criteria precede implementation. Each phase has independent closure evidence.
10. No phase closure, merge, database operation or deployment is inferred from code presence or a prior phase approval.

## 3. Domain ownership and folder standards

### 3.1 API canonical root

`mases_core/src/Domains/People/`

Recommended structure (create subfolders when required, not as empty scaffolding):

- `Config/`
- `Contracts/`
- `Database/Migrations/`, `Database/Seeders/`, `Database/Factories/`
- `Docs/Architecture/`
- `Docs/Requirements/`
- `Docs/Policies/`
- `Docs/Data-Dictionary/`
- `Docs/API/`
- `Docs/Security/`
- `Docs/User-Manuals/`
- `Docs/Implementation/`
- `Docs/Testing/`
- `Docs/Reports/`
- `Docs/Decisions/`
- `Enums/`, `Events/`, `Exceptions/`
- `Http/Controllers/`, `Http/Middleware/`, `Http/Requests/`, `Http/Resources/`
- `Jobs/`, `Listeners/`, `Models/`, `Notifications/`, `Policies/`, `Providers/`
- `Repositories/`, `Rules/`, `Services/`, `Support/`
- `Tests/Unit/`, `Tests/Feature/`, `Tests/Integration/`, `Tests/Security/`, `Tests/Concurrency/`
- `Resources/lang/am/`, `Resources/lang/en/`, and domain-owned views only if needed.

### 3.2 Application canonical root

`src/features/people/`

Recommended structure:

- `api/`, `components/`, `composables/`, `config/`
- `docs/Architecture/`, `docs/Requirements/`, `docs/Implementation/`, `docs/Testing/`, `docs/Reports/`, `docs/User-Manuals/`
- `layouts/`, `pages/`, `services/`, `stores/`, `types/`, `utils/`, `validation/`, `tests/`

Existing Vue component naming and conventions may be retained where consistent with the feature's current build system.

### 3.3 Ownership enforcement

- No new People-specific code or documentation may be added outside the canonical roots except documented framework integration points.
- Do not move or delete existing files until import paths, autoloading, route discovery, migration discovery, test discovery, workflow path filters and ownership are inventoried.
- Relocation must preserve behavior and history where feasible and be reviewed as a bounded change.
- Shared platform code stays in its owning platform/domain package; People consumes it through contracts.
- A file is classified by business ownership, not by which team first created it.

## 4. Bounded contexts and data ownership

| Context | Authoritative owner | People relationship |
|---|---|---|
| Natural-person identity/profile | People | Owns Person, profile, contact points, relationships and tenant visibility grants |
| Authentication credentials | Central Identity/Auth | Person may link to an account; does not own credentials |
| Tenant membership | Tenancy/Identity | People tenant access does not replace user membership |
| Departments, branches, positions and org hierarchy | Organization | People stores effective-dated person assignments referencing Organization |
| Employment relationship and lifecycle | People | Owns employee relationship, contracts and status history |
| Governance seats, offices and authority | Governance | Person references appointments; participation never grants authority |
| Shares and ownership transactions | Shareholder | Person is linked to shareholder records; no ownership mutation |
| Ledger, payroll posting and payment execution | Finance | People supplies approved payroll inputs; Finance owns journals and payments |
| Binary files, versions and retention engine | Documents/Files | People owns person-document associations and sensitivity metadata |
| Calendar and notifications | Platform/Calendar | People publishes events and consumes shared services |

Cross-domain integration must use explicit APIs/events and reliable delivery (transactional outbox or an approved equivalent). Avoid direct writes to another domain's authoritative tables.

## 5. Target functional architecture

1. People Core: Person master, profile, contacts, relationships, identity references, tenant visibility and privacy.
2. Employment: contracts, employment type, status, lifecycle events, separation and rehire.
3. Workforce Assignments: positions, department/branch assignment, reporting lines, acting and temporary assignments.
4. Recruitment: requisitions, candidates, applications, interviews, selection and offers.
5. Onboarding: checklists, clearances, document collection, orientation and activation.
6. Attendance and Scheduling: calendars, shifts, attendance, absences, overtime and timesheets.
7. Leave: leave types, policy, balances, requests, approvals and corrections.
8. Performance and Talent: goals, competencies, review cycles, evaluations, development and succession.
9. Learning and Qualifications: courses, training, assessments, skills, certifications and expiry.
10. Compensation and Benefits: grades, compensation history, allowances, benefits and payroll inputs.
11. People Self-Service: employee/manager journeys, requests, profile, documents and approvals.
12. People Analytics: headcount, vacancies, turnover, skills, workforce cost and planning.

## 6. Phased implementation roadmap and phase closure criteria

### P0 — Architecture, governance and domain consolidation
Scope: baseline inventory; reconcile current main and open People PRs; define canonical roots; classify all People files; specify bounded contexts, dependencies, requirements, permissions, privacy and documentation; establish report templates and decisions.

Deliverables: master architecture, requirements catalogue, ownership/disposition register, dependency map, security/privacy specification, roadmap, risk/decision register and P0 report.

Closure criteria:
- Every existing People-related file in both repositories is inventoried and classified as domain-owned, shared integration, framework-required exception, legacy, duplicate or unresolved.
- Every out-of-root People file has a disposition and dependency/migration plan; no file is moved without a separate bounded implementation approval.
- Canonical Person identity and domain authority are unambiguous.
- API/app contracts and shared-service dependencies are documented.
- Requirements have stable IDs, priorities, owners and testable acceptance criteria.
- Permission, tenant, privacy, audit and retention requirements are approved.
- Open PRs and cross-domain changes have an explicit disposition.
- Owner approves P0 closure.

### P1 — Unified Person Core
Scope: registration, bilingual names, immutable public UUID, profile, lifecycle, tenant access, account association, duplicate resolution, domain links and audit.

Closure criteria: one authoritative Person; uniqueness under concurrent creation; explicit tenant grants; separation from credentials/employment/ownership/authority; transactional link/unlink invariants; complete audit; MySQL clean-install and migration evidence; API/app contract tests; security and Owner acceptance.

### P2 — Directory, Contacts and Relationships
Scope: directory search/filter/sort/pagination; profile; encrypted contact points; contact verification and preferences; typed person relationships; household/emergency contact policy; participation; profile documents and completeness.

Closure criteria: server-side bounded search and pagination; contact verification state is truthful; masked outputs and key-rotation strategy; tenant-scoped relationships; sensitive/minor policies; no authority inheritance; concurrency and authorization tests; bilingual parity; accessibility and user acceptance.

### P3 — Employment and HR Management
Scope: employee relationship; employment types/contracts; probation; confirmation; promotion; transfer; acting; suspension; separation, resignation, retirement and rehire; employee records and HR cases.

Closure criteria: effective-dated lifecycle; legal/contract terms; controlled state transitions; approvals and segregation of duties; no destructive history edits; separation/rehire correctness; sensitive record controls; audit and HR reports.

### P4 — Organization and Workforce Integration
Scope: assignments to department/branch/position; job titles/grades; reporting lines; supervisor/manager relationships; acting/temporary assignments; history and headcount.

Closure criteria: Organization remains authoritative for structures; People owns person assignments; effective dating; no invalid reporting cycles; acting authority and expiry; transfer history; reconciled workforce reports; cross-domain integration tests.

### P5 — Recruitment and Onboarding
Scope: workforce requisitions, vacancies, candidates, application tracking, shortlisting, interviews, evaluations, selection, offers, lawful checks, onboarding, clearance and activation.

Closure criteria: approved requisitions; candidate privacy; auditable pipeline; controlled interview/selection; offer approvals; duplicate-safe candidate-to-Person conversion; onboarding completion evidence and recruitment reporting.

### P6 — Attendance, Leave and Scheduling
Scope: calendars, shifts, attendance, check-in integrations, absence, leave policies/balances/requests, public holidays, overtime, timesheets and corrections.

Closure criteria: configurable policies; correct balance calculations; protected attendance edits; reasoned corrections; shift/calendar conflict controls; overtime approval; reconciled reports; Calendar/Organization integration.

### P7 — Performance and Talent
Scope: objectives, KPIs, performance plans, review cycles, self/manager evaluations, competencies, improvement plans, career plans, succession and recognition.

Closure criteria: approved framework and periods; role-restricted access; transparent scoring; immutable completed evaluations; review/appeal mechanisms; audit and performance reporting.

### P8 — Learning and Qualifications
Scope: training catalogue, needs assessment, requests, schedules, registration, attendance, assessments, certificates, skills, qualification expiry and learning history.

Closure criteria: approved training and budget controls; verifiable completion; certificate evidence; expiry notifications; skills-to-position mapping; effectiveness reports.

### P9 — Compensation and Benefits
Scope: grades, salary structures/history, allowances, benefits, deductions, compensation changes, payroll preparation, approvals and Finance integration.

Closure criteria: authorized effective-dated compensation; segregation of duties; payroll reconciliation; restricted salary access; approved payroll inputs; Finance-owned posting/payment; audit and statements.

### P10 — Self-Service, Analytics and Enterprise Enhancement
Scope: employee/manager self-service; HR dashboards; requests; leave/attendance/performance/training dashboards; workforce analytics/planning; automation; mobile, accessibility and localization enhancement.

Closure criteria: complete employee/manager journeys; mobile responsiveness; Amharic-first acceptance; accessibility; reconciled analytics; end-to-end workflow evidence; performance/security acceptance; operational manuals; independent acceptance and Owner approval.

## 7. Cross-phase engineering requirements

- Backend and frontend must follow existing ARNA framework conventions and maintain domain ownership.
- MySQL is the production database contract; SQLite-only success is insufficient for MySQL-specific integrity/concurrency claims.
- Use transactions, deterministic lock ordering, uniqueness constraints, idempotency and bounded retries where appropriate.
- Tenant isolation applies to API, exports, reports, background jobs, notifications and document access.
- Sensitive fields require purpose, classification, least-privilege access, masking/encryption and access audit.
- Use effective dating for employment, assignments, compensation and other historical business relationships.
- Localization must not hardcode translated labels into domain logic.
- APIs must use validated requests, stable resources/contracts, authorization policies and bounded query behavior.
- Tests include unit, feature, integration, security, concurrency, migration-discovery and relevant UI/E2E coverage.
- Production readiness requires exact-head CI evidence, migration evidence, defect disposition, operational runbooks and separate deployment approval.

## 8. Mandatory phase reporting

Every phase produces a report under API `Docs/Reports/` and a corresponding frontend report under `docs/Reports/`. Each report records:
- phase ID, scope, dates, baseline and exact source SHAs;
- requirements totals and status by requirement ID;
- implementation inventory, file paths and migrations;
- API/UI contracts and domain integration;
- tenant/security/privacy/audit findings;
- migration, rollback and data-impact evidence;
- tests planned/executed/passed/failed/skipped/not-run;
- exact workflow names, run IDs, URLs, conclusions and commit SHAs;
- defects, risks, decisions, deferred scope and accepted exceptions;
- documentation/manual updates;
- weighted readiness calculation and evidence references;
- closure decision, Owner approval and next authorized phase.

Use only these evidence states: VERIFIED, PARTIALLY VERIFIED, MISSING, UNRESOLVED, UNKNOWN, NOT ASSESSED, NOT RUN. Do not convert unrun checks into passes. Phase closure requires every mandatory exit criterion to be VERIFIED, all critical/high defects resolved or explicitly accepted by the Owner, independent review, and explicit Owner closure approval.

Readiness percentage is calculated from an Owner-approved weighted requirements catalogue. It is not estimated from file count, line count, PR size or developer assertions. Report requirement, implementation, verification, security, integration and documentation completion separately.

## 9. Benchmark strategy

Use capability benchmarks, not feature-count claims:
- Workday HCM: unified worker model, effective-dated organizational changes, workforce planning and analytics.
- SAP SuccessFactors: connected employee lifecycle, recruiting, onboarding, learning, performance and compensation.
- Oracle Fusion Cloud HCM: enterprise security, process governance and suite integration.
- BambooHR: accessible employee experience, onboarding, leave and manager workflows.
- Rippling: event-driven lifecycle automation and controlled identity/access provisioning.

ARNA's differentiators should be Amharic-first operations, Ethiopian context, unified enterprise identity, multi-entity governance and native integration with ARNA Organization, Governance, Shareholder, Documents and Finance.

## 10. Immediate P0 work sequence

1. Freeze exact current main and PR head SHAs as assessment baselines.
2. Inventory all People-related paths in API main, API PR #31, App main and App PR #5.
3. Classify each file by ownership and identify framework/shared-service exceptions.
4. Map API and app routes, permissions, migrations, providers, workflows, tests and imports.
5. Reconcile cross-domain PR changes separately from People-owned changes.
6. Finalize requirements IDs, architecture decisions, risks and closure matrix.
7. Submit P0 report for Owner review.

## 11. Governance and explicit exclusions

A1 is assessment/specification; A2 is read-only reconciliation; A3 implementation; A4 verification; A5 deployment. Each gate requires its own authorization. Approval of this strategy does not automatically authorize implementation of all future phases, migration execution, workflow reruns, PR modification, merge, production database operations or deployment.

## 12. Current P0 status

This document is a strategy baseline, not a phase closure report. Current repository evidence identifies People-owned files outside the canonical roots and open API/App PRs requiring reconciliation. The P0 inventory and disposition work remains in progress. No completion percentage is asserted until the full inventory and weighted requirements baseline are approved.
