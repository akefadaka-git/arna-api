# ARNA D01 — A2 Cross-Domain Source Inspection and Status Errata

- **Record ID:** ARNA-D01-A2-TRACE-001
- **Version:** 1.0.5
- **Type:** Controlled source-inspection supplement and correction to v1.0.4
- **Prepared:** 2026-11-24
- **Parent supplement:** v1.0.4, commit `13c5a45b2ffe0b885496f9c79f908769087c0e5f`
- **API main:** `6e274c25ecc55b84e026e879d735b77f0e51592f`
- **App main:** `ffe0495c0eb7c8b5d2013419123aac5b68893d51`

## 1. Controlling correction to v1.0.4

Section 6 of v1.0.4 grouped D01-A2-02 / 03 / 04 / 05 as “PARTIAL / UNRESOLVED as previously registered.” That shorthand was imprecise and is corrected here. The individual requirement statuses in the controlling 24-row matrix are:

| Requirement | Correct controlling status |
|---|---|
| D01-A2-01 — operative bylaws, MoA/AoA and certified amendments | **UNRESOLVED** |
| D01-A2-02 — current GA/Board strategy approval resolutions | **MISSING / FAIL-CLOSED** |
| D01-A2-03 — authority, delegation and approval matrix | **PARTIAL** |
| D01-A2-04 — current org chart and accountable executive/planning appointments | **PARTIAL** |
| D01-A2-05 — adopted strategy, multi-year plans and prior versions | **PARTIAL** |

This correction changes no requirement row and no overall status count. It supersedes only the ambiguous grouped wording in v1.0.4.

## 2. Additional exact-main source inspection

### 2.1 Organization and People

| Source | Blob SHA | Inspected evidence | D01 boundary |
|---|---|---|---|
| `mases_core/src/Domains/Organization/Http/Controllers/OrganizationController.php` | `8330b447621cc3bfebfbcddd1c5f619ca14cdd88` | Tenant-context scoped unit and assignment operations; policy checks; transaction/row locking; hierarchy cycle/type validation; append-only organization audit event writes; active PersonTenantAccess check before assignment. | Useful producer for accountable-unit and assignment references. Not a strategic objective owner registry. |
| `mases_core/src/Domains/Organization/Models/OrganizationUnit.php` | `93fa42ef36e2bcc801958f9f1c42eb122fa1b55a` | Tenant-scoped organization units, parent/children, assignments and audit-event relations. | Canonical structure reference candidate; no D01 strategy linkage. |
| `mases_core/src/Domains/Organization/Models/OrganizationAssignment.php` | `a6b5887318d54ab6d16b22869dfdc8b1d7b355a4` | Assignment references organization unit and canonical Person; stores tenant, assignment type and effective dates. | Candidate accountable-person assignment source; not D01 responsibility acceptance. |
| `database/migrations/2026_10_28_000002_create_organization_units_table.php` | `f7ada0d938218a06b76d97acf3879e393fe5de1f` | Creates organization_units, organization_assignments and organization_audit_events; tenant-scoped foreign keys/indexes; audit-event update/delete triggers prohibit mutation. | Structural and audit persistence exists. |
| `mases_core/src/Domains/People/Services/WorkforceAggregateProvider.php` | `963d1bcadc65ab2c30c7042d6498a508d6f2fe4d` | Read-only active employee count by tenant for Executive Command Centre. | One narrow workforce aggregate; not a strategic workforce planning model. |
| `mases_core/src/Domains/People/Models/PersonTenantAccess.php` | `254624e6a32aeb3479d4fc1d5d2cb7f34e94f5d4` | Explicit active tenant visibility grant for a global Person record. | Person references must respect tenant access; D01 cannot treat global identity as tenant-authorized by default. |
| `database/migrations/2026_10_27_000001_create_people_table.php` | `db2fd2086639f4581ae0d735434cb8a4bcb9d256` | Creates canonical People table with public UUID and unique user linkage. | Canonical identity foundation; no D01 accountability/skills/capacity plan. |
| API `routes/api.php` | `7284faefc156548394a9ca7edfc2ead791773fb5` | People routes include tenant-scoped profiles, documents and relationships; Organization routes include units and assignments under auth + tenant membership; Calendar routes have explicit Calendar permissions. | Confirms route-level source registration in the central API route file; no D01 strategy routes. |

