# ARNA API — Backend for the ARNA Smart System

**ARNA API is the Laravel-based backend and API layer for the ARNA Smart System.** Laravel is the implementation framework; ARNA is the product and system this repository serves.

ARNA is the master name for the Maale Arna Smart System, including its platform and intelligence capabilities. This repository contains backend/API implementation. It is not the complete ARNA system and should not be described as a generic Laravel starter project.

## Purpose and system boundary

The ARNA API provides the server-side foundation through which ARNA clients and approved system components can access backend capabilities. Its responsibilities include API endpoints, application rules, identity and authorization controls, tenant-aware operations, persistence, and integration boundaries as those capabilities are implemented and verified.

The intended ARNA system spans governance, shareholder and enterprise workflows, finance, identity and tenant controls, reporting, and intelligence capabilities. Listing a domain here describes system direction; it does **not** mean every domain is implemented, enabled, or production-ready in this repository.

The ARNA frontend/application is maintained separately. This repository is the Laravel backend/API component, not the frontend and not the entire ARNA platform.

## Technology

- **Application/framework:** PHP and Laravel
- **API authentication:** Laravel Sanctum is used where configured by the application
- **Persistence:** database migrations and application data access; the selected database and its operational configuration must be verified for each environment
- **Testing:** automated application tests and isolated database acceptance tests

Framework documentation is available in the [Laravel documentation](https://laravel.com/docs). Laravel is a technical dependency of ARNA API, not the identity or purpose of this project.

## Security and governance principles

Backend capabilities must be implemented with explicit authorization, tenant boundaries, least-privilege database access, auditable sensitive actions, and fail-closed behavior where authority or evidence is missing. Passing a test suite is evidence for the tested commit and environment only; it is not by itself proof of production readiness.

Do not infer that an API action is authorized merely because an endpoint or data model exists. High-impact financial or governance actions require separately defined authority, controls, verification, and approval.

## ARNA system-principal evidence storage (I2)

This repository includes candidate storage primitives for versioned system-principal policies, append-only action audits, and tenant/action-scoped idempotency records. These primitives do **not** authorize or execute Loan actions, provision a system principal, activate tenant grants, or enable automated approval, rejection, or disbursement. Automation remains disabled by default.

### Restricted MySQL application-role contract

The application database identity must receive only these table-level privileges for the evidence tables:

| Table | Required privileges |
| --- | --- |
| `system_principal_policy_versions` | `SELECT, INSERT` |
| `system_principal_action_audits` | `SELECT, INSERT` |
| `system_principal_idempotency_records` | `SELECT, INSERT, UPDATE` |

The application identity must have no `DELETE` privilege on these tables and no `UPDATE` privilege on policy-version or action-audit records. Idempotency updates are needed for the controlled `reserved → completed` or `reserved → failed` transition; database triggers enforce transition invariants and reject deletion. MySQL table-level grants cannot restrict `UPDATE` to particular columns, so the trigger remains a required complementary control.

Schema and trigger changes must use a separately controlled migration/administrative identity. The application identity must not be able to create or drop users, grant privileges, alter evidence tables, or modify immutability triggers. Production provisioning must use reviewed grants and an appropriately restricted account host; the wildcard host used by isolated CI setup is not a production-hosting recommendation.

### Verification status

The isolated MySQL concurrency/immutability acceptance test passed on candidate commit `a48b5d792817435deab1c8ee4e1a4e2c97bdd14e` (5 tests, 56 assertions). The associated PR #13 remains open and unmerged, so these candidate changes must not be treated as already integrated into the default branch. Production-role provisioning and actual production grants remain **unverified** until independently inspected. Passing isolated CI does not close the I2 gate or authorize deployment.

See `docs/architecture/ARNA-SYSTEM-PRINCIPAL-PROVENANCE-001.md` and `tests/Feature/SystemPrincipalMySqlConcurrencyAndImmutabilityTest.php` for the implementation boundary and acceptance coverage.

## Development and verification

Run the test commands appropriate to the checked-out branch and environment, and record the commit, database engine/version, test results, and any exceptions. Do not treat tests that were skipped, not run, or executed against a different commit as passing evidence.

## License

Refer to the repository's license file and dependency licenses for the applicable terms.
