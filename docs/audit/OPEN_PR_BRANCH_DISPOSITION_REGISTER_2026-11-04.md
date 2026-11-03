# arna-api — Open PR and Branch Disposition Register

Audit date: 2026-11-04
Repository: akefadaka-git/arna-api
Current open PRs: 43
Current branches: 56

## Open pull requests

| PR | Title | Head branch | Base | State | Mergeability | Disposition |
|---:|---|---|---|---|---|---|
| #86 | A1–A3: retire inactive legacy localization and provider code | `audit/arna-repository-consolidation-2026-11-04` | `main` | Ready | Mergeable | A1–A3 audit cleanup PR; verify then merge |
| #85 | A2: Admin central identity, RBAC and tenant foundation API | `feat/admin-central-identity-rbac-tenancy-a2` | `main` | Draft | Mergeable | OVERLAP: competing Admin Console contracts; consolidate API/App pair before merge |
| #84 | Admin control plane: central identity, RBAC and tenant foundation | `feature/admin-central-identity-rbac-tenancy` | `main` | Draft | Mergeable | OVERLAP: competing Admin Console contracts; consolidate API/App pair before merge |
| #83 | Complete dividend accounting, tax, payment and cancellation controls | `feat/dividend-engine-a1-a4-completion` | `feat/annual-shareholder-dividend-engine` | Draft | Mergeable | STACKED DEPENDENCY: based on feat/annual-shareholder-dividend-engine; preserve order and do not merge independently |
| #81 | Build annual shareholder dividend distribution engine | `feat/annual-shareholder-dividend-engine` | `main` | Draft | Blocked | BLOCKED: GitHub currently reports not mergeable; inspect conflicts/base drift |
| #78 | docs(governance): attendance policy draft and preliminary legal review | `docs/governance-attendance-penalty-policy` | `main` | Draft | Mergeable | HISTORICAL/SUPERSEDED CANDIDATE: PR body says superseded by approved implementation; verify merged successor then close without deleting branch evidence |
| #77 | [WIP] Build ARNA Board Election API foundation | `feat/board-election-a3` | `main` | Ready | Blocked | BLOCKED: GitHub currently reports not mergeable; inspect conflicts/base drift |
| #76 | feat(ocr): introduce self-hosted ARNA Document Intelligence | `feat/arna-ocr-a3-api` | `main` | Draft | Mergeable | Independent feature/documentation work; preserve and review against its own acceptance gate |
| #75 | A3.4: Calendar reminder delivery and recurrence lifecycle | `feat/arna-calendar-a4-worker` | `feat/arna-calendar-a4-remediation` | Draft | Mergeable | STACKED DEPENDENCY: based on feat/arna-calendar-a4-remediation; preserve order and do not merge independently |
| #74 | [Draft] Expand Communication Hub backend capabilities | `feat/communication-hub-expansion` | `main` | Draft | Blocked | BLOCKED: GitHub currently reports not mergeable; inspect conflicts/base drift |
| #73 | A3.3: Calendar privacy, lifecycle APIs and permission integration | `feat/arna-calendar-a4-remediation` | `feat/arna-calendar-core-a3` | Draft | Mergeable | STACKED DEPENDENCY: based on feat/arna-calendar-core-a3; preserve order and do not merge independently |
| #71 | A3.1–A3.2: Calendar temporal foundation and tenant-scoped Core API | `feat/arna-calendar-core-a3` | `main` | Draft | Blocked | BLOCKED: GitHub currently reports not mergeable; inspect conflicts/base drift |
| #69 | A2: Calendar Core API baseline inventory | `docs/arna-enterprise-calendar-a2` | `main` | Draft | Mergeable | Independent feature/documentation work; preserve and review against its own acceptance gate |
| #68 | A1: ARNA enterprise calendar core architecture | `docs/arna-enterprise-calendar-a1` | `main` | Draft | Mergeable | Independent feature/documentation work; preserve and review against its own acceptance gate |
| #67 | A1: ARNA Core Calendar & Scheduling Architecture | `feat/arna-calendar-core-a1` | `main` | Draft | Mergeable | Independent feature/documentation work; preserve and review against its own acceptance gate |
| #65 | feat(auth): implement browser session SSO endpoints | `fix/unified-cookie-sso-api` | `main` | Draft | Mergeable | Independent feature/documentation work; preserve and review against its own acceptance gate |
| #58 | feat(finance): Finance domain ownership and Phase 2 CoA integrity | `feat/finance-phase2-coa-integrity` | `feat/finance-gl-integrity-phase1` | Ready | Mergeable | STACKED DEPENDENCY: based on feat/finance-gl-integrity-phase1; preserve order and do not merge independently |
| #57 | docs(people): establish P0 domain strategy and ownership register | `docs/people-domain-p0-governance` | `main` | Draft | Mergeable | Independent feature/documentation work; preserve and review against its own acceptance gate |
| #56 | CI: exact-SHA Phase 0 security verification | `fix/phase0-exact-sha-verification` | `main` | Draft | Blocked | BLOCKED: GitHub currently reports not mergeable; inspect conflicts/base drift |
| #55 | fix(finance): repair clean migration and harden approval proposals | `fix/finance-migration-approval-hardening` | `main` | Draft | Blocked | OVERLAP CANDIDATE: Finance migration/proposal hardening; compare exact patches and tests |
| #54 | fix(finance): repair migration collision and harden proposal history | `fix/finance-migration-and-proposal-hardening` | `main` | Draft | Blocked | OVERLAP CANDIDATE: Finance migration/proposal hardening; compare exact patches and tests |
| #53 | feat(documents): transactional numbering and issuance lifecycle | `feat/documents-transactional-numbering-lifecycle` | `integration/organization-filemanagement-reconciled-2026-10-31` | Draft | Mergeable | STACKED DEPENDENCY: based on integration/organization-filemanagement-reconciled-2026-10-31; preserve order and do not merge independently |
| #52 | Governance: add tenant-scoped meeting lifecycle foundation | `feat/governance-meetings-foundation` | `main` | Draft | Blocked | BLOCKED: GitHub currently reports not mergeable; inspect conflicts/base drift |
| #51 | refactor(file-management): domain boundary and production hardening | `refactor/file-management-domain-boundary` | `main` | Ready | Blocked | BLOCKED: GitHub currently reports not mergeable; inspect conflicts/base drift |
| #49 | Organization domain: domain-contained structure and authorization gaps | `fix/organization-domain-production-gaps` | `main` | Ready | Blocked | BLOCKED: GitHub currently reports not mergeable; inspect conflicts/base drift |
| #48 | Add approved-SHA remote Finance Phase 0 regression runner | `ci/finance-phase0-remote-regression-runner` | `main` | Draft | Mergeable | Independent feature/documentation work; preserve and review against its own acceptance gate |
| #46 | Integrate Finance foundation, reporting, and year-end close against current main | `feat/finance-integrated-phase0-3` | `main` | Draft | Blocked | BLOCKED: GitHub currently reports not mergeable; inspect conflicts/base drift |
| #45 | feat(finance): Phase 0 baseline and fail-closed local acceptance | `feat/finance-phase0-baseline-local-test-runner` | `feat/finance-accounting-completion-reporting` | Draft | Mergeable | STACKED DEPENDENCY: based on feat/finance-accounting-completion-reporting; preserve order and do not merge independently |
| #44 | docs(finance): add Owner-approved enhancement phased roadmap | `docs/finance-accounting-enhancement-roadmap` | `feat/finance-accounting-completion` | Draft | Mergeable | STACKED DEPENDENCY: based on feat/finance-accounting-completion; preserve order and do not merge independently |
| #43 | feat(finance): complete financial statements and cash-flow reporting | `feat/finance-accounting-completion-reporting` | `feat/finance-accounting-completion` | Draft | Mergeable | STACKED DEPENDENCY: based on feat/finance-accounting-completion; preserve order and do not merge independently |
| #42 | Phase 3: add read-only year-end close readiness gate | `feat/finance-phase3-year-end-close-readiness` | `feat/finance-accounting-completion` | Draft | Mergeable | STACKED DEPENDENCY: based on feat/finance-accounting-completion; preserve order and do not merge independently |
| #40 | feat(finance): reconcile accounting foundation and canonical CoA | `feat/finance-accounting-reconciled-v1` | `main` | Draft | Blocked | BLOCKED: GitHub currently reports not mergeable; inspect conflicts/base drift |
| #39 | feat(finance): reconcile accounting foundation and canonical CoA | `feat/finance-accounting-completion` | `main` | Draft | Blocked | BLOCKED: GitHub currently reports not mergeable; inspect conflicts/base drift |
| #38 | Finance: cash-flow classification contract and reporting service | `feat/finance-cash-flow-classification` | `feat/finance-canonical-coa-v1` | Draft | Mergeable | STACKED DEPENDENCY: based on feat/finance-canonical-coa-v1; preserve order and do not merge independently |
| #37 | Add fail-closed control-account reconciliation baseline | `feat/finance-control-account-reconciliation-v1` | `feat/finance-canonical-coa-v1` | Draft | Mergeable | STACKED DEPENDENCY: based on feat/finance-canonical-coa-v1; preserve order and do not merge independently |
| #36 | Add tenant-scoped Finance financial statement reporting | `feat/finance-financial-statements` | `feat/finance-accounting-completion` | Draft | Mergeable | STACKED DEPENDENCY: based on feat/finance-accounting-completion; preserve order and do not merge independently |
| #35 | Complete Finance CoA ownership and metadata safeguards | `feat/finance-accounting-completion` | `feat/finance-canonical-coa-v1` | Draft | Mergeable | STACKED DEPENDENCY: based on feat/finance-canonical-coa-v1; preserve order and do not merge independently |
| #34 | Add Finance control-account ownership register v1.0.0 | `feat/finance-control-account-ownership` | `feat/finance-canonical-coa-v1` | Draft | Mergeable | STACKED DEPENDENCY: based on feat/finance-canonical-coa-v1; preserve order and do not merge independently |
| #33 | Finance production readiness: CoA contract tests and release gates | `feat/finance-production-readiness` | `feat/finance-canonical-coa-v1` | Draft | Mergeable | STACKED DEPENDENCY: based on feat/finance-canonical-coa-v1; preserve order and do not merge independently |
| #32 | Establish canonical ARNA Chart of Accounts v1.0.0 | `feat/finance-canonical-coa-v1` | `feat/finance-general-ledger-foundation` | Draft | Blocked | STACKED DEPENDENCY: based on feat/finance-general-ledger-foundation; preserve order and do not merge independently |
| #31 | feat(people): add secure contacts and person relationships | `feat/people-domain-phase-2-api` | `main` | Ready | Blocked | BLOCKED: GitHub currently reports not mergeable; inspect conflicts/base drift |
| #30 | docs(finance): define GL domain contract and schema proposal | `docs/finance-accounting-domain-contract` | `main` | Ready | Mergeable | Independent feature/documentation work; preserve and review against its own acceptance gate |
| #11 | docs: propose P1.2 unified People Profile identity contract | `docs/p1-2-people-profile-design` | `main` | Draft | Mergeable | Independent feature/documentation work; preserve and review against its own acceptance gate |

