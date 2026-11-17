# Historical Shareholder and Articles Reconciliation Notes

**Record type:** historical working notes; not a legal opinion, adopted policy, verified transcription, or proof of original-document custody.  
**Repository:** `akefadaka-git/arna-api`  
**Prepared:** 2026-11-18  
**Authority:** Owner's statements and prior conversation/repository-assessment summaries. Every item below retains its stated evidence limitation.

## 1. Original Articles of Association

### Owner-confirmed current position

- The Owner states that they possess the original Articles of Association.
- The document is an image-scanned PDF. This format is acceptable as the legal-source original; selectable text is not required.
- Uploading in ChatGPT is currently unavailable to the Owner. Do not repeatedly request re-upload while that limitation remains.
- The original PDF has not yet been deposited or verified in this repository.

### Historical conversation record

- A prior conversation record says the original Amharic Articles were supplied in a ChatGPT conversation on **2026-09-11**.
- The document was historically described as dated **16/12/2016 E.C.**, executed, notarized and sealed.
- These are historical descriptions, not current direct inspection findings. The original filename, binary, attachment identifier, byte size, page count, checksum and page-by-page condition are not currently verified.
- Earlier retrieval attempts did not recover the original binary from the available conversation/library results.

### Distinct document — do not substitute

A separate 17-page signed/stamped bylaws PDF was found in earlier work. Its historical filename was:

`የማሌ_ዓርና_አክሲዮን_ማኅበር_መተዳደሪያ_ደንብ.pdf`

It is a bylaws document, not the original Articles of Association. It must not be substituted for the Articles. Earlier notes said it was only partially reviewed; exact clauses and legal effect were not fully transcribed or verified.

## 2. Reported Articles clauses — provisional only

A prior assistant reconciliation summary attributed the following topics to the Articles. These references are **unverified until checked against the original scan**:

- Article 2: 145 shares at ETB 10,000 per share.
- Articles 4 and 5: shareholder rights and register-related matters.
- Article 8: proxy-related provisions.
- Article 9: majority and a 15-day certificate-deposit reference.
- Article 17: quorum described as more than half of capital.

Do not implement or enforce these summaries as legal rules until the original wording, surrounding clauses, amendments and applicable law have been checked. In particular, the precise meaning and scope of the Article 9 summary are not established here.

## 3. Historical capital and shareholder figures — unresolved

Earlier conversation/repository summaries reported:

| Item | Historical reported value | Status |
|---|---:|---|
| Founders | 29 | Historical report; reconcile to original documents and official register |
| Founder shares | 145 | Historical report; reconcile |
| Nominal value per share | ETB 10,000 | Historical report; verify against Articles and records |
| Aggregate share capital | ETB 1,450,000 | Arithmetic from 145 × ETB 10,000; legal/paid-up status not independently verified |
| Reported paid amount | ETB 1,023,000 | Historical report; supporting receipts/bank evidence not verified here |
| Reported unpaid amount | ETB 427,000 | Historical report; supporting evidence and legal treatment not verified here |

The arithmetic 145 × ETB 10,000 = ETB 1,450,000 is correct, but arithmetic does not establish issuance, ownership, payment, voting entitlement or current register status.

## 4. Shareholder register and application-data discrepancies

- The Owner previously stated that official shareholder-register pages **9–11** had already been uploaded in an earlier conversation. The current repository task has not recovered or inspected those page images.
- Prior implementation assessment reported **100 seeded entries** in application reference data, with categories `FOUNDERS` and `STANDARD`. These are software seed records, not proof of the official legal register.
- Prior notes reported a **29-founder / 145-share** historical figure. It must not be assumed that the 100 application entries represent 100 legally registered shareholders.
- Shareholder record **SH-0023** was reported as classified `religious_body` in one seed/reference context and as `cooperative` in an earlier context. This classification conflict remains unresolved. Do not silently choose either category.
- The exact reconciliation between the original Articles, official register pages 9–11, historical founder/share totals, current shareholder records and application seed data remains open.

## 5. Other historical source and data notes

- On 2026-10-21, the Owner stated that a new `arna` database had been created but no migrations had been run. That database must not be treated as historical source evidence.
- Earlier notes identified legacy SQL exports `maale_arna_smart_system(1).sql` and `maale_arna_smart_system.sql` as potential historical leads. Their contents must be inspected before relying on them; neither filename alone proves authority or completeness.
- Earlier assessment said the Memorandum of Association, signed Board/shareholder resolutions, bank mandates, and adopted procurement/sales policies had not been located or verified at that time. Their present availability is unknown.
- An earlier assessment reported `docs/PHASE_2_SHAREHOLDER_CHARTER.md` as **DRAFT — NOT APPROVED**. Do not treat that charter as adopted authority without fresh evidence and explicit Owner approval.

## 6. Historical governance-policy work

Prior conversation summaries recorded draft governance artifacts on branch `integration/arna-system-consolidation` in the older `akeayu/arna-api` repository context:

