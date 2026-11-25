# Shareholder identity import preview contract

Status: implemented as a preview-only technical foundation; not an approved data import.

## Scope

The current adapter accepts UTF-8 CSV only and validates shareholder identity rows. It does not create, update, match, number, or soft-delete shareholder records. It does not write to the database or persist the uploaded source file.

Allowed fields:
- source_reference
- shareholder_type
- first_name_am, father_name_am, grandfather_name_am
- first_name_en, father_name_en, grandfather_name_en
- entity_name_am, entity_name_en
- representative_reference

Individual rows require Amharic first and father names. Legal-entity rows require an Amharic entity name and a representative source reference. Shareholder types are restricted to the existing ShareholderType enum.

## Safety controls

- 5 MiB maximum input size.
- 2,000 data-row maximum.
- UTF-8 validation and BOM handling.
- Duplicate and unknown header rejection.
- Strict field allowlist; share quantity, share class, subscription, capital and payment columns are rejected.
- Duplicate source references are flagged.
- Extra row values and invalid type/name combinations are rejected.
- Output explicitly reports preview-only mode, no writes, and no source-file storage.

## Deliberate limitations

- CSV is the only supported input adapter in this increment.
- No identity matching against the live shareholder register.
- No representative user resolution.
- No shareholding, share certificate, subscription, paid-up capital, or payment import.
- No persistence, API route, background job, or production database interaction.

A valid preview means only that the row is structurally eligible for manual reconciliation. It is not authority to create or amend a statutory register. A later implementation must reconcile authoritative corporate records, approvals, identity matches, and supporting evidence before any write path is designed or enabled.
