# Calendar Core A3.2 — Persistence and API contract

Status: implementation increment committed; CI and exact-head verification pending.

## Scope
Tenant-scoped Calendar Core tables and HTTP routes for calendars, events, attendees, recurrence metadata, domain links, resource reservations and immutable audit entries. The migration is loaded by the existing CoreServiceProvider migration loader.

## Temporal contract
- All-day events persist civil dates in starts_on/ends_on and reject instant fields.
- Timed events require ISO-8601 instants with explicit UTC offsets and an IANA timezone; Eloquent stores instants as timestamps.
- Recurrence is stored as a bounded structured rule (daily/weekly/monthly/yearly, interval and optional count). Expansion engine and exception materialization remain a subsequent increment; do not treat the stored rule as an executed schedule.
- E.C. input conversion continues through the canonical EthiopianDate/LocalDate contract.

## Isolation and authorization
- Every query/mutation derives tenant_id from TenantContextService after auth:sanctum and tenant.membership middleware.
- Calendar and event lookups include tenant_id; attendee additions require active tenant membership.
- Linked domain references are opaque references only. They do not confer access to the referenced domain object; consumers must re-authorize through the owning domain.
- Routes require calendar.view/create/update/reserve permissions. These are proposed canonical slugs and must be reconciled with the canonical permission catalogue before assignment; absence of a grant denies access.
- No Governance quorum, voting or binding resolution behavior is implemented here.

## Concurrency and audit
- Event update uses row locking plus an expected version; stale versions return HTTP 409.
- Resource reservation serializes on the tenant-owned resource row, checks overlapping confirmed intervals under lock, and retries the transaction on supported deadlocks.
- Mutations write tenant-scoped calendar_audit_entries in the same database transaction.
- Reservation locking is only effective if all reservation writers use this service; direct SQL writers are outside the application concurrency contract.

## Deliberate limitations / next increments
- Recurrence expansion, exception instances, reminder/outbox delivery, cancellation workflows, attendee response endpoint, resource CRUD, and full domain-link validation are not yet implemented.
- Resource rows must be provisioned through a separately permissioned resource-management endpoint before reservations can be made.
- A4 must add migration, MySQL clean-install, tenant-isolation, IDOR, permission-denial, stale-version, overlapping-reservation race, audit-atomicity, E.C. boundary and date/instant tests.
- No deployment or merge is authorized by this increment.
