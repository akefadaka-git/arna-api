# ARNA People Domain — Functional Requirements Catalogue

- Document ID: PD-REQ-001
- Version: 1.0.0
- Date: 2026-10-30
- Status: Initial planning baseline; Owner weighting and policy decisions pending
- Canonical location: `mases_core/src/Domains/People/Docs/Requirements/`

This catalogue defines phase-level functional requirements and testable acceptance outcomes. It is not evidence that a requirement has been implemented. Requirement weights, legal/policy choices and final acceptance thresholds require Owner approval before readiness percentages can be calculated.

| ID | Phase | Requirement | Minimum acceptance outcome | Priority |
|---|---|---|---|---|
| PD-P0-001 | P0 | Canonical domain ownership | All People-specific code, data artifacts, scripts, tests and documents have a canonical People root or an explicitly documented framework/shared exception. | Critical |
| PD-P0-002 | P0 | Complete file inventory | Every tracked People-related file in API/App main and candidate PRs is inventoried with ownership and disposition. | Critical |
| PD-P0-003 | P0 | Dependency and discovery map | Imports, autoload, provider, routes, migration/test discovery, workflow filters and script references are mapped before relocation. | Critical |
| PD-P0-004 | P0 | Bounded-context authority | People and adjacent domain ownership is documented with no ambiguous authoritative record. | Critical |
| PD-P0-005 | P0 | Security and privacy contract | Tenant, role, sensitive field, audit, retention and subject-rights requirements are specified and approved. | Critical |
| PD-P0-006 | P0 | Phase reporting | Every phase has a report, exact SHA/run evidence, defects, readiness method and Owner closure decision. | High |
| PD-P1-001 | P1 | Canonical Person creation | A natural person receives one stable ARNA-issued public identifier and cannot be duplicated by concurrent creation. | Critical |
| PD-P1-002 | P1 | Bilingual identity profile | Amharic and English name fields support validation, display, search and correction history. | High |
| PD-P1-003 | P1 | Person lifecycle | Person status changes are validated, reasoned where required and auditable. | High |
| PD-P1-004 | P1 | Tenant visibility grants | A Person is visible in a tenant only through an explicit active grant and authorized tenant context. | Critical |
| PD-P1-005 | P1 | Account association | User-account linking is separate from Person identity and requires a dedicated permission and audit record. | Critical |
| PD-P1-006 | P1 | Domain identity links | Employee, shareholder and governance references are linked without transferring domain ownership or authority. | Critical |
| PD-P1-007 | P1 | Duplicate resolution | Potential duplicates are detected and resolved through a governed process without silently merging records. | High |
| PD-P1-008 | P1 | Identity audit | Create, update, link, unlink and lifecycle events produce traceable audit evidence. | Critical |
| PD-P2-001 | P2 | People directory | Authorized users can search and browse tenant-scoped active People records. | High |
| PD-P2-002 | P2 | Server-side search | Search, filters, sorting and pagination are performed server-side with bounded inputs and stable results. | High |
| PD-P2-003 | P2 | Profile management | Authorized users can view and update permitted profile fields with validation and history. | High |
| PD-P2-004 | P2 | Contact point management | Email, phone and other approved contact points can be created, listed, designated primary and deactivated. | High |
| PD-P2-005 | P2 | Contact verification | Verification status reflects a completed trusted challenge; unverified values are never represented as verified. | Critical |
| PD-P2-006 | P2 | Contact confidentiality | Contact values are encrypted at rest, masked by default and protected by a documented key-rotation/fingerprint strategy. | Critical |
| PD-P2-007 | P2 | Contact preferences | Permitted communication channels and purpose/consent preferences are recorded and respected. | High |
| PD-P2-008 | P2 | Person relationships | Typed and dated relationships are tenant-scoped, reasoned and non-destructive. | High |
| PD-P2-009 | P2 | Sensitive relationships | Household, guardian, minor and emergency-contact data have field-level access and privacy rules. | Critical |
| PD-P2-010 | P2 | Participation | Participation records describe involvement and do not implicitly grant employment, membership, office or authority. | High |
| PD-P2-011 | P2 | Person documents | Person-document associations respect People permissions and Documents/Files ownership, retention and access controls. | High |
| PD-P3-001 | P3 | Employment relationship | Employment is a separate time-bound relationship linked to a canonical Person. | Critical |
| PD-P3-002 | P3 | Employment contracts | Contracts capture type, dates, terms, status and approved document links. | Critical |
| PD-P3-003 | P3 | Employment lifecycle | Hire, probation, confirmation, promotion, transfer, suspension, separation, resignation, retirement and rehire are governed transitions. | Critical |
| PD-P3-004 | P3 | Effective-dated history | Employment changes preserve prior values and effective dates; historical records are not destructively overwritten. | Critical |
| PD-P3-005 | P3 | HR cases and records | Sensitive HR cases and records have purpose-limited access, retention and audit. | High |
| PD-P4-001 | P4 | Organization assignment | Person assignments reference authoritative Organization department, branch and position records. | Critical |
| PD-P4-002 | P4 | Reporting relationships | Manager/reporting lines are effective-dated and reject invalid cycles. | High |
| PD-P4-003 | P4 | Acting/temporary assignments | Acting duties have explicit scope, authority source, approver, start/end dates and expiry. | Critical |
| PD-P4-004 | P4 | Workforce reconciliation | Headcount and assignment reports reconcile with active employment and Organization records. | High |
| PD-P5-001 | P5 | Requisition governance | Recruitment begins from an approved workforce requisition and authorized vacancy. | High |
| PD-P5-002 | P5 | Candidate privacy | Candidate personal data is purpose-limited, access-controlled and retained under policy. | Critical |
| PD-P5-003 | P5 | Recruitment pipeline | Applications, shortlist, interviews, evaluations and decisions are traceable. | High |
| PD-P5-004 | P5 | Offer and onboarding | Offers require approval; accepted candidates convert to Person/Employment without duplicate identity. | High |
| PD-P6-001 | P6 | Work calendars and shifts | Schedules use approved calendars, shifts, holidays and effective dates. | High |
| PD-P6-002 | P6 | Attendance | Attendance entries and integrations are authenticated, tenant-scoped and auditable. | Critical |
| PD-P6-003 | P6 | Leave policies and balances | Leave balances follow configured policy and are reproducible from transactions. | Critical |
| PD-P6-004 | P6 | Leave approval | Requests, approvals, cancellations and corrections follow delegated authority. | High |
| PD-P6-005 | P6 | Overtime and timesheets | Overtime and timesheet changes require authorization and preserve correction history. | High |
| PD-P7-001 | P7 | Goals and KPIs | Goals are linked to approved organizational and role objectives. | High |
| PD-P7-002 | P7 | Performance cycles | Review cycles have defined periods, participants, deadlines and workflow. | High |
| PD-P7-003 | P7 | Evaluation integrity | Scores, comments, moderation and completed evaluations are permission-controlled and auditable. | Critical |
| PD-P7-004 | P7 | Appeal and improvement | Employees can use governed review/appeal and performance-improvement processes. | High |
| PD-P8-001 | P8 | Learning catalogue | Courses and training providers have controlled descriptions, eligibility and schedules. | Medium |
| PD-P8-002 | P8 | Training completion | Attendance and assessment support verifiable completion and certificate records. | High |
| PD-P8-003 | P8 | Skills and qualifications | Skills/certifications are linked to People and role requirements with expiry alerts. | High |
| PD-P9-001 | P9 | Compensation structures | Grades, salary bands and allowances are effective-dated and approval-controlled. | Critical |
| PD-P9-002 | P9 | Compensation privacy | Salary and benefits are restricted by least privilege and audited. | Critical |
| PD-P9-003 | P9 | Payroll preparation | Payroll inputs reconcile to approved employment/attendance/compensation records. | Critical |
| PD-P9-004 | P9 | Finance integration | Finance alone owns journal posting, accounting treatment and payment execution. | Critical |
| PD-P10-001 | P10 | Employee self-service | Employees can securely view permitted profile, documents, requests and employment information. | High |
| PD-P10-002 | P10 | Manager self-service | Managers can perform only delegated team actions with scope and audit controls. | High |
| PD-P10-003 | P10 | People analytics | Reports are permission-scoped, reproducible and reconciled to authoritative records. | High |
| PD-P10-004 | P10 | Workforce planning | Forecasts support headcount, vacancy, skills and workforce-cost planning. | Medium |
| PD-P10-005 | P10 | Amharic-first experience | Core journeys are usable in Amharic, with English support and responsive mobile layouts. | Critical |
| PD-P10-006 | P10 | Accessibility and usability | Keyboard, focus, contrast, screen-reader and mobile acceptance criteria pass. | High |
| PD-P10-007 | P10 | Operational automation | Events and notifications are idempotent, permission-aware, observable and recoverable. | High |

## Cross-cutting acceptance requirements

All phases must additionally satisfy:
- Tenant isolation across API, search, exports, reports, queues, notifications and document access.
- Least-privilege authorization and separation of duties.
- Immutable or append-only evidence for important lifecycle and approval decisions.
- Explicit audit of sensitive reads and high-impact writes.
- MySQL migration, uniqueness, foreign-key and concurrency verification where applicable.
- API/frontend contract compatibility and exact-head CI.
- Amharic and English localization and accessible UX where user-facing.
- Documentation and operational procedures under the People roots.

## Status and scoring rules

Initial status for requirements is NOT ASSESSED unless specific source or test evidence is recorded in the phase report. The existence of source files does not mean VERIFIED. A weighted completion percentage is NOT SCORABLE until the Owner approves weights and acceptance evidence has been reviewed.

Allowed states: VERIFIED, PARTIALLY VERIFIED, MISSING, UNRESOLVED, UNKNOWN, NOT ASSESSED, NOT RUN.
