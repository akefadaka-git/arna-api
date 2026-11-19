# ARNA Documents Domain Manual

**Audience:** Organization administrators, records officers, department heads, finance staff, auditors and system administrators  
**Status:** Draft operational manual; depends on approval of ARNA-DOC-STD-001

## 1. Purpose and ownership

The Documents Domain manages formal records from registration through review, approval, issuance, publication, verification, retention and revocation. It owns document identity, taxonomy, numbering, lifecycle, decisions and public verification status.

It is not a binary-file storage system and must not create a parallel Finance ledger.

## 2. Canonical document number

Use only:

`ማዓ/EE/DD/TT/YYYY/NNNN`

- EE: registered legal entity / tenant code.
- DD: registered issuing department or office code.
- TT: controlled document type code.
- YYYY: full Ethiopian Calendar year.
- NNNN: annual sequence from 0001 to 9999.

Never use a two-digit year. Never manually type or reserve a number outside the allocator. The number is allocated atomically during the authorized issuance transaction, not at draft creation.

## 3. Registry administration

Before issuance, administrators must confirm:
1. The tenant and legal entity are active and the EE code is approved.
2. The issuing department/office belongs to that legal entity and its DD code is active.
3. The TT code is active in the approved document-type catalogue.
4. The EC year is calculated from the issuance instant using the canonical Ethiopian calendar service and Addis Ababa timezone.
5. The user has the relevant create/submit/approve/issue permission. A department code does not itself confer approval authority.

Codes are stable identifiers. Renaming a legal entity or department does not rewrite prior records. Retired codes are never reused.

## 4. Document-type reference

| TT | Key | Amharic | English |
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

Certificate variants remain subtypes under 05/CER.

## 5. Issuance and integrity

- A draft has no final document number unless a separately approved reservation policy exists.
- The allocator must run in the same database transaction as issuance.
- Issuance binds the exact immutable File Management version and its server-computed SHA-256 digest.
- A replacement is a new formal record with a new number and a relation to the superseded record; never overwrite an issued record.
- Revocation/voiding requires an authorized actor, reason, timestamp and immutable audit event.
- QR codes contain a verification URL or opaque verification token, never private data, file credentials or direct storage paths.
- A QR scan alone is not proof of authenticity. The verification service must validate status, exact file version and digest, and disclose only approved public metadata.

## 6. Finance integration

Invoices, purchase orders, expense orders, income orders and receipts must reference the canonical Finance transaction or workflow object where applicable. The Documents Domain records the formal document and its lifecycle; it does not duplicate balances, journals, payments or accounting entries.

## 7. Records officer checklist

- [ ] Correct tenant and legal entity (EE)
- [ ] Correct issuing department/office (DD)
- [ ] Correct active document type (TT)
- [ ] Full EC year (YYYY), not GC year or two-digit year
- [ ] Approved workflow and authority evidence
- [ ] Correct final file version and SHA-256
- [ ] Issuance event and audit history
- [ ] QR verification points to the approved public verification endpoint
- [ ] Finance linkage present where required

## 8. Related standards

See `docs/standards/arna-entity-department-document-type-code-register.md` for the controlled EE/DD/TT catalogue, code governance and uniqueness contract. See the File Management manual for binary storage and version controls.
