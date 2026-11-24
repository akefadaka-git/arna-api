# ARNA D01 — A2 Existing Test Evidence and Requirement Acceptance Matrix

- **Record ID:** ARNA-D01-A2-TRACE-001
- **Version:** 1.0.6
- **Type:** Controlled test-source inventory and proposed requirement acceptance matrix
- **Prepared:** 2026-11-24
- **Parent supplement:** v1.0.5, commit `6041a29b74d4c40d2301c0908048f909be443529`
- **API main:** `6e274c25ecc55b84e026e879d735b77f0e51592f`
- **App main:** `ffe0495c0eb7c8b5d2013419123aac5b68893d51`

## 1. Purpose

This supplement completes the next A2 inventory pass by mapping existing API and App test-source files to D01 requirements and proposing requirement-linked acceptance tests. It distinguishes:
- existing source-level test coverage for shared platform/domain foundations;
- D01-specific acceptance evidence that does not yet exist in the inspected main tree;
- test source presence from actual test execution.

No tests were executed in this pass. The matrix below is an A2 proposal, not an approved A3 implementation contract.

## 2. Existing API test-source inventory

| Test source | Blob SHA | Existing coverage relevant to D01 | D01 limitation |
|---|---|---|---|
| `tests/Feature/OrganizationDomainTest.php` | `20b9a4de0d98d281913ddc812c5da4fd2c7f8d6a` | Tenant isolation, policy denial, hierarchy rules, append-only audit, effective-dated assignments, cross-tenant resource hiding. | No strategic objective/owner hierarchy mapping. |
| `tests/Feature/OrganizationHierarchyMySqlConcurrencyTest.php` | `0dfd361e415b9dd6a61307ed2431d9c6e57b9e00` | Concurrent hierarchy mutations produce one accepted result and one conflict; no invalid parent state remains. | No strategy-to-organization cascade. |
| `tests/Feature/PersonApiIntegrationTest.php` | `9177cca8c8b1ded61f371fda828d0797a93c7c46` | Canonical Person profile, tenant access, relationships to employee/shareholder/governance membership, privacy-safe fields and permission checks. | No D01 accountable-owner or workforce-capacity model. |
| `tests/Feature/PersonIdentityContractTest.php` | `3049646ba4503defc44c7c100931ce0200c8e950` | Canonical People schema and identity-contract constraints; rejects unapproved identity-claim table. | No D01 role/competency/capacity contract. |
| `tests/Feature/CalendarHttpAuthorizationTest.php` | `a5219fa8a51786fe80b960afce8caabc805b4790` | Calendar permission checks, Ethiopian civil dates, E.C. filters/recurrence, private-event visibility and cross-tenant hiding. | No D01 planning-cycle approval or milestone ownership. |
| `tests/Feature/CalendarLifecycleTest.php` | `36d7558e606c07eb7038445bee78c438b723e096` | Calendar/event versioning, E.C./G.C. conversions, recurrence exceptions, reminders/outbox and rollback behavior. | Shared calendar behavior only; no strategy-period model. |
| `tests/Feature/DocumentWorkflowAggregateServiceTest.php` | `7c68ed6c324e8b3f852610056500185585b5a030` | Atomic draft/review/issue/signing transitions, stale revisions, tenant scope, immutable event rows, signature verification, rollback and immutable snapshots. | No D01 strategy approval instrument or strategy version type. |
| `tests/Feature/DocumentsWorkflowAuthorizationCatalogueTest.php` | `c805a8393d9fbf9aeb5d5395d6431b5cbd8af276` | Tenant-scoped document permissions, least-privilege grants and explicit Governance action grants for governed decisions. | No D01 proposer/reviewer/approver segregation matrix. |
| `mases_core/src/Domains/Governance/Tests/Feature/GovernanceBodyTest.php` | `6c8e620bc1144db3bfed85a2a5f797e2ce3bb20a` | Tenant-scoped governance bodies, permissions, body type immutability, effective date validation and audit before/after. | Does not record a strategy resolution or strategy adoption. |
| `mases_core/src/Domains/Governance/Tests/Feature/GovernanceAppointmentTest.php` | `4eeec7da788ae26da024cf70a8f84c9ecc53c564` | Appointment instruments, body/position/term scope, effective dates, draft status, permission checks and cross-tenant rejection. | Appointment records alone do not prove current office-holder evidence or D01 authority. |
| `mases_core/src/Domains/Governance/Tests/Feature/GovernanceAuthorityGrantTest.php` | `4064c8bc0e83fa503ca5c7dcbc836a7f2ee9de6b` | Position/action-scoped authority grants, delegation, revocation, acting assignments, tenant access and governed document approval. | No D01-specific strategy adoption action or reserved-matters test. |
| `tests/Feature/ExecutiveOverviewAuthorizationTest.php` | `3f29639b2a2efe9d6d951fecba3a92230a34c298` | Executive Overview authorization tests exist. | Dashboard authorization is not strategy-management acceptance. |
| `tests/Feature/ExecutiveOverviewRouteTest.php` | `cb331992c72eeb71ae0e7b2e1fa50653abdb824d` | Executive Overview route/response contract tests exist. | Finance/People widgets only; not D01 strategic KPI or scenario contract. |

