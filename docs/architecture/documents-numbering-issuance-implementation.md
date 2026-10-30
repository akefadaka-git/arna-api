# Documents Domain: Numbering Ledger and Issuance Lifecycle

**Status:** Governed source implementation; runtime and migration behavior are not verified.
**Domain boundary:** Documents owns canonical document identity, numbering, issue/void/revoke lifecycle, and the lifecycle-event record. FileManagement owns binary storage and file versions. Finance owns legal-entity identity. Organization owns units and department codes. Governance owns positions, authority grants, and acting assignments.

## Canonical number

\`[ORG]-[TYP]-[DP]-[RL]-[NNNNNN]-EC[YYYY]\`

Example only: \`ARNA-LET-HQ-CEO-000042-EC2019\`.

The number uses registered codes rather than caller-defined labels:
- \`ORG\` is the active Finance \`legal_entities.code\`.
- \`DP\` is an active \`organization_units.code\` in the same tenant and legal entity.
- \`RL\` is an active \`governance_positions.code\` belonging to an active Governance body in the same tenant.
- \`TYP\` is defined by the Documents \`DocumentTypeRegistry\`; each type has a separate authority action \`documents.issue.{TYP}\`.
- Number segments reject hyphens so delimiter-based canonical numbers remain unambiguous. Existing registered codes with unsupported characters must be corrected through their owning domain before issuance.

Sequence scope is tenant + legal entity + Organization unit + Governance position + document type + Ethiopian Calendar year. The year is derived server-side from the captured Addis Ababa issuance timestamp using the platform Ethiopian Calendar service. The counter row snapshots the canonical codes; changing a code mid-scope fails closed and requires an explicit numbering-scope migration. Sequence allocation uses a unique scope row and row locking in the same transaction as the document and issuance event. The six-digit sequence limit fails closed.

## Authoritative registry and issuing authority

The Documents domain does not duplicate the owning domains' registries:
- Finance \`LegalEntity\`: entity must be active and belong to the resolved tenant.
- Organization \`OrganizationUnit\`: unit must be active, non-deleted, belong to the resolved tenant, and reference the selected legal entity.
- Governance \`GovernancePosition\` and \`GovernanceBody\`: position and body must be active and belong to the resolved tenant.
- Documents \`DocumentTypeRegistry\`: single code catalogue for the supported document types.
- Governance \`GovernanceAuthorityGrant\`: a current, unrevoked grant must match the exact \`documents.issue.{TYP}\` action and position.
- Acting authority is explicit, never inherited: an active acting assignment must name the exact action, cover the current date, and match the acting person's seat and position; the substantive incumbent must hold the same effective type-specific grant.
- The caller must also hold the tenant-scoped \`documents.issue\` RBAC permission and an active canonical Person identity with active tenant access.

The issuing role is deliberately mapped to a Governance position code; a title string or Organization assignment alone cannot grant authority. Missing registry matches, missing grants, expired/revoked authority, or tenant mismatch fail closed. No default grants are seeded by this change.

## Issuance and lifecycle

- A stable \`Idempotency-Key\` is required. The key is unique per tenant; same canonical payload returns the persisted issue, while a different payload conflicts.
- Counter advancement, document insertion, and issuance lifecycle event insertion are one database transaction.
- Authority and authoritative Finance/Organization/Governance registry records are resolved again inside that transaction immediately before counter allocation; a changed registry snapshot fails closed rather than issuing against stale preflight data.
- The transactional authority pass uses `SELECT ... FOR UPDATE` on the matched legal entity, Organization unit, Governance position/body, actor and incumbent Person records, tenant-access rows, direct/incumbent membership rows, matching type-specific authority grants, and applicable acting-assignment, seat, and substantive-occupancy rows. These locks are held through counter allocation, document insertion, and lifecycle-event insertion, so revocation/deactivation updates to those same rows must wait for issuance to commit or roll back.
- The central `AuthorizationService::hasPermission('documents.issue', ...)` check remains cache-backed and does not expose a row-locking contract for the underlying RBAC role/permission pivots. Therefore this change closes the row-level race for canonical domain registries and Governance authority evidence, but does not establish a serialized lock protocol for concurrent RBAC permission/role revocation. That remains an explicit production verification/design gate.
- Issued numbers are permanent. Void and revoke retain the number and append a lifecycle event; only an \`issued\` document may transition.
- \`documents.view\`, \`documents.void\`, and \`documents.revoke\` remain separate RBAC permissions. Reads and mutations are tenant-scoped.
- The database schema references canonical legal entities, Organization units, Governance bodies/positions, users, tenants, and FileManagement file records. Tenant-scoped canonical-number uniqueness prevents unrelated tenants from colliding.

## Remaining production gates

Public document verification, QR-image rendering, and SHA-256 verification of the exact linked file bytes are not implemented. The issuing-authority integration is source-complete but has not been runtime-verified. The migration assumes the existing Finance, Organization, Governance, tenant/user, and FileManagement tables are discovered before the Documents migration. No migration was executed; tests and GitHub Actions were NOT RUN; database operations, deployment, and merge were NOT performed. Keep the PR draft until these runtime gates can be verified and the Owner approves the authority policy and registry-code conventions.
