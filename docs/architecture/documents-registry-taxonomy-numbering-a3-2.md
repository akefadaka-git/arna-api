# ARNA Documents registry, taxonomy and numbering — A3.2

**Implementation baseline:** A3.1 branch head `8083050f1362e7cf90b3ea67610397f6ad294f25`  
**A2 source finding preserved:** PR #53 introduced both `document_number_counters` and `document_number_counters_v2`; its two-stage migration pair and residual old document uniqueness constraints are not imported.  
**Status:** implementation contract and source changes; migrations/tests not yet executed.

## 1. Canonical number

`ዓርና/BB/TT/SSSS/YY`

- `BB`: two-digit Organization-owned branch/department code (digits 1–9 only)
- `TT`: immutable two-digit Documents taxonomy code
- `SSSS`: sequence from 0001 through 9999
- `YY`: final two digits of the full Ethiopian Calendar year
- Full `ec_year` is stored as an integer; never infer it from `YY`
- Issuance time is captured in `Africa/Addis_Ababa` and converted by the canonical EthiopianCalendar service

## 2. One authoritative counter scope

The printed number exposes only tenant, BB, TT, sequence and YY. The authoritative counter scope is therefore:

`tenant_id + bb_code + document_type_number + full_ec_year`

The legal entity and organization unit are mandatory issuer context and are stored on the assignment and document. They do not create parallel sequence streams hidden from the printed identifier. A unique tenant/BB assignment prevents the same visible BB code from being assigned to multiple entity/unit combinations in one tenant.

This resolves a collision inherent in the earlier v2 proposal: separate entity/unit counters could independently allocate the same visible `BB/TT/SSSS/YY`. The v2 entity/unit scope is therefore not retained as the counter key.

## 3. Matching database uniqueness contract

- `document_numbering_assignments`: unique `(tenant_id, bb_code)`; unique `(tenant_id, legal_entity_id, organization_unit_id)`
- `document_number_counters`: unique `(tenant_id, bb_code, document_type_number, ec_year)`
- `documents`: unique `(tenant_id, bb_code, document_type_number, ec_year, sequence_number)`
- `documents`: unique `(tenant_id, canonical_number)` as a final guard against the two-digit year repeating over a century
- `documents`: unique `(tenant_id, idempotency_key)`
- Tenant-bound foreign keys bind entity, organization unit, BB assignment, replacement and lifecycle records

The database constraints, not application preflight alone, are authoritative.

## 4. Counter allocation

Allocation is only permitted inside the same database transaction that creates the issued document. The allocator locks the BB assignment and counter row, increments one counter and returns the canonical number. A caller must roll back the whole issuance transaction if document creation fails. Four-digit exhaustion fails closed; there is no silent rollover.

## 5. Controlled taxonomy v1

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

Existing symbolic keys 01–11 are preserved. 12–15 are added for A1-required finance record types. Issued numeric codes are immutable; deactivation does not release a code for reuse. Certificate subtypes (shareholder, appreciation, award, loyal customer) remain metadata/type subcategories under CER, not separate numbering series unless separately approved.

## 6. A2 migration conflict handling

Do not merge the PR #53 migration pair:
- `2026_10_29_000001_create_documents_numbering_tables.php`
- `2026_10_30_000001_create_document_numbering_v2_scope.php`

The A3.2 clean-install migration creates the catalogue, issuer assignment, one canonical counter, documents and lifecycle tables in one schema definition. It does not create `document_number_counters_v2`, and it does not retain the old uniqueness key containing department/issuing-role in place of the visible BB scope.

If either old migration has ever been applied to a real database, this clean-install migration is not an upgrade path. A separately reviewed forward-only data-preserving reconciliation migration is required before deployment. No destructive drop/rename is authorized here.

## 7. Deferred

Formal approval authority, signing, full lifecycle workflow, QR verification, public release, file digest enforcement, OCR and Finance posting remain later A3 work. Invoice/order/receipt records must link to canonical Finance transactions; this registry is not a shadow ledger.

## 8. Verification gate

A4 must test clean-install MySQL, schema order, assignment uniqueness, cross-tenant and cross-entity isolation, exact canonical uniqueness, Ethiopian year rollover, leap/Pagume boundary, 9999 exhaustion, concurrent allocation, idempotent retries, rollback/no-gap behavior and historical numbering immutability. None is claimed passed by this source commit.
