# ARNA GA A3 — Statutory Controls and Fail-Closed Gate

**Status:** A3 implementation increment; API integration and verification pending  
**Branch:** `feat/ga-statutory-controls-a3-current`  
**Base:** current `main` at `ec81c97bb9b06665236e3577ab669a46e7073a5d`

## Legal baseline

The research baseline is the Commercial Code of Ethiopia, Proclamation No. 1243/2021, Book Two, Title Six (Share Company), Chapter Five (Shareholders' Meetings). Its applicability remains conditional on authentication of ARNA's registered legal form and operative corporate instruments. Relevant statutory anchors include Arts. 365–372 (meeting categories, calling and notice), 373 and 377 (proxy), 383–386 (voting), 387–391 (minutes and resolutions), 398 (ordinary meeting quorum), 402 (extraordinary meeting quorum), and 406 (special meeting quorum).

Sources: [Federal Ministry of Justice](https://justice.gov.et/en/sector/trade-commercial-regulations/), [WIPO Lex](https://www.wipo.int/wipolex/en/legislation/details/21658), [English text](https://ethiodata.et/wp-content/uploads/2023/01/Commercial-Code-of-Ethiopia-English-Proclamation-No.-1243_2021.pdf).

No numerical quorum, notice period, voting cap, proxy deadline, penalty, or minutes signatory rule is activated by this increment. The distinct 17-page public-use and 22-page internal-use bylaws remain separate evidence; no hierarchy is inferred.

## Gate behavior

`GovernanceMeetingLegalGate` fails closed for unknown actions and requires verified share-company legal form, meeting category, operative legal-basis reference, verification attestation, reviewer separation, and action-specific approved evidence. It does not infer or calculate statutory rules.

## Lifecycle attestation controls

The API now provides evidence-backed lifecycle attestation records for notice, quorum, voting and minutes. Each record stores a source reference, SHA-256 fingerprint and optional structured details, begins in `pending_review`, and requires a different authorized reviewer to verify or reject it. Meeting detail responses expose the legal-basis record, action evidence and lifecycle attestations. These records attest to independently reviewed source artifacts; they do not themselves send notices, calculate statutory quorum, count share-weighted votes, generate minutes or authenticate source documents. No statutory threshold or numerical rule is inferred.

GA proxy approvals and penalty assessments are blocked until the relevant action evidence passes the legal gate. Legal-basis/evidence verification and lifecycle attestation review require `governance.meeting.approve`; source registration requires `governance.body.manage` or the meeting-management permission.

## A3 work remaining

- Persist legal-basis and per-action evidence with tenant isolation, immutable source references/hashes, pending/verified/rejected/revoked states, and independent reviewer controls.
- Wire the gate into proxy approval and penalty assessment; ensure unverified penalty policy cannot be assessed or posted.
- Add endpoint and regression tests, run exact-head CI/tests, and inspect current `main` divergence before any merge proposal.
- Obtain independent Ethiopian corporate-law review before enabling any legal rule.

This branch does not merge, deploy, or modify production records. The existing A3 service tests have not yet been run.
