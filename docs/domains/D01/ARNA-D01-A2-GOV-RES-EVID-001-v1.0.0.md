# ARNA D01 A2 — Governance Resolution and Source-Instrument Linkage Review

- **Record ID:** ARNA-D01-A2-GOV-RES-EVID-001
- **Version:** 1.0.0
- **Stage:** A2 read-only reconciliation
- **Disposition:** PARTIAL / GAP CONFIRMED
- **Repository inspected:** `akefadaka-git/arna-api`
- **Inspected ref:** `main` (destination repository source of truth)
- **Inspection date:** 2026-11-25
- **Workstream:** Existing D01 A2 evidence branch; no new branch

## 1. Purpose

Determine whether the current Governance implementation contains a first-class corporate resolution/decision record and an authenticated source-document linkage mechanism sufficient to evidence General Assembly and Board decisions relevant to D01 strategy authority, annual plans/budgets, major strategic changes, appointments and delegations.

This is a repository-source inspection only. It is not a database query, legal opinion, operational record verification, or authorization to implement.

## 2. Findings

### 2.1 First-class resolution/decision capability — MISSING in inspected Governance source

A recursive inspection of the current `main` tree (1,300 entries; non-truncated) found no `GovernanceResolution` model, resolution migration/table, resolution controller, or resolution service. The Governance domain has meeting scheduling and attendance/proxy/penalty capabilities, but the inspected model/controller/migration inventory does not establish a formal resolution register or decision lifecycle.

Relevant existing source:

- `mases_core/src/Domains/Governance/Database/Migrations/2026_09_18_130000_create_governance_appointments_table.php`, blob `74775629532014644d8ce2f4abac7d351f4afdb7`.
- `mases_core/src/Domains/Governance/Models/GovernanceAppointment.php`, blob `968944676073614f4edcf41fa9539529d04680e2`.

The appointment migration explicitly states that `instrument_reference` is a free-text identifier and does not store a specific resolution, decision, or external document; it anticipates a future optional `resolution_id` foreign key when a `governance_resolutions` capability is delivered. Therefore, a populated appointment reference is not proof that the referenced source exists, is authentic, was adopted by a competent organ, or remains effective.

### 2.2 Meeting capability — PARTIAL; not a decision register

The migration `mases_core/src/Domains/Governance/Database/Migrations/2026_11_02_100000_create_governance_meeting_attendance_tables.php`, blob `efa43bf08b0f89aa103536f97f381a25fe6e19e1`, creates:

- `governance_meetings` (tenant, governance body, meeting code/kind, title, scheduled timestamps and status);
- attendance and proxy records;
- penalty, settlement-request and meeting-activity records.

The corresponding `GovernanceMeetingAttendanceController.php`, blob `ee2abfd7b2881499575df078d3c62b931303c217`, implements tenant-scoped meeting and attendance workflows. The inspected meeting schema does not contain agenda items, motions, quorum determinations, voting/ballot outcomes, adopted resolutions, approved minutes, or an authenticated source-instrument file reference. Attendance/activity evidence cannot substitute for those decision artifacts.

### 2.3 Existing document linkage — not a corporate resolution link

The People-domain `PersonDocumentLink` model, blob `87f95cfe8473ea0606a59950f4df0f1c7dd11aa4`, and migration `database/migrations/2026_10_28_000001_create_person_document_links_table.php`, blob `f4c4b9f3c7ba68b528c5e69abf170973b66f5401`, link a file to a person with a free-text reason. This is a person-profile document association, not a governance-meeting, resolution, appointment-instrument, or authority-grant source relation. It does not close the corporate decision evidence gap.

### 2.4 Tests and seeders

The Governance meeting feature test, blob `78ffc9a4e5fe2c4c33241ec0b746371660e50312`, creates synthetic test tenants, governance bodies, users, memberships, meetings and attendance. Such fixtures demonstrate application behavior only; they are not evidence of actual ARNA meetings or adopted decisions.

No live database rows, production/staging records, uploaded source instruments, or authenticated corporate resolutions were inspected. No test suite or workflow was run as part of this A2 read-only review.

## 3. D01 evidence impact

The source gap materially affects verification of:

1. The adopted enterprise strategy and its approval/version history.
2. The competent organ and source instrument approving the annual strategic plan and budget.
3. General Meeting approval of major changes to corporate strategic direction or reserved matters.
4. The legal basis and source decision for current appointments and delegations.
5. The ability to trace a strategy/plan decision from meeting notice and attendance through agenda, motion, quorum, vote, resolution, minutes, approval and execution.

The Owner-approved D01 architecture contract remains an architecture baseline subject to explicit legal exceptions. It does not authenticate historical resolutions or establish legal authority. Legal exceptions LE-01 through LE-06 remain OPEN.

## 4. A2 disposition and recommended acceptance criteria for a future phase

- **Resolution/decision register:** MISSING in inspected Governance source.
- **Meeting-to-resolution linkage:** MISSING.
- **Resolution-to-source-file linkage and integrity metadata:** MISSING for corporate decisions in the inspected Governance source.
- **Appointment-to-authenticated-resolution linkage:** MISSING; current appointment instrument reference is free text.
- **Meeting scheduling/attendance:** PARTIAL capability exists; it does not establish adopted decisions.
- **Actual corporate records:** UNKNOWN / NOT INSPECTED.

A future separately authorized design/implementation phase should assess, at minimum, first-class records for decision organ, meeting, agenda item/motion, quorum evidence, voting method and result, resolution number/text, adoption timestamp, effective date, approval state, signatories/secretary, minutes, supersession/revocation, tenant/legal entity, and one or more immutable source-file references with content hash, file/version identity, provenance, custody and verification status. It should enforce fail-closed behavior where authority, quorum, source instrument, approval, or effective status is missing, conflicting, draft, revoked, expired or superseded. This paragraph is a gap-derived recommendation, not an approved schema or A3 specification.

## 5. Status preservation and controls

- D01 enterprise A2 disposition: **UNVERIFIED / OPEN**.
- D01 requirement checklist remains **16 PARTIAL / 7 MISSING / 1 UNRESOLVED / 0 VERIFIED-CLOSED**; this review does not silently reclassify or recount requirements.
- Legal exceptions LE-01 through LE-06 remain **OPEN**.
- Parent A1 v1.2.0 and all prior A1/A2 records remain preserved.
- This record is documentation-only on the existing D01 A2 evidence branch.
- No application code, schema, migration, seeder, route, production record or runtime configuration was changed.
- No tests, builds or CI workflows were run.
- No A3 implementation, A4 verification, A5 deployment, merge or deployment is authorized.

## 6. Source inventory

| Source | Blob SHA |
|---|---|
| Appointment migration | `74775629532014644d8ce2f4abac7d351f4afdb7` |
| Appointment model | `968944676073614f4edcf41fa9539529d04680e2` |
| Meeting/attendance migration | `efa43bf08b0f89aa103536f97f381a25fe6e19e1` |
| Meeting/attendance controller | `ee2abfd7b2881499575df078d3c62b931303c217` |
| People document-link model | `87f95cfe8473ea0606a59950f4df0f1c7dd11aa4` |
| People document-link migration | `f4c4b9f3c7ba68b528c5e69abf170973b66f5401` |
| Meeting feature test | `78ffc9a4e5fe2c4c33241ec0b746371660e50312` |
| GA A1 requirements baseline | `c73b9ce63219cf39529adc303b9e58ef09838507` |
