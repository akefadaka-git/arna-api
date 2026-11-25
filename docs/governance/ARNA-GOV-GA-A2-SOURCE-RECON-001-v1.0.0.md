# ARNA General Assembly — A2 Legal and Policy Source Register Reconciliation

- **Document ID:** ARNA-GOV-GA-A2-SOURCE-RECON-001
- **Version:** 1.0.0
- **Stage:** A2 — read-only source and authority reconciliation
- **Status:** PARTIAL — legal activation parameters remain blocked
- **Date:** 2026-11-25
- **Repository:** `akefadaka-git/arna-api`
- **Working branch:** `docs/arna-d01-a2-evidence-register`
- **Controlling functional baseline:** `docs/governance/ARNA-GOV-GA-A1-001.md`
- **A1 blob:** `c73b9ce63219cf39529adc303b9e58ef09838507`
- **Related A2 traceability:** `docs/governance/ARNA-GOV-GA-A2-TRACE-001-v1.0.0.md`
- **Related A2 technical contract:** `docs/governance/ARNA-GOV-GA-A2-CLOSURE-A3-IMPLEMENTATION-CONTRACT.md`

## 1. Purpose and authority boundary

This record reconciles the GA A1 legal/policy source register against the currently accessible repository evidence and the prior Owner-confirmed source-history recorded in the repository. It does not amend the frozen A1 requirements, authenticate a corporate instrument, issue a legal opinion, adopt a policy, or activate a GA rule.

The evidence is classified as follows:

1. **Operative authority — authenticated:** a source whose legal identity, completeness, approval/registration, currentness and applicability have been verified against the required evidence.
2. **Owner-supplied source/provenance:** a document or fact identified or confirmed by the Owner, but not independently authenticated for legal reliance.
3. **Provisional transcription / historical summary:** OCR, working recovery, earlier notes or reported clauses that are not a certified or clause-verified transcription.
4. **Owner-approved architecture or policy input:** an instruction governing ARNA design or a recorded Owner policy choice; not by itself a competent corporate resolution or proof of legal validity.
5. **Technical evidence:** code, schema, tests, seeders, workflow or UI; evidence of software only, not corporate authority.
6. **Missing / unavailable:** the required source or approval evidence was not found in the inspected repository tree.

A lower evidence class must not be promoted into an operative legal rule by repetition in A1, A2, code, a dashboard, or a prior assistant summary. Where authority, wording, hierarchy or applicability is unresolved, the affected operation remains fail-closed.

## 2. Source register

