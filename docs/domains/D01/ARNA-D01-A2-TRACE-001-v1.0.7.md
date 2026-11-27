# ARNA D01 — A2 Domain-Disposition Alignment Errata

- **Record ID:** ARNA-D01-A2-TRACE-001
- **Version:** 1.0.7
- **Type:** Controlling status-alignment errata
- **Prepared:** 2026-11-24
- **Supersedes wording in:** Traceability matrix/supplements v1.0.0–v1.0.6 only where they state or imply that the overall D01 domain disposition is PARTIAL
- **Does not supersede:** The 24 requirement-level evidence rows or their arithmetic errata
- **Owner decision source:** `ARNA-ESPPM-A2-CLOSURE-REPORT-001 v1.0.1`, PR #219, branch `docs/esppm-a2-closure-report-001`, report blob `9f9adca91eadef06d50bf661d648b71cd7596d40`, branch head `026d5cc5a56ec827bb5c8460167808f604082f8d`

## 1. Controlling distinction — two different status levels

The prior D01 records use the word “PARTIAL” for the requirement-level evidence checklist. That is not the controlling enterprise domain disposition.

The Owner decision recorded in ESPPM A2 closure report v1.0.1 explicitly rejects the proposed upgrade of D01 from UNVERIFIED to PARTIAL. It states that additional corporate identity and document-provenance evidence does not establish:
- an adopted enterprise strategy;
- competent strategy-approval authority; or
- current executive appointments.

The controlling status must therefore be reported at two separate levels:

| Status level | Controlling result | Meaning |
|---|---|---|
| **D01 enterprise domain A2 disposition** | **UNVERIFIED** | Owner rejected the proposed domain-level upgrade to PARTIAL. D01 is not verified as an accepted enterprise strategy/planning contract. |
| **D01 requirement-level checklist** | **16 PARTIAL / 7 MISSING / 1 UNRESOLVED / 0 VERIFIED-CLOSED** | The 24 detailed evidence requirements have individual source/evidence assessments. These lower-level PARTIAL rows do not promote the overall domain disposition. |
| **D01 A2 workstream** | **OPEN / ACTIVE** | Read-only evidence reconciliation continues; no closure recommendation is accepted yet. |
| **D01 subordinate A1 specification** | **DRAFT — OWNER REVIEW REQUIRED — NOT FROZEN** | PR #222 contains a draft subordinate specification only. Parent catalogue A1 v1.2.0 remains frozen. |

## 2. Exact Owner decision evidence

The Owner decision table in ESPPM A2 closure report v1.0.1 records:

- Approved D01 A2 disposition before resolution: **UNVERIFIED**.
- Supplemental proposal: PR #223 proposes **PARTIAL**.
- Owner decision: **REJECT proposed upgrade**.
- Controlling A2 disposition: **UNVERIFIED**.

The report further states that the D01 v1.0.2 checklist's 24 requirement-level results (16 PARTIAL, 7 MISSING, 1 UNRESOLVED, 0 VERIFIED-CLOSED) remain a lower-level evidence assessment and do not promote the enterprise D01 disposition from UNVERIFIED. It also states that D01 subordinate A1 remains draft and is not a frozen implementation contract.

This errata aligns all later A2 reporting with that Owner decision. It does not reopen, reverse or reinterpret the Owner decision.

## 3. Related PR/branch evidence — proposal versus accepted authority

| PR | Branch | Current state at inspection | Evidence | D01 interpretation |
|---|---|---|---|---|
| #219 | `docs/esppm-a2-closure-report-001` | OPEN / DRAFT / UNMERGED | ESPPM A2 closure report v1.0.1 records the explicit Owner rejection of the D01 PARTIAL upgrade and preserves UNVERIFIED. | Controlling domain-disposition decision record. |
| #220 | `feat/arna-ecc-a3-p0` | OPEN / DRAFT / UNMERGED | ECC 30-domain KPI readiness register v1.0.0, blob `f3a8237d6c014aa4cc75506626ef1cf4d2f061f8`, marks D01 UNAVAILABLE for KPI readiness; D09 and D11 PARTIAL; none READY. | Separate KPI-readiness evidence; not D01 strategy approval or an A2 domain upgrade. |
| #222 | `docs/arna-d01-a1-spec-a2-evidence` | OPEN / DRAFT / UNMERGED | D01 A1 spec v1.0.0, blob `c04dc84e21ccc9114d8952ba4d38913e36e58004`, states DRAFT / OWNER REVIEW REQUIRED / NOT FROZEN. | Proposed subordinate specification; not an approved/frozen D01 A1 baseline. |
| #223 | `docs/arna-d01-a2-evidence-register` | OPEN / DRAFT / UNMERGED | Evidence register and traceability documents, including this successor series. | Supplemental evidence proposal; does not change the Owner-approved UNVERIFIED domain disposition. |

A branch search for D01 in `akefadaka-git/arna-api` returned the two D01-named branches above and no further results on the subsequent page. A strategy/strategic branch-name search returned no matching branch. This is branch-name search evidence only; it is not proof that no differently named historical work exists.

## 4. Required wording for all future D01 reports

Use this exact status structure:

- **Enterprise domain A2 disposition:** UNVERIFIED — Owner-approved; proposed PARTIAL upgrade rejected.
- **Requirement-level evidence checklist:** 24/24 mapped; 16 PARTIAL, 7 MISSING, 1 UNRESOLVED, 0 VERIFIED-CLOSED.
- **A2 workstream:** OPEN / ACTIVE — read-only reconciliation continues.
- **Subordinate D01 A1:** DRAFT / OWNER REVIEW REQUIRED / NOT FROZEN (PR #222).
- **A3:** NOT AUTHORIZED.
- **A4:** NOT STARTED.
- **A5:** NOT AUTHORIZED.
- **Merge/deployment:** NOT AUTHORIZED.

Do not report “D01 overall A2 PARTIAL.” Do not add the 16 requirement-level PARTIAL items to the enterprise domain disposition. Do not describe PR #223 as accepted authority for an overall status change.

## 5. Correction to prior traceability supplements

The status sentences in v1.0.0 through v1.0.6 that say “D01 A2 remains ACTIVE — PARTIAL — NOT VERIFIED-CLOSED” are ambiguous if read as the enterprise domain disposition. They must be interpreted as referring only to the lower-level requirement evidence and the ongoing workstream.

For clarity, the controlling replacement wording is:

> **D01 enterprise domain A2 disposition remains UNVERIFIED.** The 24 requirement-level checklist remains 16 PARTIAL, 7 MISSING, 1 UNRESOLVED and 0 VERIFIED-CLOSED. The A2 evidence workstream remains OPEN / ACTIVE and read-only. The subordinate D01 A1 specification remains DRAFT / OWNER REVIEW REQUIRED / NOT FROZEN.

Earlier records remain preserved as issued. This errata controls future interpretation; it does not rewrite their historical content.

## 6. Change and gate boundary

- Documentation-only successor record on `docs/arna-d01-a2-evidence-register`.
- No API/App runtime source, route, model, migration, schema, seed, permission, configuration or production data changed.
- No tests/build/CI workflow run.
- No PR merged; `main` remains untouched.
- No A3 implementation, A4 verification, A5 deployment or merge authority is created.

**Controlling disposition: D01 enterprise domain A2 = UNVERIFIED; requirement-level checklist = 16 PARTIAL / 7 MISSING / 1 UNRESOLVED / 0 VERIFIED-CLOSED; A2 workstream = OPEN / ACTIVE.**
