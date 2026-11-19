# ARNA document evidence storage controls — production gate

Status: A4-R02 design specification only. This document does not configure or deploy a production storage volume and does not claim the current local evidence disk is administrator-resistant WORM.

## Current implementation boundary

A3.3.2 stores immutable snapshots on a local filesystem using content-addressed paths, exclusive temporary creation, atomic no-overwrite hard-link creation, SHA-256 verification, read-only file mode (0400), and read/execute-only version directories (0500). Database evidence rows are append-only and issuance re-hashes the stored object. Disks without local path and atomic no-overwrite semantics fail closed.

These controls defend against ordinary application-level writes and accidental mutation. They do not prevent a privileged host or filesystem administrator from changing permissions, replacing files, altering the mount, or destroying both primary data and local backups. File mode bits are not WORM.

## Required production topology

Before production enablement, the Owner must approve a dedicated evidence-storage design with:

1. A dedicated filesystem or storage service, separate from application uploads, temporary files, deployment artifacts, and application runtime write locations.
2. A narrowly scoped evidence-writer identity. The API runtime must not have general delete, rename, permission-change, mount, snapshot-retention, or administrator privileges.
3. A separate storage-administrator identity and separately controlled break-glass procedure. Routine application operators must not possess both application and storage-administration authority.
4. Write-once retention where supported (immutable snapshots, object-lock retention, or WORM policy), with retention duration, legal hold, expiry, and exception authority explicitly approved. A future object-store adapter must use provider-enforced conditional create and independently verified retention; an object ACL alone is insufficient.
5. Independent integrity monitoring: periodic inventory of evidence IDs, paths, sizes and SHA-256 values; alert on missing, replaced, permission-changed, or hash-mismatched objects; append monitor results to a separately protected audit destination.
6. Encrypted backups in a separate failure and administrative domain, with immutable retention and documented recovery-point/recovery-time objectives.
7. Restore testing into an isolated environment, including verification of every restored hash and referential consistency with document_file_versions, issued documents, and signature evidence.
8. Operational logging for privileged storage changes, retention-policy changes, backup deletion, restore activity, and break-glass use.

## Acceptance evidence required

The A5 production gate must contain inspectable evidence for:
- Owner-approved storage architecture and threat model;
- filesystem/provider identity, mount and privilege configuration independently reviewed;
- proof that the API identity cannot delete, overwrite, chmod, rename, or bypass retention for sealed evidence;
- WORM/immutable-retention policy and an adversarial retention test, or explicit Owner-approved residual-risk acceptance if WORM is unavailable;
- monitoring configuration plus demonstrated tamper/missing-object alert;
- backup policy, independent credentials, successful restore run, and post-restore SHA-256 verification report;
- incident and break-glass runbook, named accountable roles, and evidence-retention schedule.

A successful application-level test is not a substitute for these infrastructure controls.

## Current disposition

- Application-level content-addressed snapshot and hash verification: IMPLEMENTED.
- Database evidence append-only protections: IMPLEMENTED and covered by the A3.3.2 MySQL/SQLite test suite.
- Dedicated production volume, privileged-administrator separation, WORM retention, monitoring, backup/restore and independent acceptance: NOT IMPLEMENTED / NOT VERIFIED.
- Production storage configuration or migration: NOT AUTHORIZED by this remediation.

No production data or storage configuration is changed by this design document.
