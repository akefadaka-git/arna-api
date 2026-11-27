# ARNA A2 Deterministic Scan — Evidence Artifact Manifest

**Evidence date:** 2026-11-27  
**Repository baseline:** API `9c0ce83afcc084101fb14e6e55d54e1d953c2ffe`; App `71bed2158894e3ce9cf507e8bf70492ac1f9dfd4`  
**Scan workflow:** https://github.com/akefadaka-git/arna-api/actions/runs/45975141734  
**GitHub Actions artifact ID:** `15187345405`  
**Original artifact SHA-256:** `46c7d28c52d9fec755ddb178f8a580e75af14379e6b3cf3d077574f785a693c4`  
**Artifact expiry recorded by GitHub:** 2026-12-04 09:36:48 UTC

## Repository evidence already committed

- `docs/architecture/ARNA-CORE-NSM-001-A2-DETERMINISTIC-SCAN-RECONCILIATION-2026-11-27.md`
- `docs/architecture/ARNA-CORE-NSM-001-A2-REFERENCE-INVENTORY-2026-11-27.md`
- `docs/architecture/ARNA-CORE-NAMESPACE-MIGRATION-001-v0.1.0.md`

## Full scan artifact contents

The original Actions ZIP contains:
- `scan-report.json`
- `identity-occurrences.csv`
- `scan-summary.md`
- `tracked-file-inventory.csv`

The reconciled local evidence set additionally contains:
- `reconciled-occurrences.csv` — all occurrence rows with primary reconciliation classifications.
- `reconciliation-file-summary.csv` — file-level reconciliation summary.
- `A2-deterministic-scan-reconciliation.md` — standalone reconciliation report.

## Scope and integrity

The scan covered 1,539/1,539 tracked blobs from the two frozen repository commits and reconciled 9,896 occurrence rows. The full scan artifact's SHA-256 above is the integrity reference for the original Actions artifact. Do not treat a regenerated archive as byte-identical unless its SHA-256 matches.

**Storage limitation:** This manifest records the exact GitHub Actions artifact locator and checksum. It does not itself embed the ZIP or the two large reconciled CSVs. The Actions artifact remains the authoritative binary download until a repository-side binary archival transfer is completed. Do not describe the binary archive as committed to Git merely because this manifest exists.

## Next evidence-preservation action

Before the Actions artifact expires, copy the original ZIP and reconciled CSV evidence into a versioned repository evidence location or another Owner-approved durable store. Preserve the checksum and verify the copied bytes. Do not alter `main` or merge the documentation PR as part of that transfer.
