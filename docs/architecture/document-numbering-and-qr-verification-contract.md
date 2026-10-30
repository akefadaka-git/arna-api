> **Policy alignment notice (2026-10-30):** The numbering format and counter-scope sections in this earlier contract are superseded for future implementation by [ARNA Amharic Document Numbering Standard v1.0](arna-amharic-document-numbering-standard-v1.md) and the [ARNA Document Numbering Operations Manual v1.0](../operations/arna-document-numbering-operations-manual-v1.md). The target number is **ዓርና/BB/TT/SSSS/YY**. The counter scope is tenant + legal entity + issuing unit + document type + full Ethiopian year; issuing Governance position/role is authority metadata, not a counter dimension. The older format and position-inclusive counter scope below are historical design material and must not be used as the target contract. QR and verification guidance remains subject to separate implementation and verification. This notice does not claim the target numbering format is implemented.

# Document Numbering and QR Verification Contract

**Status:** Contract plus source implementation in draft PR #53; runtime behavior remains unverified.
**Domain ownership:** `Domains/Documents` owns document identity, numbering, approval/publication lifecycle, and verification policy. `Domains/FileManagement` owns binary storage, file metadata, access-controlled streaming, and file version history. Documents reference FileManagement records; neither domain creates a second binary store.

## 1. Goals and invariants

- Assign a human-readable, immutable number to each issued document.
- Support Letters, Memos, Legal instruments, Invoices, Certificates, and extensible document types.
- Represent the Ethiopian Calendar (E.C.) year explicitly and calculate it using the platform's Ethiopian-calendar service, never by subtracting a fixed number from Gregorian year.
- Prevent duplicate numbers under concurrent issuance.
- Never reuse a number after issuance, cancellation, voiding, or revocation.
- Separate document identity from storage path, file ID, workflow status, and the issuing person's mutable account roles.
- Make every displayed document view include a QR code/payload for a file-specific verification link. QR generation may be rendered by the web/mobile client; the API must supply the authoritative URL and verification result.
- Keep public verification separate from private access. A QR code is not authorization by itself and must not expose a private document to an unauthenticated scanner.

## 2. Number format

### Approved printed number

`AR-[TYP]-[DP]-[RL]-[NNNN]/[YY]`

Example: `AR-LET-HQ-CEO-0042/19`

`AR` is the fixed ARNA platform prefix. The full E.C. year remains stored as a four-digit integer in the document record; `/YY` is only its two-digit printed suffix. The two-digit suffix is intentionally a compact display convention, not a replacement for the full year in records, reports, or audit evidence.

| Segment | Meaning | Rule |
|---|---|---|
| `AR` | Fixed ARNA platform prefix | Literal prefix; it is not a per-entity code |
| `TYP` | Document type | Required controlled code, e.g. LET, MEM, LEG, INV, CER |
| `DP` | Department or branch | Required controlled code; HQ is allowed only as an explicit registered code |
| `RL` | Issuing authority role | Snapshot of the authorized issuing role/office at issuance, not a live lookup |
| `NNNN` | Sequence | Four decimal digits, zero-padded; allocated atomically; range 0001–9999 |
| `YY` | Ethiopian Calendar year suffix | Final two digits of the full E.C. year; the full year remains authoritative in storage |

The approved printed number is also the canonical number returned by the API. The database retains the legal-entity ID/code snapshot, department and issuing-position IDs, and the full E.C. year as separate authoritative fields. Because the printed form uses a two-digit year, it is not independently sufficient to establish the century; use the stored full E.C. year and immutable document UUID for authoritative records. A future century rollover requires a governed numbering-format revision before a duplicate printed number can arise.

Allowed code characters: uppercase ASCII letters, digits, and the existing registry underscore convention. Hyphens and slashes are separators and are not permitted inside codes. Codes are assigned through governed registries; do not derive codes from free-text department or role names.

## 3. Document type registry

Initial proposed codes (Owner may approve or amend before implementation):

| Document type | Code | Notes |
|---|---|---|
| Letter | `LET` | Incoming/outgoing correspondence; direction and addressee are metadata |
| Memo / Memorandum | `MEM` | Internal formal communication |
| Legal instrument | `LEG` | Bylaw, resolution, agreement, legal notice, or other legal instrument; subtype is separate metadata |
| Invoice | `INV` | Issued invoice; financial posting and tax status remain controlled by Finance |
| Certificate | `CER` | Issued certificate with revocation/expiry status where applicable |
| Resolution | `RES` | Governance resolution if separate numbering is approved |
| Circular | `CIR` | Organization-wide or department circular |
| Report | `RPT` | Formal report |
| Contract / Agreement | `CON` | Executed or controlled agreement |
| Notice | `NOT` | Formal notice |
| Policy | `POL` | Approved policy |

Do not treat a document type as approved merely because a code exists. Each type needs its own approval-authority matrix, lifecycle, retention, and publication rules. In particular, invoices must reference the Finance domain's authoritative invoice/accounting records rather than independently inventing financial status.

