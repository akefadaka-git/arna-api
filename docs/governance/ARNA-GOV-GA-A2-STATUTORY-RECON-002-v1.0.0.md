# ARNA General Assembly — A2 Statutory and Source-Clause Reconciliation

- **Document ID:** ARNA-GOV-GA-A2-STATUTORY-RECON-002
- **Version:** 1.0.0
- **Stage:** A2 — read-only legal/source reconciliation
- **Status:** PARTIAL — statutory baseline identified; company-specific activation remains gated
- **Date:** 2026-11-25
- **Repository:** `akefadaka-git/arna-api`
- **Branch:** `docs/arna-d01-a2-evidence-register`
- **Current API main inspected:** `f3fdd3219b7f9c5b1ff55ef24929fd5f2ec42446`
- **Frozen A1:** `docs/governance/ARNA-GOV-GA-A1-001.md`, blob `c73b9ce63219cf39529adc303b9e58ef09838507`
- **Prior A2 source register:** `docs/governance/ARNA-GOV-GA-A2-SOURCE-RECON-001-v1.0.0.md`, blob `5ddd6cd5f44fa14117ea665f4a6435e6558f7dd5`
- **Prior A2 traceability:** `docs/governance/ARNA-GOV-GA-A2-TRACE-001-v1.0.0.md`, blob `4582de1d64f34c3b17f7e922cbf0b2dd2a10141b`

## 1. Purpose, evidence standard and effect

This is a controlled A2 addendum to the previous source-register reconciliation. It identifies the relevant provisions in the Commercial Code of Ethiopia, Proclamation No. 1243/2021, and compares them with the source-clause leads already recorded for ARNA.

This addendum does not:
- amend or overwrite the frozen A1 baseline;
- authenticate the original Articles, the 17-page public/government-use bylaws or the separate 22-page internal-use bylaws;
- determine which company instrument is registered, incorporated, amended, currently operative or controlling;
- provide a formal Ethiopian-law opinion on ARNA's instrument hierarchy or every application of the Code;
- activate any rule in software, approve a meeting, validate a shareholder register, create a liability, or authorize A3, merge or deployment.

The English statutory text is used as a working research source. The Federal Negarit Gazette's Amharic text is controlling if the English translation differs. Before legal reliance, Ethiopian counsel must confirm the Gazette text, amendments and applicability to ARNA's registered company form and operative instruments.

**Important correction to the prior A2 register:** the prior report's statement that no statutory quorum, notice, proxy or minutes rules had been identified was too broad. The Code contains detailed shareholder-meeting rules in Articles 362–391 and class-specific quorum provisions in Articles 398, 402 and 406. The previous report remains historically preserved; this addendum updates the parameter interpretation only.

## 2. Statutory source and applicable scope

Commercial Code Proclamation No. 1243/2021 was published in the Federal Negarit Gazette on 12 April 2021 and is listed as in force. Its Title Six provisions on share companies contain Chapter Five, “Shareholders' Meetings,” Articles 362–406. Article 365 distinguishes ordinary and extraordinary general meetings (all shareholder classes) from special meetings (a particular class).

The statutory provisions directly relevant to this reconciliation are:
- Meeting classification, calling, notice and agenda: Articles 365–372.
- Proxy and conduct of business: Articles 373–374 and 377.
- Attendance sheet and chair: Articles 378–380.
- Voting rights, limitations and conflicts: Articles 383–386.
- Minutes, extracts, adjournment and effect of resolutions: Articles 387–391.
- Ordinary meeting quorum and majority: Article 398.
- Extraordinary general meeting quorum and majority: Article 402.
- Special class meeting quorum and majority: Article 406.

**Scope gate:** these provisions are for a share company. The ARNA A1 identifies the company as a share company, but counsel must verify the company's registered legal form and registration particulars against the official commercial register before enabling production rules.

## 3. Exact statutory provision reconciliation

### 3.1 Calling authority and notice method — Articles 366–367

Article 366(1) identifies the directors, supervisory board (if any), auditors, liquidators or, where appropriate, the court as bodies that call general meetings. Article 366 also provides court-related mechanisms where the competent company organ does not call a meeting in circumstances provided by the Code. The meeting cannot be treated as validly convened merely because a user creates a calendar event or has an administrator role.

