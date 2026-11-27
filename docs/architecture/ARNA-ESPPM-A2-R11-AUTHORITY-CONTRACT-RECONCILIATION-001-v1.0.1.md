# ARNA ESPPM A2-R11 — Authority-Source and Contract-Exception Reconciliation

- **Record ID:** ARNA-ESPPM-A2-R11-AUTHORITY-CONTRACT-RECONCILIATION-001
- **Version:** 1.0.1
- **Prepared:** 2026-11-25
- **Stage:** A2 — read-only evidence reconciliation
- **Frozen catalogue:** ARNA-ESPPM-A1-SPEC-002 v1.2.0
- **Controlling Owner decision:** KEEP A2 OPEN — DO NOT CLOSE (2026-11-24)
- **Repositories:** `akefadaka-git/arna-api`, `akefadaka-git/arna-app`
- **Change class:** Documentation-only; exact source evidence, contract boundaries and authority exceptions

## 1. Executive determination

**R11 resolves the D01/D02 objective-ownership ambiguity at the catalogue-boundary level, improves the corporate-source evidence register, and resolves the evidence classification of several authority claims. It does not establish current corporate authority and does not make A2 closure-ready.**

A previously unincorporated 22-page Amharic company document was found in the Library and its extracted text was inspected. It contains substantial founding/governance provisions, including Board composition, historical initial directors, Board powers, delegation, signing authority and an initial managing-director statement. Its own text says it was signed on 16/12/2016 E.C. in Arba Minch. However, this is a Library-held DOCX transcription; its registered status, execution/authentication, completeness, amendment history and continuing legal effect have not been independently established.

The earlier 17-page signed/stamped bylaw scan and its recovery record remain separate evidence. A later working election manual reports a material difference in the historical founding Board roster: five members in the earlier scan versus seven in the 22-page transcription. Upload chronology is not proof of legal supersession. No version is selected as the operative registered instrument by inference.

**No Owner-approved domain disposition is changed. No missing legal authority is invented.**

## 2. Exact repository baselines and current A2 branches

The following repository main snapshots remain the revalidated R10 baselines:
- API `main`: `6e274c25ecc55b84e026e879d735b77f0e51592f`.
- App `main`: `ffe0495c0eb7c8b5d2013419123aac5b68893d51`.

These refs are read-only evidence anchors in R11. R11 does not write to either main branch.

Related documentation branches at the R10 revalidation cutoff:
- PR #219 consolidated A2 package: `docs/esppm-a2-closure-report-001`, head `7766cb5fdcabe4a712431eb9b8af44a56bea2b41`.
- PR #222 D01 subordinate A1 specification: `docs/arna-d01-a1-spec-a2-evidence`, head `cf4486f0c983641bfd3f81ad284b5efe4e57c401`.
- PR #223 D01 A2 evidence register: `docs/arna-d01-a2-evidence-register`, head `06483f578ab75e4a9824bc01e213abb0418c0288`.

The final PR heads after the R11 documentation commits must be taken from live PR metadata, not inferred from the pre-commit refs above.

## 3. Newly reconciled company-source evidence

### 3.1 Source A — 22-page Amharic DOCX

- **Library title:** `የማሌ ዓርና አክስዩን ማኅበር መተዳደሪያ ደንብና መመሥረቻ ጽሑፍ.docx`
- **Library file ID:** `file_00000000a2248246b741e6cf3a0a8364`
- **Library artifact ID:** `libfile_b109adc7b86c8191b5fc98aa12d4103c`
- **Extracted extent:** 22 pages / 1,068 extracted lines.
- **Exact materialized-byte SHA-256:** `3683b521f97b136de5849ef2cfd4ccd9eaf870836605688c1e71058498da9d67`
- **File size:** 94,295 bytes.
- **Document text date:** 16/12/2016 E.C.; Arba Minch; stated as signed by founding members.
- **Source classification:** Company-source transcription / founding-governance text; NOT independently authenticated as the registered operative instrument.

