# ARNA Platform Provider Adapter and Approval Architecture

**Status:** A3 architecture specification; implementation and activation are separately gated.  
**Control plane:** `admin.maalearna.com.et`  
**API ownership:** PR #224 owns provider registry/operations APIs. PR #214 remains the sole owner of platform identity, tenant, membership and Owner-signed tenant RBAC contracts.  
**Safety invariant:** External provider mutations remain disabled until the implementation, independent review, sandbox verification and separate Owner authorization gates are complete.

## 1. Scope and non-goals

This architecture governs integrations for DNS, domain registrars, SSL/TLS certificate authorities, hosting/Plesk, edge routing/reverse proxies, monitoring and email delivery.

It defines a provider adapter contract, secret boundary, operation planning, risk classification, approvals, execution, idempotency, audit and reconciliation.

It does not select a vendor, provision credentials, connect a production provider, change DNS/nameservers/DNSSEC, issue or revoke certificates, modify hosting/routing, send mail, or enable automated remediation. A catalogue entry or stored credential is not evidence of a live connection.

## 2. Trust boundaries and components

1. **Provider catalogue** — canonical provider key/type, vendor, environment, endpoint identity, adapter version, declared capabilities, enabled state and health observation metadata. Provider keys are allowlisted; user-supplied adapter class names and arbitrary URLs are prohibited.
2. **Adapter registry** — maps a reviewed provider key and version to a server-registered adapter. Unknown, disabled, incompatible or unregistered adapters fail closed.
3. **Provider adapter** — implements only a typed vendor-specific operation allowlist. It validates target identifiers and provider response schemas; it cannot choose arbitrary hosts or arbitrary HTTP methods.
4. **Credential vault** — decrypts credentials only inside the server-side adapter boundary. APIs, logs, exceptions, audit records, job payloads and browser clients receive neither plaintext nor ciphertext. Credentials are environment-scoped, versioned, rotatable and least-privileged.
5. **Operation planner** — validates the requested action, computes an immutable normalized plan, identifies the target resource and risk class, and calculates a canonical SHA-256 payload digest. Planning is read-only and produces no external side effect.
6. **Approval policy engine** — resolves required approvals from provider, operation, environment and risk class. Policy is server-side and cannot be weakened by a caller-supplied flag.
7. **Approval ledger** — append-only decision records bound to the exact request digest, requester, approver, policy version, expiry and decision note.
8. **Execution outbox/worker** — claims only fully approved, unexpired and still-current requests. It performs the provider call outside the database transaction using a stable idempotency key, then persists the result and audit event.
9. **Reconciliation and health** — compares observed provider state with the requested target and records source, timestamp, status and sanitized evidence. An unobserved state must never be presented as healthy.

## 3. Adapter contract

Every adapter must provide these logical operations:

- `providerKey()` and `adapterVersion()` — stable identity and compatibility version.
- `capabilities()` — explicit typed operation allowlist and supported resource types.
- `validateConfiguration()` — validates endpoint identity, auth mode, scopes and required credential references without disclosing secrets.
- `observe(resource)` — read-only state/health observation.
- `plan(operation)` — validates and returns a normalized, immutable operation plan and risk classification; it must not mutate external state.
- `execute(approvedPlan, idempotencyKey)` — performs only the exact approved operation. It must reject a changed plan digest, unsupported operation, expired approval, duplicate non-idempotent execution or disabled mutation gate.
- `reconcile(operation)` — reads the provider and determines whether the intended state was applied, not applied, or remains indeterminate.

Adapters must have bounded timeouts, TLS verification, strict response validation, rate limits, safe retry semantics and sanitized errors. No generic HTTP proxy, arbitrary URL fetch, shell execution or provider-specific credentials in frontend code is permitted. SSRF protections include HTTPS-only endpoints where supported, host allowlists, DNS/IP validation at connection time, private/link-local/loopback address denial and redirect restrictions.

## 4. Operation and approval state machine

Allowed state transitions:

`draft → pending_approval → approved → queued → executing → succeeded`

Alternative terminal or recovery states are `rejected`, `expired`, `cancelled`, `failed` and `reconciliation_required`. State transitions are server-enforced and append-only in the audit ledger. A caller cannot directly set a status.

Each operation request binds:
- provider and adapter version;
- environment and immutable provider/resource identity;
- operation key and canonical normalized payload;
- payload SHA-256 digest;
- risk classification and policy version;
- requester identity and reason;
- idempotency key, creation time and expiry;
- required approval count and Owner-signature requirement;
- execution result reference and reconciliation status.

The approval decision is valid only for the exact digest and policy version. Any change to provider, environment, target, operation, payload, adapter version or risk policy invalidates prior approvals and requires a new request. The requester cannot approve or reject their own request. Each approver is unique per request. Approval records are append-only.

## 5. Approval tiers

