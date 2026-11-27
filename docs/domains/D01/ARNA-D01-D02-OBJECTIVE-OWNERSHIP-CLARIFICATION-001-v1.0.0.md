# ARNA D01–D02 Objective Ownership Clarification — A2 Catalogue-Conformance Addendum

- **Document ID:** ARNA-D01-D02-OBJECTIVE-OWNERSHIP-CLARIFICATION-001
- **Version:** 1.0.0
- **Prepared:** 2026-11-25
- **Status:** A2 technical boundary clarification; subordinate D01 A1 specification remains DRAFT / OWNER REVIEW REQUIRED
- **Parent catalogue:** ARNA-ESPPM-A1-SPEC-002 v1.2.0 — OWNER-APPROVED / FROZEN
- **Repository baseline:** `akefadaka-git/arna-api` main `6e274c25ecc55b84e026e879d735b77f0e51592f`
- **Related D01 specification:** `ARNA-D01-A1-SPEC-001 v1.0.0`, blob `c04dc84e21ccc9114d8952ba4d38913e36e58004`, PR #222
- **Related D01 evidence register:** traceability v1.0.8, blob `12dbb73cef6fde2d3cf8889a6422dfaef0af6191`, PR #223
- **Change class:** documentation-only; no schema, interface, implementation or domain-disposition change

## 1. Determination

The D01/D02 ownership ambiguity is **RESOLVED AT THE DOMAIN-BOUNDARY LEVEL** by applying the frozen parent catalogue. This addendum does not create a new business rule or amend the catalogue.

The frozen catalogue expressly states:
- D01 owns enterprise mission, strategic direction, strategic themes, multi-year plans, enterprise priorities and the approved cascade from strategy into business plans.
- D02 owns enterprise objectives, KPI definitions, targets, scorecards, performance periods, review cycles, variance commentary, corrective actions and performance accountability.

Accordingly, the D01 subordinate draft must not establish a competing authoritative enterprise-objective hierarchy or system of record.

## 2. Binding boundary for subordinate-specification conformance

| Concern | Authoritative owner | D01 permitted relationship |
|---|---|---|
| Enterprise mission, strategic direction, themes and strategy versions | D01 | Owns authoritative strategy/planning baseline |
| Multi-year strategic plans, enterprise priorities and planning cycles | D01 | Owns planning context and approved strategic priority baseline |
| Enterprise objective records and hierarchy | D02 | References D02 objective IDs and records strategy alignment; does not own duplicate objective records |
| KPI definitions, formulas, units, target versions and scorecards | D02 | Consumes approved references; does not define or approve KPI content |
| Performance periods, review cycles, variance commentary and corrective actions | D02 | Consumes performance outputs as strategy-review evidence |
| Portfolio/project intake and delivery execution | D07 | Publishes strategic alignment references; does not own execution |
| Certified analytical datasets and cross-domain reporting semantics | D23 | Consumes reports; does not redefine semantic/KPI ownership |
| Approval authority and resolution records | D03 | References an authoritative D03 decision; does not infer or grant approval authority |

## 3. Required correction to D01 conceptual model

The D01 draft's proposed `StrategicObjective` record class and wording that D01 owns “strategic objective definition, hierarchy, alignment and accountable ownership” are not conformant with the frozen parent catalogue.

For the next controlled D01 specification revision:
1. Remove the D01-owned `StrategicObjective` system-of-record concept.
2. Replace it with a conceptual `StrategyObjectiveAlignmentReference` record that contains only:
   - a stable D01 strategy/version reference;
   - the authoritative D02 objective identifier and version reference;
   - the strategy theme/priority context;
   - alignment rationale and traceability;
   - the D02 source status/effective period as consumed evidence;
   - source authority reference and audit provenance.
3. Treat objective lifecycle, objective hierarchy, KPI definition, target approval and performance accountability as D02-owned.
4. Treat missing, inactive, superseded or unauthorized D02 references as unresolved; do not create a local D01 fallback objective.
5. Do not invent an API endpoint, event name, schema, stable business identifier or interface version. Those remain NOT ESTABLISHED until separately specified and accepted by D01 and D02 source/consumer owners.

## 4. Exact evidence and provenance

| Evidence | Exact reference | What it establishes |
|---|---|---|
| Frozen parent catalogue v1.2.0 | API commit `631b7a0eb7f027c17751b7af39bbc826fd70124e`; blob `e9cd1f4a087a3d7f285ccda7786149d64db6a4bc` | D01/D02 authoritative domain boundary |
| D01 subordinate A1 draft v1.0.0 | PR #222 head `cf4486f0c983641bfd3f81ad284b5efe4e57c401`; blob `c04dc84e21ccc9114d8952ba4d38913e36e58004` | Conflicting draft wording and conceptual record |
| D01 traceability v1.0.8 | PR #223 head `06483f578ab75e4a9824bc01e213abb0418c0288`; blob `12dbb73cef6fde2d3cf8889a6422dfaef0af6191` | Previously identified catalogue-conformance conflict and absent accepted D01/D02 interface IDs |
| Consolidated A2 report v1.0.4 | PR #219 head at prior R10 baseline `7766cb5fdcabe4a712431eb9b8af44a56bea2b41`; blob `67a7b5272cee27aeb30461bcc2d43e4cd58cf387` | Owner-controlled domain dispositions and A2 status |

## 5. Status after this clarification

- **Boundary ambiguity:** RESOLVED at the catalogue-conformance level.
- **D01 subordinate A1 specification:** remains DRAFT / OWNER REVIEW REQUIRED; this addendum does not freeze or approve it.
- **D01 domain A2 disposition:** UNVERIFIED — unchanged.
- **D02 domain A2 disposition:** PARTIAL — unchanged.
- **D01/D02 API/event contract IDs and semantic interface version:** NOT ESTABLISHED.
- **Named current D01/D02 human owners and competent approvers:** NOT ESTABLISHED.
- **A3 implementation:** NOT AUTHORIZED.
- **Merge, A4 and A5:** NOT AUTHORIZED.

This clarification resolves the ownership interpretation only. It is not evidence that D01 strategy capability or D02 EPM capability has been implemented, accepted or legally approved.