Relevant text observed:
- Article 11.1 describes the General Assembly as the highest authority and states that it elects, appoints and removes Board members, subject to the governing rules.
- Articles 12.1–12.2 describe a Board of 3–8 directors, a three-year term and seven historical initial Board members.
- Article 12.7 describes general Board powers, subject to law, the founding document, bylaws and General Assembly decisions.
- Article 12.8 says the Board may delegate powers to the President as necessary.
- Article 12.9 says specified officers/directors or additional managers may sign for the company when authorized by the Board, including single or joint signing arrangements.
- Article 12.11 says Board decisions are signed by the President and Secretary and recorded in minutes.
- Article 13.3 identifies an initial managing director / general manager.
- Article 17.1 states a 3/4 capital quorum.

Limits:
- Extracted text is not a certified translation or legal opinion.
- No independently verified registration/certification, currentness, amendment register, current appointment record or later Board/GA resolution accompanies this Library item in the inspected evidence.
- The extracted text does not itself establish that historical initial officers remain in office today.
- The presence of a signatory provision does not establish a current named signatory, current mandate, transaction scope, monetary limit or effective date.

### 3.2 Source B — earlier 17-page signed/stamped scan recovery

- **Recovery record:** `maale_arna_bylaws_full_recovery.md`
- **Library file ID:** `file_00000000865c82119c8381da1ad22bfc`
- **Library artifact ID:** `libfile_3f3b422fad78819199ede07922586992`
- **Exact materialized-byte SHA-256 of recovery Markdown:** `80f908fb526eb5ceeb029524579d309fccb68f6bfbc7cbcde89601de37b4331e`
- **Underlying source named by recovery:** `maale_arna_bylaws_original_scan.pdf`, 17 scanned pages. The original PDF bytes were not available for a fresh materialization/hash in this R11 run.
- **Source classification:** Recovery/transcription notes for an earlier signed/stamped scan; not a certified transcription.

The recovery record explicitly warns that OCR is noisy, the original PDF remains the controlling visual source, the title identifies the source as bylaws, and the file must not be renamed as the separate Memorandum or Articles without documentary proof. It records that signatures/stamps are visible but authentication, registration and currentness are not established. Clause-level verbatim transcription remains incomplete.

### 3.3 Source C — election manual v2.2

- **Library title:** `MAALE_ARNA_Board_Election_Manual_v2.2.docx`
- **Library file ID:** `file_0000000062e08246bfd54c0fce90b228`
- **Library artifact ID:** `libfile_b9c8eb0a3fc48191b13079f2c5786959`
- **Exact materialized-byte SHA-256:** `407eb319e5777af8e08e81ad3a1df18e4ec741a2ffd0e977d28449e3687e83cf`
- **Status printed in document:** WORKING DRAFT — NOT ADOPTED; prepared 22 November 2026.

This manual explicitly records:
- a five-member founding Board in the earlier 17-page signed scan;
- a seven-member initial Board in the 22-page transcription;
- a three-quarters quorum clause requiring statutory reconciliation;
- open legal issues for second-call notice, director one-share qualification, proxy eligibility and old Commercial Code references;
- current Board records and the current shareholder register were not certified as part of the manual;
- the manual itself is not an adopted policy or authority to conduct an election.

The manual is corroborating evidence of a known source discrepancy and legal-review status, not a governing instrument.

### 3.4 Related legacy MASES export and resolution search

A read-only Library search also surfaced the legacy export `maale_arna_smart_system(1).sql`. Its extracted content contains a scheduled 7th General Assembly notice for Nehase 1, 2018 E.C. and an agenda that includes review of reports, a proposed 2019 E.C. strategic plan and committee elections. This is scheduling/agenda content, not authenticated attendance, approved minutes, a certified resolution or proof that the meeting occurred as scheduled.