Article 367(1) allows the Memorandum of Association to prescribe a reliable and effective method for calling shareholder meetings. In the absence of such rules, notices are published in a newspaper with nationwide circulation. Article 367(2) provides an alternative where all shareholders are registered in the shareholder register: the company may call the meeting at its expense by registered letter, email address given for that purpose, or another reliable electronic message sent to each shareholder. Article 367(3) addresses the notification choice of a registered shareholder in a company that also has bearer shares.

**ARNA source reconciliation:** no authenticated operative Memorandum clause or current statutory shareholder register was available in the inspected repository. Thus the Code supplies a default method and a registered-shareholder alternative, but the company-specific method cannot yet be selected. Do not treat a platform push notification, email delivery event or calendar reminder as legally sufficient service without confirming the Article 367 method and proof of dispatch/service.

### 3.2 Notice period and notice contents — Articles 369–372

Article 370 states that notice for a first general or special meeting is to be given twenty-four days before the meeting. Article 371 requires the notice to state the company name, nature, capital, registration number, head-office location, any bearer-share deposit place/time, meeting place/time and agenda. A notice for a subsequent meeting required due to lack of quorum must also state the dates of the abortive meeting(s).

Article 369 provides that where an extraordinary or special meeting fails for lack of quorum, a second and, if necessary, third meeting are called at one-week intervals in accordance with Articles 366 and 367. Article 368 provides that a second ordinary general meeting is called in the same manner and within the same period of time as the first meeting.

Article 372 regulates the agenda. Only agenda items may ordinarily be discussed, subject to the statutory exceptions and the precise provisions of that Article. The A1 requirement to control agenda publication and changes is consistent with the Code's notice/agenda protections.

**ARNA clause lead:** P2.5 reports a thirty-day GA notice clause from OCR/page-image recovery of the 17-page bylaws. The report expressly says the exact Amharic wording and clause mapping need checking. This is not yet a verified clause from the registered Memorandum.

**Reconciliation:** the Code's first-meeting notice period is twenty-four days. The reported thirty-day company clause, if accurately transcribed, validly adopted and applicable, may be a longer company-specific notice requirement; do not silently replace it with twenty-four days. Conversely, do not enforce thirty days from the provisional OCR summary. Counsel must determine whether the verified company clause is operative and whether it supplements the statutory period. Until then, meeting issuance remains blocked.

### 3.3 Quorum, majority and adjourned/reconvened meetings — Articles 374, 368–369, 398, 402 and 406

Article 374(1) provides that a meeting may pass a decision only when the necessary quorum is met and the majority of those present supports the motion. Article 374(2) requires quorum to be calculated by reference to the capital represented by shares, excluding shares that carry no voting rights under law and taking account of the capital represented by the remaining shares. Article 374(3) states that the Memorandum of Association may not vary the Code's provisions relating to majority and quorum.

The class-specific statutory rules are:

| Meeting class | First meeting | Subsequent meeting(s) | Majority / decision rule |
|---|---|---|---|
| Ordinary general meeting — Article 398 | Voting shares representing at least one-quarter of the company's capital must be represented in person or by proxy | If called a second time under Article 368, the meeting may be held and decisions made without regard to the number of voting shares represented | Simple majority of capital represented by shares carrying voting rights; abstentions and blank ballots disregarded |
| Extraordinary general meeting — Article 402 | Holders of at least one-half of all shares carrying voting rights must be present or represented | Second meeting: at least one-third; third meeting: at least one-tenth | At least two-thirds majority of capital represented by voting shares; special matters under Article 402 have additional unanimity/consent requirements |
| Special meeting of a class — Article 406 | Shares carrying voting rights representing at least one-half of the capital represented by the class concerned | Second meeting: at least one-third; third meeting: at least one-quarter | Majority as provided under Article 402(1), i.e. the two-thirds majority rule |

**Quorum calculation caveats:**
- Do not confuse the number of persons present with the amount of voting capital represented. Articles 374 and 398 frame the ordinary quorum by capital represented.
- Article 385(3) provides that shares deprived of voting rights for the conflict-of-interest reasons in Article 385 are nevertheless taken into account in calculating quorum. This is a specific statutory exception to a simplistic “exclude every non-voting vote from quorum” implementation. It must be represented in the legal calculation model.
- Article 375 says shares redeemed by the company under Article 275 carry no voting rights.
- A class meeting under Articles 404–406 is not the same as an ordinary or extraordinary GA.
- Article 389 permits shareholders representing at least one-third of the capital represented at a meeting, where they consider information insufficient, to require adjournment for no more than three days; the right may be exercised once for a matter on the agenda. This is not a substitute for lack-of-quorum reconvening under Articles 368–369.