**Organization limitation discovered:** the complete `mases_core/src/Domains/Organization` subtree contains seven files: one controller, three models and three policies. No Organization-specific provider, route file, service layer, contract directory or test directory is present in that domain subtree at the inspected commit. The API routes are registered centrally in `routes/api.php`. The Organization controller explicitly prohibits `legal_entity_id` input until the authoritative domain contract is wired. This is a material integration boundary for any future legal-entity-scoped D01 plans.

### 2.2 Calendar and Ethiopian-calendar capability

| Source | Blob SHA | Inspected evidence | D01 boundary |
|---|---|---|---|
| `mases_core/src/Calendar/Services/CalendarService.php` | `bc2ee942ef66bfc06a670be5257252327db79311` | Tenant-scoped calendar/event service; creates versioned calendar records; uses domain-resource authorization and audit; depends on shared Ethiopian date/time types. | Reusable scheduling and localization capability; not an approved D01 planning cycle. |
| `mases_core/src/Calendar/Models/CalendarEvent.php` | `ee4662b92f8c52dc5c1f2a58e324dd7dbbde7d90` | Immutable datetime casts, version field and Ethiopian date/time payload accessors. | Calendar events can render E.C. and G.C. representations; does not define D01 planning-period semantics. |
| `src/features/calendar/api.ts` | `c9452f785f09cdab07a7fa678413c05adb5103bb` | App types include recurrence, calendar-system selection, event version and linked-domain resource references. | Candidate D01 milestone scheduling client; no D01 planning cycle or approval owner contract. |

### 2.3 Documents, Policy and Audit

| Source | Blob SHA | Inspected evidence | D01 boundary |
|---|---|---|---|
| `mases_core/src/Domains/Documents/Contracts/DocumentWorkflowAuthorizer.php` | `f243221173e939eab840470311609ed4f284d75d` | Authorization port requires permission and governed-action checks with tenant context. | Reusable approval-authority boundary; not proof of D01 competent authority. |
| `mases_core/src/Domains/Documents/Services/DocumentWorkflowAggregateService.php` | `55667ea2df7658cc141057e232aa3afbe4d0a32d` | Versioned document workflow; each authorized state change and immutable event are committed in one transaction; validates tenant, legal entity and organization unit references; supports signature evidence verifier and immutable binary storage. | Strong candidate record/evidence pattern for a future strategy approval package; no D01 strategy document type or decision lifecycle currently established. |
| `mases_core/src/Policy/Services/VersionedPolicyRegistry.php` | `c60d9ab1ad0148325a3ecdb1ea414b18fa85d3a7` | Domain-agnostic, append-only versioned policy definitions; resolves versions by effective date and can retrieve specific versions. | Reusable policy version/effective-date pattern; explicitly a policy registry, not a strategy/objective/scenario register. |
| `mases_core/src/Audit/Services/AuditService.php` | `82cf879387e18440f51134a9b75010318d6862eb` | Central audit logging with tenant/resource context and request/correlation metadata. | Platform audit producer; D01 still needs a defined retention schedule, decision evidence model and legal-hold rules. |
| `mases_core/src/Providers/CoreServiceProvider.php` | `a1aed79e3bf13ae1f721281ef73b0fea914218c6` | Registers document workflow authorization, signature verification, immutable binary store and aggregate service; also binds shared Ethiopian calendar engine and Finance source-reference resolver registry. | Confirms reusable core bindings; does not bind a D01 strategy service. |

### 2.4 App-side consumer surfaces