A targeted search for `GA Resolution 0001/2019`, `ጥቅምት 26/2019`, Board appointment resolutions and current CEO/Managing Director appointments did not return a matching resolution or current appointment instrument in the inspected Library results or API repository search. This is a bounded search result, not proof that no such record exists elsewhere.

The governance API main snapshot includes appointment instrument types, an appointment table migration and feature tests. These establish a technical record capability only; they do not establish actual current appointment records or the legal validity of any appointment.

## 4. Source conflict and legal-identity resolution

| Question | Evidence | R11 disposition |
|---|---|---|
| Are the 17-page scan and 22-page DOCX demonstrably the same operative version? | Different extent, source presentation and founding Board roster; no authenticated version history or registry concordance | UNRESOLVED |
| Does the 22-page DOCX establish a Board range of 3–8 and three-year term as text? | Articles 12.1–12.2 in extracted Amharic text | SOURCE TEXT PRESENT; legal effect/currentness NOT VERIFIED |
| Does it establish seven current directors? | It lists seven initial directors historically | NO — historical initial roster is not a current appointment record |
| Does it establish a current managing director? | Article 13.3 identifies an initial managing director | NO — current appointment, term and continuing authority not established |
| Does it establish that the Board can delegate and authorize signatories in principle? | Articles 12.8–12.9 | SOURCE TEXT PRESENT; no current delegation/signatory instrument or legal validation |
| Does it establish a current, valid delegation or signing limit for any person? | No matched current Board resolution/delegation instrument found | NO — UNRESOLVED |
| Is the 22-page file proven to be the separate registered Memorandum/Articles? | Title identifies bylaws/founding text; separate registered instrument and registry concordance not established | NO — legal identity UNRESOLVED |
| Is the 3/4 quorum clause safe to implement as a universal meeting rule? | Article 17.1 text; manual v2.2 flags statutory conflict and leaves legal issue open | NO — meeting-type-specific legal reconciliation remains required |

**Resolution:** R11 resolves the *classification* of these sources and the exact claims each can support. It does not resolve which source is the operative registered instrument. No legal precedence is assigned between them.

## 5. Authority exception register

| ID | Authority exception | Evidence result | Final R11 status |
|---|---|---|---|
| AUTH-01 | Current Board membership, appointments and terms | Historical founding rosters only; no current adopted election/appointment resolution, term register or current registry evidence | OPEN — NOT ESTABLISHED |
| AUTH-02 | Current President / Chair and Secretary | Historical roles in source text; no current Board minutes/resolution confirming office-holders | OPEN — NOT ESTABLISHED |
| AUTH-03 | Current CEO / Managing Director appointment | Initial appointment statement in Article 13.3; no current appointment/renewal/termination record | OPEN — NOT ESTABLISHED |
| AUTH-04 | Delegation to President or executive | Articles 12.8–12.9 provide a textual delegation mechanism; no specific current Board delegation resolution, scope, limits, dates or revocation evidence | OPEN — NOT ESTABLISHED |
| AUTH-05 | Company signing authority | Article 12.9 describes signing subject to Board authorization; no current named signatory schedule or operative resolution | OPEN — NOT ESTABLISHED |
| AUTH-06 | Strategy approval authority | Board powers are broadly described; no validated strategy approval clause/competent decision sequence or adopted current enterprise strategy decision was established | OPEN — NOT ESTABLISHED |
| AUTH-07 | Procurement and Sales approval thresholds | No adopted Procurement policy, Sales/pricing/discount/credit/refund policy or valid monetary delegation schedule found. Historical draft thresholds remain non-authoritative | OPEN — NOT ESTABLISHED |
| AUTH-08 | Risk appetite and risk acceptance | Draft PSC language describes proposed controls; no adopted risk appetite, risk-acceptance matrix or current competent authority appointment | OPEN — NOT ESTABLISHED |
| AUTH-09 | Compliance / Control Committee appointment and mandate | Provisional control principles are Owner-approved, but current committee appointment, charter, membership, quorum, term and delegated powers are not established | OPEN — NOT ESTABLISHED |
| AUTH-10 | Legal instrument identity and precedence | Two company-source versions and a separate-instrument identity gap remain | OPEN — NOT ESTABLISHED |
| AUTH-11 | Meeting quorum and proxy legal interpretation | Company text and draft manual expose unresolved statutory/concordance issues; manual is not adopted | OPEN — NOT ESTABLISHED |
| AUTH-12 | Adoption/ratification evidence for GA Resolution 0001/2019 and current strategic-plan decision | Targeted repo/Library search did not return the signed resolution/minutes or ratification record; legacy SQL contains a scheduled GA notice/agenda only | OPEN — NOT ESTABLISHED |

