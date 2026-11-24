# ARNA ESPPM A2-R10 — Exact-SHA Evidence Index, Exception Revalidation and Closure-Readiness Assessment

- **Record ID:** ARNA-ESPPM-A2-R10-INDEX-EXCEPTIONS-001
- **Version:** 1.0.0
- **Prepared:** 2026-11-24
- **Stage:** A2 — read-only evidence-index and exception revalidation
- **Frozen catalogue:** ARNA-ESPPM-A1-SPEC-002 v1.2.0
- **Controlling Owner decision:** KEEP A2 OPEN — DO NOT CLOSE (2026-11-24)
- **Repository of record:** `akefadaka-git/arna-api`
- **Change class:** Documentation-only index and governance assessment; no source implementation

## 1. Executive determination

**R10 result: EXACT-SHA REVALIDATION COMPLETE FOR THE IDENTIFIED OPEN A2 DOCUMENTATION BRANCHES; A2 CLOSURE NOT READY.**

The current GitHub metadata and commit search were rechecked on 2026-11-24. The live API and App main tips remain the same exact commits recorded in R09. PR #219 is still OPEN / DRAFT / UNMERGED and its current head is `a1d2b12e82a7a806a761e2e3c0e303f3c9722faf`, not the older head recorded in prior conversational progress summaries.

R10 does not change, promote, downgrade or reinterpret any of the 30 Owner-approved domain dispositions. It does not accept residual exceptions, close A2, authorize a merge, or authorize A3/A4/A5.

## 2. Current main snapshots — revalidated

| Repository | Ref | Exact current tip | Verification |
|---|---|---|---|
| `akefadaka-git/arna-api` | `main` | `6e274c25ecc55b84e026e879d735b77f0e51592f` | Latest commit-search result; matches PR #219 base SHA |
| `akefadaka-git/arna-app` | `main` | `ffe0495c0eb7c8b5d2013419123aac5b68893d51` | Latest commit-search result; matches R09 and D01 exact-main source baselines |

The API main SHA is independently corroborated by PR #219's base SHA and the compare endpoint. The App main SHA is independently corroborated by the latest commit-search result and the exact-main references in the re-fetched D01 traceability supplement and R09. Neither repository's main branch was changed by R10.

## 3. Outstanding documentation and related evidence branches

All SHAs below are full commit IDs. Ahead/behind figures are measured against the current API main SHA in Section 2, not merely copied from each PR's original base comparison.

| PR | Branch | Live head SHA | Compare vs current API main | Current state | Revalidated artifact / meaning |
|---|---|---|---:|---|---|
| #212 | `docs/esppm-a1-v1.2.0-frozen-catalogue` | `631b7a0eb7f027c17751b7af39bbc826fd70124e` | 2 ahead / 1 behind | OPEN / DRAFT | Frozen catalogue blob `e9cd1f4a087a3d7f285ccda7786149d64db6a4bc` |
| #213 | `docs/esppm-a2-owner-decision-record-001` | `ded7b487544972588f163a0dd7b14a9f02f6fe69` | 1 ahead / 1 behind | OPEN / DRAFT | Owner-approved A2 decision-record blob `722589724b77f5330b4f592f36108ace0309bedc` |
| #215 | `docs/esppm-a2-treasury-psc-evidence-addendum-001` | `4c65ada3343571167bcb9c2e47e7f92abd4b89d8` | 3 ahead / 1 behind | OPEN / DRAFT | Treasury/PSC evidence addendum blob `9c88fcd23a58954fde9d63017cea5aad88b030dd` |
| #219 | `docs/esppm-a2-closure-report-001` | `a1d2b12e82a7a806a761e2e3c0e303f3c9722faf` | 17 ahead / 0 behind | OPEN / DRAFT | Consolidated A2 report v1.0.4 and R09/R08/D12/D15-D17/evidence-timing baselines |
| #220 | `feat/arna-ecc-a3-p0` | `8d0e4f236cc23eea21b0c44b4f3571d0710f2120` | 1 ahead / 0 behind | OPEN / DRAFT | ECC A3.1 readiness register blob `f3a8237d6c014aa4cc75506626ef1cf4d2f061f8`; related work, not A2 disposition authority |
| #222 | `docs/arna-d01-a1-spec-a2-evidence` | `cf4486f0c983641bfd3f81ad284b5efe4e57c401` | 1 ahead / 0 behind | OPEN / DRAFT | D01 subordinate A1 draft blob `c04dc84e21ccc9114d8952ba4d38913e36e58004`; NOT FROZEN |
| #223 | `docs/arna-d01-a2-evidence-register` | `06483f578ab75e4a9824bc01e213abb0418c0288` | 13 ahead / 0 behind | OPEN / DRAFT | D01 traceability v1.0.8 blob `12dbb73cef6fde2d3cf8889a6422dfaef0af6191`; D01 evidence register v1.0.3 blob `454adbf71c5145d330a30d5354da6285f9d3c6ed` |