| Source | Blob SHA | Inspected evidence | D01 boundary |
|---|---|---|---|
| `src/features/calendar/api.ts` | `c9452f785f09cdab07a7fa678413c05adb5103bb` | Typed calendar client includes event recurrence and linked-domain references. | Reusable event client; no D01 planning calendar feature. |
| `src/features/people/api.ts` | `95ab392d5feec171d82dd78948d5996c4528d52d` | People client supports canonical person profile and relationship links, including governance membership. | Person/relationship consumer; not D01 role assignment or workforce planning. |
| `src/features/dashboard/labels.ts` | `ed1c18bc68fbf1969a8dc8b523de47082279a3b9` | Workspace feature dashboard label catalogue. | Navigation tiles only; no strategic KPI data surface. |
| `src/router/shell-labels.ts` | `ccc52aa553dbb4e6c922ea94f86f53aa0bd86872` | Workspace nav has separate Executive Overview, Governance, Calendar and general dashboard entries. Governance entry uses `governance.body.view`; Executive Overview uses `dashboard.executive.view` and UX role names. | No D01 strategy/objective/planning navigation entry. |

## 3. Domain-root coverage implications

The exact API tree contains ten implemented domain roots listed in v1.0.4. It does not contain roots corresponding to D01 Strategy & Enterprise Planning, D02 KPI/Performance, D04 Risk, D06 Compliance, D07 Portfolio/Projects, D23 BI/Data, D25 Investment & Capital, or D26 Business Development/Strategic Partnerships.

This is a repository-tree observation, not a claim that every such business activity is absent from all ARNA documents, external systems or future work. It means no corresponding dedicated domain root is available as a current-main implementation source in this API tree.

The present D01 cross-domain interface matrix must therefore distinguish:
- existing source domains that can provide canonical data;
- absent dedicated domain roots whose contracts are not yet implemented in this repository;
- draft PR/documentation artifacts that are not part of the frozen `main` baseline;
- D01 capabilities that have no implementation source at all.

## 4. Refined producer/consumer acceptance rules

The newly inspected source details refine, but do not close, these acceptance requirements:

1. **Organization → D01:** accept only stable tenant-scoped organization-unit IDs and effective-dated assignments. Reject cross-tenant parent/unit/person references. Do not assume legal-entity scope is implemented; current OrganizationController prohibits `legal_entity_id`.
2. **People → D01:** use canonical Person IDs plus active PersonTenantAccess and effective-dated assignments. Do not duplicate identity or infer employment from governance membership.
3. **Calendar → D01:** store planning cycle semantics in a D01-owned contract; use Calendar only for milestones/events. Persist an explicit calendar-system and timezone rule; do not infer a planning year from a display formatter.
4. **Documents → D01:** strategy records must be versioned, signed/verified where required, authority-bound, tenant/legal-entity scoped, immutable in decision history, and retained under an approved schedule.
5. **Policy → D01:** use policy registry only for policy/methodology versions; strategy versions, scenario versions and objective baselines require their own semantic identities and approval lifecycle.
6. **Audit → D01:** record actor, tenant, action, subject, before/after or immutable evidence reference, request/correlation ID, timestamp, authority instrument and outcome. Generic audit logging does not replace D01 retention/legal-hold policy.
7. **Finance → D01:** distinguish actuals from approved budgets, forecasts and scenario assumptions. A trial balance is realized accounting data, not an approved strategic target or budget baseline.

## 5. Status preservation and corrected requirement interpretation

The 24-row requirement matrix remains unchanged:
- PARTIAL: **16**
- MISSING: **7**
- UNRESOLVED: **1**
- VERIFIED-CLOSED: **0**

D01-A2-15 and D01-A2-16 remain PARTIAL: path enumeration is now complete for the two exact Git trees, but not every candidate source file has been inspected, and no runtime route list/build/test execution was performed. The source-inventory task has made material progress without changing requirement acceptance.

## 6. Work not performed

- No code, route, model, migration, policy, permission, seeder, schema or runtime configuration changed.
- No route:list, PHP/Composer command, database query, App build, test, browser test or CI workflow was run.
- No production system, server or private data was accessed.
- No PR merged; `main` remained unchanged.
- No A3, A4 or A5 authorization is created.

**Disposition: D01 A2 remains ACTIVE — PARTIAL — NOT VERIFIED-CLOSED.**
