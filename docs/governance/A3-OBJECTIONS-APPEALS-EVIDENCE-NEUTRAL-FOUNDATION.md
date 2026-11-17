# A3 objections and appeals — evidence-neutral foundation

**Approval:** Owner-approved A3 implementation  
**Legal evidence status:** BLOCKED  
**Implementation slice:** A3.1 intake identity, A3.2 private evidence storage, and A3.2 integrity ledger  
**Branch:** `feat/a3-election-objections-foundation`  
**Parent:** PR #151 head `582bee84e64deaee445439b580c6ab1b1523c82c`

## Scope implemented in this slice

- Preserve the existing election objection and appeal tables and add nullable receipt reference, submission timestamp, and SHA-256 submission digest fields. Nullable fields preserve compatibility with legacy rows.
- Add tenant/election/subject-scoped evidence metadata with a private storage disk/key, original filename, detected MIME type, byte size, SHA-256 digest, uploader, upload time, and pending/verified/quarantined/rejected verification state.
- Add a dispute event ledger with tenant/election/subject scope, actor, event payload, previous-event digest, event digest, and occurrence time.
- Implement a serialized event-ledger service: election-row locking prevents concurrent chain forks; versioned canonical JSON is hashed with tenant, election, subject, actor, event type, timestamp, and previous digest; verification replays the chain and detects payload/hash tampering.
- Implement private evidence storage: allowlisted detected MIME types, content-signature and filename-extension checks, bounded file size, server-generated object keys, forced private visibility, read-after-write SHA-256 verification, tenant membership validation for the uploader, transactional metadata/event writes, and object cleanup on failure. New evidence remains `pending`.
- Add uniqueness and lookup indexes for tenant-scoped receipt references, private storage object identities, event digests, and subject event history.
- Exercise tenant-scoped uniqueness: duplicate evidence storage identities and event digests are rejected within one tenant, while the same identity/digest remains valid in another tenant.
- Keep the clean-install inventory aligned with the two new tables and the additional migration record.

## Required application invariants before endpoints are exposed

1. Derive tenant and actor identity from authenticated server context; never accept either from request payload.
2. Resolve the election and dispute subject within the same tenant before reading or writing any related row.
3. Generate receipt references server-side. Compute submission digests from a canonical, versioned representation; do not hash unstable JSON serialization.
4. Store evidence only on private storage. Never return storage keys or direct public URLs to clients.
5. Verify actual file bytes, size, MIME/content signature, and SHA-256 after storage. Client-supplied MIME type and digest are untrusted.
6. Keep evidence pending until a trusted scanner and integrity verification have completed. No scanner verdict is currently produced by this slice; do not treat upload as accepted evidence.
7. Enforce append-only event writes through the ledger service; no update/delete endpoints. Hash-chain ordering is serialized by locking the election row. Chain verification detects later mutation, but database-level triggers/permissions preventing direct SQL edits remain a separate hardening gate.
8. Emit no decision, admissibility, standing, deadline, reviewer assignment, quorum, appeal entitlement, certification hold, or election outcome from this technical foundation.

## Explicitly blocked pending authoritative instruments

- Eligible objectors/appellants and standing.
- Grounds, filing periods, extensions, and admissibility criteria.
- Review/adjudication body, powers, member eligibility, conflicts, recusals, and quorum.
- Notice recipients, response periods, service rules, and evidentiary standards.
- Appeal authority, appeal window, finality, and available remedies.
- Whether any dispute suspends nomination, tally, certification, or GA ratification.

These values must remain unresolved. No defaults, legal conclusions, or automatic adverse outcomes may be introduced.

## Not included in this slice

- No public or authenticated HTTP endpoints.
- No public or authenticated evidence download route; upload is available only through the internal service and is not wired to an HTTP endpoint.
- No malware scanner integration or verification-state transition out of `pending`.
- No adjudication, decision, appeal-finality, certification, or ratification actions.
- No automatic invalidation of a nomination, ballot, tally, or election.
- No production migration execution, role grants, merge, or deployment.

## Verification gates

- Validate migration up/down against the supported MySQL and PostgreSQL versions.
- Test legacy rows with null receipt metadata and duplicate non-null receipt rejection within a tenant.
- MySQL CI covers schema constraints, private evidence upload/read-back hash verification, tenant-scoped uploader access, and event-chain tamper detection. PostgreSQL parity, scanner integration, direct-SQL immutability controls, and HTTP endpoint security remain open gates.
- Keep legal decision paths disabled until authoritative documents are reconciled and separately approved.
