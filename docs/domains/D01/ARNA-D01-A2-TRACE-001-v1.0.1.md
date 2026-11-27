# ARNA D01 — A2 Inventory and Traceability Matrix Errata

- **Record ID:** ARNA-D01-A2-TRACE-001
- **Version:** 1.0.1
- **Type:** Controlled arithmetic correction to v1.0.0
- **Prepared:** 2026-11-24
- **Parent record:** `ARNA-D01-A2-TRACE-001 v1.0.0`
- **Parent commit:** `f9e5fda2360b2f4d44f1a13efe2ac318b40b70df`

## Correction

Section 5 of v1.0.0 contains an arithmetic typo in the traceability summary. The 24 matrix rows and their individual statuses are unchanged.

The correct summary is:

| Measure | Correct result |
|---|---:|
| D01 A2 requirements traced | 24 / 24 |
| Requirements with some supporting source evidence (PARTIAL) | 16 |
| Requirements PARTIAL | 16 |
| Requirements MISSING | 7 |
| Requirements UNRESOLVED | 1 |
| Requirements VERIFIED-CLOSED | 0 |

The phrase “Requirements with some supporting source evidence” is the same 16-item PARTIAL category and must not be added to the PARTIAL count as a separate category.

The original v1.0.0 remains preserved as issued. This errata controls interpretation of its Section 5 summary. No source, application code, schema, migration, test or production data changed.

**D01 A2 remains ACTIVE — PARTIAL — NOT VERIFIED-CLOSED.** No A3, merge, A4 or A5 authorization is created by this correction.