### 5.1 Authority controls that are safely resolved now

The current-main document approval matrix blob `f53446ece9481ddf09759854cf2fd63e4ba20709` already establishes the correct fail-closed control design:
- no approval/signing/publication authority is assigned from a job title, uploader identity, role slug or draft policy;
- authority must be tied to the applicable source instrument, current appointment/delegation, scope, dates, required quorum/resolution and separation-of-duties controls;
- missing, conflicting, expired, revoked or untraceable evidence results in DENY;
- the current Governance authority-grant implementation is narrow and does not act as a generic financial/document-approval delegation mechanism.

The provisional PSC control matrix remains limited to control principles. It does not establish legal powers, committee appointments, quorum, monetary limits or specific approvers.

**Control-design exception: RESOLVED / FAIL-CLOSED. Business authority assignment: OPEN.**

## 6. Contract exception register

| ID | Contract exception | Resolution | Status |
|---|---|---|---|
| CONTRACT-01 | D01 subordinate spec assigns strategic objective hierarchy to D01, conflicting with frozen catalogue | New controlled D01–D02 objective-ownership clarification addendum on PR #222. D01 owns strategy/planning baseline; D02 owns authoritative enterprise objective records/hierarchy and performance measures; D01 keeps only alignment references | RESOLVED AT BOUNDARY LEVEL |
| CONTRACT-02 | D01/D02 API/event/business reference IDs and interface version | No accepted contract ID/version exists in exact-main evidence. Do not invent one | OPEN — NOT ESTABLISHED |
| CONTRACT-03 | Named D01/D02 source owners and approval authorities | Domain ownership is known; current human appointments are not | OPEN — NOT ESTABLISHED |
| CONTRACT-04 | D01-to-D07 and D01-to-D23 consumer contracts | Ownership boundary is defined; versioned producer/consumer contracts and acceptance evidence are absent | OPEN — NOT ESTABLISHED |
| CONTRACT-05 | D04/D05 risk/compliance source contracts | Draft PSC controls do not constitute adopted risk/compliance domain contracts | OPEN — NOT ESTABLISHED |
| CONTRACT-06 | D15/D17 Procurement/Sales policy and source contracts | Draft workflows/thresholds are not adopted business contracts; D15/D17 remain MISSING | OPEN — NOT ESTABLISHED |
| CONTRACT-07 | Cross-domain legal/governance authority envelope | No authenticated current appointment/delegation and accepted authority reference contract is established | OPEN — NOT ESTABLISHED |

## 7. No-change confirmation — Owner-approved domain dispositions

R11 does not change the frozen 30-domain catalogue or any Owner-approved disposition.

| Disposition | Domains | Count |
|---|---|---:|
| SUPPORTED | D08, D22 | 2 |
| PARTIAL | D02, D03, D06, D09, D10, D11, D13, D14, D20, D21, D23 | 11 |
| UNVERIFIED | D01, D04, D05, D07, D18, D24 | 6 |
| MISSING | D12, D15, D16, D17, D19, D25, D26, D27, D28, D29, D30 | 11 |
| **Total** | **D01–D30** | **30** |

Specific preserved decisions:
- D01 remains UNVERIFIED.
- D02 remains PARTIAL.
- D03 remains PARTIAL.
- D04 and D05 remain UNVERIFIED.
- D06 remains PARTIAL.
- D12, D15 and D17 remain MISSING.
- No domain was upgraded or downgraded.

