# ARNA Admin A1 Controlled Addendum — Domain Management

**Document ID:** ARNA-ADMIN-A1-ADD-001  
**Version:** 1.0.0  
**Status:** Owner-authorized scope amendment  
**Parent baseline:** ARNA Master Administration Console A1 baseline (preserved; not superseded)  
**Authorization:** Owner approval in the Admin Console implementation conversation, 2026-11-24  
**Implementation stage:** A3 authorized; merge and deployment remain separate gates

## 1. Purpose

This addendum extends the Admin Console A1 scope to include Domain Management as a first-class administrative capability. It does not replace, rewrite, or reopen the previously established A1 baseline.

## 2. Scope added

Domain Management includes:
- Domain and subdomain registry.
- Domain-to-application, tenant, environment, and service associations.
- Ownership verification state and evidence references.
- DNS record inventory and provider status.
- Nameserver delegation and DNSSEC status.
- SSL/TLS certificate inventory, SANs, validity, and renewal state.
- Hosting/origin associations, including Plesk where an authorized integration exists.
- Routing, reverse-proxy and redirect metadata.
- DNS, HTTP and TLS health observations.
- Domain-specific approvals, audit events and change history.

## 3. Safety and authority

A registry entry is not proof of registration, ownership, DNS delegation, DNS resolution, certificate validity, hosting, or production readiness. Those states require independent authoritative evidence.

Live registrar, DNS, nameserver, DNSSEC, certificate, hosting, Plesk, reverse-proxy and routing mutations require:
1. A verified provider adapter and least-privilege provider credential.
2. Server-side permission and scope checks.
3. A reviewed before/after change plan and dry-run where supported.
4. Explicit approval appropriate to the operation's risk.
5. Durable intent and outcome audit records.
6. A rollback or recovery procedure.

Provider secrets must never be exposed to the browser or returned by API. No live provider operation is authorized by this addendum.

## 4. RBAC

Domain capabilities must be additive to the existing authoritative RBAC schema and use the existing roles, permissions, user-role, user-permission, and role-permission relations. No parallel RBAC tables or implicit wildcard grants are introduced.

## 5. Implementation and verification

A3 may implement the registry, provider-adapter boundaries, permission-gated APIs, Admin UI, tests, and operational readiness indicators. A4 must verify authorization, tenant/platform boundaries, truthful provider states, audit behavior, and frontend accessibility. A5 deployment and any live provider mutation remain separately authorized.

## 6. Baseline preservation

The parent A1 baseline remains historically valid. This addendum is the sole controlled record of the added Domain Management scope. Any further scope expansion must be recorded as another controlled addendum or revision.
