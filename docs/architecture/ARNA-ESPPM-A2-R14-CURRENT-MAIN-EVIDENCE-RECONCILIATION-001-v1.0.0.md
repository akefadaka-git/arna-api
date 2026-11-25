# ARNA ESPPM A2 R14 — Current-Main Evidence Reconciliation Record

**Record ID:** ARNA-ESPPM-A2-R14-EVIDENCE-RECONCILIATION-001  
**Version:** 1.0.0  
**Review date:** 2026-11-25  
**Classification:** A2 read-only evidence reconciliation; documentation-only record  
**Repository:** `akefadaka-git/arna-api`  
**Evidence target:** API `main` = `796109e1c2e8cc5f84548e42b626803dc297bf35`  
**Historical A2 merge base:** `6e274c25ecc55b84e026e879d735b77f0e51592f`  
**Existing A2 evidence branch before this record:** `docs/esppm-a2-closure-report-001` at `314b01420e203a536c5a5a4d054a28029f831fac`

## 1. Purpose and boundaries

This record captures a bounded, read-only revalidation of selected API implementation evidence after the API `main` branch advanced beyond the baseline used by earlier A2 records. It supplements, and does not overwrite, R11, R12 or R13.

The review inspected current-main source and test files through GitHub repository file retrieval. It did not execute tests, builds, migrations, seeders, application code, or production operations. Test-file presence and assertions are evidence of intended/covered behavior, not proof that tests pass at this SHA or that controls operate effectively in production.

No application source, routes, models, migrations, schema, seeders, runtime configuration, secrets, production data, or `main` branch content was changed by this record. The only intended repository write is this Markdown record on the pre-existing A2 evidence branch.

## 2. Baseline divergence context

The previously recorded API main baseline was `6e274c25ecc55b84e026e879d735b77f0e51592f`. The current API main target for this review is `796109e1c2e8cc5f84548e42b626803dc297bf35`.

The prior comparison found:
- 288 commits on current API main beyond the historical merge base;
- 273 changed file paths in that comparison;
- the existing PR #219 evidence branch was 23 commits ahead of the old merge base and current main was 288 commits ahead of it;
- PR #219's documentation paths did not overlap the then-observed current-main changed paths, but its evidence claims were tied to the old implementation baseline.

Accordingly, prior A2 findings are not assumed to describe current main without revalidation. PR #219 was not rebased, retargeted, edited, merged or closed as part of R14.

## 3. Current-main source evidence inspected

The following paths were fetched from API `main` at the evidence target above. The listed Git blob SHAs identify the exact file versions inspected.

| Area | Path | Blob SHA |
|---|---|---|
| Governance | `mases_core/src/Domains/Governance/Services/GovernanceAuthorityGrantService.php` | `cc560b578da4f343e3ffbcb8606c7bddb8c2c498` |
| Governance | `mases_core/src/Domains/Governance/Tests/Feature/GovernanceAuthorityGrantTest.php` | `4064c8bc0e83fa503ca5c7dcbc836a7f2ee9de6b` |
| Governance | `mases_core/src/Domains/Governance/Tests/Feature/GovernanceElectionLifecycleTest.php` | `87acb28e08c642ade0fef49f534e81b74f4bfe02` |
| Shareholder | `mases_core/src/Domains/Shareholder/Services/ShareholderService.php` | `a984a0ec0b158124b72868e34e8e63828bf65670` |
| Shareholder | `mases_core/src/Domains/Shareholder/Services/ShareholderNumberReconciler.php` | `2458044d5d42852315130b0b4029b39f1bfbcd86` |
| Loan | `mases_core/src/Domains/Loan/Services/LoanRepaymentService.php` | `b69574a37409ed90d06fd701e3220153310e2a80` |
| Loan | `mases_core/src/Domains/Loan/Services/LoanReconciliationService.php` | `fcfada305356e66829875429f9f0626f405f65c9` |
| Loan | `mases_core/src/Domains/Loan/Services/LoanStateMachine.php` | `3687290139de5f45a5ac3b028cde54b58d81eab2` |
| Loan | `mases_core/src/Domains/Loan/Tests/Feature/LoanRepaymentTest.php` | `e1d6e2cd551b99472d8e91eb37a36b98d6173ad9` |
| Loan | `mases_core/src/Domains/Loan/Tests/Unit/LoanStateMachineTest.php` | `f9c5279e7c1c293b24bd925482a947f1b7064025` |
| Wallet | `mases_core/src/Domains/Wallet/Services/PaymentService.php` | `0d8ee0be492a8e3930f38f16489d11007aab53c3` |
| Wallet | `mases_core/src/Domains/Wallet/Services/AllocationService.php` | `16233046d858b9f3baeaac8e13512b0bc233c8ce` |
| Wallet | `mases_core/src/Domains/Wallet/Tests/Feature/PaymentTest.php` | `5db620d9f84b4b1a4f80efce79746ffac2381798` |
| Authentication | `mases_core/src/Auth/Services/DeviceSessionService.php` | `080262dc68adb2b0522c2fe698f803f10646b38d` |
| Authentication | `mases_core/src/Auth/Services/AdaptiveStepUpService.php` | `5c658a2a83a300af7090ff8db35430546a5fbaa8` |

