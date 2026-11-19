# A3.1 — File Management domain boundary

**Status:** implementation draft on `feat/a3-1-file-management-boundary`  
**Base:** `dc2c53aa1b0b0d192acff53880848c9e517ecf67`  
**Scope:** API namespace ownership and dependency-integration foundation only.

## Canonical ownership

The canonical API namespace is:

- Model: `MaaleArna\\MASES\\Core\\Domains\\FileManagement\\Models\\File`
- Service: `MaaleArna\\MASES\\Core\\Domains\\FileManagement\\Services\\FileService`

The implementations now live under `mases_core/src/Domains/FileManagement/`. The old `Core\\Files` model and service names are compatibility aliases to those canonical classes. They are not independent implementations.

## Persistence and storage contract

- The existing `files` table remains the only file metadata table.
- The existing `database/migrations/2026_09_07_000012_create_files_table.php` remains the sole schema definition for that table.
- The existing `mases.files.disk` configuration and Laravel Storage abstraction remain the storage contract.
- Existing file IDs, paths, disk names, version relationships, soft-delete semantics, metadata, and tenant ownership are preserved.
- No second file table, storage root, file model, or service implementation is introduced.
- No migration was added or rewritten in A3.1.

## Authorization and audit contract

- File operations continue to use the platform `AuthorizationService`; no allow-all or domain-local authorization bypass is introduced.
- The canonical service remains bound as a singleton with the existing `AuditService` dependency.
- The legacy service FQCN is registered as a container alias to the canonical singleton.
- Existing permission slugs and tenant-membership behavior are unchanged by this namespace refactor.

## Consumer integration

The File API controller and People document-link / identity services now import the canonical File Management namespace. Existing tests and external integrations that still import `Core\\Files` remain source-compatible through aliases.

## Explicitly deferred

A3.1 does not implement or claim completion of:

- file schema redesign, immutable binary versioning, or SHA-256 digest persistence;
- document lifecycle, numbering, signatures, or public QR verification;
- malware scanning, sandboxed preview/conversion, retention, or legal hold;
- OCR workers or Document Intelligence integration;
- production authority grants or permission catalogue expansion;
- App changes, merge, or deployment.

These are separate A3 work items and A4 verification gates.

## Verification requirements

Before A3.1 can be marked verified:

1. Composer autoload must resolve both canonical classes and legacy aliases.
2. The container must resolve canonical and legacy service identifiers to the same singleton.
3. Model identity and table mapping must remain singular (`files`).
4. Existing file-security and People integration tests must pass.
5. A clean-install MySQL migration run must show no duplicate `files` table or migration.
6. Repository-wide search must show no unintended production imports of the legacy namespace.

Tests and migrations are not represented as run by this source change alone.