**ARNA source lead:** the historical Articles notes report “more than half of capital” in Article 17, but explicitly classify that summary as unverified. The reported clause cannot replace the statutory class-specific thresholds. It may reflect a different instrument, an older provision, a particular class/meeting type or an inaccurate summary. Exact text, document identity, amendment history and legal interaction must be checked before any company-specific rule is inferred.

**Reconciliation:** statutory quorum is no longer wholly unknown. It is now specified by meeting class in the Code. However, A2 must keep meeting creation/decision acceptance blocked until the meeting type and business are classified, current voting capital is reconciled, the shareholder/representation snapshot is authoritative, and the required statutory notice/calling sequence is proven. No universal 50% rule and no election `half_register` rule may be used.

### 3.4 Voting rights and electorate — Articles 278–280, 274, 271, 375 and 383–386

Article 383(1) provides that the weight of votes attached to ordinary or dividend shares is proportional to the amount of capital represented. Article 383(2) provides that, unless voting rights are deprived by law, every share carries at least one vote. Article 384 permits the Memorandum to limit the highest number of votes a shareholder may exercise, but the limitation must be equal for all shares without distinction of class.

The wider share provisions matter:
- Article 274 requires a share company to keep a shareholder register containing shareholder names and addresses, number/numeration of shares, amount paid up and date of entry.
- Article 278 permits the Memorandum or an amendment to create share classes with different rights, subject to the Code.
- Article 279(2) prohibits issuing shares with preferential voting rights; Article 279(3) allows specified restricted-voting arrangements for certain preference shares, subject to the limits in Article 279(4).
- Article 271 addresses voting rights where shares are pledged or in usufruct, subject to agreement and registration.
- Article 375 excludes company-redeemed shares from voting.
- Article 385 disqualifies a shareholder from voting on a matter where that shareholder's interests conflict with the company's interests; directors also may not vote on resolutions relating to their duties/liabilities and specified conflicts. Article 385(3) says the affected shares are still counted for quorum.
- Article 386 invalidates agreements that restrict or have the effect of restricting the free exercise of voting rights.

Articles 397 and 401 separately state that shareholders may participate in ordinary and extraordinary meetings irrespective of the number of shares they hold. That participation right does not convert the vote-weight rule into one-person-one-vote.

**ARNA source reconciliation:** P2.5 reports historical totals of 29 holders and 145 shares, but expressly says these do not establish the current statutory register, current ownership, share classes or voting rights. No current statutory register, transfer/pledge/usufruct reconciliation, class-right schedule or valid Memorandum vote-cap clause was available in the inspected repository.

**Reconciliation:** the legal framework is identified, but individual voting entitlement and the numerical denominator remain unverified. Do not freeze the electorate or calculate votes from historical founder totals, seeded records or an assumed equal-vote model. Obtain the statutory register and reconcile each share's current holder, class, paid-up status, transfer, pledge/usufruct and legal voting status. Determine any valid cap and matter-specific conflict disqualification from operative evidence. Do not activate electronic voting: A1 separately requires further corporate/legal/security authorization.

### 3.5 Proxy participation, form and deadline — Articles 373 and 377

Article 373(1) permits a shareholder unable to attend to take part and vote through a proxy; once a proxy is appointed, the shareholder may neither participate nor vote personally. Article 373(2) applies Articles 270 and 271 to joint share holders, usufructuaries and pledgees.

Article 377(1) provides that the Board determines the content of the proxy form, place of deposit and time for deposit, but the deposit period may not expire more than three days before commencement of the meeting. Article 377(2) states that a proxy for participation in a shareholders' meeting need not be authenticated before a document-authentication and registration agency or another relevant institution.

Article 378 requires an attendance sheet recording shareholders present or represented by proxy and their shares and votes. It must be initialled before the meeting by shareholders or proxies physically present and certified as correct by the company secretary.

**ARNA clause lead:** P2.5 reports a written, dated, shareholder-signed proxy clause allowing a non-shareholder representative in the 17-page bylaws recovery, but exact wording and clause mapping are provisional.

**Owner policy lead:** GA A1 records submission at least three days before the meeting; the separate Board proxy input is three hours before a Board meeting.

