# ARNA OS — Phase 0–7 Destination Repository Gate Register

**Assessment date:** 2026-10-29  
**Assessment mode:** Read-only reconciliation; no tests run, no workflows manually launched or retried, no PRs opened, no merges, no database operations, and no deployment operations performed as part of the assessment.  
**Decision:** **PRODUCTION NO-GO — current exact-head acceptance evidence is incomplete or failed.**

## 1. Fixed repository baselines

| Repository | Current `main` SHA at assessment | Commit |
|---|---|---|
| API — `akefadaka-git/arna-api` | `2915c10432208f0535ede2b7c3ea5a74d2dc7e99` | https://github.com/akefadaka-git/arna-api/commit/2915c10432208f0535ede2b7c3ea5a74d2dc7e99 |
| App — `akefadaka-git/arna-app` | `7b04286d4a20b3587249eed8b90281699492b4c7` | https://github.com/akefadaka-git/arna-app/commit/7b04286d4a20b3587249eed8b90281699492b4c7 |

A result against an older commit or PR head does not prove that the same behavior is verified on these current `main` commits. API and App are separate repositories and require separate evidence.

## 2. Status vocabulary

- **VERIFIED:** evidence supports the gate on the exact current commit.
- **PARTIAL:** source or historical evidence exists, but current acceptance is incomplete.
- **BLOCKED:** a known failed check, stale base, or unresolved dependency prevents acceptance.
- **UNKNOWN / NOT CLEARED:** the required evidence was not established in this assessment.
- **NOT RUN:** no execution evidence is available; do not infer success.
- **NO-GO:** the production release gate is not accepted.

“Implementation exists” is not equivalent to “production-ready.” Unknown gates remain open and must not be counted as complete.

## 3. Phase 0 — Baseline, tenancy and security floor