Additional path discovery confirmed Loan and Wallet feature/unit test suites and Governance appointment/election test paths exist in the current tree. The R14 content review was selective, not a complete file-by-file audit of every changed path.

## 4. Findings by area

### 4.1 D03 — Governance

**Observed:** The authority-grant service implements action-scoped grants, tenant-context checks, effective date boundaries, revocation and transactional record operations. Its feature tests cover position/action scope, tenant access, acting assignments, revocation and document-approval authority checks. Election lifecycle tests cover versioned transitions, published authority documents, rule-set evidence digests, tenant ownership, unavailable evidence and fail-closed behavior.

**Limitations:** This evidence does not establish the legal validity or currentness of the underlying governing instrument, current Board/President/Secretary appointments, CEO/Managing Director appointment, actual delegation or company signing authority. Nor does it establish that every governance process is complete.

**Disposition:** D03 remains **PARTIAL**. Evidence strengthened; no status upgrade.

### 4.2 D09 — People

**Observed:** Governance authority and membership tests reference canonical People-domain Person records and explicit PersonTenantAccess relationships. This confirms a People-domain integration dependency in the inspected Governance slice.

**Limitations:** R14 did not complete a full People-domain source, lifecycle, access-control and acceptance review. Cross-reference evidence alone is insufficient to establish full D09 implementation.

**Disposition:** D09 remains **PARTIAL**. No status upgrade.

### 4.3 D10 — Shareholder & Ownership

**Observed:** ShareholderService exposes registration, update, deactivation/reactivation and representative assignment/clearing operations, using transactional and tenant-scoped service paths. ShareholderNumberReconciler performs an idempotent numbering reconciliation and explicitly fails when unmapped shareholder IDs above the reserved initial range are encountered. The current-main seeder was also inspected in the preceding divergence review: individual and legal-entity matching uses distinct identity keys, with tenant scoping.

**Limitations:** These findings do not establish complete beneficial-ownership governance, all ownership transfer/approval processes, current legal shareholder register correctness, or full integration with Finance and governance processes.

**Disposition:** D10 remains **PARTIAL**. Evidence strengthened; no status upgrade.

### 4.4 D13 — Loan & Credit

**Observed:** LoanRepaymentService contains transactional repayment recording, row-locking, idempotency-key handling and canonical request hashing. LoanRepaymentTest includes replay, payload mismatch, cross-tenant and settlement assertions. LoanStateMachine is documented and structured as the sole writer of loan status and append-only status history; its tests cover legal/illegal transitions, reason requirements and after-commit event behavior. LoanReconciliationService provides a reconciliation surface between loan projections and wallet-linked obligations.

**Limitations:** Test execution was not performed. The source review does not establish a complete formally approved credit policy, all credit-risk governance, production operating effectiveness, or a complete Finance accounting adapter and end-to-end source-to-ledger reconciliation.

**Disposition:** D13 remains **PARTIAL**. Evidence strengthened; no status upgrade.

### 4.5 D14 — Wallet & Digital Financial Services

**Observed:** PaymentService records payment facts and the matching ledger entry transactionally. AllocationService uses transactions and row locks for allocation and reversal operations and enforces amount, currency and effective-reversal constraints. Payment feature tests cover authorization, validation, tenant isolation and ledger creation.

**Limitations:** This does not establish external payment-rail integration, custody, settlement, Treasury controls, independent external reconciliation or production financial operating effectiveness.

