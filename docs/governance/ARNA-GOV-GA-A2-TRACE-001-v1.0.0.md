# ARNA General Assembly A2 — Frozen Requirements to Current API/App Traceability

- **Record ID:** ARNA-GOV-GA-A2-TRACE-001
- **Version:** 1.0.0
- **Stage:** A2 read-only reconciliation
- **Disposition:** PARTIAL — substantial functional gaps remain
- **Controlling A1 baseline:** `docs/governance/ARNA-GOV-GA-A1-001.md` v1.0.0 (frozen)
- **API inspected:** `akefadaka-git/arna-api` main at `f3fdd3219b7f9c5b1ff55ef24929fd5f2ec42446`
- **App inspected:** `akefadaka-git/arna-app` main at `a111a0ae4a8c7ffa1505b9788fb08e71a78f14b5`
- **Inspection date:** 2026-11-25
- **Scope:** Repository evidence only; no runtime/production database inspection

## 1. Executive disposition

The current API and App provide a tenant-scoped meeting scheduling, attendance, proxy submission/review, attendance correction, penalty/dispute, and Finance settlement-request foundation. They do not implement the full General Assembly lifecycle frozen in A1.

The current meeting API creates a meeting directly with status `scheduled`; the inspected workflow does not evidence convening-authority approval, notice authorization/service, electorate snapshot, quorum determination, agenda, motions, voting, resolutions, approved minutes, or post-meeting decision execution. The meeting UI is an attendance and proxy workspace, not a complete GA workspace.

The previous GA A2 technical contract (`docs/governance/ARNA-GOV-GA-A2-CLOSURE-A3-IMPLEMENTATION-CONTRACT.md`, blob `691012753ab51604ed487b0edf6a8ca0ef4aa5d4`) remains useful design evidence, but it is not proof those capabilities were implemented. Its API baseline was `9f65465e9e78107ca0530cc758b268ea4a27d5ed`; this review rechecked current source at the exact main SHAs above.

## 2. Traceability method

A1 sections are mapped below to current API schema/routes/controllers, App API/UI and available feature-test evidence. Status meanings:

- **PARTIAL:** some relevant capability exists, but one or more material A1 controls or acceptance criteria are absent or unverified.
- **MISSING:** no corresponding implementation was found in the inspected current-main source.
- **UNRESOLVED:** legal/source authority or a controlling parameter must be authenticated before implementation or activation.
- **NOT VERIFIED:** source code exists, but no current CI/test execution or live operational record was inspected.

The requirement IDs below are traceability labels introduced by this A2 record. They do not amend or renumber the frozen A1 document.

## 3. Requirement traceability matrix

