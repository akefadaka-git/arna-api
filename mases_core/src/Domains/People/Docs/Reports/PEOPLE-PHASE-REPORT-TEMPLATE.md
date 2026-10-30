# People Domain — Phase Report and Closure Template

- Document ID: PD-TPL-001
- Version: 1.0.0
- Owner: ARNA Owner
- Storage: `mases_core/src/Domains/People/Docs/Reports/`
- Companion application report: `src/features/people/docs/Reports/`

## 1. Phase identity

| Field | Value |
|---|---|
| Phase ID / name | |
| Report version | |
| Reporting period | |
| Report author / reviewer | |
| Approved scope reference | |
| Baseline API SHA | |
| Baseline App SHA | |
| Candidate API SHA / branch / PR | |
| Candidate App SHA / branch / PR | |
| Current phase status | NOT STARTED / IN PROGRESS / PARTIAL / BLOCKED / READY FOR OWNER REVIEW / CLOSED |

## 2. Executive summary

State what the phase set out to deliver, what is evidenced, what remains incomplete, the highest risks, and the requested Owner decision. Do not use "complete" without closure evidence.

## 3. Requirements status

| Requirement ID | Requirement | Priority | Acceptance evidence | Status | Defect / decision |
|---|---|---|---|---|---|
| | | | | VERIFIED / PARTIAL / MISSING / UNRESOLVED / UNKNOWN / NOT RUN | |

Report counts for total, verified, partial, missing, unresolved, unknown and not run.

## 4. Implementation inventory

| Repository | Exact path | Change type | Requirement ID | Review evidence | Status |
|---|---|---|---|---|---|
| API / App | | Added / modified / moved / deleted | | Commit / diff reference | |

List migrations, seeders, configuration, routes, permissions, models, services, jobs, UI screens, translations, scripts and documentation separately.

## 5. Architecture and integration

- Bounded-context ownership:
- Dependencies and contracts:
- API schema and compatibility:
- Events and reliable delivery:
- Cross-domain data ownership:
- Framework integration points:
- Backward compatibility and deprecation:

## 6. Security, privacy and audit

| Control | Required state | Evidence | Result |
|---|---|---|---|
| Tenant isolation | | | |
| Authorization / least privilege | | | |
| Sensitive-field access | | | |
| Encryption / key management | | | |
| Audit completeness | | | |
| Retention / subject rights | | | |
| Export / background-job isolation | | | |
| Concurrency / idempotency | | | |

## 7. Database and data integrity

Record engine/version, migrations discovered, migration execution result, schema constraints, indexes, FK behavior, transaction boundaries, lock ordering, rollback strategy, backfill/data impact and evidence. If not executed, mark NOT RUN.

## 8. API and frontend acceptance

Record route contracts, request validation, resources, permission middleware, API client types, pages/components, loading/error/empty states, responsive behavior, keyboard/accessibility, Amharic/English parity and end-to-end journeys.

## 9. Verification and CI evidence

| Check | Exact commit SHA | Workflow / command | Run ID and URL | Result | Notes |
|---|---|---|---|---|---|
| Static analysis / lint | | | | | |
| Unit / feature | | | | | |
| Integration | | | | | |
| MySQL clean install | | | | | |
| Security / tenant tests | | | | | |
| Concurrency | | | | | |
| Frontend type-check | | | | | |
| Frontend build | | | | | |
| UI / E2E / accessibility | | | | | |

Never report a result from a different SHA as exact-head verification. No run means NOT RUN; a missing check record means UNKNOWN or NOT ESTABLISHED, not PASS.

## 10. Defects, risks and decisions

| ID | Finding | Severity | Owner | Mitigation / decision | Status |
|---|---|---|---|---|---|

Record accepted risks with explicit Owner acceptance and expiry/review date.

## 11. Documentation and operational readiness

List updated architecture, requirements, data dictionary, API contracts, security policies, manuals, runbooks, support procedures, monitoring/alerting, backup/restore and incident response materials.

## 12. Readiness metrics

Use only the approved weighted requirements catalogue. Report separately:
- Requirements completion:
- Implementation completion:
- Verification completion:
- Security completion:
- Integration completion:
- Documentation completion:
- Defect closure:
- Overall weighted phase readiness:

For every percentage, state numerator, denominator, weighting method and excluded/deferred requirements. If the baseline or evidence is insufficient, state NOT SCORABLE.

## 13. Phase exit checklist

- [ ] Every mandatory requirement has acceptance evidence.
- [ ] No unresolved critical/high defect remains, or the Owner has explicitly accepted it.
- [ ] Tenant/security/privacy/audit controls passed.
- [ ] Database/migration and rollback evidence passed where applicable.
- [ ] API/frontend contract and integration evidence passed.
- [ ] Exact-head CI and required test suites passed.
- [ ] Documentation and operational manuals updated inside People roots.
- [ ] Independent review completed.
- [ ] Risks, exceptions and deferred work recorded.
- [ ] Owner has explicitly approved phase closure.

## 14. Closure decision

Decision: OPEN / PARTIAL / BLOCKED / READY FOR OWNER REVIEW / CLOSED

Evidence summary:

Outstanding conditions:

Owner decision and date:

Next authorized phase and scope:

## 15. Action boundary record

Explicitly record which source changes, file moves, migrations, seeders, test runs, workflow executions, PR actions, database operations and deployments were authorized and performed. Never infer authorization for a subsequent gate.