| Gate | Implementation evidence and relevant branch/PR | Verification evidence | Status and blocker | Accountable acceptance |
|---|---|---|---|---|
| **P0-01 — Exact-head baseline** | Finance domain restructure merged in [API PR #50](https://github.com/akefadaka-git/arna-api/pull/50). Current API `main` is the SHA in §1. | Current-main [MySQL clean-install run](https://github.com/akefadaka-git/arna-api/actions/runs/40896512555) and [public certificate run](https://github.com/akefadaka-git/arna-api/actions/runs/40896512554) failed. | **BLOCKED.** Failure causes not classified here. | Owner accepts baseline and phase closure; maintainers diagnose from existing evidence. No reruns authorized by this assessment. |
| **P0-02 — Tenant isolation / fail-closed context** | Tenant context, membership and policy infrastructure exist. [API PR #47](https://github.com/akefadaka-git/arna-api/pull/47) merged older cross-tenant Finance regression work. | G-01 failed on PR #49 head: [run 40899619450](https://github.com/akefadaka-git/arna-api/actions/runs/40899619450). | **BLOCKED.** Current-main tenant isolation is not demonstrated as passing. | Owner accepts security closure; API security maintainer supplies route-level and negative-case evidence. |
| **P0-03 — Effective authorization and canonical identity** | Canonical User model is specified as `mases_core/src/Auth/Models/User.php`; auth, membership, policy and provider code exists. Relevant: [API PR #49](https://github.com/akefadaka-git/arna-api/pull/49). | [Authorization audit failure](https://github.com/akefadaka-git/arna-api/actions/runs/40899619398) and [tenant-membership/auth reconciliation failure](https://github.com/akefadaka-git/arna-api/actions/runs/40899619319) on PR #49 head. | **BLOCKED.** Effective middleware, policy and model behavior not verified on current API `main`. | Owner accepts security gate; API maintainer documents route/middleware truth table and denial behavior. |
| **P0-04 — Clean-install migrations and schema compatibility** | Finance migrations now reside under `mases_core/src/Domains/Finance/Database/Migrations` and are loaded by the Finance provider. Historical P1.2 evidence records a clean install on an older baseline. | Current-main [clean-install MySQL run failed](https://github.com/akefadaka-git/arna-api/actions/runs/40896512555); PR #49 [clean-install run failed](https://github.com/akefadaka-git/arna-api/actions/runs/40899619403); historical older-SHA run [40528584920](https://github.com/akefadaka-git/arna-api/actions/runs/40528584920). | **BLOCKED.** Current exact-main migration discovery/schema verification failed; historical success does not close this gate. | Owner authorizes any later DB operation; API maintainer proposes a source-level correction. Production DB changes require separate authorization. |
| **P0-05 — Login abuse controls, MFA and identifier regressions** | Dedicated checks exist for throttling, TOTP, phone verification, employee-ID login, wallet account-number login and certificate verification. | Multiple checks failed on PR #49 head, including [TOTP](https://github.com/akefadaka-git/arna-api/actions/runs/40899619609) and [phone verification](https://github.com/akefadaka-git/arna-api/actions/runs/40899619708). | **BLOCKED.** Multiple security/compatibility checks fail on an open branch; no current-main passing evidence established. | Owner approves security policy; API security maintainer classifies existing failures. |
| **P0-06 — Audit coverage and durability** | Audit services/listeners exist. Targeted Shareholder read-audit work is on unmerged branch [`fix/shareholder-tenant-lookup-read-audit`](https://github.com/akefadaka-git/arna-api/tree/fix/shareholder-tenant-lookup-read-audit). | Shareholder source readback only; tests **NOT RUN**. Current-main full audit acceptance not established. | **PARTIAL.** Audit feature flags and fail-open paths need an explicit critical-vs-ordinary event policy. | Owner accepts audit failure semantics and sensitive-read scope; domain owners accept coverage. |

**Phase 0 disposition:** **NOT CLEARED on current evidence.** This does not erase historical closure records; it records that current baseline failures and security gates need reconciliation before that closure can support release.

## 4. Phase 1 — Core platform foundation

| Gate | Implementation evidence and relevant branch/PR | Verification evidence | Status and blocker | Accountable acceptance |
|---|---|---|---|---|
| **P1-01 — Unified People Profile API foundation** | [API PR #12](https://github.com/akefadaka-git/arna-api/pull/12) merged historically; current API contains People artifacts and P1.2 closeout documentation. App `main` contains People directory/profile/lifecycle UI. | [Run 40528584920](https://github.com/akefadaka-git/arna-api/actions/runs/40528584920) passed on older API SHA `a7d01451d068f80dee24f2a5903873720bb8c564`. | **PARTIAL — historically verified, current revalidation open.** Older evidence does not certify later changes. | Owner accepts bounded P1.2 scope; API/App maintainers accept cross-repository contract. |
| **P1-02 — Route truth table, tenant fail-closed, authorization and rate limits** | Security/tenant contract: `docs/phase-1/P1.1-PLATFORM-SECURITY-TENANT-CONTRACT.md`; relevant implementation PR #49. | Current PR #49 security-related checks failed; API `main` status endpoint reported pending with zero status contexts. | **BLOCKED.** Effective route map and invariants not verified on current exact `main`. | Owner accepts security gate; API maintainer supplies source map and exact-head evidence. |
| **P1-03 — File/document lifecycle, version access and linked-record privacy** | Core FileService and document lifecycle contract exist; People child-record/file-version privacy work was previously scoped. | No current exact-main end-to-end lifecycle verification established in this pass. | **UNKNOWN / NOT CLEARED.** Need route-level inventory of list, view, download, versions, latest/history and Person privacy. | Owner accepts privacy boundary; File/People maintainers accept access contract. |
| **P1-04 — Loan repayment concurrency and idempotency** | Historical review identified row-lock ordering/lost-update risk and an unproven idempotency contract on the reviewed baseline. Current candidate branch state was not conclusively reconciled here. | No current-main acceptance evidence established. | **OPEN / REVALIDATION REQUIRED.** High-impact financial behavior needs source trace of locks, transaction boundaries and duplicate/conflict semantics. | Owner approves behavioral contract; Loan/Finance domain owner accepts integrity requirements. |

## 5. Phase 2 — People, Organization and Governance

| Gate | Implementation evidence and relevant branch/PR | Verification evidence | Status and blocker | Accountable acceptance |
|---|---|---|---|---|
| **P2-01 — People contacts and relationships** | [API PR #31](https://github.com/akefadaka-git/arna-api/pull/31) is open on older base SHA `e853874ee41130d47be07d2ffc097a41ecb69a9b`; [App PR #5](https://github.com/akefadaka-git/arna-app/pull/5) is open. | App frontend verification failed on PR #5 head: [run 40728793536](https://github.com/akefadaka-git/arna-app/actions/runs/40728793536). | **BLOCKED.** Stale API base, failed App check and unaccepted cross-repo contract. | Owner accepts scope/privacy; API/App maintainers reconcile contracts. |
| **P2-02 — Organization, committees, boards, directors and lifecycle** | [API PR #49](https://github.com/akefadaka-git/arna-api/pull/49) is draft on older base SHA `3c1ebf3d6f0e5d07c0b60d15575d6f01ddd63b5a`; Organization domain contract exists in `docs/architecture/organization-domain-contract.md`. App Organization UI branch exists. | Multiple API security, clean-install, authentication and compatibility workflows failed on PR #49 head. App frontend verification failed on Organization UI head: [run 40903035655](https://github.com/akefadaka-git/arna-app/actions/runs/40903035655). | **BLOCKED.** Stale base, failed checks and incomplete committee/director feature-to-code map. | Owner approves organization/governance authority model; API/App maintainers own implementation contracts. |
| **P2-03 — Governance appointments, acting authority and delegation** | Governance bodies, positions, appointments, authority grants, seats and acting assignments exist. [API PR #21](https://github.com/akefadaka-git/arna-api/pull/21) is historical merged work; PR #49 remains relevant. | PR #49 current checks fail; current-main lifecycle/concurrency acceptance not established. | **PARTIAL / NOT CLEARED.** Need to validate overlapping appointment intervals, primary/acting seat constraints, delegation source and migrations. | Owner approves bylaw/resolution authority matrix; Governance maintainer implements approved rules. |

## 6. Phase 3 — Finance and Accounting

| Gate | Implementation evidence and relevant branch/PR | Verification evidence | Status and blocker | Accountable acceptance |
|---|---|---|---|---|
| **P3-01 — Ledger, Chart of Accounts, journal invariants and reversals** | [API PR #50](https://github.com/akefadaka-git/arna-api/pull/50) merged Finance domain restructuring and guarded consolidation/manual FX foundation. Finance tree contains accounts, periods, journal entries/lines, policies, services and migrations. | PR #50 commit states tests/workflows were not run; current-main clean-install and certificate runs failed. | **PARTIAL / BLOCKED.** Current-main journal balance, immutable posting, reversal, idempotency and migration acceptance not established. | Owner approves accounting policy; technical maintainers supply implementation evidence. |
| **P3-02 — Statements, cash flow, control-account reconciliation, consolidation and FX** | Source includes `FinancialReportService`, `TrialBalanceService`, `FinanceConsolidationService`, and `FinanceExchangeRateService`. Related draft PRs #34–#46 include work on older bases. | Current-main Finance acceptance not demonstrated; PR #50 exact-main workflow evidence includes failures. | **PARTIAL / BLOCKED.** Statutory tax adjustments, intercompany eliminations, full cash flow/reconciliation acceptance, opening balances and account defaults remain unresolved or intentionally inactive. | Owner approves policy; qualified accounting/reconciliation owner and control-account owners must be designated. |

## 7. Phase 4 — Operations and enterprise workflows

| Gate | Implementation evidence and relevant branch/PR | Verification evidence | Status and blocker | Accountable acceptance |
|---|---|---|---|---|
| **P4-01 — Documents, approvals, procurement, orders and communications** | Core file service and document lifecycle contract exist; no single current PR in the retrieved inventory demonstrated the entire operations lifecycle. | No current exact-main end-to-end operations acceptance evidence identified. | **UNKNOWN / NOT CLEARED.** Approval authority, procurement-to-finance integration, versioning and operational state transitions need feature-to-code mapping. | Owner approves workflows/authority matrix; domain owners accept behavior. |

## 8. Phase 5 — Application UX and integration

| Gate | Implementation evidence and relevant branch/PR | Verification evidence | Status and blocker | Accountable acceptance |
|---|---|---|---|---|
| **P5-01 — API/App contracts, navigation, permission gating and People UI** | App `main` includes Amharic-first People directory/profile/lifecycle UI; `PermissionGate` exists. [App PR #5](https://github.com/akefadaka-git/arna-app/pull/5) remains open. | App run [40646495503](https://github.com/akefadaka-git/arna-app/actions/runs/40646495503) passed on current App SHA; a later run targeting that SHA failed: [40902505406](https://github.com/akefadaka-git/arna-app/actions/runs/40902505406). PR #5 also has failed frontend evidence. | **PARTIAL / BLOCKED.** Conflicting same-SHA results require explanation; API/App contract acceptance incomplete. | Owner accepts user-facing workflow; API/App maintainers agree contracts and permissions. |

## 9. Phase 6 — Intelligence and AI capabilities

| Gate | Implementation evidence and relevant branch/PR | Verification evidence | Status and blocker | Accountable acceptance |
|---|---|---|---|---|
| **P6-01 — AI provenance, action authorization and human approvals** | API README describes candidate system-principal policy versions, append-only action audits and tenant/action-scoped idempotency storage; automation is disabled by default. | README cites candidate isolated MySQL evidence on SHA `a48b5d792817435deab1c8ee4e1a4e2c97bdd14e`; current-main integration, production grants and provisioning are not verified. | **PARTIAL / NOT CLEARED.** Storage primitives do not authorize actions or prove production permissions. | Owner approves AI authority/human-in-the-loop policy; security owner accepts boundaries; production DB permissions require separate approval. |

## 10. Phase 7 — Assurance, release and operations

| Gate | Implementation evidence and relevant branch/PR | Verification evidence | Status and blocker | Accountable acceptance |
|---|---|---|---|---|
| **P7-01 — Build/test evidence on exact destination SHAs** | API and App automated workflows exist. | API current-main clean-install and certificate runs failed. App has both pass and failure on the same SHA (runs cited in P5-01). Commit-status endpoints reported pending with zero status contexts. | **BLOCKED.** No complete passing required-check set established across both current repositories. | Owner decides acceptance; maintainers provide exact-head evidence. No workflow reruns authorized here. |
| **P7-02 — Monitoring, backup/restore, rollback and incident readiness** | API has `docs/operations/arna-production-readonly-preflight.sql`; App has `docs/LOCAL_WINDOWS_RELEASE_HANDOFF.md`. | No completed production preflight, restore rehearsal, rollback rehearsal, monitoring acceptance or deployment smoke evidence retrieved. | **NOT CLEARED / NOT RUN.** Operational evidence absent from this read-only review. | Owner authorizes release; operations/hosting owner accepts recovery and monitoring controls. |
| **P7-03 — Final production release** | Security contracts and domain foundations exist; open PRs remain across API and App. | Security/clean-install checks failed; People/Organization PRs remain open; Finance current-main verification incomplete; operational evidence absent. | **NO-GO.** Mandatory security, integration, schema, domain and operations gates are not closed. | Owner is final production acceptance authority; no implicit release approval. |

## 11. Cross-repository integration register

| Work item | Disposition | Required next decision |
|---|---|---|
| Organization API [PR #49](https://github.com/akefadaka-git/arna-api/pull/49) | Draft, stale base, multiple failed checks | Diagnose existing failures and reconcile diff against current API `main`. |
| People API [PR #31](https://github.com/akefadaka-git/arna-api/pull/31) | Open, stale base | Reconcile against current API `main` and verify privacy/contract alignment. |
| People App [PR #5](https://github.com/akefadaka-git/arna-app/pull/5) | Open, frontend checks failed | Diagnose existing evidence and reconcile with API contract. |
| Finance [PR #50](https://github.com/akefadaka-git/arna-api/pull/50) | Merged; current-main workflows failed | Classify clean-install and certificate failures before declaring restructure verified. |
| Shareholder branch `fix/shareholder-tenant-lookup-read-audit` | Committed but not PR'd or merged; tests not run | Review the diff and decide separately whether to open a PR. |
| Finance PR family #32–#48 | Multiple overlapping candidates; several use older bases | Reconcile ownership/dependencies before further merges. |
| AI/system-principal candidate | Candidate test evidence documented; current-main and production grants unverified | Confirm current source/PR disposition and preserve disabled-by-default boundary. |

## 12. Accountable acceptance model

| Decision area | Accountable party | Required evidence |
|---|---|---|
| Scope, architecture, phase closure and final release | **Owner** | Explicit acceptance of gate and residual risk. |
| Security and tenant isolation | Owner for risk acceptance; API security maintainer for technical evidence | Current-source route/policy map and exact-head negative-case evidence. |
| Domain feature behavior | API/App domain maintainers | Feature-to-code traceability, contracts and acceptance evidence. |
| Finance policy/control accounts | Owner for policy; designated qualified accounting/reconciliation owners for control acceptance | Approved rules, named control-account owners and reconciliation evidence. |
| Production operations | Owner for release authorization; operations/hosting owner for operational controls | Preflight, backup/restore, rollback, monitoring and smoke-check evidence. |

## 13. Overall disposition and next step

- **Implementation:** substantial source foundations exist; completion percentage cannot be defensibly calculated from the evidence in this register alone.
- **Verification:** mixed, stale, failed and historical results; full-system current-main acceptance is not established.
- **Production readiness:** **NOT CLEARED — NO-GO**.
- **Recommended next step:** source-only diagnosis of existing Phase 0 failures, starting with current-main clean-install MySQL, G-01 tenant isolation and effective authorization. Use existing evidence; do not rerun workflows. Then reconcile stale-base PRs against the fixed API/App SHAs in §1.

## 14. Assessment limitations

This register consolidates destination-repository metadata, selected workflow records, committed documentation and known PRs. It is **not** yet an exhaustive file-by-file inventory of every feature in both repositories. Uninspected areas remain **UNKNOWN / NOT CLEARED** rather than being treated as complete.

No tests were run, no workflows were manually launched or retried, no PR was opened, no merge was performed, and no database or deployment operation was performed as part of the assessment.
