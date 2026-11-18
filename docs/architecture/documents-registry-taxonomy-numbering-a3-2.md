# ARNA Documents registry, taxonomy and numbering — A3.2

**Implementation baseline:** A3.1 branch head `8083050f1362e7cf90b3ea67610397f6ad294f25`  
**Current numbering standard:** ARNA-DOC-STD-001, `ማዓ/EE/DD/TT/YYYY/NNNN`  
**A2 source finding preserved:** PR #53 introduced both `document_number_counters` and `document_number_counters_v2`; its conflicting migration pair and residual old document uniqueness constraints are not imported.  
**Status:** EE/DD/TT/full-EC-year schema, allocator and formatter implementation committed; independent exact-SHA verification pending.

## 1. Canonical number

`ማዓ/EE/DD/TT/YYYY/NNNN`

- `EE`: two-digit registered legal-entity/tenant code.
- `DD`: two-digit issuing department or office code within the legal entity.
- `TT`: immutable two-digit Documents taxonomy code.
- `YYYY`: full Ethiopian Calendar year, never a two-digit suffix.
- `NNNN`: annual sequence from 0001 through 9999.

Example: `ማዓ/01/03/03/2019/0001`. This is illustrative only; entity and department codes must be activated from approved registries.

Issuance year is resolved from the issuance instant in `Africa/Addis_Ababa` by the canonical EthiopianCalendar service. The full year is stored as an integer and printed in full.

## 2. Registry ownership

- Organization owns the legal-entity (EE) and department/office (DD) registries.
- Documents owns the document-type (TT) catalogue, numbering policy, allocator and issued-number history.
- File Management owns binary storage, metadata, immutable versions, hashes and access controls; it does not allocate or change formal document numbers.
- Department codes do not confer approval or signing authority. Authority is separately governed by approved delegation records.

The legal-entity list must be populated from verified registration documents and canonical Organization records. This repository evidence does not establish a complete authoritative list of registered ARNA legal entities. Therefore no entity code is activated by this draft. The department catalogue in `docs/standards/arna-entity-department-document-type-code-register.md` is a proposed starter list; activate only departments in the approved organization structure. Codes are immutable and never reused.

## 3. Controlled document taxonomy v1

| TT | Legacy key | Amharic label | English label |
|---|---|---|---|
| 01 | LET | ደብዳቤ | Letter |
| 02 | MEM | ማስታወሻ | Memorandum |
| 03 | LEG | ሕጋዊ ሰነድ | Legal instrument |
| 04 | INV | የክፍያ መጠየቂያ | Invoice |
| 05 | CER | ማረጋገጫ ወረቀት | Certificate |
| 06 | RES | ውሳኔ | Resolution |
| 07 | CIR | ሰርኩላር | Circular |
| 08 | RPT | ሪፖርት | Report |
| 09 | CON | ውል | Contract |
| 10 | NOT | ማስታወቂያ | Notice |
| 11 | POL | ፖሊሲ | Policy |
| 12 | PUR | የግዢ ትዕዛዝ | Purchase order |
| 13 | EXP | የወጪ ትዕዛዝ | Expense order |
| 14 | INC | የገቢ ትዕዛዝ | Income order |
| 15 | RCT | ደረሰኝ | Receipt |

Existing symbolic keys 01–11 are preserved; 12–15 add A1-required finance document classes. Certificate subtypes remain metadata under CER. Finance documents must link to canonical Finance transactions; this catalogue is not a shadow ledger.

## 4. Canonical counter scope and uniqueness

The sequence scope must be:

`tenant_id + EE + DD + TT + full_ec_year`

Matching constraints:
- unique `(tenant_id, EE)` for legal-entity code assignment;
- unique `(tenant_id, legal_entity_id, DD)` for department code assignment;
- unique `(tenant_id, EE, DD, TT, ec_year)` for counter scope;
- unique `(tenant_id, EE, DD, TT, ec_year, sequence_number)` for issued sequence;
- unique `(tenant_id, canonical_number)` for the complete printed identifier;
- tenant-bound foreign keys for entity, department, file and lifecycle references;
- immutable historical snapshots for the issuer, department, type, EC year and number.

The database constraints, not application preflight alone, are authoritative. Allocation must occur inside the issuance transaction, lock the exact counter row, be concurrency-safe, and fail closed after 9999. No silent rollover or code reuse.

## 5. A2 migration conflict handling

Do not merge the PR #53 migration pair:
- `2026_10_29_000001_create_documents_numbering_tables.php`
- `2026_10_30_000001_create_document_numbering_v2_scope.php`

The A3.2 clean-install schema must define one authoritative counter model and must not import both conflicting counter implementations. If either old migration has been applied to a real database, a separately reviewed forward-only data-preserving reconciliation migration is required before deployment. No destructive drop/rename is authorized.

## 6. Implemented schema and allocation contract

The A3.2 migration now creates:
- `document_entity_code_assignments`: one stable EE code per legal entity and one EE code per tenant;
- `document_department_code_assignments`: one stable DD code per issuing Organization unit within its legal entity;
- one `document_number_counters` table with tenant, legal entity, Organization unit, EE, DD, TT and full EC year;
- document snapshots containing EE, DD, TT, full EC year, sequence and canonical printed number.

Composite foreign keys bind code assignments to the same tenant, legal entity and Organization unit. The migration adds the required parent key for the legal-entity/Organization-unit pair and preserves the same-tenant File Management reference. The A2 PR #53 old/v2 counter migration pair remains excluded.

The allocator resolves EE and DD from active code-assignment records; callers cannot supply or override issuer codes. It validates active tenant/entity/unit/type, requires the issuance transaction, locks issuer and counter records, and fails closed on missing assignments or sequence exhaustion. The formatter emits exactly `ማዓ/EE/DD/TT/YYYY/NNNN`, with 01–99 issuer codes, a four-digit full EC year and a four-digit sequence.

No legal entity or department code is preassigned by this migration. Code assignments must be created only after the Organization registry and supporting legal evidence are approved.

## 7. Verification gate

A4 must verify clean-install MySQL migration order, composite foreign keys, entity and department code uniqueness, cross-tenant isolation, duplicate-number prevention, Ethiopian year rollover and Pagume boundary, 9999 exhaustion, concurrent allocation, transaction rollback, and immutable historical numbering. Unit and MySQL execution results must be tied to the exact commit SHA.