## 3. Existing App test-source inventory

| Test source | Blob SHA | Existing coverage relevant to D01 | D01 limitation |
|---|---|---|---|
| `tests/governance-route-contract.test.mjs` | `2eff0083a501bd91b06377d18979f527c1c35938` | Governance route/navigation permission parity, API-backed governance views, supported query filters, People/membership routes and explicit disclosure of unsupported data. | No D01 planning workspace or strategy approval UI. |
| `tests/calendar-workspace-contract.test.mjs` | `117b5ea92a60dbf3f6337e781e73dd2826a99ce1` | Calendar route permission, typed tenant-context endpoints, E.C.-first formatting, timezone and responsive accessibility contract, recurrence operations. | No D01 cycle or strategy milestone contract. |
| `tests/executive-overview-route-contract.test.mjs` | `e10707d156aa2e6254ae84348568b9a1a0276aab` | Executive Overview permission/role UX gates, typed Finance/People widgets, tenant/auth change clearing, request cancellation, forbidden/unavailable states and partial-data disclosure. | No strategy objective, target, risk, scenario or planning-period widget. |
| `tests/executive-overview-security.spec.mjs` | `e30f8cf3017fff07bd0ef9841bd13f65b7f4f633` | Browser security test source exists for Executive Overview. | Not D01 acceptance and not executed in this pass. |
| `tests/calendar-browser-contract.spec.mjs` | `268ba9a31a6da3fddca3579a56da9c398101a6fb` | Browser contract test source exists for Calendar. | Not D01 acceptance and not executed in this pass. |

### 3.1 Coverage conclusion

The exact-main test tree contains meaningful reusable tests for Governance authority, Organization hierarchy, People tenant access, Calendar E.C. behavior, Documents immutable evidence and Executive Overview access. No D01-specific strategy-plan, objective-cascade, scenario-baseline, planning-cycle, strategic KPI, strategy-to-budget, strategy-to-portfolio or strategy-approval test file was identified.

The presence of test source is not a PASS result. No API test, App test, build, browser test or CI workflow was run in this A2 inventory pass.

## 4. Proposed D01 requirement-linked acceptance/test matrix

The following minimum acceptance cases are proposed for A2 requirement traceability. They should be converted into an Owner-approved A3 implementation/test contract only after the A2 baseline is accepted and implementation is separately authorized.

