# Shareholder Read-Audit Policy

**Status:** Implemented narrowly in the Shareholder HTTP read controllers  
**Scope:** Sensitive shareholder-register, holding, and certificate reads

## Included successful reads

After tenant checks and policy authorization succeed, the following endpoints emit a domain audit action through the shared Core `AuditService`:

| Endpoint | Action | Resource recorded |
|---|---|---|
| `GET /api/shareholders` | `shareholder.read.list` | `shareholder_register`; result count only |
| `GET /api/shareholders/{shareholder}` | `shareholder.read.view` | Shareholder ID |
| `GET /api/shareholders/holdings?shareholder_id=…` | `share_holding.read.list` | Shareholder ID; result count only |
| `GET /api/shareholders/holdings/{shareHolding}` | `share_holding.read.view` | Holding ID |
| `GET /api/shareholders/certificates?shareholder_id=…` | `certificate.read.list` | Shareholder ID; result count only |
| `GET /api/shareholders/certificates/{certificate}` | `certificate.read.view` | Certificate ID |

The audit entry is attempted only after the existing tenant-boundary and authorization checks succeed. Query filters, search terms, returned names, certificate contents, holding entries, and other response payloads are not copied into the audit data. The shared service supplies actor, tenant, request metadata, and timestamp fields according to its existing contract.

## Explicit exclusions

- `GET /api/shareholders/me` remains an ordinary self-service identity lookup and does not emit a domain read-audit event.
- Share-class list/detail endpoints remain excluded because they expose global reference data, not tenant-specific shareholder records.
- This change does not add generic middleware or blanket logging for all `GET` requests.
- This change does not add a new domain audit event for denied requests. Existing security-event mechanisms remain separate.

## Delivery and durability limits

The existing Core `AuditService` honors `mases.features.audit_enabled`. When disabled, it does not persist an audit row. Persistence errors are logged and returned as a null result (fail-open); therefore this implementation attempts audit recording but does not guarantee durable delivery. A future policy decision is required before changing those platform-wide failure semantics or making audit persistence block a read response.

## Review boundary

This is a bounded domain policy. It does not change permissions, policy grants, tenant resolution, response payloads, database schema, or deployment configuration. Automated tests and workflows were not run for this change.