| ID | Source / artifact | Repository evidence and provenance | Class | A2 disposition |
|---|---|---|---|---|
| SRC-01 | Frozen GA A1 requirements baseline | `docs/governance/ARNA-GOV-GA-A1-001.md`; blob `c73b9ce63219cf39529adc303b9e58ef09838507` | Owner-approved requirements baseline | Controlling functional specification; not legal authority |
| SRC-02 | GA A2 technical disposition / implementation contract | `docs/governance/ARNA-GOV-GA-A2-CLOSURE-A3-IMPLEMENTATION-CONTRACT.md`; blob `691012753ab51604ed487b0edf6a8ca0ef4aa5d4` | Technical contract | Correctly blocks unconfigured quorum, voting, notices, proxy and minutes rules; does not activate them |
| SRC-03 | GA A2 traceability report | `docs/governance/ARNA-GOV-GA-A2-TRACE-001-v1.0.0.md`; blob `4582de1d64f34c3b17f7e922cbf0b2dd2a10141b` on the working branch | A2 traceability evidence | Retain 10 PARTIAL / 6 MISSING / 1 UNRESOLVED group disposition |
| SRC-04 | Original Articles of Association | `docs/legal/articles-of-association/README.md`; blob `c90f607d818304eaa44921d2bc05188a7e4da878`. Owner reports possession of an image-scanned original; original PDF bytes, filename, page count and checksum are not preserved/verified in this repository | Owner-supplied provenance; source unavailable in repo | Separate from both bylaws documents; no clause can be treated as verified from the README |
| SRC-05 | Historical Articles reconciliation notes | `docs/legal/articles-of-association/HISTORICAL-RECONCILIATION-NOTES.md`; blob `856cd6afbcfd16f26b26c98d91850ebd82fcd29a` | Historical working notes | Reported Article 17 quorum (“more than half of capital”), Article 8 proxy, and Article 9 majority/deposit summaries are expressly unverified; do not enforce |
| SRC-06 | 17-page public/government-use bylaws scan and P2.5 recovery | P2.5 report `docs/governance/elections/P2.5-SOURCE-AND-STATUTORY-RECONCILIATION-v1.0.md`; blob `8101ccbd3a9fbdc194c5a51aef34e0e458c11132`. Report records Owner-supplied source SHA-256 `8df31ad217b2221f42b3e0e1154007bdd5d2ab003df0e1ad3dc7f17c03b61183`, 17 scanned pages, Amharic, image-only. The original PDF itself is not committed in the current repository | Owner-supplied provenance plus provisional recovery | Separate bylaws document, not established as the registered Articles/Memorandum. Apparent notice/proxy clauses remain provisional pending exact clause verification and legal reconciliation |
| SRC-07 | 22-page internal-use bylaws | Owner-confirmed as a separate internal-use document in prior governance context; not located in the inspected current-main legal directory or working-branch tree | Owner-reported source; repository evidence missing | Preserve as a separate source item. No hierarchy, supersession or legal effect inferred |
| SRC-08 | GA/Board rules of procedure and adopted amendments | A1 source register requires approved GA and Board rules; no authenticated GA rules/procedure package or amendment register was located in the inspected current-main governance/legal tree | Missing | Cannot supply quorum, notice, voting, proxy or minutes rules |
| SRC-09 | GA attendance and penalty policy ARNA-GOV-POL-MEETING-ATTENDANCE-001 | Named by A1 as an approved attendance policy, and values are repeated in the A2 technical contract; no file with this identifier was present in the inspected current-main or working-branch repository tree | Owner-confirmed policy inputs; source artifact missing | The A1 wording “approved attendance policy” is not corroborated by a retrievable policy artifact in this repository. Treat the amounts/deadline as Owner-confirmed design inputs pending the signed/adopted policy and legal review |
| SRC-10 | Legal reconciliation memorandum ARNA-GOV-LEGAL-MEMO-MEETING-ATTENDANCE-001 | Named by A1; no file with this identifier was present in the inspected current-main or working-branch repository tree | Missing | No legal applicability determination evidenced |
| SRC-11 | Ethiopian Commercial Code, Proclamation No. 1243/2021 | P2.5 report cites the English text and Articles 296–300, 370, 373, 377, 383–386; Article 387 is referenced in the election-directives report. External statutory text is identified in those records, but no counsel opinion applying it to ARNA's operative instruments is evidenced here | Statutory research reference; application not legally certified | Relevant legal framework to be checked by competent Ethiopian counsel against the authenticated company instruments; repository summaries are not a legal opinion |
| SRC-12 | Board-election specification and proposed electoral committee directives | `docs/governance/elections/ARNA-GOV-ELE-SPEC-001-BOARD-ELECTION-SYSTEM-v1.0.0.md`, blob `33c2fe181d2462acd54cff966b826d1e2efeef9a`; `ARNA-GOV-ELE-DIR-001-ELECTORAL-COMMITTEE-DIRECTIVES-v1.0.0.md`, blob `03ebe9da443a13a95bfaad5427abb35b0964c385` | Proposed, not adopted | Election-only material. It cannot be imported as GA quorum, notice, proxy or voting procedure |
| SRC-13 | Governance meeting/attendance implementation | Controller blob `ee2abfd7b2881499575df078d3c62b931303c217`; migration blob `efa43bf08b0f89aa103536f97f381a25fe6e19e1`; attendance test blob `78ffc9a4e5fe2c4c33241ec0b746371660e50312` | Technical evidence | Establishes only the implemented meeting/attendance/proxy/penalty workflow surface. Does not authenticate policy or corporate records; test fixtures are not real meeting evidence |
| SRC-14 | Minutes, resolutions and decision-execution source package | No GA agenda/motion/vote/resolution/minutes approval source package or authenticated adopted minutes were identified in the inspected repository evidence | Missing | Approval authority, signatures, certification, correction and filing remain blocked |

**Repository scope note:** The current-main tree inspected for this reconciliation was API SHA `f3fdd3219b7f9c5b1ff55ef24929fd5f2ec42446`. The working branch tree inspected was `docs/arna-d01-a2-evidence-register` at `d10a8b8c741c2da5ed912662be51bde1b22d7709`. “Not found” means not present in those inspected repository trees; it is not a claim that a source does not exist elsewhere.

## 3. Parameter-by-parameter reconciliation

### GA-P01 — Quorum