**Reconciliation:**
- The GA three-day cut-off can be aligned with Article 377 if implemented as a deadline no earlier than three days before the meeting. The Board's three-hour rule is separate and must not be applied to GA.
- Do not interpret “at least three days before” to impose a deadline earlier than the statutory three-day boundary. The final UI and policy wording must say precisely whether the deadline is at T-3 and specify local time.
- The Code says proxy authentication by the public document-authentication/registration agency is not required. Do not impose notarization or public authentication as a default. This does not prevent internal verification of the principal, representative, authority, form, scope, revocation and duplicate use.
- The Code does not itself establish all company-specific form fields or all representative/cap rules. The Board's statutory authority to set proxy-form content and deposit logistics must be exercised under the operative company governance and must not contradict the Code or valid company instruments.
- The reported signed/dated/written clause remains a provisional company-source lead. Verify the exact Amharic clause and legal status before enforcing it. The Code's no-public-authentication rule must not be misread as a prohibition on a company requiring a written or signed proxy instrument.
- Enforce the one-personal-or-proxy participation rule and prevent duplicate voting entitlement.

### 3.6 Attendance penalties — no identified enabling provision in Articles 362–406

The reviewed shareholder-meeting chapter establishes meeting, notice, attendance, proxy, quorum, voting and record rules. It does not, in the provisions reviewed, create a statutory ETB 50 late-arrival fine or ETB 300 absence fine for shareholders.

**ARNA policy lead:** A1 records ETB 50 for late arrival after 9:00 and ETB 300 for unexcused absence without proxy/permission. It names `ARNA-GOV-POL-MEETING-ATTENDANCE-001` and `ARNA-GOV-LEGAL-MEMO-MEETING-ATTENDANCE-001`, but neither artifact was found in the inspected current-main or working-branch repository tree. The amounts remain Owner-confirmed policy inputs, not a verified adopted instrument.

**Reconciliation:** this research did not identify a Commercial Code provision that itself authorizes these amounts. That is not a definitive conclusion that no company-law or contractual basis could exist. Counsel must identify the lawful source, competent adopting organ, member notice/consent requirements, exceptions, proportionality, challenge/appeal and enforcement route. Until then:
- no automatic debt, receivable or payable liability;
- no deduction from dividends, shares or voting rights;
- no automated wallet/Finance posting;
- no penalty enforcement solely from attendance timestamps;
- only a non-binding penalty candidate may be recorded for later authorized review, if permitted by the approved technical contract.

### 3.7 Minutes, signatories and certification — Articles 340–341 and 387–388

The prior A2 register classified minutes approval as MISSING/UNRESOLVED. That requires refinement: Article 387 already provides a statutory minimum minutes and certification procedure.

Article 387(1) requires meeting discussions to be reduced to minutes, signed by the directors who were present and the company secretary, entered in a minute book, and certified as correct by the chairperson of the Board or by two other directors.

Article 387(2) provides an alternative: before business begins, the meeting may elect three shareholders from those present to sign and certify the minutes entry in place of the directors and company secretary. The election may occur even if not on the agenda.

Article 387(3) requires minutes to include, in particular:
- manner in which the meeting was called;
- place and date;
- agenda;
- directors present;
- number of shares represented and quorum;
- documents laid before the meeting;
- summary of discussions;
- voting results; and
- texts of resolutions adopted.

Article 387(4) requires the chairperson to cause the lack of quorum to be recorded in the minutes. Article 388 governs certification of copies or extracts. Articles 340–341 identify the company secretary and the secretary's duty to organize and keep company information/records and prepare, organize and keep minutes.

**Reconciliation:** the statutory signatory/certification route is no longer missing. It is a legal baseline. However, there is no authenticated ARNA adopted minutes procedure, no verified current appointment record for the relevant directors/secretary, and no actual adopted minutes package in the inspected repository. The Code does not support inventing a further blanket requirement that the next GA must approve every prior meeting's minutes. Nor should the software replace Article 387's signatory alternatives with a secretary-only or chair-only approval flow.

The implementation must preserve:
- the selected statutory signing route and the identities/authority of its signatories;
- the three-shareholder alternative only where elected before business begins;
- minute-book entry and the required contents;
- quorum-failure notation;
- correction/corrigendum version history without overwriting the signed record;
- certified copy/extract workflow under Article 388.

The precise process for draft review, approval timing, objections, corrections, filing and retention beyond the statutory minimum remains subject to counsel and the operative instruments.

## 4. Six parameter dispositions — updated