### 3.1 Branch-base drift — archival proposals

PRs #212, #213 and #215 were based on historical API commit `40428db0a41d54397dd2aee482c9395f7950e9d0`. The current API main contains the intervening documentation/progress commit `6e274c25ecc55b84e026e879d735b77f0e51592f`. Consequently, each historical branch is one commit behind current main and its compare status is diverged.

This is **base drift, not evidence that the archived document content is corrupted**. Preserve these historical proposal branches as-is. Do not rebase, merge or retarget them as part of A2 evidence revalidation. Their documents are evidence/archive proposals, not live current-main implementation.

PRs #219, #220, #222 and #223 are based on current API main and compare as 0 behind at the verified heads.

### 3.2 PR #219 exact artifact anchors

| Artifact | Path | Blob SHA |
|---|---|---|
| Consolidated closure report v1.0.4 | `docs/architecture/ARNA-ESPPM-A2-CLOSURE-REPORT-001-v1.0.4.md` | `67a7b5272cee27aeb30461bcc2d43e4cd58cf387` |
| R09 reconciliation v1.0.0 | `docs/architecture/ARNA-ESPPM-A2-R09-RECONCILIATION-001-v1.0.0.md` | `d41d20c0a47d313cf9c0bc4a1f35db2338644351` |
| R08 D04/D05 traceability v1.0.0 | `docs/architecture/ARNA-ESPPM-D04-D05-A2-TRACEABILITY-001-v1.0.0.md` | `ae922a9d41460fa23bf5e7995dd4b3953a0c48f9` |
| D12 Treasury baseline v1.0.0 | `docs/architecture/ARNA-ESPPM-D12-TREASURY-A2-CONTRACT-BASELINE-001-v1.0.0.md` | `72046cb9c2c76e73fe3371ec60c3efb52fa6e2e3` |
| D15/D17 contract baseline v1.0.0 | `docs/architecture/ARNA-ESPPM-D15-D17-A2-CONTRACT-BASELINE-001-v1.0.0.md` | `ead8d3755fc0c4ba5742e422fc76145c9f9bd4af` |
| Evidence-timing addendum v1.0.0 | `docs/architecture/ARNA-ESPPM-A2-EVIDENCE-TIMING-ADDENDUM-001-v1.0.0.md` | `c7210f834ae0bef7145e6e28c1722094ac1ca7b7` |
| R10 exact-SHA index and exception assessment v1.0.0 | `docs/architecture/ARNA-ESPPM-A2-R10-INDEX-EXCEPTIONS-001-v1.0.0.md` | Assigned by GitHub after creation; see the PR head and fetch-back record |

Historical reports v1.0.0–v1.0.3 remain separate immutable artifacts. The older Section 13.3 index in report v1.0.4 is explicitly a historical snapshot at its stated cutoff; its older PR head rows must not be reused as live refs. The current refs in this R10 record supersede that snapshot for present-day branch-state checks.

## 4. Stale references and conflicting evidence resolved

### EX-01 — PR #223 description carries an obsolete branch head

- **Severity:** MEDIUM — evidence-index integrity.
- **Observed:** Live PR metadata and commit comparison identify PR #223 head `06483f578ab75e4a9824bc01e213abb0418c0288`, 13 ahead / 0 behind current API main. The PR description still names `1a6197c2c1e73ea774f13f3f4156c67175a094b4` as its latest head and lists the v1.0.7 supplement as latest.
- **Resolution:** The live head is controlling. Fetch-back at the live head verified D01 traceability v1.0.8 blob `12dbb73cef6fde2d3cf8889a6422dfaef0af6191` and D01 evidence register v1.0.3 blob `454adbf71c5145d330a30d5354da6285f9d3c6ed`. PR description requires a metadata-only correction to reflect the live head and v1.0.8/v1.0.3 artifacts. No branch commit or disposition change is needed.
- **Status:** CONTENT VERIFIED; PR DESCRIPTION CORRECTION REQUIRED.

