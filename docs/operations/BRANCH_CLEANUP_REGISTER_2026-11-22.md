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

- The prior deletion workflow had both manual dispatch and push-triggered deletion paths. The push-trigger path has been removed; legacy evidence-ref deletion is now Owner-only manual dispatch.
- The new `owner-governed-branch-lifecycle.yml` is the controlled path for PR work: exact head/base, non-draft PR, approved review, no unresolved review threads, passing exact-head checks, merge without immediate deletion, exact merge-SHA post-merge test, then exact source-ref deletion and HTTP 404 verification.
- The legacy deletion workflow is restricted to `ops/` evidence refs only and requires an explicit Owner confirmation, exact SHA, reason and this register as evidence.
- No PR was merged, no production deployment occurred, and no branch was deleted during this reconciliation.

## App repository branch scan

App main at inventory time: `0a4f355e421337a02f223dfc3403e678e0647ae7`; 74 refs; 55 open PRs. Seventeen refs were not referenced as an open PR head or base. All 17 were compared with main; none matched an exact source head SHA from a merged PR. Several are substantially divergent, including the A3 unified experience, namespace migration, mounted-shell/A4 work, governance and executive-overview branches. They remain preserved. The alias `fix/a3-1-app-vue-tsc-minimatch-exact-head-verify` shares the exact SHA of open PR #89's source branch and is not yet eligible for retirement because PR #89 is still open.

This register is a point-in-time reconciliation record. Recheck every live ref and PR before any later deletion.

## Control implementation commits

| Repository | Change | Commit |
|---|---|---|
| API | New owner-governed branch lifecycle workflow | `87c38ff5b320ca0e6081e62f90117b57dec5e921` |
| API | Cleanup register | `4ad23998c0ddca78f5334108c7a3242e2ecba5c4` |
| API | Legacy deletion workflow restricted to manual Owner-only `ops/` ref cleanup | `f2fbbbd2425c89a0c0d6bd10582a363b1ae20f9c` |
| App | New owner-governed branch lifecycle workflow | `5f207642ffc6d1a0c8f3f535f0ea3c1f4a9745d4` |
| App | Frontend CI accepts and asserts exact target SHA, including PR head SHA | `ab99070a6408aca701045b51361104ae35c604a3` |
| App | Previous bulk merged-branch cleanup retired | `8f1f044672bbf3900437906e159856fc5b416e34` |

The lifecycle workflow files have been read back from both default branches and their contents match (blob `1a263c0d69d280dd16e72935de42680a8e55b329`). The API clean-install workflow already accepts `target_sha`; the App frontend workflow now accepts `target_sha` and explicitly prints and asserts the checked-out SHA. Runtime dispatch and end-to-end deletion have **not** been run in this reconciliation.


## Dispatch-run reconciliation — 2026-11-22

The three historical dispatch refs were checked against the Actions run list and decoded job logs. Each run was completed successfully by the Owner account, and each log shows the exact recorded target SHA was checked before the delete-ref command succeeded:

