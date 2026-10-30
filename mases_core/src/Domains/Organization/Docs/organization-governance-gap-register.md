# Organization feature-to-code gap register

**Assessment baseline:** destination repositories, read-only source inspection on 2026-10-29.  
**Scope rule:** all Organization implementation belongs under `mases_core/src/Domains/Organization/`. The only permitted API integration points outside that directory are domain-provider registration, platform-wide permission aggregation, and the existing seed orchestrator. Organization frontend implementation belongs under `src/features/organization/`; the workspace router composes the domain-owned route module as a single integration point.

**Status vocabulary:** `IMPLEMENTED—UNVERIFIED`, `PARTIAL`, `MISSING`, `DECISION REQUIRED`, `NOT RUN`. A permission slug alone is not proof that a feature exists.

| Feature / business capability | Model | Migration | Service | Policy | Route / permission | Test evidence | UI integration | Status / next action |
|---|---|---|---|---|---|---|---|---|
| Organizational unit identity and hierarchy | `OrganizationUnit` | `organization_units` | `OrganizationUnitService` + `OrganizationHierarchyService` | Unit + hierarchy policies | `GET/POST/GET-id/PUT/DELETE /api/organization/units`; `organization.unit.view/manage` | Organization feature + hierarchy concurrency tests on PR branch; NOT RUN | `features/organization/OrganizationUnitsView.vue`, detail view and domain route module in app PR | PARTIAL — runtime route and migration registration not verified |
| Parent/child type rules and cycle prevention | `OrganizationUnit` parent/children | Same | `OrganizationHierarchyService` locks tenant hierarchy and validates parent | `OrganizationHierarchyPolicy` | Unit create/update | Hierarchy concurrency contract exists; NOT RUN | Parent summary and child links | PARTIAL — no runtime concurrency verification |
| Effective-dated person assignments | `OrganizationAssignment` | `organization_assignments` | `OrganizationAssignmentService` (query) + controller (writes) | `OrganizationAssignmentPolicy` | Nested assignment list/create and assignment patch; `organization.assignment.view/manage` | Assignment filter contract exists; NOT RUN | Assignment list/create/status-transition UI in app PR #6 | IMPLEMENTED—UNVERIFIED — verify API response contracts and effective-date transitions; tests NOT RUN |
| Assignment status/date/person filters | `OrganizationAssignment` | Same | `OrganizationAssignmentService` filters tenant, status, person, inclusive effective date | Assignment policy | `GET /units/{id}/assignments` | Filter contract test exists; NOT RUN | Assignment workspace in app PR #6 exposes paginated listing and creation; date/person filter controls remain limited | IMPLEMENTED—UNVERIFIED for API filters; UI filtering PARTIAL |
| Append-only Organization audit history | `OrganizationAuditEvent` | `organization_audit_events` | `OrganizationAuditService` records and queries audit rows | Audit view permission/policy | `GET /api/organization/audit-events`; `organization.audit.view` | Partial source coverage; NOT RUN | Audit timeline/filter UI in app PR #6 | IMPLEMENTED—UNVERIFIED — database trigger behavior and tenant/policy isolation remain unverified |
| Organization-specific permissions and seed integrity | Domain permission catalogue + seeders | Shared permissions table plus domain tables | Domain-owned catalogue and seeder; Core is generic aggregator only | Unit/assignment policies | Five tenant-scoped Organization slugs | Seeder contract test exists; NOT RUN | Route metadata uses `organization.unit.view` | PARTIAL — role-grant contract and deployment registration still require verification |
| Organizational positions and reporting line | No general Organization position model found; Governance positions are a separate concept | Missing general Organization position migration | Missing | Missing | Missing | Missing | Missing | MISSING — define position/employee reporting contract without duplicating Governance seats |
| Management-team register and executive roles | Generic assignments only | No management-team aggregate | Missing complete workflow | Missing complete workflow | No management-team API | Missing | Missing | PARTIAL/MISSING — define executive role, reporting and authority instrument contract |
| Governance integration boundary | Governance-owned bodies, positions, terms, appointments, memberships, seats, meetings, elections and authority instruments | Governance-owned migrations | Governance-owned services | Governance-owned policies | Governance-owned routes and permissions | Governance-specific tests and evidence belong in Governance documentation | Organization stores only explicit operational-unit links where contractually required | BOUNDARY — Organization does not own or document Governance feature implementation gaps |
| Amharic-first Organization workflows | Domain types and data fields support bilingual names | Same | API returns localized names | N/A | Unit, assignment and audit routes | UI tests not run | Amharic/English unit, assignment and audit screens in app PR #6 | PARTIAL — broader People/position integration and verification remain |
| Feature-to-code parity enforcement | N/A | N/A | N/A | N/A | N/A | Existing tests cover selected contracts only | Frontend route contract coverage incomplete | MISSING — add a non-runtime inventory/contract check once implementation contracts stabilize |

## Non-negotiable integration rules

1. Do not move Organization models, migrations, services, policies, requests, routes, translations, tests or UI files into central folders.
2. Keep the Organization backend provider registered centrally only because Laravel boots providers centrally; it owns all Organization-specific route, config, policy and migration loading.
3. Keep the app router as a composition point only; route definitions and views remain inside `src/features/organization/`.
4. Do not duplicate Governance body/seat/appointment concepts in Organization. Organization owns operational units and assignments; Governance owns formal bodies, elections, appointments, meetings, resolutions and authority.
5. Do not seed an Organization chart or formal Board/election rules without approved codes, hierarchy, mandate, quorum and governing instruments.
6. The Organization CI workflow invokes the relocated domain tests by their current paths and includes a dedicated MySQL audit immutability step. The workflow has not been rerun during this implementation pass; CI evidence remains `NOT RUN`.

## Assignment history and People visibility contract

- The assignment table has a composite foreign key to `person_tenant_accesses(person_id, tenant_id)`, so an assignment cannot be inserted for a person without a matching tenant-access record.
- People access revocation changes the access row to `revoked`; it does not delete that row or erase Organization history.
- Organization assignment queries retain the assignment row after revocation. The related Person profile is eager-loaded only while the matching tenant access is active; after revocation the assignment is returned with a null Person relation.
- New assignments still require an active People tenant-access row at the API boundary.
- Assignment classifications are exactly `primary`, `acting`, `delegated`, and `support`; these never substitute for formal Governance authority instruments.
