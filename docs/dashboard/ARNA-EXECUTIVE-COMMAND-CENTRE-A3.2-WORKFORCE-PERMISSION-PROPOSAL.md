# ARNA Executive Command Centre — A3.2 Workforce Permission Proposal

**Status:** Owner decision required before permission registration or grant  
**Implementation increment:** A3.2 — read-only workforce aggregate  
**Proposed slug:** `people.workforce.view`

## 1. Purpose

Authorize a narrowly scoped, read-only aggregate of active employee records for the Executive Command Centre. This permission is separate from the existing `people.manage` identity-management capability.

## 2. Proposed permission definition

| Field | Proposal |
|---|---|
| Slug | `people.workforce.view` |
| English name | View workforce aggregate |
| Amharic name | የሰው ኃይል አጠቃላይ መረጃን ማየት |
| Category | `people` |
| Scope | `tenant` |
| Application binding | None; tenant-scoped capability |
| System-critical | No |
| Effect | Read-only access to aggregate workforce count; no employee-level records |

## 3. Authorization boundary

- The existing Executive Overview audience gate remains mandatory: `ceo`, `president`, or `finance_manager` under the frozen A1/A2 policy.
- The workforce provider performs a second, independent check against current permission pivots; cached positive authorization is not sufficient.
- A direct grant must be an active tenant-scope permission with no application binding and a null or matching tenant binding.
- A role-derived grant must come from an active role bound to the current tenant or an unscoped role, and either an unscoped role or an active People application role.
- No default role assignment is proposed. In particular, this proposal does not automatically grant the permission to CEO, President, Finance Manager, HR Manager, tenant administrators, or platform administrators.
- `people.manage` is not a substitute and must not imply this permission.
- Permission registration and all role/direct grants remain blocked until explicit Owner approval.

## 4. Data contract

Definition: count rows in `employees` where:
- `tenant_id` equals the validated tenant context;
- `is_active = true`; and
- `deleted_at IS NULL`.

The response contains only the count, source domain/key, calculation version, and observation timestamp. It does not expose employee names, employee numbers, account IDs, person IDs, or row-level records.

The metric does not count shareholders, governance members, inactive employees, soft-deleted employees, or records from another tenant. It does not infer employment from Person identity or account linkage.

## 5. Revocation and failure behavior

- Evaluate current database pivots for each request so permission revocation takes effect without relying on a positive TTL cache.
- If the permission is absent, inactive, wrongly scoped, tenant-mismatched, or not granted through an eligible role, omit the workforce widget.
- Do not fall back to `people.manage`, direct Executive Overview entry permission, or broad administrator status.
- Do not register this permission in the canonical catalogue, seed it into any tenant, or grant it to any role/user as part of A3.2 pending Owner approval.

## 6. Owner decision requested

Approve or reject the proposed slug, scope, role eligibility model, and grant policy. Any requested default-role assignment must be specified explicitly. Until that decision is recorded, the implementation remains fail-closed and no production or seed data is changed.

## 7. Implementation evidence

A3.2 code may recognize the proposed slug so tests can validate the future authorization boundary using isolated test fixtures. Such test fixtures are not catalogue registration or production grants. The runtime returns no workforce data unless a real, active, tenant-compatible permission grant exists.
