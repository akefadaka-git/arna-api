# Branch cleanup reconciliation register — 2026-11-22

Repository: `akefadaka-git/arna-api`  
Inventory baseline: API `main` = `bbe7c71fca6e66cdf026d4f545b2838b4378a33c`; 155 branch refs.  
Scope: reconcile the 16 remaining `ops/` refs against the existing Owner-approved deletion notes. This register does not expand any earlier deletion approval.

## Disposition summary

- 3 one-time dispatch refs: their target refs are absent. Keep the dispatch refs until successful workflow-run evidence is independently confirmed; then retire the spent dispatch refs separately.
- 10 marker/evidence refs: preserve the approval record and obey each marker's narrow scope. Earlier approval to delete a named target does not authorize deleting its evidence branch.
- 3 refs contain substantive implementation or verification material and are not disposable cleanup markers.
- The 16 target refs named in the records below are currently absent from the live branch inventory. No branch was deleted as part of this reconciliation.

## One-time dispatch refs

| Dispatch ref | Exact dispatch head | Recorded target | Recorded target SHA | Current target status | Disposition |
|---|---|---|---|---|---|
| `ops/dispatch-owner-delete-organization-filemanagement-foundation-20261120` | `f19e7ea34a5c3ceb836b24babca6be525b359912` | `integration/organization-filemanagement-foundation` | `1c6bc2575e852c6a4716a00f2182f0a620c0934c` | ABSENT | Retain until the corresponding Actions run is verified; then retire only this spent dispatch ref |
| `ops/dispatch-owner-delete-p24-candidate-intake-a2-20261120` | `5ed3f0f867909391737babd6c2c552074e3c8086` | `docs/p2.4-candidate-intake-a2` | `6d3c3a992d29cd3869d3aec7921ba41c782e7397` | ABSENT | Retain until the Actions run is verified; marker records a successor reference `docs/p2.4-candidate-intake-a4` at `641c04722dbf67d69fa8264466f20a9524f60297`; successor ref still requires live verification |
| `ops/dispatch-owner-delete-a3-3-2-exact-sha-20261120` | `306712f04d08be2d80cce5abb892ee790317ee48` | `verify/a3-3-2-exact-sha` | `959d5a29dab75cf36c57587f78b6fc01985ef473` | ABSENT | Retain until the Actions run is verified; marker records retry successor `verify/a3-3-2-exact-sha-retry` at `a75013e09eb21ca9af42c203f0a433dab328e6ff`; successor ref still requires live verification |

## Owner-approved evidence and substantive refs

