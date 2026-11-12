# Calendar owning-domain authorization contract

**Status:** implementation contract for Calendar domain links; not a grant of access and not a replacement for the owning domain's policy.

## Resolution and tenant boundary

- Calendar accepts only the explicit `domain:resource_type` allowlist in `CalendarDomainResourceAuthorizer`; request data never selects a PHP class.
- The authorizer requires the active `TenantContextService` tenant ID to equal the tenant ID supplied by Calendar before it loads a resource or invokes a policy. A mismatch is deliberately indistinguishable from a missing resource.
- Non-Person resources are loaded by both primary key and `tenant_id`. Person is global identity data and is resolved only through an active `PersonTenantAccess` row for the requested tenant.
- A linked reference never grants access. Unknown types, missing policy registrations, foreign-tenant resources, and denied abilities fail closed.

## Canonical owning-domain authorization surfaces

| Domain | Calendar link types | Owning-domain authorization surface |
|---|---|---|
| People | person | No registered `PersonPolicy` or read-specific permission exists in the current source. Require active tenant access and the conservative `people.manage` permission through `AuthorizationService`. This is a documented limitation, not a new People policy. |
| Organization | unit; assignment | Invoke the existing `OrganizationUnitPolicy::view` and `OrganizationAssignmentPolicy::viewAny` directly. These policies are used as direct service dependencies by the Organization controller; they are not treated as Gate registrations. |
| Finance | legal_entity; ledger_account; accounting_period; journal_entry | Resolve the explicitly registered Laravel Gate policy and authorize `view`. |
| Governance | appointment; body; membership; position; term | Resolve the explicitly registered Laravel Gate policy and authorize `view`. |
| Loan | loan; product; collateral | Resolve the explicitly registered Laravel Gate policy and authorize `view`. |
| Shareholder | shareholder; share_holding; share_certificate; share_class | Resolve the explicitly registered Laravel Gate policy and authorize `view`. |
| Wallet | wallet; obligation; payment | Resolve the explicitly registered Laravel Gate policy and authorize `view`. |

The feature contract test pins every Gate-backed model to a registered policy exposing `view`, and pins the two Organization direct-policy entry points. People read authorization remains conservative until the People domain publishes a canonical read contract.

## Recurring reminder outbox persistence

- The existing outbox row for the series anchor remains the template and preserves its notification row ID/dedupe identity.
- Each later occurrence is identified by the tuple `(event_id, recipient_user_id, reminder_type, occurrence_key)`; `occurrence_key` is the canonical UTC original-start identity returned by Calendar recurrence expansion.
- The mutable recurrence ordinal is stored as `occurrence_index` for diagnostics and ordering only. It is not the durable idempotency identity.
- A one-time migration rekeys already-materialized future rows from ordinal keys to original-occurrence keys. The unique `idempotency_key` constraint remains the concurrent insert guard.
- Occurrence moves retain the original key while refreshing pending `occurrence_starts_at` and `deliver_at`; cancellation checks the same original key. A cancelled/stale row can be reactivated only when canonical expansion returns that original occurrence again.
- The dispatcher scans all scheduled recurring events in bounded chunks; there is no fixed first-page cap that can starve later events.
- Delivery still rechecks event state, occurrence cancellation, future start, tenant existence, and active recipient membership. Notification publication and the outbox acknowledgement remain in one transaction with notification deduplication.

## Explicit non-goals and gates

No People read-policy is invented here. No broader RRULE controls are added. Independent security review and production reverse-proxy/Plesk ingress verification remain separate A4 closure gates. This work does not authorize merge, production database operations, signing, release, or deployment.
