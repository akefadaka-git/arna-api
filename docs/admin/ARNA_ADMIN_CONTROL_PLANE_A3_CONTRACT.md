# ARNA Master Administration Control Plane — A3 Contract

**Status:** A3 implementation contract / draft  
**Authority:** Owner-authorized A3.1–A3.8  
**Baseline:** `main` at `6e274c25ecc55b84e026e879d735b77f0e51592f`  
**Scope:** `admin.maalearna.com.et` and the central ARNA API  
**Deployment:** Not authorized

## 1. Architecture boundary

The Admin Console is the central control plane for platform-wide administration. It is not a replacement for domain services and must not bypass their business rules. Each application remains the authoritative owner of its domain data and resource-level policy. The control plane may discover, register, observe, and administer those applications only through explicit API contracts and delegated capabilities.

All administrative API operations require authenticated identity and server-side authorization. UI route guards and hidden navigation items are usability controls, never security boundaries.

## 2. RBAC compatibility

The current five-table RBAC schema remains authoritative:

- `roles`
- `permissions`
- `user_has_roles`
- `user_has_permissions`
- `role_has_permissions`

A3 must extend the existing permission catalogue and existing assignment relations. It must not create a parallel RBAC schema or silently redefine the current role model.

Authorization must be scope-aware (platform, application, organization, tenant, legal entity, resource). No implicit wildcard grants. A principal may not delegate a permission outside its own approved delegation ceiling. Platform Owner authority remains protected and non-delegable through ordinary administrator flows. Sensitive grants, revocations, and lifecycle changes require transaction-bound approval and immutable audit evidence.

## 3. Control-plane modules

1. Master Dashboard — API-backed platform health, application inventory, tenant totals, security alerts, and recent audit activity. No fabricated metrics.
2. Platform Registry — registered applications, environments, service endpoints, status, ownership, and capability contracts.
3. Identity & Membership — central user directory, account lifecycle, tenant membership lifecycle.
4. RBAC — roles, permission catalogue, scoped assignments, delegation ceilings, approval challenges.
5. Domain Management — domain/subdomain registry, ownership verification, DNS inventory, nameserver metadata, TLS certificate metadata, routing/origin metadata, expiry and health observations, provider connection status, audit history.
6. Settings Center — approved organization-wide and per-application preferences, locale, Ethiopian Calendar default, Gregorian preference, timezone, branding and notifications.
7. Configuration Center — typed configuration catalogue, policy versions, feature flags and environment metadata. Secrets must never be returned to browser clients.
8. Integration Center — provider adapters, API/webhook registrations, health, scopes and credential references (never secret values).
9. Security & Audit — privileged sessions, approvals, security events, audit search and export.
10. Operations — service health, queues, scheduled jobs, backup/migration status and diagnostics.

## 4. Domain Management safety contract

Domain Management is a first-class module, not a settings subsection.

Initial registry records are metadata and must not be interpreted as proof that a domain is registered, delegated, resolving, hosted, or production-ready. Proposed names such as `maalearna.com.et`, `admin.maalearna.com.et`, `api.maalearna.com.et`, `app.maalearna.com.et`, and `portal.maalearna.com.et` require authoritative provider verification before their operational state is asserted.

Provider adapters are separate capabilities:
- Registrar: registration, renewal, transfer, lock, ownership.
- DNS: A, AAAA, CNAME, MX, TXT, NS, SRV and supported record metadata.
- Nameservers / DNSSEC: delegation, DS, DNSSEC status.
- TLS: certificate inventory, issuer, SANs, validity, renewal state.
- Hosting: Plesk or other hosting inventory and document-root mapping.
- Routing: origin, reverse-proxy and redirect metadata.
- Monitoring: DNS resolution, HTTP availability, TLS expiry and drift.

No provider adapter may perform live mutation in this A3 phase without its own credentials, capability verification, explicit per-operation authorization, dry-run/diff, confirmation, audit record, and rollback plan. No registrar, DNS, Plesk, certificate or hosting credentials are to be stored in frontend code or returned by API.

## 5. API contract — current implementation status

This branch implements the read-only platform overview, application registry, and Domain Registry inventory projection. Platform identity, RBAC catalogues, tenant directory, and tenant membership directory are owned by the separate API PR #214 candidate. That PR remains unmerged; these endpoints are not duplicated in this branch. None of the domain inventory endpoints claims provider verification or live operational health.

- `GET /api/admin/platform/overview` — IMPLEMENTED; requires `platform.overview.read`; returns database-backed counts and generation timestamp.
- `GET /api/admin/platform/applications` — IMPLEMENTED; requires `platform.applications.read`; returns the platform application catalogue and frontend readiness.
- `GET /api/admin/platform/identity/users`, `/api/admin/platform/rbac/roles`, `/api/admin/platform/rbac/permissions`, `/api/admin/platform/tenants`, and `/api/admin/platform/tenants/{tenant}/memberships` — provided by API PR #214 candidate; not duplicated here and not yet merged.
- `GET /api/admin/platform/domains` — IMPLEMENTED; merges persistent registry records with legacy application/tenant projections; requires `platform.domains.read`.
- `POST /api/admin/platform/domains` — IMPLEMENTED; creates a pending-verification record and one-time DNS TXT challenge; requires `platform.domains.manage`.
- `PATCH /api/admin/platform/domains/{domain}` — IMPLEMENTED; updates display metadata/environment only, records before/after audit evidence, and requires `platform.domains.manage`.
- `POST /api/admin/platform/domains/{domain}/verify` — IMPLEMENTED; verifies a one-use, expiring DNS TXT challenge using a stored SHA-256 hash; requires `platform.domains.verify`.
- `GET /api/admin/platform/domains/{domain}/history` — IMPLEMENTED; returns append-only domain audit events; requires `platform.domains.read`.
- DNS zone, registrar, nameserver, certificate, hosting, routing and provider event APIs — TARGET; no provider connection or live mutation is claimed.

