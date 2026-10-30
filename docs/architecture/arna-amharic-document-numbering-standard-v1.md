# የዓርና የሰነዶች ቁጥር አሰጣጥ መመዘኛ
## ARNA Document Numbering Standard — Design v1.0

**Status:** Owner-approved design decisions documented; implementation is pending.
**Applies to:** ARNA Documents domain and all ARNA organizational units that register or issue controlled documents.
**Language:** Amharic-first. The canonical visible prefix is the Amharic brand name `ዓርና`.
**Change control:** This document records the approved design choices. Any change to segment meanings, widths, sequence scope, or calendar rules requires explicit Owner approval.

## 1. Purpose and principles

This standard defines one compact, consistent, Amharic-first reference format for ARNA-controlled documents. It is designed for clear correspondence, filing, retrieval, auditability, and long-term records preservation.

The number uses the ARNA name in Amharic; all variable components are numeric. No English document-type abbreviations, branch abbreviations, department abbreviations, or issuing-role abbreviations appear in the visible number.

The numbering system is an internal ARNA design. This document does not claim that the exact syntax is prescribed or certified by Ethiopian national law or a government-wide directive. Applicable sector-specific requirements (including tax, financial, regulatory, and external filing requirements) take precedence where they prescribe additional identifiers or formats.

## 2. Canonical visible format

`ዓርና/BB/TT/SSSS/YY`

Illustrative example:

`ዓርና/11/03/0001/19`

| Segment | Width | Meaning |
|---|---:|---|
| `ዓርና` | Fixed | ARNA identity in Amharic |
| `BB` | 2 digits | Combined branch and department code: first digit is branch; second digit is department |
| `TT` | 2 digits | Controlled numeric document-type code |
| `SSSS` | 4 digits | Sequential number within the approved counter scope |
| `YY` | 2 digits | Last two digits of the Ethiopian Calendar year |

Only the fixed Amharic identity contains letters. Every variable segment is numeric. Forward slashes are the canonical separators. No spaces, Latin letters, localized digit glyphs, or alternative separators are permitted in the canonical machine identifier. User interfaces and printed documents may render labels and dates in Amharic; the canonical identifier remains exactly as defined above.

## 3. Branch and department code

The two-digit `BB` segment is deliberately fixed at one digit for branch and one digit for department:

- First digit: branch code, 1–9.
- Second digit: department code within that branch, 1–9.

Examples:
- `11`: branch 1, department 1.
- `12`: branch 1, department 2.
- `21`: branch 2, department 1.
- `23`: branch 2, department 3.

Codes are assigned and maintained by the authoritative Organization domain. They must not be caller-supplied free text. A branch/department code must resolve unambiguously to an active organizational unit in the current tenant and legal-entity context.

**Capacity constraint:** This approved two-digit design supports at most nine numbered branches and nine numbered departments per branch. The system must reject zero, unknown, inactive, or unassigned codes. Expanding beyond this capacity requires a separately approved versioned format change; do not silently widen the segment or reinterpret existing numbers.

## 4. Document-type code

The `TT` segment is a two-digit numeric code from a centrally governed Documents document-type catalogue. Codes are stable identifiers, not labels or user-entered values. The catalogue must retain each code's Amharic name, definition, lifecycle rules, applicable authority action, active/retired status, and effective dates.

A code must never be reassigned to a different document type after it has been used. Retired types remain resolvable for historical records. New types require an authorized catalogue change.

The example value `03` in `ዓርና/11/03/0001/19` illustrates a document-type code; it does not, by itself, approve a particular Amharic document type for code 03. The final code-to-type catalogue must be maintained as a controlled register and approved through ARNA governance before production use.

## 5. Sequence and counter scope

The Owner selected **unit-level sequence scope**. A distinct sequence is maintained for each combination of:

1. Tenant;
2. Legal entity;
3. Branch/department (the resolved Organization unit represented by `BB`);
4. Document type (`TT`); and
5. Full Ethiopian Calendar year.

The counter is therefore independent for each issuing unit, document type, and Ethiopian year, within the tenant and legal-entity boundary. The issuing Governance position or individual issuer is authority metadata, not a counter-scope dimension. Changing the issuer does not create a new sequence for the same unit/type/year.

Sequence starts at `0001` for each new scope/year and increments by one. The four-digit segment supports values `0001` through `9999`. If the next value would exceed `9999`, issuance must fail closed and require an explicitly approved capacity/format change; it must not wrap, truncate, or reuse a number.

A number is allocated only during successful official issuance, not while drafting, editing, uploading, or submitting a draft for review.

## 6. Ethiopian Calendar year

The visible `YY` segment is the final two digits of the Ethiopian Calendar year. The authoritative year is the full four-digit Ethiopian year, derived server-side from the captured issuance timestamp using the platform's approved Ethiopian Calendar service and the Africa/Addis_Ababa time zone.

