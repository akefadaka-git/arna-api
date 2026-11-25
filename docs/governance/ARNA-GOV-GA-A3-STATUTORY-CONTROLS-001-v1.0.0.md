# ARNA GA A3 — Statutory Controls and Fail-Closed Gate

**Status:** A3 implementation increment; API integration implemented; exact-head verification pending  
**Branch:** `feat/ga-statutory-controls-a3-current-main`  
**Base:** `main` at `28afcbd0b0a45a6062714ebc51993abe4d38387b`

## Legal baseline

The research baseline is the Commercial Code of Ethiopia, Proclamation No. 1243/2021, Book Two, Title Six (Share Company), Chapter Five (Shareholders' Meetings). Its applicability remains conditional on authentication of ARNA's registered legal form and operative corporate instruments. Relevant statutory anchors include Arts. 365–372 (meeting categories, calling and notice), 373 and 377 (proxy), 383–386 (voting), 387–391 (minutes and resolutions), 398 (ordinary meeting quorum), 402 (extraordinary meeting quorum), and 406 (special meeting quorum).

Sources: [Federal Ministry of Justice](https://justice.gov.et/en/sector/trade-commercial-regulations/), [WIPO Lex](https://www.wipo.int/wipolex/en/legislation/details/21658), [English text](https://ethiodata.et/wp-content/uploads/2023/01/Commercial-Code-of-Ethiopia-English-Proclamation-No.-1243_2021.pdf).

No numerical quorum, notice period, voting cap, proxy deadline, penalty, or minutes signatory rule is activated by this increment. The distinct 17-page public-use and 22-page internal-use bylaws remain separate evidence; no hierarchy is inferred.

## Gate behavior

`GovernanceMeetingLegalGate` fails closed for unknown actions and requires verified share-company legal form, meeting category, operative legal-basis reference, verification attestation, reviewer separation, and action-specific approved evidence. It does not infer or calculate statutory rules.

## Lifecycle attestation controls

The API now provides evidence-backed lifecycle attestation records for notice, quorum, voting and minutes. Each record stores a source reference, SHA-256 fingerprint and optional structured details, begins in `pending_review`, and requires a different authorized reviewer to verify or reject it. Meeting detail responses expose the legal-basis record, action evidence and lifecycle attestations. These records attest to independently reviewed source artifacts; they do not themselves send notices, calculate statutory quorum, count share-weighted votes, generate minutes or authenticate source documents. No statutory threshold or numerical rule is inferred.

GA proxy approvals and penalty assessments are blocked until the relevant action evidence passes the legal gate. Legal-basis/evidence verification and lifecycle attestation review require `governance.meeting.approve`; source registration requires `governance.body.manage` or the meeting-management permission.

## A3 implementation boundary and remaining work

Implemented in this increment:
- Tenant-scoped legal-basis and per-action evidence tables with source references and SHA-256 fingerprints.
- Independent verification controls for legal basis and action-specific evidence.
- Fail-closed gates on GA proxy approval and penalty assessment.
- Evidence-backed lifecycle attestations for notice, quorum, voting and minutes, with independent review and audit events.
- Meeting-detail visibility for legal-basis, evidence and lifecycle attestation records.
- Unit and feature tests for gate behavior.

Lifecycle attestations are controlled evidence records only. They do not send notices, calculate statutory quorum, count share-weighted votes, generate minutes or authenticate source documents. The next functional increment must build those substantive workflows on these gates. Controlled revocation/supersession is also still required before live legal rules are enabled.

## Verification and legal blockers

- Exact-head PHP lint, unit/feature suites, tenant-isolation regression and disposable MySQL migration/seed verification are pending GitHub Actions results.
- No test, migration or production database result is claimed as PASS.
- Authenticate ARNA's registered legal form, operative Articles/bylaws and amendments, current shareholder register and share classes.
- Obtain independent Ethiopian corporate-law review before enabling any statutory rule or penalty.
- Preserve the distinct 17-page public-use and 22-page internal-use bylaws; no hierarchy is inferred.

This branch is not merged or deployed. No production migration or production-record change has occurred.