| Requirement | Minimum positive acceptance case | Minimum negative/failure case | Existing reusable test source | D01-specific test status |
|---|---|---|---|---|
| D01-A2-01 — operative corporate instruments | Verify registered MoA/AoA/bylaws identity, amendments, effective dates and source hashes against certified records. | Reject ambiguous, superseded or unauthenticated instrument as authority source. | Documents immutable evidence tests are reusable only. | MISSING |
| D01-A2-02 — GA/Board strategy approval | Record exact strategy version, competent body, decision ID/date, quorum, vote/outcome and effective date. | Reject missing/invalid authority, quorum, vote, strategy version or effective date. | Governance body/appointment/authority tests; document workflow tests. | MISSING |
| D01-A2-03 — authority/delegation matrix | Resolve approver from current effective appointment and action/scope-specific grant. | Reject expired/revoked grant, self-approval conflict, wrong body/position/action or out-of-scope delegation. | GovernanceAuthorityGrantTest; DocumentsWorkflowAuthorizationCatalogueTest. | MISSING for D01 action matrix |
| D01-A2-04 — accountable executive and planning owners | Assign objective/planning responsibility to an active tenant Person and effective-dated Organization assignment. | Reject inactive/cross-tenant person, overlapping prohibited assignment or expired appointment. | PersonApiIntegrationTest; OrganizationDomainTest; GovernanceAppointmentTest. | MISSING for D01 owner contract |
| D01-A2-05 — strategy versions and multi-year plans | Create immutable draft, submit/review/approve, publish effective version and retain supersession lineage. | Reject stale revision, silent overwrite, unapproved publication or concurrent duplicate current version. | DocumentWorkflowAggregateServiceTest; generic VersionedPolicyRegistry pattern. | MISSING |
| D01-A2-06 — planning calendar | Configure approved planning horizon/cycle with E.C./G.C. period mapping and owned milestones. | Reject invalid period, timezone ambiguity, duplicate active cycle or milestone without accountable owner. | CalendarHttpAuthorizationTest; CalendarLifecycleTest. | MISSING |
| D01-A2-07 — objective hierarchy/cascade | Create uniquely identified parent/child objectives with owner, period, target and domain linkage. | Reject cycles, cross-tenant parent, orphaned required parent, invalid effective interval or unauthorized cascade. | OrganizationHierarchyMySqlConcurrencyTest is a hierarchy pattern only. | MISSING |
| D01-A2-08 — scenarios/assumptions/baselines | Create scenario with explicit baseline, assumption provenance, version, author and approval state. | Reject baseline mutation, missing provenance, stale version or unapproved scenario as a forecast. | Document immutable evidence tests; versioned policy pattern only. | MISSING |
| D01-A2-09 — strategy-to-budget/investment | Link objective to approved budget/investment reference and legal entity/currency/period; show actuals separately. | Reject unapproved budget, cross-entity mapping, currency mismatch or any implied spending authority. | FinanceGeneralLedger and FinanceSourceEntityMapping tests. | MISSING |
| D01-A2-10 — portfolio/project intake | Convert approved priority to portfolio intake with sponsor, delivery owner, stage gate and benefit owner. | Reject intake without approved objective, sponsor, required risk review or funding authority. | No D01/portfolio acceptance source located. | MISSING |
| D01-A2-11 — KPI dictionary and mapping | Bind objective to governed KPI definition, formula, unit, target, threshold, period and owner. | Reject duplicate/conflicting definition, missing formula/owner/target, invalid unit or unapproved KPI version. | ExecutiveOverviewRouteTest only demonstrates non-D01 widget contracts. | MISSING |
| D01-A2-12 — BI dataset and lineage | Bind objective/KPI to certified dataset with source lineage, transformation version, freshness and quality status. | Reject stale/uncertified dataset, missing lineage, tenant leakage or failed quality gate. | Executive Overview widget source tests are narrow patterns only. | MISSING |
| D01-A2-13 — strategic risk | Link strategy/objective to approved risk appetite and risk-register record with owner and response. | Reject risk outside authority/tolerance without escalation or acceptance record. | Governance and policy tests are indirect only. | MISSING |
| D01-A2-14 — legal/regulatory obligations | Link each material strategic constraint to applicable instrument, jurisdiction, effective date, owner and review evidence. | Reject expired/inapplicable instrument, unresolved legal interpretation or unsupported compliance claim. | Document evidence tests are reusable only. | MISSING |
| D01-A2-15 — API source and contract inventory | Verify provider/route registration, request/response schema, auth, tenant/entity scope and domain contract tests on exact SHA. | Reject unregistered route, unauthorized tenant/entity access, unknown contract version or stale source SHA. | API domain tests listed above; no D01 API test. | PARTIAL — inventory; D01 acceptance missing |
| D01-A2-16 — App feature/UI inventory | Verify D01 route/menu, backend permission parity, localization, accessible states, typed API and tenant-change clearing. | Reject frontend-only authorization, fabricated data, stale tenant data or unsupported workflow action. | Governance/Calendar/Executive Overview App contract tests. | PARTIAL — inventory; D01 UI acceptance missing |
| D01-A2-17 — branch/PR/workflow evidence | Bind each acceptance claim to exact commit, PR, workflow run, test report and changed-file scope. | Reject evidence from a different head SHA, missing workflow, skipped tests or unrelated branch. | GitHub metadata/Actions evidence; no D01 workflow run in this pass. | PARTIAL |
| D01-A2-18 — identity/tenant/RBAC | Resolve actor, tenant membership, legal-entity scope and action-specific permission before each read/write. | Reject forged tenant context, cross-tenant IDs, revoked membership and unauthorized action. | OrganizationDomainTest; PersonApiIntegrationTest; GovernanceBodyTest. | PARTIAL — shared patterns; D01 matrix missing |
| D01-A2-19 — audit/retention/legal hold | Persist append-only decision events, evidence references, actor, authority, timestamps, before/after and retention classification. | Reject event mutation/deletion, missing decision audit, retention breach or legal-hold bypass. | OrganizationDomainTest; DocumentWorkflowEventImmutabilityTest; DocumentWorkflowAggregateServiceTest. | PARTIAL — shared patterns; D01 schedule missing |
| D01-A2-20 — Amharic-first/E.C./accessibility | Verify Amharic labels, E.C. planning periods, G.C. conversion, keyboard/screen-reader support and approved glossary. | Reject silent G.C.-only assumptions, ambiguous date conversion, missing translation or inaccessible approval controls. | CalendarHttpAuthorizationTest; Calendar workspace contract tests. | PARTIAL — shared foundations; D01 acceptance missing |
| D01-A2-21 — dictionary/lineage/reference IDs | Verify canonical IDs, source owner, definition, effective dates, provenance, quality and freshness. | Reject duplicate IDs, unresolved source, missing lineage or stale/unverifiable value. | Finance report contract and Executive Overview source tests are limited. | MISSING |
| D01-A2-22 — process/templates/consultation | Verify formulation, consultation, review, challenge, approval, publication and exception process with minutes/templates. | Reject bypassed consultation, missing minutes, untracked exception or publication before approval. | Governance/Document lifecycle tests are reusable only. | MISSING |
| D01-A2-23 — security/privacy/SoD | Enforce proposer/reviewer/approver separation, least privilege, confidentiality classification and tenant/entity scope. | Reject self-approval, privilege escalation, unauthorized export or disclosure across tenants. | GovernanceAuthorityGrantTest; DocumentsWorkflowAuthorizationCatalogueTest; ExecutiveOverviewAuthorizationTest. | PARTIAL — shared patterns; D01 SoD matrix missing |
| D01-A2-24 — accepted domain dependencies | Verify producer/consumer contract version, owner, source IDs, freshness, failure handling and deprecation path. | Reject unknown contract version, broken producer, stale data, missing owner or silent fallback. | Existing Finance/People/Calendar/Organization tests are component patterns. | PARTIAL — no Owner-accepted D01 interface contracts |