**Evidence found**
- Historical Articles notes report that Article 17 describes quorum as “more than half of capital”; the same notes explicitly classify this clause summary as unverified pending inspection of the original scan.
- P2.5 does not provide a verified quorum clause transcription.
- The GA A2 technical contract expressly rejects a numerical GA quorum, rejects reuse of the election `half_register` rule and the earlier 50% assumption, and requires a versioned, source-backed calculation.
- No authenticated operative GA procedure or adjourned-meeting rule was found.

**Disposition: UNRESOLVED — BLOCKED.**

Do not configure a percentage, capital denominator, number-of-shareholders denominator, represented-voting-right denominator, proxy treatment, or adjourned/second-call rule. The historical “more than half of capital” statement is a legal-review lead only. A valid rule must identify its source, denominator, exclusions, valid representation treatment, effective date and adjournment context. Without an active authenticated rule, quorum evaluation must return a blocked/unconfigured state; the chair or administrator must not override it manually.

### GA-P02 — Notice period, method and service

**Evidence found**
- P2.5 records an apparent 30-day general-meeting notice period in OCR/page-image recovery and expressly says the exact wording requires checking.
- The source is the 17-page bylaws document, which P2.5 identifies as bylaws/internal regulations and does not establish as the separate registered Memorandum/Articles.
- A1 requires authority, notice approval, recipient register, service method and delivery evidence.
- The A2 technical contract states that Calendar reminders are not legal notice/service unless an approved procedure expressly recognizes them.
- No authenticated GA procedure, recipient/record-date rule or service-channel approval was found.

**Disposition: PARTIAL — BLOCKED FOR ENFORCEMENT.**

Record “30 days” as a provisional source lead only. Do not use it as a production deadline until the exact Amharic clause is verified, the document's operative status and applicability are established, and counsel reconciles it with applicable law and the registered Articles/Memorandum. Notice method, deemed-service rules, recipient list, record date, special-meeting notice and proof-of-delivery requirements remain unconfigured.

### GA-P03 — Electorate, voting rights and vote weight

**Evidence found**
- P2.5's statutory research summary describes Commercial Code Articles 383–384 as providing share/capital-weighted votes for ordinary/dividend shares and allowing a Memorandum-based vote limit, subject to applicable law.
- The GA A1 baseline prohibits assuming one shareholder equals one vote or identical voting rights for every share.
- Historical source notes report 145 shares and 29 founders, but classify those figures as historical and not proof of current registered ownership, share classes, paid-up status or current entitlement.
- The current authoritative shareholder register, transfers, share classes, special rights and any valid vote cap were not reconciled in this task.
- Board-election documents are proposals and do not establish the GA electorate or decision thresholds.

**Disposition: PARTIAL — BLOCKED FOR VOTER-REGISTER FREEZE AND VOTE CALCULATION.**

The design direction is to calculate entitlement from an authenticated shareholder register and applicable share/class rights at a legally approved record date. Do not use seeded application records, historical founder counts or a one-person-one-vote default. Do not activate vote caps, class privileges, ordinary/special/reserved thresholds, secret ballot or voting methods without the operative source and legal review. The statutory summary is a research lead, not a complete company-specific entitlement determination.

### GA-P04 — Proxy formality, representative eligibility and deadline

**Evidence found**
- P2.5 reports a provisional bylaw recovery of a written, dated and shareholder-signed proxy authorizing a non-shareholder representative; exact wording and clause mapping remain subject to source verification.
- The Owner-confirmed GA attendance policy input records submission at least three (3) days before the GA. The GA A1 and A2 technical contract repeat this as an Owner-confirmed policy input, but the named policy artifact was not found in the repository.
- The separate Board proxy input is three (3) hours before a Board meeting. It is a Board rule and must not be applied to GA proxies.
- The GA A1 baseline requires review of scope, identity, validity, duplicate representation, revocation, expiry and proxy evidence.
- No authenticated GA procedure establishing proxy caps, one-representative limits, notarization/witnessing, revocation/replacement or late-proxy exceptions was located.

**Disposition: PARTIAL — BLOCKED FOR AUTOMATIC VALIDATION/ACCEPTANCE.**

Retain the three-day GA deadline as a recorded Owner policy input, not as a verified legal deadline. Retain the provisional written/dated/signed form and non-shareholder representative clause as source leads. Before enforcement, reconcile exact operative wording, legal permissibility, proxy scope, meeting specificity, identity, submission deadline, witnessing/notarization if required, representative limits, multiple principals, revocation/replacement and double-count prevention. Do not import the Board's three-hour rule into GA.

