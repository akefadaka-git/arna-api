# Tenant Context Migration Completion — A3 Engineering Decision

**Status:** PARTIAL — fresh-install membership schema corrected; historical Migration 002 not reproduced.
**Scope:** Source-backed migration completion only. No database was accessed or modified.

## Decision

The historical executable source for `002_identity_tenant_context` was not recoverable from the accessible canonical repository history. It must not be recreated under the historical filename or represented as recovered source.

The current canonical migration sequence already creates the core tenant and authorization primitives:

- `tenants`
- `users` (including `default_tenant_id`)
- `tenant_memberships`
- `platform_applications`
- `roles` and `permissions`
- `role_has_permissions`, `user_has_roles`, and `user_has_permissions`
- `audit_logs` and `security_events`

The existing `tenant_memberships` migration was incomplete relative to its current application contract: `TenantMembership` declares the lifecycle values `active`, `suspended`, and `revoked`, and `TenantMembershipService` reads and writes the `status` column. The table-creation migration did not create that column. A clean installation would therefore create a schema incompatible with the current membership lifecycle service.

## Implemented in this branch

Updated `database/migrations/2026_09_07_000003_create_tenant_memberships_table.php`:

- Added `status VARCHAR(20) NOT NULL DEFAULT 'active'`.
- Added an index on `status`.
- Kept the existing composite primary key, foreign keys, `joined_at`, `settings`, and timestamps unchanged.
- The default preserves the existing application interpretation that a membership without an explicit state is active.
- The service remains responsible for validating allowed transitions. The migration does not introduce implicit status transitions or seed historical membership rows.

This is a correction to the existing fresh-install migration, not a claim that the historical Migration 002 has been recovered.

## Remaining historical capabilities — not silently fabricated

The incomplete historical transcript names these additional structures, but omits critical executable SQL and constraints:

| Historical structure | Current canonical coverage | Decision |
|---|---|---|
| `platform_application_roles` | Application-scoped `roles` plus `user_has_roles` and `role_has_permissions` provide overlapping primitives, but semantic equivalence and tenant scoping are not yet demonstrated. | Do not add a parallel table or redirect authorization until the role crosswalk is approved. |
| `platform_application_users` | No verified equivalent for explicit user-level allow/deny overrides, expiry, reason, and grantor semantics was established. | Missing capability; design an additive table only after the application-entitlement crosswalk and override precedence are approved. |
| `arna_tool_permission_map` | The permission catalogue may contain ARNA permissions, but no verified tool-to-permission mapping equivalent was established. | Missing capability; define mapping keys and authorization resolution after the ARNA tool crosswalk is approved. |
| `tenant_context_audit` | Generic `audit_logs` and `security_events` exist, but equivalence for tenant/application context decisions has not been proven. | Do not assume generic audit records satisfy the required context audit contract; specify event fields, durability, and failure policy before migration. |

The historical `tenant_memberships` table is covered by the current canonical table name, but its full historical classification/backfill logic is not recovered. No backfill is included here.

## Explicit non-actions

- No guessed reconstruction of the missing historical SQL.
- No duplicate table creation for capabilities already represented by current canonical tables.
- No table drops, renames, truncation, backfills, reseeds, or dual writes.
- No changes to the custom RBAC authority or Laravel-standard authorization reads.
- No database connection, migration execution, `migrate:fresh --seed`, merge, or deployment.
- No claim of A3 gate closure.

## Required A3 evidence before adding the remaining tables

1. Tenant identity crosswalk, including the role of `users.default_tenant_id` versus explicit memberships.
2. Role and permission crosswalk preserving the existing custom MASES authorization authority.
3. Application entitlement crosswalk, including deny/allow precedence, expiry, revocation, and grantor attribution.
4. ARNA tool-to-permission mapping contract and fail-closed resolution.
5. Tenant-context audit event contract, retention/durability requirements, and behavior when audit persistence fails.
6. Clean-install test coverage proving all migrations and seeders run in order without destructive runtime execution.

## Verification status

- Source inspection: verified against the current canonical `main` files for the membership migration, `TenantMembership` model, `TenantMembershipService`, tenant/application/role/permission migrations, and audit migration.
- Migration execution: NOT RUN.
- Automated tests: NOT RUN.
- Database state: NOT inspected or changed.
- Merge/deployment: NOT authorized by this change.