### EX-02 — D01 subordinate A1 objective ownership conflicts with frozen catalogue

- **Severity:** HIGH — domain ownership / contract conformance.
- **Observed:** PR #222's D01 A1 draft v1.0.0 assigns strategic-objective hierarchy and a StrategicObjective record to D01. Frozen catalogue v1.2.0 and D01 traceability v1.0.8 assign authoritative enterprise objectives, KPI definitions and targets to D02.
- **Resolution:** R09's controlling interpretation is retained: D01 owns strategy direction, themes, priorities, planning context and references/alignment to D02 objectives; D02 owns authoritative enterprise objective records/hierarchy, KPIs, targets and performance accountability. D01 draft remains NOT FROZEN; a controlled successor and Owner review are still required.
- **Status:** CONFLICT IDENTIFIED; NOT CLOSED; NO DISPOSITION CHANGE.

### EX-03 — PR #215 addendum status predates the Owner's D15/D17 decision

- **Severity:** MEDIUM — decision chronology / evidence interpretation.
- **Observed:** PR #215's archived addendum v1.0.2 still describes D15/D17 PARTIAL refinements as awaiting Owner decision. The controlling R06 decision in the consolidated A2 record rejected both proposed upgrades.
- **Resolution:** Treat PR #215 as historical supplemental evidence only. The later Owner decision controls: D15 remains MISSING and D17 remains MISSING. The addendum's pending-decision language is superseded, not silently rewritten. PR #215 description should carry a clear supersession note.
- **Status:** DOMAIN DECISION RESOLVED; HISTORICAL DOCUMENT LABEL SUPERSEDED.

### EX-04 — Historical PR index rows in report v1.0.4 are not live branch metadata

- **Severity:** LOW — temporal qualification.
- **Observed:** Report v1.0.4 Section 13.3 records earlier branch heads as a snapshot and explicitly says the live PR metadata is authoritative after that cutoff. R09 and R10 contain later live references.
- **Resolution:** Preserve the historical index as history. Use Section 3 of R10 for current live heads. Do not label the earlier snapshot values as current.
- **Status:** QUALIFICATION CLARIFIED.

### EX-05 — Historical-base branches #212/#213/#215 diverge from current main

- **Severity:** MEDIUM — branch lifecycle / review hygiene.
- **Observed:** Current-main comparisons show each branch one commit behind and respectively two, one and three commits ahead; their merge base is historical commit `40428db0a41d54397dd2aee482c9395f7950e9d0`.
- **Resolution:** This is expected archival branch-base drift. Preserve the branches and exact content; do not rebase or merge without separate authorization. Their historical source records remain usable with their stated historical scope.
- **Status:** EXPLAINED; NO ACTION TO MAIN.

### EX-06 — Draft/implementation readiness is not equivalent to A2 domain acceptance

- **Severity:** HIGH — stage-gate integrity.
- **Observed:** PR #220 is an A3.1 KPI-readiness workstream and its register labels itself an A3.1 governance baseline; it is not an Owner-approved A2 domain reclassification. PRs #222 and #223 remain draft documentation proposals. No current-main universal acceptance execution is evidenced by these branch states.
- **Resolution:** Keep A2 dispositions controlled solely by the Owner-approved decision record and subsequent explicit Owner decisions. Candidate branch content and successful candidate CI, where any, do not promote a domain or satisfy A4.
- **Status:** RULE REAFFIRMED.

### EX-07 — Deferred primary financial/corporate evidence

- **Severity:** HIGH for eventual legal/operational verification; not a pre-deployment blocker.
- **Observed:** The evidence-timing addendum and R09 explicitly defer primary corporate and financial source records until ARNA is deployed and running on the server.
- **Resolution:** No pre-deployment upload is requested. Continue with repository/specification evidence only. Post-deployment intake and authentication remain a future verification gate; deferred evidence is not deemed verified.
- **Status:** CORRECTLY DEFERRED; NOT A PRE-DEPLOYMENT BLOCKER.