### GA-P05 — Attendance penalties and financial treatment

**Evidence found**
- Owner-confirmed policy inputs recorded in the GA A1 baseline and A2 technical contract are ETB 50 flat for late arrival after scheduled start and ETB 300 for unexcused absence.
- The same records require authorized review, a dispute/review route and no automatic dividend deduction.
- The policy identifier `ARNA-GOV-POL-MEETING-ATTENDANCE-001` and legal memo `ARNA-GOV-LEGAL-MEMO-MEETING-ATTENDANCE-001` are named in A1 but no corresponding file was present in the inspected repository tree.
- The current implementation's penalty state was previously observed as immediately `assessed`; the A2 contract requires an independent review lifecycle. Software status does not establish a legally payable liability.
- No authenticated source establishes GA-specific applicability, exceptions, appeal authority, waiver authority or enforceability.

**Disposition: PARTIAL — NOT LEGALLY ACTIVATED; NO PAYABLE LIABILITY FROM CONFIGURATION ALONE.**

The amounts and late/absence definitions may remain visible as Owner policy inputs for requirements traceability, but must not be represented as an adopted and legally enforceable GA sanction until the approved policy artifact, competent corporate approval and legal basis are verified. A penalty candidate is not a debt. Require independent review, dispute/appeal, documented waiver/reversal, and separate Finance recognition/settlement. No automatic dividend, shareholding or voting-entitlement deduction.

### GA-P06 — Minutes approval, signatures, certification and filing

**Evidence found**
- GA A1 requires draft minutes, attendance register, agenda proceedings, motions, voting results, resolutions, objections, declarations, adjournments, attachments, review, approval, signature/certification and archive.
- P2.5 and the proposed election directives identify Commercial Code Article 387 as relevant statutory material, but no company-specific clause-level analysis or counsel opinion on GA minutes approval was found.
- The 17-page bylaws scan's prior review did not yield a reliable clause-level transcription for meeting minutes approval. The 22-page internal-use bylaws and original Articles are not available in the inspected repository tree.
- No authenticated adopted GA procedure, minutes template with approval authority, signed minutes, or resolution approving a minutes procedure was located.
- The secretary's technical role or a draft workflow cannot create approval authority.

**Disposition: MISSING / UNRESOLVED — BLOCKED.**

Do not assume chair-only, secretary-only, joint chair/secretary, majority-of-attendees or subsequent-GA approval. Verify who prepares, reviews, approves, signs, certifies and files minutes; when minutes become final; how dissent and objections are recorded; how corrections/corrigenda are authorized; whether signatures or witnesses are required; and the applicable filing/retention period. Preserve drafts and versions; do not silently replace an approved record.

### GA-P07 — Adjourned meetings, special resolutions and other reserved procedures

**Evidence found**
- A1 expressly leaves adjourned-meeting quorum, special-resolution thresholds, voting methods and related procedural rules unresolved.
- The technical contract requires source-versioned rules and blocked/unconfigured states.
- No authenticated GA procedure or adopted resolution resolving these items was found.

**Disposition: UNRESOLVED — BLOCKED.**

No second-call quorum reduction, simple-majority default, special-resolution threshold, chair casting vote, remote attendance or electronic voting rule may be inferred.

## 4. Cross-source conflict and non-substitution rules

1. The original Articles, 17-page public/government-use bylaws and 22-page internal-use bylaws remain three distinct source items. Their identity, legal relationship, incorporation, hierarchy and amendment history must be determined from authenticated records and competent legal advice; no hierarchy is inferred here.
2. P2.5 records Owner confirmation that the 17-page bylaws scan is the latest complete approved version and was signed by the named Bureau. This is recorded as Owner-supplied provenance/completeness evidence. It does not independently authenticate the stamp/signatories, establish registration as the Articles/Memorandum, or resolve clause-level meaning and applicability.
3. The apparent 30-day notice and proxy-formality clauses are provisional recovery, not certified transcription. They must not be configured as binding rules on this evidence alone.
4. The reported Article 17 “more than half of capital” quorum and Article 8/9 summaries in historical notes remain unverified. They do not override the A1/A2 blocked state.
5. The three-day GA proxy deadline and ETB 50/ETB 300 penalty values are Owner-confirmed policy inputs. The Board's three-hour proxy rule is separate. Neither an Owner instruction nor an A1 restatement substitutes for a formally adopted, legally valid GA policy.
6. Board-election policies, committee directives, `half_register` logic and election-specific voting rules are not GA meeting rules.
7. Software routes, migrations, seeded data, fixtures, test assertions, meeting-calendar events and notifications are technical evidence only. They do not establish valid convening, notice service, quorum, entitlement, proxy validity, penalty liability or minutes approval.
8. A legal research summary of the Commercial Code is not a written Ethiopian corporate-law opinion applying the current law to ARNA's authenticated operative instruments.