## 4. Number allocation and uniqueness

The sequence is scoped by:

`tenant_id + type_code + department_code + issuing_role_code + full_ec_year`

Recommended storage:

- `documents`: immutable document UUID, canonical number, type, tenant/legal entity, department, issuing-role snapshot, E.C. year, sequence, linked FileManagement file/version, workflow status, issued/revoked timestamps, and audit references.
- `document_number_counters`: one row per printed-number scope, with the next sequence and a unique constraint on tenant, type code, department code, issuing-role code, and full E.C. year. Legal-entity and internal registry IDs remain on each document for audit but do not split a printed-number counter.
- A database unique constraint on the canonical number and a unique constraint on the counter scope.
- Allocate the counter and create the issued document in one database transaction. Lock the counter row (`SELECT ... FOR UPDATE` or the database-equivalent), increment it, insert the document, and commit together. The four-digit sequence fails closed after 9999.
- Never compute a sequence using `MAX(sequence) + 1`; that races under concurrent requests.
- Do not allocate a permanent number during draft creation. Allocate at the authorized issue transition. If a failure occurs before commit, the transaction rolls back. After successful issue, voided/cancelled numbers remain consumed and are never recycled.
- Idempotency key on the issue command prevents a client retry from issuing a second number.
- The application must use the platform's E.C. calendar service and a single captured issuance timestamp to determine `ec_year`; do not trust a client-supplied year.

## 5. Issuing role and department governance

- `DP` is the registered department/branch that owns the document, not the user's current department inferred at read time.
- `RL` is the issuing office/authority snapshot (e.g. CEO, CFO, HRM, SEC), checked against the approved document-type authority matrix at issuance.
- A person may act in a role only if the platform's Governance/Organization authority and effective dates authorize it. Acting authority must be recorded in issuance audit metadata.
- Renaming a department, role, or user must not change previously issued document numbers.
- Cross-tenant or cross-legal-entity issuance is rejected. Administrative overrides require explicit permission, reason, and immutable audit event.

## 6. QR verification and access control

### Public document

- QR URL points to the named, signed verification route, not a storage path or raw disk URL.
- The verification route verifies the URL signature and reloads the current FileManagement record. It returns only a safe verification projection: canonical document number, type, issuer organization, published/revoked/expired status, issue date, and version identity.
- Verification succeeds only while the linked document is explicitly published and public, not merely because a file was uploaded or its `is_public` flag was once set.
- Public download is a separate route and streams content only when the current record is public and published.
- Revoking publication, soft deletion, or changing the file to private makes public verification/download fail immediately, even if a previously generated QR URL is scanned.

### Private / restricted document

- QR points to the authenticated application document route. Scanning may lead to login, but access is then re-authorized against the current tenant, membership, document permission, and file permission.
- Never create a signed public download URL for private content. Do not place private names, tenant IDs, storage paths, or sensitive metadata in QR query parameters.
- A QR URL is a locator and integrity-protected route, not a substitute for authentication, authorization, encryption, or a digital signature over the document bytes.

### Integrity and audit

- For strong byte-level verification, calculate SHA-256 server-side at upload/issuance and persist it in a server-controlled integrity field, separate from user-editable metadata. Verification should compare the stored digest against the immutable issued version or a signed issuance manifest.
- QR scan/verification events should be rate-limited and audited with timestamp, outcome, document UUID, and request correlation ID. Avoid storing excessive scanner personal data.
- A formal cryptographic signature, certificate revocation status, and key rotation are a separate security design; the current signed URL proves route/query integrity, not who authored the document or that PDF bytes carry a legally recognized digital signature.

## 7. View contract

Every document view should render:

- canonical document number and document type;
- E.C. issue date/year and status;
- department/branch and issuing authority snapshot;
- current approved version and revision history where the viewer has permission;
- a QR code encoding the API-provided verification URL (public) or authenticated application URL (private);
- a human-readable fallback verification URL and a clear result page;
- a warning/status for revoked, expired, superseded, or unpublished documents.

The QR must be generated from the authoritative URL returned by the backend; the client must not assemble signatures or invent document IDs. If QR rendering is unavailable, display the URL and do not imply that a QR was generated.

## 8. Implementation sequence and acceptance gates

1. Approve type, department, role, legal-entity registries and the authority matrix.
2. Confirm E.C. calendar service API and define invoice/legal/certificate lifecycle owners.
3. Add Documents domain models, migration/counter table, unique constraints, and provider registration.
4. Implement transactional number issuance and idempotency; add audit events.
5. Link a document record to a specific immutable FileManagement version and server-calculated digest.
6. Implement public verification and private authenticated verification; add client QR rendering.
7. Verify concurrency, tenant isolation, role/delegation, revocation, QR tampering, and version immutability in CI before any production deployment.

No schema, migration, number allocation, or client QR-rendering implementation is implied by this design document.