**Disposition:** D14 remains **PARTIAL**. Evidence strengthened; no status upgrade.

### 4.6 D24 — Information Technology & Digital Systems

**Observed:** DeviceSessionService provides device-session establishment, refresh, revocation, logout and token-validation paths. AdaptiveStepUpService provides OTP/step-up challenge and verification flows, grant consumption and audit integration.

**Limitations:** The selected authentication/security files do not establish enterprise IT service management, CMDB, infrastructure inventory, change/release management, service catalogue, licence/vendor management, monitoring, backup or disaster-recovery capability. R14 did not fully revalidate the shared authentication, tenancy and RBAC architecture as a whole.

**Disposition:** D24 remains **UNVERIFIED**. Evidence strengthened for selected platform-security foundations, but insufficient for domain-level classification upgrade.

## 5. R14 disposition register

| ID | Finding | Result |
|---|---|---|
| R14-01 | Current API main differs materially from historical A2 implementation baseline | RECONCILED |
| R14-02 | Governance authority/election control source evidence | STRENGTHENED; D03 PARTIAL retained |
| R14-03 | People-domain dependency evidence | LIMITED; D09 PARTIAL retained |
| R14-04 | Shareholder lifecycle/numbering source evidence | STRENGTHENED; D10 PARTIAL retained |
| R14-05 | Loan repayment/state-machine/reconciliation source evidence | STRENGTHENED; D13 PARTIAL retained |
| R14-06 | Wallet payment/allocation source evidence | STRENGTHENED; D14 PARTIAL retained |
| R14-07 | Device-session/step-up source evidence | STRENGTHENED; D24 UNVERIFIED retained |
| R14-08 | Test execution/build/CI on the R14 target | NOT RUN |
| R14-09 | Production operation and control effectiveness | NOT VERIFIED |
| R14-10 | Formal corporate appointments/delegations/signing authority | OPEN — NOT ESTABLISHED |
| R14-11 | Accepted enterprise cross-domain contracts | OPEN — NOT ESTABLISHED |
| R14-12 | PR #219 alignment to current main | NOT PERFORMED |

## 6. Domain status impact

No domain status changes are authorized or proposed by this evidence record.

- D03: PARTIAL — RETAIN
- D09: PARTIAL — RETAIN
- D10: PARTIAL — RETAIN
- D13: PARTIAL — RETAIN
- D14: PARTIAL — RETAIN
- D24: UNVERIFIED — RETAIN

All other domain dispositions remain as recorded in the frozen A2 register. This R14 record does not close any domain requirement or exception.

## 7. Gate assessment

| Gate | R14 result |
|---|---|
| Exact current-main target identified | PASS |
| Selected source files tied to exact blob SHAs | PASS |
| Selected domain evidence re-reviewed | PASS WITH SCOPE LIMITATION |
| Full shared auth/tenancy/RBAC revalidation | PARTIAL |
| Tests/builds/CI executed | NOT RUN |
| Legal authority verified | UNRESOLVED |
| Accepted cross-domain contracts complete | NOT MET |
| A2 closure readiness | NOT READY |

**Overall R14 result:** RECONCILED — EVIDENCE STRENGTHENED; NO DOMAIN STATUS UPGRADES.

## 8. Controlled next actions

1. Preserve R11, R12, R13 and this R14 record as separate historical evidence records.
2. Before any PR #219 branch alignment, perform a separate exact-base/head and documentation-claim review against current API main. Do not rebase, retarget, merge or close PR #219 without separate Owner authorization.
3. Continue bounded A2 reconciliation for remaining contracts and authority exceptions. Keep legal/corporate evidence requirements open until authoritative evidence is available.
4. Keep primary corporate/financial source-record verification deferred until ARNA is deployed and running. Do not request those records before deployment.
5. No A3 implementation, A4 verification campaign, merge or A5 deployment is authorized by this record.

## 9. Approval and change control

This is an evidence record, not a corporate policy, legal opinion, appointment instrument, delegation, financial authorization, or A2 closure decision. It does not amend the frozen 30-domain catalogue or any prior Owner decision.

**A2 remains OPEN — NOT VERIFIED-CLOSED.**  
**A3 NOT AUTHORIZED. A4 NOT STARTED. A5 NOT AUTHORIZED. PR #219 MERGE NOT AUTHORIZED.**