## 5. Required evidence to resolve each parameter

| Parameter | Minimum evidence needed before legal activation |
|---|---|
| Quorum | Authenticated operative Articles/Memorandum and adopted GA rules; exact clause and denominator; ordinary/special/adjourned meeting treatment; counsel determination; effective source version |
| Notice | Exact verified clause; applicable statutory analysis; approved notice procedure; service methods, record date, recipient set and delivery standard; competent approval |
| Voting rights | Current statutory shareholder register and share ledger; class/special-right records; transfer and restriction evidence; operative vote-weight/cap clauses; approved record-date rule; counsel review |
| Proxy | Verified operative proxy clause and approved form; representative eligibility; signature/date/witness/notary requirements; scope; caps; deadline; revocation/replacement and late exception; duplicate-entitlement controls |
| Penalties | Retrievable signed/adopted attendance policy; competent approval record and effective date; legal basis and GA applicability; exceptions; independent review and appeal authority; Finance treatment |
| Minutes | Verified statutory and operative company clauses; adopted procedure; approval/signature/certification roles; objection/correction process; filing/retention obligations; competent approval evidence |
| Adjournment / special matters | Authenticated rules for adjournment, reconvening, special resolutions, thresholds, ballot method, chair powers and any remote/electronic participation |

This list identifies evidence required; it is not a request to re-upload the same documents while upload remains unavailable. Continue to record repository-accessible evidence and preserve the gaps.

## 6. Corrected source-register interpretation for A1/A2

For traceability, the A1 source-register entries should be interpreted as follows without editing the frozen A1 file:

- “Approved GA and Board Meeting Rules of Procedure” — **required source; not evidenced as an authenticated approved package in the inspected repository**.
- “Approved attendance and penalty policy ARNA-GOV-POL-MEETING-ATTENDANCE-001” — **Owner-confirmed policy inputs are recorded, but the named policy artifact and competent approval record were not located in the inspected repository**.
- “Legal reconciliation memorandum ARNA-GOV-LEGAL-MEMO-MEETING-ATTENDANCE-001” — **required source; not located in the inspected repository**.
- “Operative Memorandum and Articles, including amendments” — **original Articles reported by Owner but not preserved/verified in repository; 17-page bylaws are a distinct document; 22-page internal bylaws remain a separate unlocated evidence item**.
- “Applicable Ethiopian law and regulations, as reviewed by competent counsel” — **statutory research references exist; a company-specific counsel opinion resolving the listed GA parameters is not evidenced**.

This is a controlled A2 clarification, not an amendment to the A1 baseline or a declaration that the source documents do not exist outside the inspected repository.

## 7. Final A2 disposition and stage boundary

- Source register reconciled against current-main and the existing A2 working branch: **PASS — bounded repository inspection completed**.
- Operative legal source package authenticated: **NOT VERIFIED**.
- Quorum: **UNRESOLVED / BLOCKED**.
- Notice: **PARTIAL / BLOCKED**.
- Voting rights: **PARTIAL / BLOCKED FOR CONFIGURATION**.
- Proxy: **PARTIAL / BLOCKED**.
- Penalties: **PARTIAL / NOT LEGALLY ACTIVATED**.
- Minutes approval: **MISSING / UNRESOLVED / BLOCKED**.
- Adjournment and special-resolution parameters: **UNRESOLVED / BLOCKED**.
- Legal counsel review: **NOT EVIDENCED**.
- Runtime/database inspection: **NOT RUN**.
- Tests/builds/workflows: **NOT RUN**.
- A3 implementation: **NOT AUTHORIZED**.
- A4 verification: **NOT STARTED**.
- A5 deployment: **NOT AUTHORIZED**.
- Merge: **NOT AUTHORIZED**.

No code, schema, migrations, configuration, production records, legal source files or frozen A1 baselines were changed by this reconciliation. The next A2 step is to preserve this source disposition in the existing evidence branch and, if further repository evidence becomes available, update only the affected source item with its immutable provenance and approval evidence.
