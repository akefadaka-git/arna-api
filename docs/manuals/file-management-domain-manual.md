# ARNA File Management Domain Manual

**Audience:** System administrators, records officers, developers, security reviewers and application operators  
**Status:** Draft operational and technical manual

## 1. Purpose and ownership

File Management is the authoritative service for binary content and file lifecycle. It owns:
- binary upload and storage;
- file metadata and classification;
- immutable file versions;
- server-side content digest (SHA-256);
- access control and tenant isolation;
- malware scanning, quarantine and safe download/preview;
- retention, legal hold and controlled deletion;
- audit history for file access and mutations.

File Management is not the formal document registry and must not allocate document numbers.

## 2. Relationship with Documents

Documents owns formal document records, EE/DD/TT numbering, workflow, approval/issuance, QR verification and document status.

The canonical printed number is `ማዓ/EE/DD/TT/YYYY/NNNN`. File Management may store this number as a read-only association or searchable metadata supplied by Documents after issuance. It must not generate, normalize, change, or independently validate numbering scope.

A formal document references:
- the tenant;
- the exact File Management file ID;
- the immutable file version;
- the server-computed SHA-256 digest captured at issuance.

The same-tenant relationship must be enforced by database constraints and service authorization, not only by UI filtering.

## 3. Upload and version lifecycle

1. Authenticate the actor and resolve the tenant/resource scope.
2. Validate size, allowed content type, extension/content signature and upload policy.
3. Store the upload in a quarantine or pending-scan state.
4. Run malware scanning and any required content safety checks.
5. Compute SHA-256 server-side; never trust a client-supplied digest.
6. Publish the file version only after required checks pass.
7. Keep each version immutable. A replacement creates a new version; it does not mutate the bytes of an earlier version.
8. Record actor, timestamp, source, scan outcome and relevant audit metadata.

## 4. Preview, download and readers

- Enforce tenant, resource and permission checks on every preview/download request.
- Use safe rendering or sandboxed conversion for PDF, office and image previews.
- Do not serve active content inline from a privileged origin.
- Apply page, memory, time and file-size limits to parsers and OCR consumers.
- Use short-lived authorized access paths where needed; do not expose storage credentials or permanent private object URLs.
- Ensure temporary files are cleaned up after successful and failed processing.

## 5. Integrity and formal issuance

At document issuance, Documents must bind an exact immutable file version and the SHA-256 digest returned by File Management. Later file replacement must not change the issued record's file reference or digest.

A QR verification request is handled by Documents' verification service. File Management may provide the exact verified version through a controlled access interface, but it does not decide whether the formal document is valid, revoked, expired or replaced.

## 6. Retention, legal hold and deletion

Apply the approved retention schedule and legal-hold policy. Do not physically delete a file version referenced by an issued formal record unless a separately authorized retention/legal process permits it and the audit trail remains intact. Soft deletion is not equivalent to erasure of legal evidence.

## 7. Security and audit requirements

- Tenant isolation must be enforced at query, service and database boundaries.
- Access to files must be authorized against the owning resource, not merely possession of a file ID.
- Log upload, scan, quarantine, release, preview, download, version creation, retention changes and deletion.
- Do not place private document contents, personal data or storage secrets in public QR payloads.
- OCR and indexing are separate consumers; they must use the canonical File Management contract and must not create duplicate file models.

## 8. Operator checklist

- [ ] Tenant and resource authorization confirmed
- [ ] Upload policy and file signature validated
- [ ] Malware scan completed and passed
- [ ] SHA-256 computed server-side
- [ ] Immutable version recorded
- [ ] Preview/download path is authorized and safe
- [ ] Documents reference exact version and digest
- [ ] Retention/legal-hold policy applied
- [ ] Audit event persisted

## 9. Related standards

See `docs/standards/arna-entity-department-document-type-code-register.md` and `docs/manuals/documents-domain-manual.md`. The Documents Domain is the only owner of formal document numbering and authenticity status.
