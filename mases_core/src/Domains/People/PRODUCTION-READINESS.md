# People Domain — Production Readiness and Verification Register

**Repository:** `akefadaka-git/arna-api`  
**Branch:** `feat/people-domain-phase-2-api`  
**Verification policy:** local PowerShell runner; no GitHub Actions reruns until the workflow is reset by explicit authorization.

## Implementation in this increment

- Controller integration for contact-point, relationship and participation lifecycle services and dedicated JSON resources.
- Profile create/update requests and dedicated account/domain/document link request classes integrated into the main People controller.
- Transactional lifecycle services with tenant/person-scoped reads and writes, bounded active-record counts, duplicate checks, and reasoned deactivation.
- Focused feature coverage for route registration, declared middleware, migration path discovery, duplicate contact rejection, tenant isolation, encryption/masking and deactivation. Existing API integration tests also exercise the contact, relationship and participation endpoints.
- Local PowerShell runner at `scripts/people/Invoke-ArnaPeopleLocalTests.ps1`.
- Destination-aware runner synchronization helper at `scripts/people/Sync-ArnaPeopleLocalRunner.ps1`; it reads the target branch directly from `akefadaka-git/arna-api` and does not depend on the local `origin` remote.
- Domain unlink operations now require the record's current `person_id` to match the Person named in the route; mismatch is rejected inside the transaction after the domain record is locked.
- Tenant-access reactivation preserves the original `created_by` and `created_at` grant provenance; only `status` and `updated_at` change on reactivation. New grants insert a provenance row, with the composite unique key retained as the final duplicate guard.
- Tenant-access revocation locks linked domain records in a fixed order before locking the Person, matching the domain-link lock direction and avoiding the previous Person-first/domain-row-second lock inversion. The revocation write is restricted to an active grant.
- Contact-point, participation, and relationship create/deactivate operations now re-check active Person status and tenant grants inside their write transactions after acquiring Person locks, closing the stale-authorization window between controller visibility checks and database writes. Relationship creation revalidates both endpoint grants under stable Person-row lock ordering.
- Phone contact duplicate detection now normalizes away formatting punctuation before deriving the keyed duplicate hash, so formatting-only variants cannot create separate active contact rows.
- Regression coverage now asserts that revocation is rejected while an employee record remains linked and that the active tenant grant is preserved.

## Run locally

From the existing API checkout in PowerShell:

```powershell
.\scripts\people\Invoke-ArnaPeopleLocalTests.ps1 -RepositoryPath 'D:\MASES\github-current\arna-api'
```

The runner checks branch identity and PHP >= 8.3, preserves unrelated working-tree changes, verifies the effective Composer PSR-4 mapping points to the current checkout, lints People PHP files, and runs the People feature suite once. It intentionally does not run global `artisan route:list`, because unrelated controller registration can fail before People route acceptance is evaluated. It does not invoke GitHub Actions, reset the checkout, install packages, commit, push, merge or deploy.

For a clean-install MySQL migration gate, first provision an **empty disposable schema** and configure it in `.env.testing` with `DB_CONNECTION=mysql`, a dedicated database name beginning with `arna` and ending in a test/testing/disposable marker, plus connection credentials. Then run:

```powershell
.\scripts\people\Invoke-ArnaPeopleLocalTests.ps1 -RepositoryPath 'D:\MASES\github-current\arna-api' -RunMySql
```

The runner requires exact database-name confirmation, verifies the target schema has zero tables, and runs ordinary `artisan migrate` (not `migrate:fresh` or `db:wipe`). It then runs only `PersonIdentityMySqlConcurrencyTest.php` against MySQL, avoiding the broader `RefreshDatabase` suite on that schema. Concurrency cases that require `pcntl_fork` are skipped on Windows PHP builds without `pcntl`. Do not point this at any shared, staging, or production schema.

## Security and contract notes

- Tenant context is derived from validated middleware, never from request payloads.
- Contact values use Laravel encrypted casts and API resources expose masked values only. Contact entry does not imply verification.
- Person relationships and participations do not grant account access, tenant membership, employment, ownership, governance office, or permissions.
- Relationship duplicate attempts retain HTTP 409 conflict behavior; validation failures remain 422.
- Deactivation is non-destructive and requires a reason.
- Document access still depends on Files-domain visibility checks in the identity service.

## Known blocker — MySQL clean-install workflow

The available retrieval for workflow run `40734382888`, failed job `122978877517`, returned GitHub's `BlobNotFound` response rather than the job's diagnostic output. This is a log-evidence retrieval failure and does not establish the migration's root cause. No first failing SQL statement is available, and no workflow was rerun. Therefore the migration failure cause remains **UNKNOWN**. Do not claim the migration failure is fixed until the local disposable-schema gate succeeds or original diagnostics are recovered.

## Acceptance status

- Code generation and controller integration: **implemented, static review only**.
- Cross-Person domain unlink guard and regression assertions: **generated, NOT RUN**.
- Tenant-access grant-provenance preservation: **implemented, NOT RUN**.
- Tenant-access reactivation regression coverage: **generated in `PersonApiIntegrationTest.php`, NOT RUN**.
- Tenant-access revocation/link integrity regression coverage: **generated in `PersonApiIntegrationTest.php`, NOT RUN**.
- Phone formatting duplicate and stale-tenant-grant persistence regression coverage: **generated in `PersonApiIntegrationTest.php`, NOT RUN**.
- Focused test code: **added, NOT RUN**.
- PHP lint and route registration: **NOT RUN**.
- Fresh MySQL migration: **NOT RUN locally; existing CI run failed, root cause unresolved**.
- Production readiness: **PARTIAL / BLOCKED** pending local execution and review of the exact resulting commit.
- Merge/deployment/production database operations: **not authorized in this increment**.