| Risk tier | Examples | Required control |
|---|---|---|
| Read-only | DNS/TLS/hosting/monitoring observation | Read permission; no mutation approval |
| Low reversible | Narrow TXT record create/update with exact zone and TTL | Two distinct authorized people; requester excluded |
| Elevated | A/AAAA/CNAME/MX changes, certificate issuance/renewal, hosting setting change | Two distinct approvers, including an authorized security/platform approver; environment-specific policy |
| Critical/destructive | Nameserver/delegation/DNSSEC changes, registrar transfer/lock changes, certificate revocation, production route cutover, destructive hosting action | Two-person approval **and** a cryptographic Owner authorization over the canonical operation digest; short expiry and explicit change window |

The critical tier must use a dedicated Owner trust-anchor and signed approval contract. The Owner-signed RBAC challenge in PR #214 is not automatically reusable for provider operations. Provider approval requires its own action/resource/digest binding and key-purpose separation. Until that contract is independently reviewed and implemented, critical operations are not executable.

## 6. Transaction and execution safety

- Creating an operation request and its audit record is one database transaction.
- Recording the final required approval and enqueuing the execution outbox item is one transaction.
- The worker claims a request with a lease and compare-and-set state transition. It rechecks approvals, digest, expiry, provider status, adapter version and kill switches immediately before execution.
- External API calls never run while holding a database transaction open.
- Every call carries a stable idempotency key where the provider supports it. Retry is allowed only when the operation is proven idempotent; otherwise the worker reconciles before retrying.
- A timeout after a provider accepted a request is indeterminate, not an automatic failure. The state becomes `reconciliation_required` until a read-only observation resolves it.
- Provider responses and errors are sanitized before persistence. No secret, authorization header, raw request body containing credentials or private key is written to logs or audit.
- Cancellation is permitted only before execution is claimed. In-flight external calls are reconciled, not represented as cancelled without evidence.

## 7. Required persistence model

The provider lifecycle requires separate records (not new RBAC tables):

- `platform_integrations`: provider identity, adapter key/version, environment, state, configuration version, credential reference/version and declared capabilities.
- `platform_provider_action_requests`: immutable operation plan, digest, risk, policy version, idempotency key, requester, expiry and lifecycle state.
- `platform_provider_action_approvals`: unique approver decisions bound to the request digest and policy version.
- `platform_provider_action_audit_events`: append-only state/decision/execution/reconciliation events with sanitized metadata.
- `platform_provider_action_outbox`: unique execution intent, lease, attempt count and retry/reconciliation scheduling.
- `platform_health_observations`: append-only sanitized provider and infrastructure observations.

The existing five-table RBAC schema remains authoritative. New provider permissions are explicit and separate: read catalogue, manage provider metadata/credentials, request action, approve action, view audit, execute worker capability and administer policy. No wildcard grant is introduced.

## 8. Global and per-provider fail-closed controls

All of the following must be true before an adapter may execute a mutation:

- global `ARNA_PROVIDER_MUTATIONS_ENABLED` kill switch is explicitly enabled;
- provider-level mutation switch is enabled for the exact environment;
- adapter key/version is registered, reviewed and enabled;
- operation is in the adapter's allowlist;
- provider credential is valid, least-privileged and not expired/revoked;
- request digest and policy version match the approvals;
- all required independent approvals and any Owner signature are valid;
- request is unexpired and within its approved change window;
- idempotency/reconciliation checks pass;
- execution actor/worker has the dedicated server-side capability.

The default for every switch is **false**. No frontend action can enable these switches. The provider registry's current `provider_mutations_enabled=false` and `active_provider_probes=false` behavior must remain until all gates are met.

## 9. Rollout and verification gates

1. **A3 architecture freeze:** approve this contract, vendor-neutral adapter API, risk matrix and state machine.
2. **A3 implementation:** add adapter contracts/registry, versioned credential references, action/approval/audit/outbox persistence, policy engine and disabled-by-default execution gate. No production adapter credentials.
3. **A4 verification:** unit/contract tests for every adapter; authorization-negative tests; requester-self-approval, duplicate approver, changed digest, stale policy, expiry, replay, idempotency, concurrent approval/execution, outbox lease recovery, timeout/reconciliation, secret redaction and tenant/environment isolation tests.
4. **Independent security review:** review SSRF, credential vault, trust anchors, approval binding, worker privilege, replay/concurrency, audit integrity and provider-specific scopes.
5. **Sandbox-only adapter certification:** vendor sandbox accounts and synthetic resources; verify no cross-environment calls and no real customer/domain mutation.
6. **Separate Owner authorization:** select providers, exact environments, allowed operations, risk policy, approvers, trust anchor, change window and rollback/reconciliation plan. Only then may a separate activation change enable selected operations.

## 10. Current implementation boundary and closure criteria

The current PR #224 baseline provides a read-only provider catalogue, encrypted credential storage, sanitized credential-presence metadata and read-only/explicitly unobserved health reporting.