| Ref | Head SHA | Recorded target / subject | Approval and retention reconciliation | Disposition |
|---|---|---|---|---|
| `ops/owner-approved-delete-a3-3-2-exact-sha-20261120` | `959d5a29dab75cf36c57587f78b6fc01985ef473` | Exact-SHA Documents verification | Contains a workflow change enabling isolated retry branches; main does not contain this workflow file under the same path. It is substantive verification material, not just a deletion note. | PRESERVE pending workflow/retry integration review |
| `ops/owner-approved-delete-a5-ci-bootstrap-credential-output` | `5f191a1c2eac0690af5bc2aa96395860a4a731b2` | `a5/fix-ci-bootstrap-credential-output` @ `b75a5619029e8287a735d89b465fed15e39507f7` | Original approval explicitly limited scope to the target ref only. | PRESERVE as evidence; target is ABSENT |
| `ops/owner-approved-delete-age-implementation-contracts` | `9316b8fa4648ef3f8660f1766f54de0192957145` | `docs/age-a3-implementation-contracts` @ `8bf86726467276e3fdf84eecb49b1dfb35ef5f20` | Record protects `docs/age-a3-anti-rollback-design` for open PR #143. Do not delete that protected branch or alter PR #143. | PRESERVE as evidence; target is ABSENT |
| `ops/owner-approved-delete-audit-phase0-effective-authorization` | `cb3c983c6ed51237b2447be0f15b7fc6765764f3` | `audit/phase0-effective-authorization` @ `be5f1a2abb70af83e0a0cb7c76cf1611cadcfb22` | Approval authorizes only the named target ref; no other branch or PR action. | PRESERVE as evidence; target is ABSENT |
| `ops/owner-approved-delete-calendar-reminders-domain-auth` | `3f5265d8feafda10490182cab668f68aada0bcea` | `feat/a3-calendar-recurring-reminders-domain-auth` @ `11fe69e8c6c4faf354da899027a24a7c9d1a057d` | Records a preserved alias `feat/a3-calendar-current-main-integration` at the same SHA and open PRs #109/#110. Those PRs and alias remain protected. | PRESERVE as evidence; target is ABSENT |
| `ops/owner-approved-delete-docs-a2-20261105` | `a280e1fe6271ef242352e61e18eb51944c2a0bcc` | `integration/documents-file-management-a2-2026-11-05` @ `9f65465e9e78107ca0530cc758b268ea4a27d5ed` | Approval records the exact target and main baseline; it does not authorize deletion of this evidence ref. | PRESERVE as evidence; target is ABSENT |
| `ops/owner-approved-delete-election-dispute` | `d92d568b4b19bd13a5ea68a52d3dfe60bea4cbd1` | `feat/a3-election-dispute-foundation` | Marker has no expected target SHA, so an exact-head deletion cannot be reconstructed from this record alone. | UNRESOLVED; preserve |
| `ops/owner-approved-delete-feat-arna-finance-a3-integrated` | `759439d986fc52f150b2fbfa86840bcbb583c5ba` | `feat/arna-finance-a3-integrated` @ `8ad7ab63d97334725a3833978aac59ea2b0a3452` | Explicitly preserves PR #121, other Finance A3 branches, all PRs and this operational trigger branch. | PRESERVE; target is ABSENT |
| `ops/owner-approved-delete-finance-journal-outbox-20261120` | `736ba498fd5b80fd24e553df472c01297e8a544e` | `feat/arna-finance-a3-journal-outbox` @ `69f19b335898672c44176541e001bf981c7752e0` | Explicit instruction: retain this trigger branch as operational evidence. PR #115 is closed/unmerged; PR #121 remains open and protected. | RETAIN explicitly; target is ABSENT |
| `ops/owner-approved-delete-organization-domain` | `3659274fb2c3d7d215f1714b5de94556963afb2b` | `feat/organization-domain-foundation` @ `ef220efdfdf48c44e7552df0636c549a45fe8548` | Records the earlier main baseline and exact target; does not expand scope to this evidence ref. | PRESERVE as evidence; target is ABSENT |
| `ops/owner-approved-delete-organization-filemanagement-foundation-20261120` | `1c6bc2575e852c6a4716a00f2182f0a620c0934c` | Organization/File Management integration work | Compare against current main shows 199 commits ahead, 524 behind and 49 changed paths. This branch contains substantive domain implementation and integration work, not a disposable marker. | PRESERVE; requires separate file-by-file reconciliation |
| `ops/owner-approved-delete-p13-governance` | `22d404bd35db5c12837de2d7a548d582e39e92a7` | `feat/p1.3-governance-invariants` @ `8f64cdeb1c8a83baa55702cc0f2a2e67d7b87426` | Explicit Owner approval dated 2026-11-20 for the named target only. | PRESERVE as evidence; target is ABSENT |
| `ops/owner-approved-delete-p24-candidate-intake-a2-20261120` | `6d3c3a992d29cd3869d3aec7921ba41c782e7397` | P2.4 A2 candidate-intake reconciliation and related implementation | Compare against current main shows 15 commits ahead, 252 behind and 19 changed paths. The A2 record says legal qualification remains unresolved until authoritative rules are supplied. This branch carries substantive A2 and related Governance artifacts. | PRESERVE; do not delete or claim A2 successor integration without reconciliation |

## Workflow and control changes

- The prior deletion workflow had both manual dispatch and push-triggered deletion paths. The push-trigger path is being retired in favor of Owner-only manual dispatch.
- The new `owner-governed-branch-lifecycle.yml` is the controlled path for PR work: exact head/base, non-draft PR, approved review, no unresolved review threads, passing exact-head checks, merge without immediate deletion, exact merge-SHA post-merge test, then exact source-ref deletion and HTTP 404 verification.
- The legacy deletion workflow is restricted to `ops/` evidence refs only and requires an explicit Owner confirmation, exact SHA, reason and this register as evidence.
- No PR was merged, no production deployment occurred, and no branch was deleted during this reconciliation.

## App repository branch scan

App main at inventory time: `0a4f355e421337a02f223dfc3403e678e0647ae7`; 74 refs; 55 open PRs. Seventeen refs were not referenced as an open PR head or base. All 17 were compared with main; none matched an exact source head SHA from a merged PR. Several are substantially divergent, including the A3 unified experience, namespace migration, mounted-shell/A4 work, governance and executive-overview branches. They remain preserved. The alias `fix/a3-1-app-vue-tsc-minimatch-exact-head-verify` shares the exact SHA of open PR #89's source branch and is not yet eligible for retirement because PR #89 is still open.

This register is a point-in-time reconciliation record. Recheck every live ref and PR before any later deletion.
