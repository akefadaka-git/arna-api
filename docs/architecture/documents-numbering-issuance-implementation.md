# Documents Domain: Numbering Ledger and Issuance Lifecycle

**Status:** Governed implementation slice; source-only, not runtime verified.
**Domain boundary:** Documents owns canonical document identity, numbering, issue/void/revoke lifecycle, and the append-only lifecycle event record. FileManagement remains the owner of binary storage and file versions. Governance/Organization remains the source of authority and office assignments. Finance remains the source of legal-entity and invoice/accounting truth.

## Canonical number

\`[ORG]-[TYP]-[DP]-[RL]-[NNNNNN]-EC[YYYY]\`

Example only: \`ARNA-LET-HQ-CEO-000042-EC2019\`.

Sequence scope is tenant + legal entity + type + department + issuing role + Ethiopian Calendar year. The calendar year is derived server-side from the captured Addis Ababa issuance timestamp using the platform \`EthiopianCalendar\`; callers cannot submit the year. Sequence allocation uses a unique scope row, \`insertOrIgnore\`, and \`lockForUpdate\` inside the same transaction as the document and lifecycle event inserts. Canonical numbers and scope sequence tuples have database unique constraints. Six-digit exhaustion fails closed.

## Issuance and idempotency

- Issuance requires the \`documents.issue\` permission, a verified tenant context, an active tenant-owned file, supported type code, registered-code-shaped inputs, and an \`Idempotency-Key\`.
- The idempotency key is unique per tenant. Reusing it with the same canonicalized request returns the persisted issuance; reusing it with a different request returns conflict.
- Issuance, counter advancement, document insertion, and lifecycle event insertion commit or roll back together.
- A successful issue permanently consumes its number. Void and revoke retain the number and append a lifecycle event; only documents in \`issued\` state can transition.
- \`documents.view\`, \`documents.void\`, and \`documents.revoke\` are separate permissions. All reads and mutations are tenant-scoped.

## Explicit integration limits

This slice does not create public verification or QR-image rendering. A future verification slice must bind the canonical document to a precise file version and compute/verify content SHA-256 without exposing storage paths. It does not yet integrate a registered legal-entity code registry, Organization department registry, or Governance acting-authority matrix. Current code validates code syntax and snapshots the supplied codes, but the issuance permission is not a substitute for the document-type-specific authority decision. Before production issuance is enabled, those registries and the authoritative legal-entity relationship must be enforced.

The migration references the existing \`files\` table and therefore assumes the FileManagement migration is available earlier in the migration set. Migration discovery is registered by \`DocumentsServiceProvider\` in Laravel's provider registry. Database migration execution, tests, workflows, deployment, and merge were not run as part of this source-only delivery.