| A2 trace ID | Frozen A1 subject | Current evidence | Disposition |
|---|---|---|---|
| GA-R01 | Authority hierarchy, source register and conflict handling (A1 §§2, 13, 19) | A1 defines precedence; D01 authority contract and legal-exception register exist as architecture/evidence documents. No runtime authenticated governing-source register or automated conflict gate was found in GA routes/models. | PARTIAL; legal source identity remains UNRESOLVED |
| GA-R02 | Meeting classes, convening and lifecycle (A1 §3) | `governance_meetings` stores body, meeting code/kind, title, start/end and status. API creates status `scheduled` directly. No convening authority/evidence, authorization transition, notice state, adjournment/closure/minutes lifecycle found in this workflow. | PARTIAL |
| GA-R03 | Electorate and voting entitlement (A1 §4) | Attendance checks active shareholder rows. No record-date snapshot, share-class entitlement calculation, frozen electorate, entitlement reconciliation or duplicate voting-right control found. | PARTIAL |
| GA-R04 | Attendance and representation (A1 §5) | Attendance rows support shareholder/director subject, status, arrival timestamp and notes; tenant-scoped read/write and activity records exist. No complete A1 channel, check-in/check-out, verified entitlement snapshot or evidence pack. | PARTIAL |
| GA-R05 | Proxy lifecycle (A1 §6) | Submission and independent review routes exist; test covers non-user GA proxy and self-review rejection. No complete revocation/expiry lifecycle, source-file authentication, proxy-cap enforcement or entitlement-level double-count proof found. | PARTIAL |
| GA-R06 | Quorum (A1 §7) | No GA quorum rule registry, electorate snapshot calculation, reproducible quorum trace or quorum approval endpoint/UI found. A2 contract correctly says unconfigured rules must block calculation; no current implementation found. | MISSING; numerical/legal rule UNRESOLVED |
| GA-R07 | Notice, calendar and time management (A1 §8) | Meeting start/end timestamps exist. No official notice preparation/approval/service evidence, recipient register, record-date notice control, session clock events, or current Governance-to-Calendar link route found. | MISSING |
| GA-R08 | Agenda and meeting papers (A1 §9) | No agenda, agenda-item, submission, publication/version or supporting-paper workflow found in current Governance routes/models/App view. | MISSING |
| GA-R09 | Motions, voting and resolutions (A1 §10) | No motion, voting session, ballot, tally, certification or resolution register/model/route/UI found. Appointment `instrument_reference` remains free text and is not a resolution record. | MISSING |
| GA-R10 | Penalties and financial treatment (A1 §11) | ETB 50/300 penalty assessment, disputes/review and Finance settlement requests exist. Current penalty creation defaults to `assessed`; the A2 contract identifies the need for candidate/pending-review then independent approval. No automatic ledger posting is established by the Governance workflow. | PARTIAL; policy/legal activation UNRESOLVED |
| GA-R11 | Minutes, decisions and execution (A1 §12) | No draft/review/approval/signature/certification/archival minutes workflow, resolution-linked action register, owner/due-date tracking or closure certification found. | MISSING |
| GA-R12 | Decision authority, segregation and scope (A1 §13) | Governance permission middleware and independent proxy/attendance/penalty review controls exist in part. The full convening/chair/secretary/voter/legal/records authority matrix and evidence-backed corporate authority checks are not implemented in the GA workflow. | PARTIAL |
| GA-R13 | Notifications and communication (A1 §14) | No GA-specific notice/agenda/proxy/quorum/voting/minutes/action notification lifecycle or delivery evidence surfaced in current Governance routes/UI. | MISSING |
| GA-R14 | Records, privacy, security and audit (A1 §15) | Tenant-scoped records and meeting activity events exist; attendance corrections and penalty disputes retain review metadata. No complete append-only GA decision audit, approved record retention/hold, certified meeting-pack export or confidential ballot control found. | PARTIAL |
| GA-R15 | Dashboard, reporting and acceptance evidence (A1 §16) | Meeting list/detail and attendance/proxy/penalty registers exist. No complete A1 GA dashboard/report set or requirement-to-API/data/UI/permission/test evidence package found. | PARTIAL |
| GA-R16 | Legal and policy parameters (A1 §17) | A1 explicitly lists blocked values; D01 legal evidence register retains legal exceptions. Current implementation must not be treated as proving those values. | UNRESOLVED |
| GA-R17 | A1 freeze and controlled change (A1 §18) | Frozen A1 baseline and separate A2 technical contract exist. No in-application controlled change-request workflow for A1 requirements was found. | PARTIAL |

## 4. Current API/App implementation evidence

### 4.1 API

- Meeting/attendance/proxy/penalty tables: `mases_core/src/Domains/Governance/Database/Migrations/2026_11_02_100000_create_governance_meeting_attendance_tables.php`, blob `efa43bf08b0f89aa103536f97f381a25fe6e19e1`.
- Attendance correction and penalty dispute tables: `.../2026_11_02_110000_create_governance_attendance_review_tables.php`, blob `1dbeeb088607fa13ff2885efeffc6b53790ef8a2`.
- Controller: `mases_core/src/Domains/Governance/Http/Controllers/GovernanceMeetingAttendanceController.php`, blob `ee2abfd7b2881499575df078d3c62b931303c217`.
- Routes: `mases_core/src/Domains/Governance/Routes/api.php`, blob `dedca778025fbfad8b129a0c7fbac93834c32846`.
- Feature tests: `mases_core/src/Domains/Governance/Tests/Feature/GovernanceMeetingAttendanceTest.php`, blob `78ffc9a4e5fe2c4c33241ec0b746371660e50312`.