## 8. Updated A2 closure-readiness assessment

| Criterion | Status | R11 evidence-based result |
|---|---|---|
| C01 — Frozen 30-domain inventory | PASS | Catalogue and Owner dispositions unchanged |
| C02 — Domain ownership / authoritative human owners | PARTIAL | D01/D02 boundary clarified; current appointments and named custodians remain unverified |
| C03 — Dependencies / integration contracts | PARTIAL | Boundary definitions improved; accepted API/event IDs and versions remain absent |
| C04 — Legal / governance authority | UNRESOLVED | New company-source text recovered, but operative registered version, current Board/executive appointments and specific delegations remain unverified |
| C05 — Finance / Treasury | PARTIAL | Domain-level statuses unchanged; primary financial source evidence remains deferred until post-deployment |
| C06 — Missing domain contracts | NOT MET | Eleven domains remain MISSING and six remain UNVERIFIED |
| C07 — Exact-head implementation mapping / acceptance | PARTIAL | Repository snapshots identified; no universal exact-head acceptance suite executed |
| C08 — Contradictions / exceptions | PARTIAL | D01/D02 boundary resolved; source-version and legal-authority conflicts remain open |
| C09 — Exact-SHA evidence package | PASS WITH LIMITATION | R10 index plus R11 source hashes and authority/contract register; Library source hashes are SHA-256, not Git blob SHAs |
| C10 — Owner closure decision | NOT GRANTED | KEEP A2 OPEN — DO NOT CLOSE remains controlling |

**Closure readiness: NOT READY.**

The newly recovered source improves evidence coverage but does not support a final A2 closure submission. The current authority-source conflict is material; current corporate authority and accepted contracts cannot be inferred from founding-era text or draft manuals.

## 9. Post-deployment evidence boundary

Primary financial/corporate source evidence remains deferred until ARNA is deployed and running on the server. No pre-deployment upload is requested. R11 does not create a bank-document request or make such records a pre-deployment closure gate.

The present pre-deployment blockers are the legal/source identity and authority evidence described in AUTH-01 through AUTH-11, plus the missing accepted domain contracts. Post-deployment source intake must be secured and authenticated under the approved Documents/Records and Governance controls.

## 10. Verification and change control

- API `main`: `6e274c25ecc55b84e026e879d735b77f0e51592f` — no R11 changes.
- App `main`: `ffe0495c0eb7c8b5d2013419123aac5b68893d51` — no R11 changes.
- API/App source, routes, models, migrations, schema, seeders, runtime configuration, secrets and production data: unchanged.
- R11 work: documentation-only on the existing PR #219 branch.
- D01/D02 clarification: documentation-only on the existing PR #222 branch.
- Tests/builds/CI: NOT RUN.
- A4 acceptance and operating-effectiveness verification: NOT RUN.
- Merge: NOT AUTHORIZED.
- A3 implementation: NOT AUTHORIZED.
- A4: NOT STARTED.
- A5 deployment: NOT AUTHORIZED.

## 11. Controlling decision and closure submission

**KEEP A2 OPEN — DO NOT CLOSE** (Owner decision recorded 2026-11-24).

R11 v1.0.1 is an evidence-based exception reconciliation, not a closure approval. The D01/D02 ownership ambiguity is resolved at the boundary level, and the authority-control design is confirmed fail-closed. The bounded search for GA Resolution 0001/2019 and current appointment instruments did not locate an authenticated record. The remaining legal-source, current-appointment, delegation, strategy-approval, committee-mandate and domain-contract exceptions remain OPEN / NOT ESTABLISHED.

Because material exit criteria C02–C08 remain partial, unresolved or not met, **no final A2 closure-readiness submission is warranted at this time**. Preserve the A2 baseline and proceed only with evidence-backed reconciliation; do not infer authority, reclassify domains, merge, implement or deploy.
