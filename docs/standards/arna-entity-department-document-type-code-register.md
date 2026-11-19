# ARNA Entity, Department and Document-Type Code Register

**Standard:** ARNA-DOC-STD-001  
**Version:** 1.0-draft  
**Status:** Proposed controlled register — Owner ratification and legal-entity verification required  
**Applies to:** Documents Domain and File Management Domain

## 1. Canonical document number

`ማዓ/EE/DD/TT/YYYY/NNNN`

| Segment | Width | Meaning | Authority |
|---|---:|---|---|
| `ማዓ` | fixed | Maale Arna organization prefix | Organization identity |
| `EE` | 2 digits | Legal entity / tenant code | Organization legal-entity registry |
| `DD` | 2 digits | Issuing department or office code | Organization-unit registry |
| `TT` | 2 digits | Document type | Documents type catalogue |
| `YYYY` | 4 digits | Full Ethiopian Calendar year | Canonical Ethiopian calendar service |
| `NNNN` | 4 digits | Annual sequence, 0001–9999 | Documents numbering allocator |

Example only: `ማዓ/01/03/03/2019/0001` means organization prefix / entity 01 / department 03 / document type 03 / EC year 2019 / sequence 0001.

The full EC year is mandatory in the printed identifier and database. Two-digit years are prohibited. The year is resolved from the issuance instant in `Africa/Addis_Ababa`, using the canonical Ethiopian calendar implementation.

## 2. Legal-entity (EE) register

EE identifies a legal entity, not a department, branch, project, brand, or software tenant label. A tenant may contain one or more legal entities. A legal entity receives one stable two-digit code within its tenant.

| EE | Legal entity registered name | Registration identifier | Tenant | Status |
|---|---|---|---|---|
| 01–99 | Assign only after verifying the registered legal name and evidence | Required | Required | Unassigned |

**No legal-entity code is activated by this draft.** The repository context does not establish a complete authoritative list of currently registered ARNA legal entities or their registration identifiers. Do not infer a legal name from a project name, trading name, proposed PLC name, or historic shorthand. Populate this table from incorporation/registration documents and the canonical Organization legal-entity records, then obtain Owner approval. Codes are never recycled after retirement.

## 3. Department and office (DD) starter catalogue

The following is a proposed functional catalogue for organizational setup, not a claim that every department currently exists. Activate only units present in the approved organization structure. Codes are stable within each legal entity; do not reuse retired codes.

| DD | Amharic label | English label | Activation rule |
|---|---|---|---|
| 01 | የቦርድ ጽሕፈት ቤት | Board Secretariat | If formally established |
| 02 | ዋና ሥራ አስኪያጅ ጽሕፈት ቤት | General Management Office | If formally established |
| 03 | ፋይናንስ ክፍል | Finance Department | If formally established |
| 04 | የሰው ሀብት ክፍል | Human Resources | If formally established |
| 05 | ግዢና አቅርቦት ክፍል | Procurement and Supply | If formally established |
| 06 | ሕግና ተገዢነት ክፍል | Legal and Compliance | If formally established |
| 07 | ኦፕሬሽን ክፍል | Operations | If formally established |
| 08 | ሽያጭና ግብይት ክፍል | Sales and Marketing | If formally established |
| 09 | ቴክኖሎጂና ዲጂታል አገልግሎት | Technology and Digital Services | If formally established |
| 10 | ውስጥ ኦዲትና ሥጋት | Internal Audit and Risk | If formally established |
| 11 | ሰነድና መዝገብ ቤት | Records and Registry Office | If formally established |
| 12 | ፕሮጀክት አስተዳደር | Project Management Office | If formally established |
| 13–99 | Reserved | Reserved | Assigned only by controlled change |

A department code is not an approval authority. Approval/signing permissions are separately governed by approved delegations and authority records.

## 4. Document type (TT) catalogue

These codes preserve the A3.2 legacy symbolic mapping for 01–11 and add 12–15 for the A1-required finance document classes.

| TT | Key | Amharic | English | Class |
|---|---|---|---|---|
| 01 | LET | ደብዳቤ | Letter | Correspondence |
| 02 | MEM | ማስታወሻ | Memorandum | Correspondence |
| 03 | LEG | ሕጋዊ ሰነድ | Legal instrument | Legal |
| 04 | INV | የክፍያ መጠየቂያ | Invoice | Finance |
| 05 | CER | ማረጋገጫ ወረቀት | Certificate | Certificate |
| 06 | RES | ውሳኔ | Resolution | Governance |
| 07 | CIR | ሰርኩላር | Circular | Correspondence |
| 08 | RPT | ሪፖርት | Report | Reporting |
| 09 | CON | ውል | Contract | Legal |
| 10 | NOT | ማስታወቂያ | Notice | Correspondence |
| 11 | POL | ፖሊሲ | Policy | Governance |
| 12 | PUR | የግዢ ትዕዛዝ | Purchase order | Finance |
| 13 | EXP | የወጪ ትዕዛዝ | Expense order | Finance |
| 14 | INC | የገቢ ትዕዛዝ | Income order | Finance |
| 15 | RCT | ደረሰኝ | Receipt | Finance |
| 16–99 | — | የተያዘ | Reserved | Reserved |

Certificate subtypes (shareholder, appreciation, award, loyal customer, and similar) are metadata subtypes under CER/05. They do not receive separate numbering series unless separately approved. Finance document records must link to canonical Finance transactions; this catalogue is not a ledger.

## 5. Numbering scope and database uniqueness

The sequence scope is:

`tenant_id + EE + DD + TT + full_ec_year`

The database must enforce:
- unique entity code within a tenant: `(tenant_id, EE)`;
- unique department code within an entity: `(tenant_id, legal_entity_id, DD)`;
- unique counter scope: `(tenant_id, EE, DD, TT, ec_year)`;
- unique issued sequence: `(tenant_id, EE, DD, TT, ec_year, sequence_number)`;
- unique complete printed number: `(tenant_id, canonical_number)`;
- immutable code assignments and historical number snapshots.

Allocation must be transactional and concurrency-safe. The sequence must fail closed after 9999. No code or sequence is reassigned to another issuer. A change of department name does not change its code. A department transfer or legal-entity change must not rewrite historical numbers.

## 6. Governance and change control

- Organization owns legal-entity and department registries.
- Documents owns the document-type catalogue, numbering policy, allocation and issued-number history.
- File Management owns binary files, metadata, immutable file versions, hashes and storage access.
- File Management does not allocate, modify, or independently interpret formal document numbers.
- New codes require a documented request, duplicate check, approval, effective date and audit event.
- Retired codes remain reserved permanently.
- No production code activation until legal/entity evidence and approved organization structure are reconciled.

## 7. Implementation note

The current A3.2 draft formatter and migration still implement the earlier `BB/TT/SSSS/YY` shape. This standard intentionally supersedes that draft. The migration, allocator, formatter, models, constraints, and tests must be updated to EE/DD/TT/YYYY/NNNN before A3.2 can be considered implementation-complete or sent to A4. Do not deploy this draft standard as though that code change has already occurred.
