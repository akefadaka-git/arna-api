# ARNA AI A3 — Authentication and deployment boundary

**Status:** A3 implementation decision; no production configuration changed.

## Decision

The first ARNA AI workspace is a feature route inside the existing authenticated Workspace SPA at `https://app.maalearna.com.et/ai`. It is not deployed as a separately authenticated AI SPA on `ai.maalearna.com.et` in this stage.

The existing Sanctum stateful host allow-list remains exactly the Workspace and Shareholder Portal hosts:

- `app.maalearna.com.et`
- `portal.maalearna.com.et`

The AI host may remain an approved CORS origin, but CORS is not authentication, authorization, or permission to receive session cookies. Do not add `ai.maalearna.com.et` to `SANCTUM_STATEFUL_DOMAINS` as part of this work.

## Deployment

- The authenticated AI route ships only as part of the existing Workspace static build (`dist/`).
- The static host remains a static asset host; no Node runtime or model credentials are placed in the browser bundle.
- AI API requests use the existing API origin and authenticated Workspace session path.
- No AI-specific cookie domain, CSRF exemption, wildcard origin, or additional trusted host is introduced.
- A future standalone AI host requires a separate threat model and explicit design for session establishment, CSRF, cookie scope, origin validation, token lifetime/storage, logout/revocation, tenant switching, and deployment isolation.

## Authorization

- Workspace route authentication is inherited from the existing authenticated route tree.
- Every AI API action must independently enforce central ARNA authorization and tenant membership.
- Frontend route guards are UX controls only; they do not replace backend authorization.
- Retrieval must invoke owning-domain services and return source references. No AI code may query domain tables directly.
- Tool execution is deny-by-default until a named tool is mapped to a specific domain permission and resource policy.

## Kill and provider controls

- Provider activation and outbound model egress remain disabled.
- A disabled gateway returns service-unavailable and does not attempt a network call.
- The nine agent definitions are registered as disabled L0 capabilities.
- No L3/L4 autonomy, financial mutation, approval, role grant, payment, posting, or direct database-write tool is exposed.

## Verification required before a standalone host

1. Threat model and architecture approval.
2. Cookie/CSRF and CORS tests for allowed and disallowed origins.
3. Tenant isolation and authorization tests across all exposed endpoints.
4. Session revocation, logout, tenant switch, and token expiry tests.
5. Static deployment and secret-scanning checks proving no credentials enter client assets.
6. Independent security review.

## Non-goals

This decision does not modify the current SSO contract, add AI host to Sanctum stateful domains, deploy a new hostname, select a model provider, or authorize production rollout.