For example, full year 2019 E.C. is displayed as `19`. The database, audit record, and counter scope must retain `2019`, not only `19`. The client must not choose or submit the authoritative year.

The full year is essential for archival retrieval and counter uniqueness. The two-digit display year is a compact presentation component only.

## 7. Issuance, uniqueness, and lifecycle

- Number allocation, canonical document creation, and the issuance lifecycle event must commit atomically in one database transaction.
- Counter allocation must be concurrency-safe, using a unique scope key and transactional row locking or an equivalently proven database mechanism.
- A tenant-scoped idempotency key is required. Repeating the same request returns the persisted issuance; reusing the key with a different canonical payload conflicts.
- The system must validate the tenant, legal entity, active organizational unit, document type, issuer identity, and effective Governance authority before allocation and revalidate authoritative records inside the issuance transaction.
- A failed transaction must not leave a partially issued document or consume a sequence.
- Once an official number has been issued, it is permanent. Void, revoke, cancellation, or supersession must retain the original number and append an auditable lifecycle event. Numbers are never recycled.
- Drafts do not receive official numbers. A superseding document receives a new number and a link to the prior record.
- Canonical numbers must be unique within the tenant. The counter scope and full year must also be stored as structured fields, not inferred only by parsing the printed number.

## 8. Incoming, outgoing, and internal records

**Outgoing documents:** ARNA assigns the canonical number at official issuance using the issuing unit's `BB` code and the registered document type.

**Incoming correspondence:** Preserve the sender's original reference exactly as received. Separately assign an ARNA internal registration reference under the approved incoming-registration procedure. Do not represent the sender's number as an ARNA-issued number.

**Internal documents:** Use the same canonical format when they are formally registered or issued as controlled documents. Informal working notes and drafts do not receive official numbers unless a specific records policy requires registration.

Where an external authority, tax regime, bank, court, or regulator requires its own identifier, retain that identifier as a separate external/reference field. It does not replace or alter the ARNA canonical number unless the applicable requirement expressly requires otherwise.

## 9. Registry ownership and separation of concerns

- **Documents domain:** owns the numbering policy implementation, document-type catalogue, counter ledger, canonical document number, issuance transaction, idempotency, and lifecycle events.
- **Organization domain:** owns branch/department identity and organizational-unit codes.
- **Finance domain:** owns legal-entity identity and status.
- **Governance domain:** owns issuing positions, authority grants, acting assignments, and effective authority.
- **Platform calendar service:** owns authoritative Ethiopian-calendar conversion using the captured Addis Ababa issuance timestamp.

The Documents domain stores foreign keys and immutable issuance-time snapshots needed for audit. It must not become a second source of truth for the owning domains' registries.

## 10. Amharic presentation

The official document header should present the identifier with an Amharic label, for example:

- **ቁጥር፦** ዓርና/11/03/0001/19
- **ቀን፦** ____ / ____ / 2019 ዓ.ም.
- **ለ፦** __________________
- **ከ፦** __________________
- **ጉዳዩ፦** ________________

The date printed on the document is a separate full Ethiopian-calendar date. It must not be inferred from the two-digit number suffix alone.

## 11. Validation and rejection rules

Reject issuance if any of the following is true:

- Branch or department digit is zero, unknown, inactive, or does not resolve to the issuing unit.
- Document-type code is unknown, inactive for new issuance, or not valid on the issuance date.
- Sequence is outside `0001`–`9999`, already allocated, or cannot be allocated atomically.
- Ethiopian year cannot be authoritatively derived from the captured issuance timestamp.
- Tenant, legal entity, organizational unit, file/document state, issuer, or Governance authority is missing, ambiguous, inactive, expired, revoked, or inconsistent.
- A numbering-scope snapshot has drifted and has not been reconciled through an explicitly approved process.

## 12. Migration and compatibility

This standard defines the target numbering design; it does not retroactively rewrite historical numbers. Existing issued identifiers remain immutable and searchable.

The currently documented/source-implemented format may differ from this standard until a separately authorized implementation change is completed and verified. During that interval, documentation and release notes must identify the mismatch clearly. Do not claim that the target format is implemented merely because this design has been approved.

Before implementation, reconcile the database counter key, model validation, formatter/parser, API contracts, idempotency payload, tests, fixtures, documentation, and any downstream integrations against this standard. No migration or code change is authorized by this document alone.

## 13. Approval record

Owner decisions recorded for this design:

- Amharic-first ARNA identity: `ዓርና`.
- All variable number segments are numeric.
- Visible format: `ዓርና/BB/TT/SSSS/YY`.
- Branch/department width: two digits total, one digit each.
- Sequence scope: issuing unit + document type + Ethiopian year, bounded by tenant and legal entity.
- Sequence width: four digits.
- Visible Ethiopian year: two digits; authoritative stored year: four digits.
- Documentation first; no source-code, migration, database, workflow, deployment, or merge action is authorized by this design approval.

**Implementation status:** NOT IMPLEMENTED / NOT RUNTIME VERIFIED.
