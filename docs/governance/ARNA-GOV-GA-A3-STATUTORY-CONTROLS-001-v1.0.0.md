# ARNA GA A3 — Statutory Controls and Fail-Closed Gate

**Status:** A3 implementation increment — code committed; integration and verification pending  
**Branch:** `feat/ga-statutory-controls-a3`  
**Base:** `main` at `5480215c74bc6f110b9b14ec1a9e3c649e493a2a`

## 1. Controlling legal source

The current research baseline is the Commercial Code of Ethiopia, Proclamation No. 1243/2021, Book Two, Title Six (Share Company), Chapter Five (Shareholders' Meetings). The Proclamation entered into force on 12 April 2021 and repealed Books One, Two and Three of Proclamation No. 166/1960. Sources:
- Federal Ministry of Justice, Trade & Commercial Regulations: https://justice.gov.et/en/sector/trade-commercial-regulations/
- WIPO Lex, Proclamation No. 1243/2021: https://www.wipo.int/wipolex/en/legislation/details/21658
- English text: https://ethiodata.et/wp-content/uploads/2023/01/Commercial-Code-of-Ethiopia-English-Proclamation-No.-1243_2021.pdf

Relevant statutory provisions to reconcile against the company's authenticated instruments:
- Arts. 365–372: meeting categories, calling, notice, notice contents and agenda.
- Arts. 373 and 377: proxy and proxy authority.
- Arts. 378–382: attendance, chair, tellers, inspection and information.
- Arts. 383–386: voting rights, limitations, conflicts and protection of free exercise.
- Arts. 387–391: minutes, copies, adjournment and effect of resolutions.
- Art. 398: ordinary meeting quorum and majority.
- Art. 402: extraordinary meeting quorum and majority.
- Art. 406: special meeting quorum and majority.

**No numerical rule is enabled by this increment.** The exact statutory text must be read together with the registered memorandum, authenticated operative bylaws, amendments, meeting category and any applicable special legal provisions. The company's registered legal form is not yet authenticated in the repository evidence; the share-company chapter therefore remains conditional.

## 2. Six parameter dispositions

| Parameter | Statutory anchor | Runtime disposition |
|---|---|---|
| Quorum | Arts. 398, 402, 406 | Block evaluation until legal form, meeting category, quorum rule and category rule are independently verified. |
| Notice | Arts. 366–372 | Block notice-validity determination until the applicable notice rule, service method and content are evidenced. |
| Voting rights | Arts. 375, 383–386 | Block voting until rights rule and authoritative share-register basis are evidenced. |
| Proxy | Arts. 373, 377 | Block proxy acceptance until formality and authority evidence are verified. |
| Penalties | No penalty authority inferred from the cited meeting provisions | Block assessment/posting until penalty authority and due-process source are verified. Owner-provided ETB 50/300 amounts remain unactivated policy inputs. |
| Minutes approval | Arts. 387–388 | Block approval/certification until approval rule and signatory authority are verified. |

## 3. Code delivered

`GovernanceMeetingLegalGate` provides a reusable fail-closed decision service:
- rejects unknown legal actions;
- requires verified share-company legal form and meeting category;
- requires a source reference and verification attestation;
- requires separation between meeting creator and legal-basis reviewer;
- requires action-specific evidence records with verified status, source reference, approval timestamp and approver;
- exposes both a structured decision and an exception-throwing assertion method.

It intentionally does not encode an assumed quorum percentage, notice period, proxy deadline, voting cap, penalty amount or minutes approver. A reference to a statute or draft document without an authenticated applicability decision is not enough to pass the gate.

## 4. Verification

A PHPUnit unit test file covers:
- missing legal form;
- draft/unverified quorum evidence;
- reviewer/creator conflict;
- complete verified evidence;
- unknown action;
- exception behavior.

**Tests have not been run in this environment.** No migration, database, API route or UI integration has been added in this increment. The service is not yet wired into existing meeting endpoints, so this commit must not be described as having blocked all current production paths. Integration is the next A3 task.

## 5. Remaining blockers

1. Authenticate ARNA's current commercial-register extract and legal form.
2. Authenticate the original Articles/Memorandum and identify amendments.
3. Authenticate and clause-compare the distinct 17-page public-use and 22-page internal-use bylaws; do not infer hierarchy.
4. Record current share classes, voting rights and the authoritative shareholder register cut-off process.
5. Record the competent body and source for adopting penalty policy, and due-process/appeal requirements.
6. Verify the statutory and instrument-based minutes signature/certification process.
7. Obtain independent Ethiopian corporate-law review before enabling legal rules.

## 6. Change control

This is an A3 code increment on a fresh branch from the current main SHA. It does not alter frozen A1/A2 baselines, merge, deploy, modify production records, or activate any unverified legal parameter.