| Parameter | Prior A2 disposition | Updated A2 disposition | What is established | What remains blocked |
|---|---|---|---|---|
| Quorum | UNRESOLVED / BLOCKED | **PARTIAL — statutory thresholds identified; operation blocked pending classification and authoritative denominator** | Ordinary, extraordinary and special meeting thresholds; reconvening rules; quorum calculation framework in Articles 368–369, 374, 398, 402 and 406 | Confirm company form; classify meeting and resolutions; current voting-share register; representation; conflict-of-interest quorum exception; valid notice/calling sequence; counsel application |
| Notice | PARTIAL / BLOCKED | **PARTIAL — statutory 24-day first notice and statutory service defaults identified** | Articles 367, 370–372; subsequent meeting procedures in Articles 368–369 | Verify operative MOA method, 30-day bylaw clause, current register completeness, channel/recipient evidence and agenda |
| Voting rights | PARTIAL / BLOCKED FOR CONFIGURATION | **PARTIAL — statutory vote-weight and limitation framework identified** | Articles 271, 274, 278–280, 375 and 383–386 | Current register, transfers, classes, paid-up and pledge/usufruct status, vote cap, conflicts, matter-specific threshold |
| Proxy | PARTIAL / BLOCKED | **PARTIAL — statutory proxy entitlement and T-3 deposit boundary identified** | Articles 373, 377–378; no public authentication requirement | Verify bylaw formality clause, Board-approved form/deposit process, exact T-3 wording, identity/scope/revocation and duplicate controls |
| Penalties | PARTIAL / NOT LEGALLY ACTIVATED | **PARTIAL — no statutory fine amount identified in shareholder-meeting chapter; policy source still missing** | Owner-confirmed ETB 50/ETB 300 policy inputs only | Adopted policy, competent approval, legal basis, exceptions, appeal, independent assessment and Finance treatment |
| Minutes approval | MISSING / UNRESOLVED / BLOCKED | **PARTIAL — statutory signatory/certification route identified; ARNA procedure and actual records missing** | Articles 340–341, 387–388 | Verify officeholders, actual approved minutes, chosen statutory route, operative procedure, corrections, filing/retention |

**A2 traceability effect:** these parameter-level refinements do not silently rewrite the frozen A1 document or the prior 17-group GA traceability report. The report's group tally remains historical until a separately versioned traceability reconciliation maps the updated evidence to every affected requirement. GA-R11 may remain MISSING as an end-to-end minutes/resolution/execution workflow even though the statutory minutes rule is now identified.

## 5. Source-clause comparison and unresolved identity

| Source | Available clause lead | Statutory comparison | Status |
|---|---|---|---|
| Original Articles of Association | Historical notes attribute Article 17 quorum “more than half of capital”; Article 8 proxy; Article 9 majority/deposit | Must be compared with Articles 368–370, 373–377, 374, 398, 402 and 406 of the 2021 Code | Unverified historical summary; no original binary in repo |
| 17-page public/government-use bylaws | P2.5 reports 30-day notice and written/dated/shareholder-signed proxy allowing non-shareholder representative | Code sets 24 days for first notice; Article 377 sets Board proxy form/deposit authority and T-3 boundary, and says public authentication is not required | Provisional OCR/source recovery; exact Amharic clause and operative status not verified |
| 22-page internal-use bylaws | Owner-confirmed separate internal document; not located in inspected repository tree | May contain internal procedure, but cannot override mandatory statutory rules or be presumed to supersede the other instruments | Missing from inspected repository; legal identity/hierarchy unknown |
| GA/Board rules of procedure | No authenticated adopted GA rules package located | Can operationalize lawful details but cannot vary statutory quorum/majority under Article 374(3) | Missing |
| Attendance/penalty policy | Owner-confirmed values and deadline recorded in A1; named policy file unavailable | No ETB 50/ETB 300 enabling provision identified in Articles 362–406 | Policy artifact/approval/legal basis missing |
| Technical implementation | Meeting, attendance, proxy and penalty routes/schema/test fixtures exist | Software must implement statutory rules only after source/authority/identity gates pass | Technical existence is not corporate evidence |

No hierarchy is inferred among the original Articles, 17-page public-use bylaws and 22-page internal-use bylaws. The P2.5 statement that the 17-page scan is Owner-confirmed as the latest complete approved bylaws version remains Owner-supplied provenance, not independent authentication of the registered Memorandum or every clause's legal effect.

## 6. Fail-closed controls retained and refined

