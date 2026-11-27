# ARNA D01 — A2 Governance Technical Evidence Review

- **Record ID:** ARNA-D01-A2-GOV-TECH-EVID-001
- **Version:** 1.0.0
- **Prepared:** 2026-11-25
- **Record type:** A2 read-only source/test/seed review
- **Repository:** `akefadaka-git/arna-api`
- **Inspected source ref:** destination `main` (PR #223 base SHA `6e274c25ecc55b84e026e879d735b77f0e51592f`)
- **Working branch:** `docs/arna-d01-a2-evidence-register`
- **Disposition:** **TECHNICAL STRUCTURES VERIFIED; CORPORATE EVIDENCE NOT ESTABLISHED**
- **Scope:** D03 governance bodies, appointments and authority grants as evidence inputs for D01 LE-05 and LE-06; limited relevance to LE-01 and LE-04.

## 1. Purpose and boundary

This review inspects existing repository source, migration, seeder and feature-test files to distinguish software capability from real corporate evidence. It is read-only. No database was queried, no seeder was run, no test was executed and no live governance records were inspected.

A model, migration, route, API payload or test fixture is not proof that a General Meeting or Board actually adopted a resolution, elected/appointed a current office-holder, or granted a legally effective delegation.

## 2. Exact source inventory

| Artifact | Blob SHA | Source-level finding |
|---|---|---|
| `GovernanceAppointment.php` | `968944676073614f4edcf41fa9539529d04680e2` | Model represents an appointment instrument and carries tenant/body, instrument type/reference/date, effective date, appointment mode and lifecycle status |
| `GovernanceAuthorityGrant.php` | `2c8e4ebcc3a2dde02824cbfc7ba5e86d7bf3b0a1` | Model carries tenant, grantee/grantor, scope/action, effective interval and revocation fields |
| `2026_09_18_130000_create_governance_appointments_table.php` | `74775629532014644d8ce2f4abac7d351f4afdb7` | Appointment table stores a free-text `instrument_reference`; migration explicitly says it does not store the specific resolution/decision/external document and notes a future optional resolution FK |
| `GovernanceAppointmentTest.php` | `4eeec7da788ae26da024cf70a8f84c9ecc53c564` | Feature tests create tenant-scoped test bodies and appointment records; test appointment starts as draft |
| `GovernanceAuthorityGrantTest.php` | `4064c8bc0e83fa503ca5c7dcbc836a7f2ee9de6b` | Test setup creates a test tenant, test users/persons, test body/position/membership and temporary grant, then tests scope and revocation |
| `GovernanceSeeder.php` | `e705a2d5ab9dd5b1944fd9f7e35f92d4dd9cf38f` | Seeder chains application, permissions and roles seeders only |
| `GovernanceApplicationSeeder.php` | `da8fb550e5877d26ca3b164c2da6d71dd28f1459` | Creates/updates the Governance platform-application registration; does not create a real Board, appointment, resolution or authority grant |

## 3. Findings

### 3.1 Appointment model and source-instrument linkage

The appointment model has fields for instrument type, free-text reference, instrument date, effective date, appointment mode and status. The migration's own documentation explicitly states:
- the appointment table represents an instrument that creates memberships;
- the table does not store the specific resolution, Board decision, General Assembly act or external document;
- `instrument_reference` is a free-text identifier;
- a future change may add an optional foreign key when a governance-resolutions capability is delivered.

Therefore, a populated reference such as `RES-2026-042` would not by itself prove that the underlying resolution exists, is authentic, was adopted by a competent organ, or applies to the named person and decision date.

### 3.2 Feature-test records are synthetic

The appointment feature test:
- creates its own tenant and governance body;
- submits a sample reference `RES-2026-042`;
- asserts that the API stores the appointment as **draft**;
- has a helper that constructs test appointments with sample references such as `RES-2026-000` and status `draft`.

The authority-grant feature test:
- creates a test tenant, administrator, grantee, person records, governance body, position and membership in test setup;
- creates a temporary grant for a bounded action and date interval;
- verifies that the grant authorizes only the expected test action and can be revoked.

These are useful technical behavior tests. They are not records of actual corporate proceedings, current Board/GM membership, real appointment instruments or legally approved delegations.

### 3.3 Seeder boundary

The Governance aggregate seeder calls only:
1. Governance application registration;
2. Governance permissions;
3. Governance roles.

The application seeder uses `PlatformApplication::updateOrCreate` for the Governance application registration. The inspected seeder chain does not create corporate governance bodies, appointments, resolutions, office-holder assignments or authority grants.

This is positive evidence that the inspected Governance seeder chain is not itself supplying the missing real-world corporate records. It does not prove that no such records exist in any other database or source.

### 3.4 What this source review does not cover

- No live database connection or data query was performed.
- No production, staging or local database rows were inspected.
- No seed command or test suite was run.
- No CI workflow was triggered or treated as passed.
- No original resolution attachment, signed minutes, attendance/quorum record, certified corporate instrument or appointment acceptance was authenticated.
- No legal opinion was obtained.

## 4. D01 legal/authority exception impact

| Exception | Technical impact of this review | Status |
|---|---|---|
| LE-01 — Competent authority | Governance software can represent bodies and appointments, but does not determine legal competence of the GM/Board | **OPEN / NOT LEGALLY VERIFIED** |
| LE-02 — Instrument identity/hierarchy | Appointment reference is not a legal-source repository or instrument hierarchy | **OPEN / UNRESOLVED** |
| LE-03 — Annual budget reservation | No budget-reservation legal evidence is represented by the inspected appointment test/seeder artifacts | **OPEN / NOT VERIFIED** |
| LE-04 — Major-change reservation/delegation | Technical authority grants are scoped application delegations; no adopted corporate reserved-matter schedule or legal delegation evidence is established | **OPEN / NOT VERIFIED** |
| LE-05 — Current appointments/delegations | Test data and software schema exist; current real office-holders and effective legal delegations remain unestablished | **OPEN / NOT ESTABLISHED** |
| LE-06 — Adopted strategy baseline | Sample resolution references and draft appointment records do not establish an authenticated adopted strategy resolution/version | **OPEN / NOT ESTABLISHED** |

## 5. Technical control implications for future A3 design (not implementation authorization)

When separately authorized, any D01 integration that consumes governance authority evidence must not treat a free-text reference, draft appointment, test fixture, seed record, role or application permission as sufficient authority. The acceptance contract should require:
- a resolvable, authenticated source instrument or resolution record;
- competent decision organ and decision-type validation;
- effective date and scope, including tenant and legal entity where applicable;
- current appointment and term/acting status at the decision timestamp;
- a valid delegation chain with scope, expiry and revocation checks;
- supporting minutes/attendance/quorum and record custody where required by the applicable instrument;
- fail-closed behavior for missing, conflicting, draft, revoked, expired or superseded evidence.

This section records design constraints only. It creates no new schema, endpoint, permission, test or implementation authorization.

## 6. Stage disposition

- Technical source inspection: **PASS — FILES READ ONLY**.
- Synthetic-test distinction: **PASS — TEST FIXTURES NOT CORPORATE EVIDENCE**.
- Seeder review: **PASS — INSPECTED CHAIN DOES NOT SEED CORPORATE APPOINTMENTS/RESOLUTIONS**.
- Runtime/live-data verification: **NOT RUN**.
- CI/workflow verification: **NOT RUN**.
- Legal verification: **NOT RUN / NOT PROVIDED**.
- LE-01 through LE-06: **ALL OPEN**.
- D01 A2: **UNVERIFIED / OPEN**.
- Requirement tally remains **16 PARTIAL / 7 MISSING / 1 UNRESOLVED / 0 VERIFIED-CLOSED**.
- A3: **NOT AUTHORIZED**. A4: **NOT STARTED**. A5: **NOT AUTHORIZED**.
- Merge: **NOT AUTHORIZED**. Deployment: **NOT AUTHORIZED**.

## 7. Next A2 action

Continue read-only source inspection for whether the Governance domain has an authoritative resolution/decision record capability and a source-document linkage mechanism beyond the appointment table's free-text reference. If no such source is present, record the gap; do not create it during A2.
