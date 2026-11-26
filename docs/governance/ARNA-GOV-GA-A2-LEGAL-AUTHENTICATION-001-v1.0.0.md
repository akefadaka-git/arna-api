# ARNA GA — Legal Form and Operative Corporate Instrument Authentication Register

**Record:** ARNA-GOV-GA-A2-LEGAL-AUTHENTICATION-001  
**Version:** 1.0.0  
**Status:** OPEN / UNRESOLVED — no operative instrument authenticated  
**Repository:** `akefadaka-git/arna-api`  
**Related implementation:** PR #235, GA legal evidence gates and substantive lifecycle APIs

## 1. Question being resolved

Before any GA statutory or company-specific rule is activated, the company’s registered legal form and currently operative corporate instruments must be authenticated. The evidence must be reviewed independently by an Ethiopian corporate-law practitioner. A company name or an owner statement is not a substitute for a current official registration record and certified constitutional instruments.

## 2. Current evidence inventory

| Evidence | Available record | Authentication result |
|---|---|---|
| Company identity | Owner identifies the entity as Maale Arna Share Company / Maale Arna & Share Co. | OWNER-REPORTED; official registry confirmation not obtained |
| Legal form | Owner has stated that the entity is a share company and has a valid registration certificate | OWNER-REPORTED; certificate itself not available for inspection |
| 17-page signed/stamped source | `maale_arna_bylaws_original_scan.pdf` (17 scanned pages) | SOURCE LOCATED; title page identifies it as `የመተዳደሪያ ደንብ` (bylaws/internal regulations), not proven Memorandum or Articles |
| 17-page transcription | `maale_arna_bylaws_full_recovery.md` | WORKING TRANSCRIPTION only; explicitly not certified or legally authenticated |
| 22-page internal source | Owner distinguishes a 22-page internal-use version from the 17-page government/public-use version | OWNER-REPORTED; primary 22-page source file not located in the accessible Library during this review |
| Board Election Manual v2.2 | `MAALE_ARNA_Board_Election_Manual_v2.2.docx`, 16-page working draft, prepared 22 November 2026 | NOT ADOPTED; not a constitutional instrument and not an independent legal opinion |
| Memorandum of Association | No certified operative copy located | MISSING |
| Articles of Association | No certified operative copy located | MISSING |
| Amendments / registry endorsements | No complete certified amendment chain located | MISSING |
| Current commercial registration certificate / official extract | Not located in accessible files | MISSING |
| Independent Ethiopian corporate-law opinion | Not obtained | MISSING |

## 3. Material source conflicts

### 3.1 Identity of the 17-page signed scan

The original scan is signed/stamped and contains an endorsement page. However, the title visible on its first page and recorded in the transcription is `የመተዳደሪያ ደንብ` (bylaws). The transcription expressly warns against renaming it as a separate Memorandum or Articles without documentary proof. The stamp/endorsement on page 17 is not legible enough in the available copy to establish the issuing office, registration number, date, or whether the instrument was registered as a constitutional instrument.

The owner has separately described this 17-page document as the government/public-use constitutional document. That description is retained as owner-provided context, but the title/provenance conflict remains unresolved. The application must not silently choose one characterization.

### 3.2 The 22-page internal-use source

The owner distinguishes the 22-page internal-use version from the 17-page government/public-use version. The 22-page primary source was not present in the accessible Library inventory during this review. The available Board Election Manual v2.2 refers to a newly supplied 22-page transcription but is itself a WORKING DRAFT — NOT ADOPTED.

The manual records material differences between the source versions, including a five-person versus seven-person founding Board and differing re-election language. It also reports that the 22-page transcription contains a historical schedule of 54 entries and 560 shares, while the 17-page scan contains 29 listed shareholders and 145 shares. Neither historical schedule is accepted as the current shareholder register.

### 3.3 Operative status

No available evidence establishes which version, if either, is the currently registered and operative constitutional instrument, whether subsequent amendments exist, or whether the 22-page internal document is an adopted bylaw, an internal consolidation, a transcription, or a draft. The 17-page public-use and 22-page internal-use records remain distinct. No hierarchy is inferred.

## 4. Consequences for the GA system

The following remain BLOCKED pending authenticated evidence and independent legal review:

1. Treating `share_company` as an authenticated legal-form fact.
2. Activating statutory meeting notice, quorum, proxy, voting, resolution, or minutes rules.
3. Setting quorum numerator/denominator or meeting-type thresholds.
4. Setting voting rights per share class or enabling secret ballots.
5. Treating the owner-reported 30-day notice provision as an operative rule.
6. Loading either historical share schedule as the current electorate.
7. Enforcing any penalty or financial consequence as a statutory obligation.