The following controls remain mandatory:
1. Do not create/issue a legally operative GA meeting until convening authority, meeting class, applicable notice method, recipients and notice evidence are validated.
2. Do not evaluate quorum from a hard-coded universal percentage or from election `half_register`. Use only the statutory class-specific rule once meeting class and voting-capital denominator are authoritative.
3. Do not proceed to a resolution unless both quorum and the correct statutory majority/threshold are met.
4. Apply Article 385 conflict rules carefully: a conflicted shareholder may not vote on the relevant matter, while the shares remain counted for quorum under Article 385(3).
5. Do not freeze electorate/vote weights from historical founder totals or seeded data; reconcile the statutory register and share rights.
6. Do not require public authentication/notarization of proxies as a default contrary to Article 377(2). Verify the proxy and prevent personal/proxy double participation.
7. Enforce the statutory proxy deposit boundary and keep the GA and Board proxy deadlines separate.
8. Do not create a payable penalty from a calculated candidate or attendance timestamp; no Finance posting or dividend deduction without lawful authority and independent approval.
9. Do not finalize minutes using a secretary-only or chair-only shortcut. Follow one of the statutory Article 387 signing/certification paths and retain the evidence.
10. Preserve statutory notice, attendance, minutes and resolution evidence in immutable/versioned records. Calendar entries and delivery notifications are supporting evidence, not substitutes for the statutory acts.

## 7. Legal review questions for competent Ethiopian counsel

1. Confirm the registered company form and whether the share-company provisions in Articles 362–406 apply without qualification to ARNA.
2. Compare the exact original Articles and all registered amendments with the 17-page and 22-page bylaws; identify incorporation, approval, registration, hierarchy and current operative status.
3. Verify the 30-day notice clause and determine whether it is a valid company-specific longer period alongside Article 370's 24-day statutory period.
4. Verify the historical Article 17 “more than half of capital” clause and determine whether it is superseded, inaccurate, meeting-class-specific or otherwise inapplicable under Articles 374, 398, 402 and 406.
5. Confirm the shareholder register, class rights, paid-up status, pledges/usufructs, vote caps and matter-specific conflicts.
6. Confirm that the GA three-day proxy cut-off is worded and administered consistently with Article 377(1); verify any company-specific written/signed proxy requirements and representative limits.
7. Identify the legal basis and competent approval for the ETB 50/ETB 300 sanctions, including whether they may validly apply to shareholders.
8. Confirm the statutory minutes signatory/certification path under Article 387 and the filing, correction and retention requirements under Articles 387–388 and other applicable laws.

## 8. Final status and stage boundary

- Statutory provisions identified and cross-mapped to six A1 parameters: **PASS — research/documentation complete for this bounded A2 increment**.
- Exact Amharic Gazette text independently checked by counsel: **NOT RUN**.
- Original Articles and both bylaws instruments authenticated and reconciled: **NOT VERIFIED**.
- Current shareholder register reconciled: **NOT RUN**.
- Meeting class and matter classification: **NOT RUN**.
- Six parameter dispositions: **PARTIAL; all operational activation gates retained**.
- Runtime/database inspection: **NOT RUN**.
- Tests/builds/workflows: **NOT RUN**.
- A3 implementation: **NOT AUTHORIZED**.
- A4 verification: **NOT STARTED**.
- A5 deployment: **NOT AUTHORIZED**.
- Merge: **NOT AUTHORIZED**.

This addendum is documentation-only. No source files, frozen A1 baselines, code, schema, migrations, runtime configuration, production data or corporate records were changed.

## 9. Statutory references

- Commercial Code of the Federal Democratic Republic of Ethiopia, Proclamation No. 1243/2021, Federal Negarit Gazette, 27th Year No. 23, 12 April 2021, Book Two, Title Six, Chapter Five, Articles 362–406. English text: https://ethiodata.et/wp-content/uploads/2023/01/Commercial-Code-of-Ethiopia-English-Proclamation-No.-1243_2021.pdf
- Amharic version: https://ethiodata.et/wp-content/uploads/2023/01/Ethiopia-Commercial-Code-Amharic-Proclamation-No.-1243_2021.pdf
- WIPO Lex instrument record (Proclamation 1243/2021; entry into force 12 April 2021): https://www.wipo.int/wipolex/en/legislation/details/21658
- FAOLEX instrument record: https://www.fao.org/faolex/en/documents/LEX-FAOC212017/proclamation-no-1243-2021-the-commercial-code-of-the-federal-eth-2021

**Classification:** A2 legal/statutory research and source-clause reconciliation; not legal advice, a certified translation, a counsel opinion, a corporate resolution or implementation authorization.