The first A3 provider-foundation increment now adds:
- typed `ProviderAdapter` contract with explicit operation capability declaration, planning, read-only observation and execution boundary;
- immutable `ProviderOperationPlan` DTO with recursive canonicalization and SHA-256 digest binding;
- explicit in-memory `ProviderAdapterRegistry` with duplicate/invalid key rejection and fail-closed resolution; no class auto-discovery;
- default-off `ProviderMutationGate` reading `services.arna_provider_mutations.enabled`, configured from `ARNA_PROVIDER_MUTATIONS_ENABLED=false` by default;
- versioned risk policy requiring two distinct approvals and additionally an Owner signature for critical actions;
- persistence migration for provider action requests, approval decisions, append-only audit events and a held execution outbox;
- unit tests for default-off mutation gate, unregistered/duplicate/invalid adapter handling, stable plan digest and critical approval policy.

This increment is **foundation only**. It does not yet implement a provider adapter for any vendor, provider action request/approval HTTP lifecycle, Owner-signature verifier for provider actions, outbox dispatcher/worker, sandbox certification, or provider-specific reconciliation. The outbox has no dispatcher and cannot cause external side effects. No provider adapter is registered by default.

Therefore:
- provider registry/health UI: implemented read-only;
- provider adapter contract/registry, operation digest, default-off gate, risk-policy foundation and persistence schema: implemented, awaiting exact-head clean-install/A4 verification;
- provider action lifecycle, cryptographic Owner approval, execution worker, vendor adapters and sandbox certification: pending implementation;
- live provider probes and external provider mutations: **DISABLED**;
- production provider connectivity: **UNKNOWN / NOT VERIFIED**.

No PR description, UI state or health response may imply otherwise.

## 11. Provider action lifecycle implementation increment (2026-11-25)

The following executable lifecycle components are now present in PR #224; this section records implementation scope, not A4 certification:

- `ProviderActionLifecycleService` creates immutable requests only through an explicitly registered adapter, validates the declared operation/resource/risk tuple, freezes the canonical digest and rejects credential-like payload fields.
- Distinct approval decisions are persisted against the exact digest and policy version. Requesters cannot approve or reject their own request; the database unique key prevents duplicate decisions by one approver.
- Critical actions use a dedicated Ed25519 trust anchor configured by `ARNA_PROVIDER_OWNER_KEY_ID` and `ARNA_PROVIDER_OWNER_PUBLIC_KEY` (Base64 raw public key). The signed message is domain-separated with `ARNA_PROVIDER_ACTION_OWNER_APPROVAL_V1` and binds request UUID, provider, adapter version, environment, operation, resource, digest, risk, policy version and expiry. No tenant RBAC or settings key is reused.
- The final approval gate creates one unique held outbox record with a stable request/digest idempotency key. The outbox worker uses short leases, recovers expired processing leases by reconciling first, uses a stable bounded idempotency key bound to the request UUID and full plan digest, and never blindly retries a non-idempotent action after an uncertain response.
- The worker requires three independent gates: `ARNA_PROVIDER_MUTATIONS_ENABLED=true`, `ARNA_PROVIDER_SANDBOX_MUTATIONS_ENABLED=true`, and a per-provider `platform_integrations` record with matching adapter version, `environment=sandbox`, `connection_state=connected`, `sandbox_mutations_enabled=true`, `sandbox_certified_at` and a certification reference. All gates default off. It only executes requests whose environment is exactly `sandbox`; production and staging actions are not executable by this worker.
- A manually invoked command, `php artisan arna:providers:dispatch-held-outbox`, dispatches the sandbox worker. No scheduler is registered and no production mutation path is enabled.
- Request, approval, Owner-signature verification, held outbox, execution and reconciliation transitions are recorded in the append-only provider action audit table. A read-only history endpoint exposes sanitized decision and audit metadata.

**Verification state:** feature/unit tests and exact-head CI have been wired; their current-head outcomes must be checked before A4 closure. No vendor adapter is registered by default. The current feature test uses an in-process synthetic adapter and does not constitute vendor certification or external sandbox evidence.

- A separate `ProviderSandboxCertificationService` and manual command, `php artisan arna:providers:certify-sandbox {provider_key}`, invoke the adapter's `certifySandbox()` contract only when `ARNA_PROVIDER_SANDBOX_CERTIFICATION_ENABLED=true`. A passing report records a sanitized certification reference and checks, but explicitly leaves `sandbox_mutations_enabled=false`; certification is not activation.

**Vendor certification remains NOT RUN:** no concrete DNS, registrar, certificate, hosting/Plesk, routing, monitoring or email vendor adapter has been registered or supplied with isolated vendor sandbox credentials. Each selected vendor requires a dedicated sandbox tenant/account, synthetic resource namespace, scoped credentials, endpoint allowlist and vendor-specific certification evidence. No real customer/domain resource may be used.

**Production safety:** production mutation remains disabled; no production credentials, trust anchor or activation flag is configured by this change. This increment does not authorize merge, deployment, production provider connectivity or live external mutations.