The A3 implementation stores rule parameters only after legal-basis verification. The legal-basis verification endpoint now requires an external Ethiopian counsel name, firm, license/registration reference, opinion reference, opinion SHA-256, and review date. This is an evidence-control requirement; the application cannot itself verify counsel credentials or the authenticity of an external opinion.

## 5. Required authentication and independent review packet

The company secretary / owner must assemble the following originals or certified copies:

- Current commercial registration certificate and current official registry extract, showing exact legal name, legal form, registration number, status and registered office.
- Certified Memorandum of Association.
- Certified Articles of Association and all schedules.
- Every registered amendment, restatement, name-change record, capital change, and relevant registry endorsement.
- The 17-page government/public-use document in its original full-resolution scan, with registration/endorsement details readable.
- The 22-page internal-use document in its original full-resolution form, plus evidence of adoption, approval date, competent organ and effective date.
- Current shareholder register and share ledger, reconciled to transfers, share classes, paid/unpaid calls, joint ownership and legal representatives.
- Relevant prior General Meeting and Board resolutions that adopted or amended the constitutional instruments or governance rules.

An independent Ethiopian corporate-law practitioner must issue a signed written opinion that:
- confirms the registered legal form and the authoritative current corporate instruments;
- determines whether the 17-page and 22-page versions are distinct instruments, versions, consolidations or drafts, and identifies their legal hierarchy/effect;
- confirms applicable Commercial Code provisions and any transitional rules;
- reconciles company-specific clauses with mandatory law;
- determines meeting-specific notice, quorum, proxy, voting, resolution and minutes requirements;
- addresses share-class rights, voting weights, record dates and secret-ballot requirements;
- identifies any amendments, registrations, filings or corporate resolutions needed before implementation.

## 6. Stage-gate disposition

- A1: preserved; no frozen baseline changed.
- A2: legal-form and operative-instrument authentication remains OPEN / UNRESOLVED.
- A3: software stores and enforces an external-counsel evidence requirement; it does not create legal validity.
- A4: implementation verification is separate from legal authentication.
- A5: NOT AUTHORIZED. No production migration or deployment occurred.

**Decision:** Do not mark the company’s legal form or operative instruments VERIFIED until the official registration evidence, certified constitutional instrument chain, and independent Ethiopian corporate-law opinion have been received and independently checked.

## 7. Official authentication route checked

The following official public sources were checked while preparing this register:

- Ministry of Trade and Regional Integration, Commercial Registration service: https://motri.gov.et/en/service_category/commercial-registration/view
- Ethiopian Business Portal, Share Company registration journey: https://new.business.gov.et/Starting%20a%20Business
- Ministry of Justice, Commercial Registration, Licensing and Post-Licensing Inspection Directive No. 935/2022: https://justice.gov.et/en/directives/commercial-registration-licensing-and-post-licensing-inspection-directive-no-935-2022/
- Commercial Code Proclamation No. 1243/2021, including the public commercial-register provisions: https://ethiodata.et/wp-content/uploads/2023/01/Commercial-Code-of-Ethiopia-English-Proclamation-No.-1243_2021.pdf

The Ethiopian Business Portal describes the Share Company route as involving authentication of the Memorandum and Articles, followed by commercial registration and issuance of a registration certificate. The Ministry's commercial-registration service also identifies supporting evidence for amendments to the Memorandum and Articles, including shareholder resolution minutes. The Commercial Code provides for public access to the commercial register and issuance of extracts on request, subject to the prescribed fee.

**Execution approach:** first use the company's existing registration certificate and certified corporate records. Reconcile their registration number, exact legal name and legal form against an official current commercial-register extract. If the certificate or extract is missing or stale, the company representative should request a current extract from the competent federal or regional registering authority. No paid registry request, filing, amendment, or legal engagement is authorized by this repository record.

These public sources explain the route; they do not authenticate this particular company. No registry query has been made for the company, and no legal-form conclusion is drawn from the public guidance alone.

## 8. Immediate evidence handoff

The next authentication event is an evidence comparison, not a new legal interpretation:
1. Compare the original registration certificate with the current official registry extract.
2. Compare the certified Memorandum and Articles with the register's filed-document record.
3. Build a dated amendment chain and verify each amendment's competent approval and registration.
4. Identify the 17-page and 22-page documents within that chain, without merging or reclassifying them by assumption.
5. Only after this reconciliation, submit the complete evidence packet for independent Ethiopian corporate-law review.

Until these checks are evidenced, the legal-basis record must remain pending and the statutory GA gates must remain blocked.