The route surface covers meeting list/create/show, attendance, proxy submit/review, penalty assessment, settlement request, attendance correction/review and penalty dispute/review. It does not expose the A1 agenda, quorum, motion, vote, resolution, minutes, notice or decision-execution routes.

### 4.2 App

- Main view: `src/features/governance/GovernanceAttendanceView.vue`, blob `cd5d8060a469b190d2cbc845f5f7a81eba660298`.
- API client: `src/features/governance/attendance-api.ts`, blob `df9705c0f4a049e9852a8c5cf242d2882d15497c`.

The view exposes meeting creation, attendance recording, proxy submission/review, attendance correction/review, penalty assessment/dispute and settlement requests. No agenda, quorum, notice, voting, resolution or minutes UI was found in this feature view.

### 4.3 Test evidence limitation

The feature-test source contains synthetic tenant/shareholder/meeting fixtures and assertions for selected meeting, attendance, proxy and penalty behaviors. The source file is not proof that tests currently pass. This A2 task did not run PHPUnit, frontend tests, builds, migrations, CI, or browser checks. No live production/staging records were inspected.

## 5. Consolidated disposition

- **PARTIAL:** GA-R01, R02, R03, R04, R05, R10, R12, R14, R15, R17 — 10
- **MISSING:** GA-R06, R07, R08, R09, R11, R13 — 6
- **UNRESOLVED:** GA-R16 — 1
- **Total traceability groups:** 17

These counts describe this A2 traceability grouping only. They are not a replacement for, or a claim of closure against, the D01 24-requirement tally. They also do not assert the GA A1 baseline is complete or that any legal requirement is activated.

## 6. A2 blockers and control implications

1. Do not treat a meeting created with status `scheduled` as proof of valid convening.
2. Do not calculate quorum until the legal denominator/rule and electorate snapshot are authenticated and effective-dated.
3. Do not infer voting entitlement from shareholder count or attendance.
4. Do not treat proxy submission or a free-text instrument reference as verified proxy authority.
5. Do not treat attendance/meeting activity as proof of a motion, vote, resolution or approved minutes.
6. Do not activate GA penalties solely from the presence of ETB 50/300 code paths; legal applicability and policy version remain gated.
7. Do not treat Calendar reminders as official notice/service.
8. Do not post financial entries or dividend offsets directly from Governance records.
9. Preserve E.C.-default localization and time-zone requirements in future work; the inspected view's generic `toLocaleString()` display is not evidence of the complete A1 calendar requirement.
10. Preserve the frozen A1 baseline; changes require controlled change evidence and Owner approval.

## 7. Recommended next A2 actions

1. Reconcile the current source documents and policy records against the legal gates in A1 §§2, 6–8, 10–13 and 17, without interpreting missing evidence as approval.
2. Produce a requirement-level acceptance matrix that maps each A1 clause to exact API schema/route, App view, permission, test and evidence; mark absent evidence explicitly.
3. Keep GA implementation planning separate from D01 legal authority verification and separate from deployment.
4. If future implementation is separately authorized, follow the existing A2 implementation contract's additive-schema and no-duplicate-table safeguards; do not merge the stale meeting/calendar branches wholesale.

## 8. Status and authorization

- Frozen GA A1 v1.0.0: **PRESERVED**.
- GA A2 technical disposition: **PARTIAL / OPEN**.
- Legal activation parameters: **UNRESOLVED / BLOCKED** where specified by A1.
- Current API/App source inventory: **INSPECTED** at the exact main SHAs recorded above.
- Tests/builds/CI/database verification in this task: **NOT RUN**.
- A3 implementation: **NOT AUTHORIZED** by this record.
- A4 verification: **NOT STARTED** by this record.
- A5 deployment: **NOT AUTHORIZED**.
- No code, schema, migrations, seeders, runtime configuration, production data, merge or deployment changed.

