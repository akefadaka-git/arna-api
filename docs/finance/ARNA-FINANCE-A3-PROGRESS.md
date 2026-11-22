# ARNA Finance A3 — Implementation Progress

**Reporting date:** 12 November 2026  
**API PR:** #121 (draft)  
**App PR:** #58 (draft)  
**Verified main base:** 779ef612589c32d81c68faad091032043ba78652  
**API implementation branch:** feat/arna-finance-a3-current-exact  
**Latest API branch head at record creation:** bd8e0124e93f147d1157234bd0bc25901d7ff157  
**App implementation head:** 9beac0ca20c5331c17050b3ae6781effe58f9ff0

## Status by package

| Package | Current state | Evidence / remaining |
|---|---|---|
| A3.1 Current-main lineage | PARTIAL | Dedicated lineage from verified main and draft PR #121; complete source/test inventory and dependency map remain open. |
| A3.2 Ledger/control hardening | IMPLEMENTED, VERIFICATION OPEN | MySQL journal-line amount-sign trigger and regression test integrated; current exact-head MySQL run not completed. |
| A3.3 Ethiopia-native accounting | IMPLEMENTED FOUNDATION, VERIFICATION OPEN | Core calendar adapter reused; E.C. request normalization and E.C./G.C. evidence output added; entity fiscal-calendar policy remains incomplete. |
| A3.4 Multi-currency/consolidation | PARTIAL | Decimal-safe currency conversion foundation; foreign-currency posting, realized/unrealized FX, valuation and adjusted consolidation remain disabled/unimplemented. |
| A3.5 Operational subledger adapters | PARTIAL | Journal outbox, leases/retries/acknowledgement, durable inbox, envelope hash, idempotent replay and quarantine. No source-specific posting adapters or source control-total reconciliation are active. |
| A3.6 ARNA AI Finance | PARTIAL | Tenant-scoped read-only trial-balance evidence, SHA-256 digest, proposal lifecycle, maker-checker, audit entries and MySQL immutability triggers. Provider is deliberately disabled until approval/configuration; adversarial review remains open. |
| A3.7 Finance App | PARTIAL | Amharic-first E.C.-default workspace for entities, accounts, journals, periods, trial balance, quarantine inbox and proposal review. Journal create/post actions, statements, reconciliation and accessibility review remain open. |
| A3.8 Enterprise/hybrid controls | PARTIAL | Tenant-scoped integration-health endpoint and hybrid/backup/restore control baseline. Cloud/self-hosted tests, backup restore, key rotation, data residency and independent reviews remain NOT RUN. |

## Verification evidence

- App PR #58 frontend workflows passed on exact app head 9beac0ca20c5331c17050b3ae6781effe58f9ff0:
  - 43217993452 — PASS
  - 43217985185 — PASS
  - 43217908354 — PASS
  - 43217901034 — PASS
- The most recent API exact-head attempt at b5db7743afa9b58b799d8e08a855cdc8892c3b14 found a PHP parse error in FinanceIntelligenceController's E.C. validation rule. That defect was corrected at 478668f8323a3cd3ba0009c1f71b191241294bb7.
- API clean-install verification and Phase 0 authorization audit have not passed on the corrected current head. The exact current API head requires a fresh workflow run; earlier SHA results are not reusable.
- Other historical API gates passed on older heads, but they do not close the current head.

## Governance and production readiness

- API main remains at 779ef612589c32d81c68faad091032043ba78652.
- API PR #121 and App PR #58 are draft and unmerged.
- Production migration execution: NOT AUTHORIZED.
- Merge: NOT AUTHORIZED.
- Deployment: NOT AUTHORIZED.
- A4 independent verification: NOT STARTED.
- A5 deployment: NOT AUTHORIZED.
- Production readiness: NOT READY.

A3 is not complete. The next acceptance gate is fresh exact-head API verification, followed by closure of the A3.4–A3.8 implementation gaps above.
