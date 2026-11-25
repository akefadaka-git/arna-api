# Admin Control Plane — A2 Architecture Contract

Status: implementation baseline; A2 contract is established by this change.
Scope: central identity, RBAC catalogue, tenant registry and tenant membership visibility.

## Authority and boundaries

- `User` in `MaaleArna\\MASES\\Core\\Auth\\Models\\User` remains the only canonical account identity. No parallel Admin user model or identity table is introduced.
- `AuthorizationService` remains the only permission decision service. The Admin API uses the existing `auth.permission` middleware; it does not duplicate authorization logic.
- `TenantContextMiddleware` remains authoritative for request tenant context. Central platform administration is deliberately outside `tenant.membership`; it is gated by explicit global platform permissions and may not trust a caller-supplied tenant header as authority.
- `Tenant`, `TenantMembership`, `Role`, and `Permission` remain the canonical tenancy/RBAC records.
- Existing tenant-scoped permissions (`user.list`, `role.list`, `permission.list`) are not silently widened to platform scope. New global read permissions are separate and are not auto-granted to any role.

## Read API contract

All endpoints require Sanctum authentication and a global permission:
- `GET /api/admin/identity/users` — `platform.identity.read`
- `GET /api/admin/rbac/roles` — `platform.rbac.read`
- `GET /api/admin/rbac/permissions` — `platform.rbac.read`
- `GET /api/admin/tenants` — `platform.tenancy.read`
- `GET /api/admin/tenants/{tenant}/memberships` — `platform.tenancy.read`

Lists are paginated (default 25, maximum 100), expose only operational fields, and accept bounded search/filter parameters. Membership listing is constrained to the path tenant. These are read endpoints only; no account, role, permission, tenant or membership mutation is included in this baseline.

## Security and operational invariants

1. No global user listing is available through tenant-scoped permissions.
2. Platform read permissions are catalogue entries only; seeding them does not grant them.
3. System permissions are visible but not mutable through this read API.
4. Passwords, MFA secrets, recovery codes, tokens, private settings and security-sensitive model attributes are never serialized.
5. No tenant membership is created or altered by these endpoints.
6. No schema migration is needed; existing canonical tables are reused.

## Follow-on mutation contract

A3 write operations must be separately permission-gated (identity.manage, rbac.manage, tenancy.manage), audited, validated against protected/system records, and transactionally update authorization caches after role or direct-permission changes. This baseline intentionally does not invent those grants or bypass existing governance.