- `ARNA-GOV-POL-PSC-001.md`, draft v0.1.0; historical commit `51fc6dc6320d6e877aa08807adc6c5ba5538d154`.
- `ARNA-GOV-POL-PSC-001-AM`, draft v0.1.0; historical commit `2246d51ce5f6a7859ad438578c7845fd30832251`.
- `ARNA-GOV-MAT-PSC-001-AM.md`; earlier summaries referenced commits `84c73d46009fe6934342dbcfcceddb5d1a187e6f`, `dc7e80a3fe5df8d4ccd5d12c4e4ac8bd82502eb5`, and `7f...` (the last SHA is incomplete in the recovered summary and is intentionally not reconstructed).
- Another historical summary referenced matrix file `docs/governance/ARNA-GOV-MAT-PSC-001-AM.md`, branch `integration/arna-system-consolidation`, commit `064454e35e61ceb8bdd3b06b407e01ae4daebd1e`, and blob `...` (blob SHA was truncated in the recovered summary).

These are historical pointers from an earlier repository/account context. Current existence, ancestry, contents, approval, merge and deployment status have not been revalidated in this record. Do not assume these artifacts are adopted policies.

## 7. Repository status recorded on 2026-11-18

A source-status README was committed directly to the destination repository:

- Repository: `akefadaka-git/arna-api`
- Branch: `main`
- File: `docs/legal/articles-of-association/README.md`
- Commit: `0b7f57aa0f27d60ab63d3f9f9120634c305819da`
- The file was fetched back from `main`; its returned blob SHA was `c90f607d818304eaa44921d2bc05188a7e4da878`.
- The README explicitly states that the original PDF is not yet preserved or verified in the repository.
- No application code was changed in that operation.
- The original PDF itself was **not** committed.

## 8. Preservation protocol for the original scanned PDF

When the original becomes accessible through an available transfer route:

1. Preserve the original PDF byte-for-byte. Do not edit, crop, rotate, compress, optimize, or overwrite it.
2. Store it under `docs/legal/articles-of-association/original/`, retaining the verified original filename where practical.
3. Record SHA-256, byte size, page count, acquisition date, source/provenance and custody notes.
4. Inspect every page for completeness, legibility, stamps, signatures, seals, numbering, attachments and visible alterations. Record defects; do not silently repair the source.
5. Any OCR/searchable transcription must be a separate derivative with its own provenance and verification notes.
6. Reconcile the Articles against official shareholder-register pages 9–11 and any authoritative amendments/resolutions. Keep every discrepancy open until supported by evidence.
7. Verify the committed original by retrieving it from GitHub and comparing its SHA-256 with the source file.

## 9. Governance, scope and approval controls

- Maintain Owner-governed, evidence-backed stage gates: A1 assessment/specification/freeze → A2 read-only reconciliation → A3 implementation → A4 verification → A5 deployment.
- Preserve A5 as a separate explicit Owner approval. Do not merge or deploy merely because A1–A4 work is complete.
- Do not claim an original has been preserved, verified, or legally reconciled until the binary and relevant pages have actually been inspected and repository evidence exists.
- Keep A2 read-only: no application-code or database mutation as part of source reconciliation.
- Do not treat historical assistant summaries, seed data, OCR, draft policies, or arithmetic as authoritative legal evidence.
- Preserve unrelated changes and existing active PR dependencies. Avoid broad scope expansion.
- The Owner has stated that paid deposits/top-ups for GitHub Actions or paid services are not feasible; prefer public-repository and no-cost/manual-dispatch verification routes where appropriate. Do not incur charges.

## 10. Current status and next evidence required

| Work item | Status |
|---|---|
| Owner possession of scanned original | OWNER-CONFIRMED |
| Original file inspected in this repository task | NOT AVAILABLE |
| Original binary stored in destination `main` | MISSING |
| Source-status README | VERIFIED-COMMITTED |
| Historical Articles clause summaries | PARTIAL / UNVERIFIED |
| Official register pages 9–11 | HISTORICALLY REPORTED AS UPLOADED; NOT CURRENTLY INSPECTED |
| 29-founder / 145-share reconciliation | UNRESOLVED |
| ETB paid/unpaid reconciliation | UNRESOLVED |
| SH-0023 classification | UNRESOLVED |
| 100 seeded entries vs official register | UNRESOLVED |
| Draft charter adoption | NOT APPROVED / NOT VERIFIED |
| Legal reconciliation closure | NOT CLOSED |
| A3 implementation based on disputed legal assumptions | NOT AUTHORIZED BY THIS RECORD |
| A5 deployment | SEPARATE OWNER APPROVAL REQUIRED |

**Immediate next step:** continue only with bounded, read-only reconciliation of already-accessible repository evidence and historical records. The original scan remains in the Owner's possession; no re-upload is required while the upload limitation persists.
