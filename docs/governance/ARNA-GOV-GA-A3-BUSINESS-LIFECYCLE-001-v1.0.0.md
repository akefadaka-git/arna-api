# ARNA General Assembly — Substantive Business Lifecycle Implementation

**Record:** ARNA-GOV-GA-A3-BUSINESS-LIFECYCLE-001  
**Version:** 1.0.0  
**Stage:** A3 implementation increment; A4 exact-head verification pending  
**Repository:** `akefadaka-git/arna-api`  
**Branch:** `feat/ga-statutory-controls-a3-current-main`  
**Pull request:** [#235](https://github.com/akefadaka-git/arna-api/pull/235)  
**Base at implementation reconciliation:** `75f089a079420fdd9501861cfd8892b3100b2ebb`

## 1. Purpose and control boundary

This increment adds substantive GA workflows on top of the existing fail-closed legal-basis and action-evidence gates. It does not determine Ethiopian statutory rules, authenticate company instruments, or activate unverified policy values. Every legally consequential operation depends on action evidence that has been independently verified by an authorized user other than the evidence submitter.

The implementation is API-side. No app UI, merge, deployment, production migration, or A5 action is included.

## 2. Implemented workflows

| Workflow | Implemented behavior | Required control |
|---|---|---|
| Notice | Versioned Amharic-first notice draft, independent approval, issuance, recipient ledger, delivery receipt/failure record | Verified `notice_rule`; explicit `notice_period_days` |
| Agenda | Ordered bilingual agenda items; agenda is frozen after notice issuance | Meeting-management permission; audit event |
| Electorate | Versioned record-date snapshot from active shareholders and share-holding ledger; projection-to-ledger reconciliation; independent review | Verified voting-rights and share-register evidence |
| Quorum | Computes eligible and present units from verified electorate and recorded attendance; independent review | Verified `quorum_rule` and `meeting_category_rule`; explicit numerator, denominator and basis |
| Motion | Proposed motion linked to agenda, seconding, voting-open/closed lifecycle | Meeting-management permission; seconding by a different actor |
| Voting | Authenticated shareholder or approved proxy casts one weighted ballot per shareholder per motion | Verified per-share class weights; verified quorum; explicit open-ballot mode |
| Resolution | Tallies for/against/abstain, applies verified approval threshold, records resolution number and passed/rejected outcome | Verified `resolution_approval_rule` |
| Minutes | Versioned Amharic-first minutes, immutable record snapshot and SHA-256 digest, submission and independent authorized-signatory approval | Verified minutes approval and signatory-authority evidence |

## 3. Evidence parameters — no statutory defaults

Action evidence now supports a structured `rule_parameters` object. The following parameters are consumed only after the corresponding evidence row is independently verified:

- `notice_rule`: `notice_period_days` (non-negative integer).
- `quorum_rule`: `threshold_numerator`, `threshold_denominator`, and `basis` (`shares`, `voting_power`, or `shareholders`).
- `voting_rights_rule`: `votes_per_share_by_class` keyed by canonical share-class code, and `ballot_mode`.
- `resolution_approval_rule`: `threshold_numerator`, `threshold_denominator`, and `basis` (`votes_cast` or `eligible_voting_power`).
- `minutes_signatory_authority`: `authorized_user_ids` list for independent minutes approval.

The controller has no fallback quorum percentage, notice period, vote weight, or resolution majority. Missing or malformed parameters block the relevant action.

## 4. Share register and vote integrity

The electorate snapshot reads the append-only `share_holding_entries` ledger as of the requested record date. Before snapshot creation, each current `share_holdings.current_quantity` projection must reconcile to the latest ledger balance. A mismatch blocks the snapshot. Only active, non-deleted shareholders and positive ledger balances are included.

Voting power is calculated from each holding's share quantity multiplied by the independently verified weight for that share class. The system does not infer that every share class has one vote per share.

Ballot casting requires:
- a verified electorate snapshot;
- a verified quorum assessment tied to the latest verified snapshot;
- present, late, or validly represented attendance;
- an authenticated shareholder or approved proxy user;
- no prior ballot by the same shareholder for that motion.

The read model returns aggregate ballot tallies only; it does not return individual ballot choices.

## 5. Explicitly unsupported / blocked

- **Secret ballots:** not implemented. Voting opens only when verified evidence explicitly sets `ballot_mode = open`. Secret-ballot rules fail closed until a separate unlinkable/controlled ballot design is implemented and reviewed.
- **External notice delivery:** issuance queues portal-recipient records. This endpoint does not send email or SMS. Delivery success is recorded only with a delivery reference; queued is not reported as sent or delivered.
- **Legal-source authentication:** a source reference and SHA-256 digest are stored, but the application does not itself prove the source's issuing authority, authenticity, current validity, or legal effect.
- **Legal form and operative instruments:** `share_company` is a required verified legal-form value for these gates, but the value must be supported by authenticated registration and corporate instruments and independent Ethiopian counsel review before production activation.
- **Frontend:** API endpoints and persistence are implemented in this API increment; the ARNA app UI is not included here.

## 6. API surface

The new tenant-scoped routes are registered in the Governance domain under `/api/governance`:

- `GET /meetings/{meeting}/business-lifecycle`
- `POST /meetings/{meeting}/notices`
- `PATCH /notices/{notice}/review`
- `POST /meetings/{meeting}/notices/{notice}/issue`
- `PATCH /notice-recipients/{recipient}/delivery`
- `POST /meetings/{meeting}/agenda-items`
- `POST /meetings/{meeting}/electorate-snapshots`
- `PATCH /electorate-snapshots/{snapshot}/review`
- `POST /meetings/{meeting}/electorate-snapshots/{snapshot}/quorum-assessments`
- `PATCH /quorum-assessments/{assessment}/review`
- `POST /meetings/{meeting}/motions`
- `PATCH /motions/{motion}/second`
- `POST /motions/{motion}/open-voting`
- `POST /motions/{motion}/ballots`
- `POST /motions/{motion}/close-voting`
- `POST /meetings/{meeting}/minutes`
- `POST /minutes/{minutes}/submit`
- `PATCH /minutes/{minutes}/review`

Sensitive actions are tenant-scoped, permission-protected, and recorded in `governance_meeting_activity`. Ballot route access requires meeting-view permission, while the controller separately verifies shareholder/proxy identity.

## 7. Persistence

The increment adds:
- `governance_meeting_notices`
- `governance_meeting_notice_recipients`
- `governance_meeting_agenda_items`
- `governance_meeting_electorate_snapshots`
- `governance_meeting_electorate_members`
- `governance_meeting_quorum_assessments`
- `governance_meeting_motions`
- `governance_meeting_ballots`
- `governance_meeting_resolutions`
- `governance_meeting_minutes`

Existing RBAC tables remain authoritative. The shareholder role receives read-only Governance meeting visibility; ballot writes are still constrained by authenticated participant identity and verified attendance/proxy records.

## 8. Verification state

A focused end-to-end feature test covers notice review/issue and delivery receipt, agenda creation, ledger-backed electorate snapshot and review, attendance, quorum assessment and review, motion seconding, weighted ballot, resolution calculation, minutes submission/approval, and aggregate-only read-model output.

At the time of this record, exact-head Governance authorization and Phase 0 bounded-authorization workflows are passing. The disposable-MySQL clean-install/migration workflow is queued or running; no migration PASS is claimed until it completes on the final exact commit.

## 9. Stage-gate disposition

- A1: preserved; no statutory assumptions are added to the frozen baseline.
- A2: legal-source authentication remains open.
- A3: substantive API implementation added; frontend remains outside this increment.
- A4: exact-head CI and disposable-MySQL results pending.
- A5: not authorized; no production migration or deployment occurred.