## 5. Preserved A2 dispositions — no change

| Disposition | Domains | Count |
|---|---|---:|
| SUPPORTED | D08, D22 | 2 |
| PARTIAL | D02, D03, D06, D09, D10, D11, D13, D14, D20, D21, D23 | 11 |
| UNVERIFIED | D01, D04, D05, D07, D18, D24 | 6 |
| MISSING | D12, D15, D16, D17, D19, D25, D26, D27, D28, D29, D30 | 11 |
| **Total** | **D01–D30** | **30** |

The R06 Owner decisions remain controlling:
- D01 proposed PARTIAL upgrade rejected; D01 remains UNVERIFIED.
- D15 proposed PARTIAL upgrade rejected; D15 remains MISSING.
- D17 proposed PARTIAL upgrade rejected; D17 remains MISSING.
- D04 and D05 remain UNVERIFIED.
- No disposition changed by R08, R09 or R10.

## 6. Closure-readiness assessment

| Exit criterion | R10 status | Reason |
|---|---|---|
| C01 — frozen 30-domain inventory | PASS | Frozen v1.2.0 and 30 dispositions preserved |
| C02 — domain ownership / authoritative records | PARTIAL | Named role-holders, canonical business record IDs and authority evidence remain incomplete |
| C03 — dependencies / integration contracts | PARTIAL | Boundaries are mapped; accepted producer/consumer versioned contracts remain missing |
| C04 — legal / governance authority | UNRESOLVED | Operative legal instrument, adopted strategy/resolution, competent authority and current appointments not independently established |
| C05 — Finance / Treasury | PARTIAL | D11 remains partial; D12 remains MISSING; primary source evidence is post-deployment deferred |
| C06 — missing domain contracts | NOT MET | Eleven domains remain MISSING and six remain UNVERIFIED under the frozen decision |
| C07 — exact-head API/App mapping and acceptance | PARTIAL | Main tips are exact-SHA identified; no universal domain acceptance suite has been run against those exact heads |
| C08 — contradictions and exceptions | PARTIAL | EX-01 and EX-03 require PR-description hygiene; EX-02 remains an unapproved subordinate-spec conflict; residual legal/authority exceptions remain open |
| C09 — final evidence package / exact-SHA index | PASS WITH LIMITATION | R10 index is revalidated; independent acceptance evidence and unresolved exception closure are not supplied by an index |
| C10 — Owner closure decision | NOT GRANTED | Controlling Owner instruction remains KEEP A2 OPEN — DO NOT CLOSE |

**Closure readiness: NOT READY.** Exact-SHA index integrity is improved, but material domain-contract, legal-authority, integration and acceptance-execution gaps remain. No residual exception is accepted by R10.

## 7. Verification and change-control record

- API `main`: `6e274c25ecc55b84e026e879d735b77f0e51592f` — unchanged.
- App `main`: `ffe0495c0eb7c8b5d2013419123aac5b68893d51` — unchanged.
- PR #219 before R10: `a1d2b12e82a7a806a761e2e3c0e303f3c9722faf`; 17 ahead / 0 behind.
- R10 is a documentation-only successor on the existing PR #219 branch.
- No application source, route, model, migration, schema, seeder, runtime configuration, secret, production data or external financial operation changed.
- Tests/builds/CI: NOT RUN for this documentation-only R10 record.
- A4 acceptance and operating-effectiveness verification: NOT RUN.
- PR merge: NOT AUTHORIZED.
- A3 implementation: NOT AUTHORIZED.
- A5 deployment: NOT AUTHORIZED.

## 8. Controlling decision and next gate

**KEEP A2 OPEN — DO NOT CLOSE** (Owner decision recorded 2026-11-24).

R10 completes the requested exact-SHA evidence-index and exception-register revalidation for the identified open documentation branches and current API/App main snapshots. It does not override the Owner decision, accept residual exceptions, or represent A2 as closure-ready.

The next valid action is limited metadata hygiene on PR #215 and PR #223 to mark their descriptions with the superseding decisions/current heads, followed by a fresh fetch-back of those PR descriptions and PR #219's final head. Any later A2 closure submission requires a separate explicit Owner decision. No A3, A4, A5 or merge authority is inferred.