## 5. Test execution and evidence posture

- All listed tests are source files inspected from the exact API/App main commits.
- **Execution status:** NOT RUN in this A2 pass.
- **CI status:** NOT CHECKED / NO RUN CLAIMED in this pass.
- **Build/type-check status:** NOT RUN.
- No test result is promoted to PASS merely because an assertion exists in source.
- The D01-specific acceptance-test status remains MISSING until a D01 test contract and exact-SHA execution evidence exist.

## 6. A2 status and next step

- Complete exact-main API/App path enumeration: **VERIFIED COMPLETE** at the named commits.
- Candidate shared-source inspection: **PARTIAL**.
- Existing shared-domain test-source inventory: **COMPLETED for the selected D01-relevant sources listed above**.
- D01 requirement traceability: **24/24 mapped**.
- D01-specific test acceptance: **MISSING / NOT RUN**.
- Requirement status distribution: **16 PARTIAL / 7 MISSING / 1 UNRESOLVED / 0 VERIFIED-CLOSED**.
- D01 A2: **ACTIVE — PARTIAL — NOT VERIFIED-CLOSED**.
- A3: **NOT AUTHORIZED**; A4 not started; A5 not authorized.

**Immediate next A2 action:** reconcile the 24 proposed acceptance cases against the frozen 30-domain catalogue and define source-of-truth, owner, interface version, positive/negative cases and exact evidence required for each D01 requirement. Do not implement the cases during A2.