Persistent storage is split across `platform_domains`, `platform_domain_verifications`, and `platform_domain_audit_events`. The raw verification challenge is returned once and stored only as a hash. Domain registration and metadata changes write audit evidence transactionally. FQDN reassignment, provider mutations and destructive lifecycle operations remain excluded until separate approval workflows and provider adapters are implemented.

Mutations must be added only with permission-specific server checks, scope validation, approval challenges where required, transactionally coupled audit evidence, and cache/session invalidation where authorization changes. The API PR #214 candidate is an integration reference only; it is not assumed merged or production-active.

## 6. Permission namespace

Permission seeds should follow existing slug conventions and be additive. Initial namespace proposal:

- `admin.dashboard.view`
- `admin.platforms.view`, `admin.platforms.manage`
- `admin.users.view`, `admin.users.create`, `admin.users.suspend`, `admin.users.revoke`
- `admin.roles.view`, `admin.roles.manage`, `admin.permissions.view`
- `admin.settings.view`, `admin.settings.update`
- `admin.configuration.view`, `admin.configuration.manage`
- `admin.integrations.view`, `admin.integrations.manage`
- `admin.audit.view`, `admin.security.manage`
- `admin.domains.view`, `admin.domains.create`, `admin.domains.update`, `admin.domains.verify`, `admin.domains.suspend`
- `admin.domains.dns.view`, `admin.domains.dns.manage`
- `admin.domains.ssl.view`, `admin.domains.ssl.manage`
- `admin.domains.routing.view`, `admin.domains.routing.manage`
- `admin.domains.security.view`, `admin.domains.security.manage`

Implemented global read permissions in this branch:
- `platform.overview.read`
- `platform.applications.read`
- `platform.domains.read`
- `platform.domains.manage`
- `platform.domains.verify`

These are distinct from the proposed `admin.*` permission namespace below. They are additive to the current RBAC catalogue and are granted by the existing platform-scope grant policy; no new RBAC tables are introduced.

These are proposed additions, not proof that the slugs already exist. Before seeding or attaching route middleware, reconcile naming against the current permission catalogue and the Owner-approved RBAC implementation.

## 7. Settings and configuration change control

The API stores both ordinary values and secret values encrypted with Laravel's application encryption key. Read APIs return ordinary values but never return secret values or ciphertext; secret records expose only a configured flag. A secret is supplied once when a change request is created and is not echoed in request, approval, audit, or read responses.

- `GET /api/admin/platform/settings` and `GET /api/admin/platform/configuration` — separate global read permissions.
- `POST /api/admin/platform/settings/change-requests` and `POST /api/admin/platform/configuration/change-requests` — create encrypted pending changes; they do not immediately alter active values.
- `GET .../change-requests` — list requests for review; secret proposals remain redacted.
- `POST .../{changeRequest}/approve` — applies a pending value transactionally only when a different authenticated actor with the corresponding approve permission approves it.
- `POST .../{changeRequest}/reject` — rejects a pending request and records the decision.

The current implementation provides a two-person separation-of-duties gate. It is not represented as an Owner-signed Ed25519 approval. Owner-only cryptographic approval remains a separate hardening requirement for designated high-impact settings before production use.

## 8. Integration registry and operations health

The provider catalogue exposes DNS, registrar, certificate, hosting/Plesk, edge-routing, monitoring and email-delivery categories. Credential presence is exposed only as a boolean; credential ciphertext is never serialized. Provider health checks, provider mutations and automated remediation remain disabled until adapter contracts, credentials, approval gates and verification tests are delivered.

The operations health endpoint performs a read-only database reachability query and labels queue, cache, object storage and external providers as `not_observed` unless a recorded observation exists. It does not convert missing telemetry into a healthy status. Observation history is queryable separately. No provider or hosting state is inferred from the application inventory.

## 9. Audit requirements

Every privileged operation records actor, acting scope, target, action, reason, request/correlation ID, timestamp, approval reference, before/after state (redacted), result, and provider receipt where applicable. Audit evidence must be written in the same transaction as local authorization/configuration mutations whenever possible. Provider operations require a durable intent record before dispatch and a result record after completion.

## 10. Delivery and stage gates

- A3: implement in isolated branches; no merge or deployment.
- A4: exact-head API tests, clean-install MySQL, tenant isolation, permission-negative tests, frontend type-check/build, route and accessibility contracts, and provider-adapter safety tests.
- A5: separate Owner authorization. Production trust-anchor provisioning, provider credentials, DNS, hosting and certificate changes are excluded until explicitly authorized.

## 11. Current known constraints

- Existing API main and frontend main are the source of truth.
- The API and frontend candidate PRs require contract reconciliation; older frontend paths must not be revived merely to satisfy stale UI.
- The Admin dashboard scaffold is currently a static landing surface. It must be upgraded with real API data only after endpoints and permission slugs are verified.
- Plesk/provider API write access is not verified. Domain operations therefore remain inventory/metadata and integration-readiness work until a provider connection is confirmed.