| Dispatch ref | Run | Event / result | Exact target verified and deleted | Current disposition |
|---|---:|---|---|---|
| `ops/dispatch-owner-delete-organization-filemanagement-foundation-20261120` | [44724721024](https://github.com/akefadaka-git/arna-api/actions/runs/44724721024) | push / success | `integration/organization-filemanagement-foundation` @ `1c6bc2575e852c6a4716a00f2182f0a620c0934c` | RUN VERIFIED; target absent. Keep dispatch evidence ref because the original approval did not authorize its deletion |
| `ops/dispatch-owner-delete-p24-candidate-intake-a2-20261120` | [44733634149](https://github.com/akefadaka-git/arna-api/actions/runs/44733634149) | push / success | `docs/p2.4-candidate-intake-a2` @ `6d3c3a992d29cd3869d3aec7921ba41c782e7397` | RUN VERIFIED; target absent. Keep dispatch evidence ref because the original approval did not authorize its deletion |
| `ops/dispatch-owner-delete-a3-3-2-exact-sha-20261120` | [44747624335](https://github.com/akefadaka-git/arna-api/actions/runs/44747624335) | push / success | `verify/a3-3-2-exact-sha` @ `959d5a29dab75cf36c57587f78b6fc01985ef473` | RUN VERIFIED; target absent. Keep dispatch evidence ref because the original approval did not authorize its deletion |

For each run, the job and all steps completed successfully: request resolution, exact-head verification and deletion. The logs explicitly show the expected SHA matched the target ref before deletion. These were push-triggered runs on the historical workflow revision; they are not evidence that the new governed lifecycle workflow has been runtime-tested. No dispatch evidence refs were deleted.

## First candidate assessment for the new lifecycle

A fresh open-PR scan was made across both repositories. No PR currently satisfies every gate for `merge_and_retire`.

- API: seven open non-draft PRs were found. None has an approving review; their combined commit status responses contain no passing checks. Several target non-default bases or are not mergeable. The nearest direct-main candidates are PRs #95, #92, #77, #51 and #49, but each lacks approval and passing exact-head check evidence; #92, #77, #51 and #49 are also currently non-mergeable. PR #194 and #195 remain drafts.
- App: seven open non-draft PRs were found. The closest current-main candidate is PR #98 (`integration/arna-app-release-candidate` @ `c272470188c5d80a99058df7975b1c73c59cab67`), but it has no approving review and no reported passing status checks; its recorded base SHA is `0a4f355e421337a02f223dfc3403e678e0647ae7`, not the current App main `ab99070a6408aca701045b51361104ae35c604a3`. PR #65 is non-mergeable and unapproved.
- Both repositories currently report `delete_branch_on_merge=true`. The new lifecycle workflow intentionally requires this setting to be false so the source ref remains available for exact-SHA post-merge testing before retirement. Therefore the workflow's merge-and-retire path is blocked at repository configuration until this setting is safely changed; no setting was changed in this reconciliation.

**Result:** first fully eligible branch = NONE. Do not dispatch the lifecycle workflow against any candidate yet. The next candidate to prepare is App PR #98: reconcile it against the current main SHA, complete analysis/enhancement, obtain an explicit approving review and passing exact-head checks, resolve all dependent-PR references, and resolve the repository auto-delete setting before lifecycle dispatch. API PR #95 is a secondary documentation-only candidate, but likewise needs approval, passing checks, and base/head revalidation.

No PR was merged, no branch was deleted, and no deployment occurred during this assessment.


## App PR #98 preparation and lifecycle workflow correction — 2026-11-22

This section supersedes the earlier point-in-time PR #98 candidate assessment above.

- PR: [#98](https://github.com/akefadaka-git/arna-app/pull/98)
- Source branch: `integration/arna-app-release-candidate`
- Current head at this update: `d761118c2fc1565f53b9f033352e208f60d7975c`
- Current App main baseline: `ab99070a6408aca701045b51361104ae35c604a3`
- Branch ancestry reconciled with current main using merge commit `311ccd74e49ed5477c82820f42f2870c03ba3691`; first parent is current main and second parent preserves previous PR head `c272470188c5d80a99058df7975b1c73c59cab67`.
- Latest compare: 64 commits ahead, 0 behind, 127 changed files.
- No other open PR currently uses the PR #98 source branch as its head or base. PR #88 and #97 are ancestor histories already included in #98; they remain open pending separate disposition. PR #94 has additional Executive Overview A4 work not imported into #98 and remains preserved.
- Notification center security behavior was restored from PR #94's hardened implementation: internal route allowlists, route existence and resource ID validation, tenant-context invalidation, explicit locale selection, pagination and focus handling.
- Stale MASES-prefixed browser assertions were updated to ARNA IDs. Exact-head run [44966774670](https://github.com/akefadaka-git/arna-app/actions/runs/44966774670) passed all steps, including type-check/build, visual/WCAG checks, mounted Combobox/Modal/Drawer browser tests and FileUploader lifecycle/accessibility tests. That run tested head `58a3744442b10d2855f02ac25d4385906ecb068f`.
- The notification route/tenant/locale contract suite and lifecycle workflow contract suite are now explicitly wired into App frontend CI. On the latest head `d761118c2fc1565f53b9f033352e208f60d7975c`, both contract steps passed in run [44967008012](https://github.com/akefadaka-git/arna-app/actions/runs/44967008012); the remaining build and browser steps were still in progress at this register update.
- The lifecycle workflow had malformed GitHub expression openings (`$` followed by a single opening brace) in 33 locations. These were repaired in both repositories; the identical corrected workflow blob is `defb824a6a65cb60b1c05d6869e1338fed9ae2e3`. API correction commit: `8a4fbaf1bd8f809d1ddd7e8c5fca78881ca4b1fd`. App correction is on PR #98 and will reach App main only if that PR is merged. App CI now has a static contract test for the lifecycle workflow.
- App PR #98 has no approving review or requested reviewer and no unresolved review threads. No independent approval is claimed.
- The App A1 GUI v1.1 specification remains explicitly marked Owner-review candidate — approval pending; PR #98 does not approve or freeze it.
- Both repositories still report `delete_branch_on_merge=true`. The lifecycle workflow requires `false`; no repository setting was changed. Dispatch remains blocked until an authorized repository administrator disables automatic head-branch deletion.
- No PR was merged, no branch was deleted, and no deployment occurred during this preparation.

| Repository | Lifecycle workflow expression repair | Corrected blob |
|---|---|---|
| API | `8a4fbaf1bd8f809d1ddd7e8c5fca78881ca4b1fd` | `defb824a6a65cb60b1c05d6869e1338fed9ae2e3` |
| App | Included in PR #98 at head `d761118c2fc1565f53b9f033352e208f60d7975c` | `defb824a6a65cb60b1c05d6869e1338fed9ae2e3` |


## App lifecycle mainline hotfix and final PR #98 head — 2026-11-22

- App main lifecycle workflow was corrected directly as a narrow operational hotfix because the workflow must be valid on the default branch before it can be dispatched. App main hotfix commit: `76110efd51910240c15faf7c0387e8c1942acf85`.
- PR #98 was then reconciled onto that exact App main head with merge commit `8cdd7559bb30239442c4f1dcef76a6e66fcb951a`; first parent is App main `76110efd51910240c15faf7c0387e8c1942acf85`, second parent preserves previous PR head `d761118c2fc1565f53b9f033352e208f60d7975c`.
- Current PR #98 head: `8cdd7559bb30239442c4f1dcef76a6e66fcb951a`; current base SHA: `76110efd51910240c15faf7c0387e8c1942acf85`; comparison: 65 commits ahead, 0 behind, 126 changed files.
- Exact-head run [44967008012](https://github.com/akefadaka-git/arna-app/actions/runs/44967008012) passed all CI steps at the prior head `d761118c2fc1565f53b9f033352e208f60d7975c`, including notification security contracts, lifecycle workflow contract, build, public browser/WCAG, visual rendering, mounted Combobox/Modal/Drawer and FileUploader tests.
- A new exact-head run [44967209367](https://github.com/akefadaka-git/arna-app/actions/runs/44967209367) was running against `8cdd7559bb30239442c4f1dcef76a6e66fcb951a` at the time of this update. Its final result must be checked before readiness attestation.
- PR #98 still has no requested or approved reviewer; review threads remain empty. Independent review is outstanding.
- App repository `delete_branch_on_merge` remains `true`; lifecycle dispatch remains blocked until the Owner or another authorized repository administrator disables the automatic head-branch deletion setting.
- No PR merge, branch deletion or deployment was performed.


## Final App PR #98 verification — 2026-11-22

- PR #98 remains OPEN and non-draft: [https://github.com/akefadaka-git/arna-app/pull/98](https://github.com/akefadaka-git/arna-app/pull/98).
- Exact head: `8cdd7559bb30239442c4f1dcef76a6e66fcb951a`; exact base `main`: `76110efd51910240c15faf7c0387e8c1942acf85`.
- Compare: 65 commits ahead, 0 behind, 126 changed files; GitHub reports `mergeable=true`, `mergeable_state=clean`.
- Exact-head App frontend CI [44967209367](https://github.com/akefadaka-git/arna-app/actions/runs/44967209367): **SUCCESS**. All steps passed, including notification route/tenant/locale security contracts, lifecycle workflow contract, dependency audit, four-application type-check/build, public browser/WCAG, visual rendering, mounted Combobox/Modal/Drawer and FileUploader tests.
- App main lifecycle hotfix CI [44967193057](https://github.com/akefadaka-git/arna-app/actions/runs/44967193057): **SUCCESS**.
- Direct dependent open PR references: none. PR #98 review threads: 0 total, 0 unresolved. Requested reviewers: none. Submitted approving reviews: none.
- The PR is not yet eligible for lifecycle dispatch because an independent approving review is still missing and App `delete_branch_on_merge` remains `true`. No merge or deletion was performed.
- App A1 GUI v1.1 remains Owner-review candidate — approval pending. PR #98 does not approve/freeze the GUI specification.
- No deployment occurred.

**Readiness decision:** source integration and exact-head CI are VERIFIED; independent review and repository auto-delete configuration are BLOCKED. Do not dispatch until both gates are satisfied.
