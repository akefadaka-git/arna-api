# ARNA API repository audit — A4 verification

Date: 2026-11-04  
Repository: `akefadaka-git/arna-api`  
Audit branch: `audit/arna-repository-consolidation-2026-11-04`  
Pull request: #86  
Verified head SHA: `feaf02b595d71cd908e9eea63e559e6616ea3a0b`  
Base SHA: `48542c59132b50d7405adb827afbb897051deb11`

## Verification evidence

<badge>PASS</badge> GitHub Actions run [41732332975](https://github.com/akefadaka-git/arna-api/actions/runs/41732332975) completed successfully against the exact verified head SHA.

The run completed all 44 steps, including:

- PHP 8.3 syntax lint over every tracked PHP file.
- Composer manifest/lock validation and locked install.
- Composer security audit: no remaining advisories after updating `league/commonmark` to 2.10.7 and `symfony/mime` to 7.4.22.
- Canonical Ethiopian localization regression and demo-seeder guard.
- Loan unit, HTTP, repayment and financial-invariant regressions.
- Person identity and People Profile integration regressions.
- Organization tenant/lifecycle and concurrent-mutation regressions.
- Public content moderation lifecycle and corporate publishing tests.
- Finance domain and settlement administration/authorization regressions.
- Governance attendance, notification tenant isolation and announcement security/lifecycle tests.
- Independent-connection People identity and Governance invariant concurrency regressions.
- Fresh empty-MySQL migration, schema checks, shareholder seed-number tests, second migration pass, full fresh migration/seed verification, and stable migration-record checks.

All steps: PASS. No step was skipped in the verification job.

## Cleanup disposition

- Retired the inactive legacy `MasesCoreServiceProvider` implementation and obsolete localization provider/event registration.
- Removed the confirmed unregistered legacy localization facade/calendar/date-time/middleware cluster after verifying the canonical localization service path.
- Preserved canonical `CoreServiceProvider`, canonical Auth User model, domain providers, active migrations, and domain code.
- Preserved unrelated branches and open PRs. Closed superseded PR #78 only after verifying successor #79 was merged; the historical branch and documents remain preserved.
- Documented the scope discrepancy in Admin PR #85: its description says read-only, while its diff adds POST/PUT/DELETE user/role mutations. That PR remains untouched and unmerged.

## A4 status

<badge color="success">VERIFIED</badge> API A4 complete.

Production deployment: NOT RUN.  
PR merge: pending coordinated A5 closure across both repositories.