## Branches without an open PR

These branches are not automatically stale or unused. They may be supporting integration branches, work in progress, or branches with merged/closed PR history. Preserve them until their commit ancestry and references are checked.

- `audit/phase0-effective-authorization`
- `docs/arna-phase-0-7-gate-register`
- `docs/board-election-a1-specification`
- `docs/document-approval-authority-matrix`
- `feat/finance-gl-integrity-phase1`
- `feat/governance-attendance-a3`
- `feat/organization-domain-foundation`
- `feat/p1.3-governance-invariants`
- `fix/finance-route-registration-coverage`
- `fix/p13-b1-effective-primary-acting`
- `fix/shareholder-tenant-lookup-read-audit`
- `integration/organization-filemanagement-foundation`
- `integration/organization-filemanagement-reconciled-2026-10-31`
- `main`

## Cleanup policy

- Do not bulk-close open PRs or delete branches.
- Resolve stacked PRs in dependency order; retain their source branches until merged or explicitly superseded.
- For overlapping implementations, compare complete diffs, tests, permission contracts and database effects before selecting a canonical branch.
- A GitHub `mergeable=false` result is a blocker requiring conflict/base analysis, not proof that the work is obsolete.
- Close a superseded PR only after the successor is verified and all unique changes are preserved.
- Audit branches are not included in the original baseline counts; they are current audit work.
