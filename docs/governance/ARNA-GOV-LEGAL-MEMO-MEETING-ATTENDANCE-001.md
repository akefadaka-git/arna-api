# Legal and Governance Reconciliation — Meeting Attendance Policy

**Reference:** ARNA-GOV-POL-MEETING-ATTENDANCE-001  
**Source instrument:** Maale Arna S.C. General Assembly and Board Meeting Rules of Procedure No. 001/2016  
**Status:** OWNER-CONFIRMED APPROVAL — A3 IMPLEMENTATION AUTHORIZED  
**Review record date:** 2026-11-02

## Approval record

The Owner has confirmed that the Memorandum reconciliation has already been supplied and completed and that the General Assembly approved the governing regulation. This repository record adopts that confirmation for the ARNA implementation gate. The uploaded original PowerPoint was inspected and its content reconciled into the implementation policy.

The original regulation states:
- Article 6.1: ETB 50 for late arrival.
- Article 6.2: ETB 300 for absence without sufficient evidence.
- Article 5.2: GA proxy arrangements must be submitted at least three days before the meeting.
- Article 8: the regulation applies from the GA approval date stated in the instrument.
- Article 9: amendments require Board action and General Assembly approval.

The Owner clarified that ETB 50 is a flat amount per meeting, not an hourly or minute-based escalation; a 09:05 arrival for a 09:00 start is late. The Owner also specified GA proxy notice at least three days before and Board proxy notice at least three hours before the meeting.

## Statutory implementation constraints

The implementation applies the following constraints from Commercial Code Proclamation No. 1243/2021:
- Article 310: under the default rule, a director's proxy must be another director; one director may hold only one proxy at a Board meeting, subject to the Memorandum.
- Articles 377–378: GA proxy deposit timing and the statutory attendance-sheet requirements apply. The implementation records the proxy submission timestamp and does not allow a deadline earlier than the three-day threshold. The statutory attendance sheet is preserved as a meeting record; late arrivals are timestamped in the system's supplemental attendance register.
- Article 397: GA proxy representation is subject to the Memorandum.
- Article 438: dividend payment is governed by the GA resolution and statutory timing.

Civil Code Articles 1831–1840 inform set-off controls. An attendance fine is never automatically deducted from a dividend. A dividend-settlement request is a separate pending record and remains blocked until legal eligibility, mutuality, due/liquidated status, notice, shareholder identity, Finance approval and a traceable Finance reference are confirmed.

## Implementation interpretation

1. **Late arrival:** record the actual arrival time. If it is later than the meeting's scheduled start, create one ETB 50 penalty assessment candidate for that member and meeting. No per-minute or per-hour increase.
2. **Absence:** on meeting close, create a pending-review ETB 300 assessment candidate only for an active body member with no accepted proxy, no attendance and no approved absence authorization.
3. **GA proxy:** accept submission only when received no later than the T-minus-three-day deadline; retain instrument reference, representative identity and received timestamp.
4. **Board proxy:** require another active member of the same Board body, reject self-proxy, enforce the T-minus-three-hour deadline, and enforce one accepted proxy held by any director per meeting.
5. **Attendance register:** record principal member, representative where applicable, arrival time, attendance state and recorder. Corrections are appended to the audit trail; records are not silently overwritten.
6. **Penalty review:** automatic detection creates a pending-review item, not a final debt. An authorized reviewer must assess or waive it with a reason.
7. **Payment:** record ETB payment amount, method, receipt reference and payment time. Payment records remain marked pending Finance reconciliation until linked to Finance evidence.
8. **Dividend settlement:** store a request only. No automatic offset, dividend mutation, or posting is performed by the Governance service.

## A3 scope and integration boundary

A3 includes the Governance meeting, proxy, attendance, penalty-review, same-day payment-recording and dividend-settlement-request workflows. The Finance domain remains the accounting system of record. The Governance subledger records source obligations and receipts; it does not invent ledger accounts, create or post a journal, or change a shareholder dividend. A Finance adapter must use the Finance domain's approved source-subledger contract and account mappings before any posting capability is enabled.

## Approval and evidence classification

| Item | Status |
|---|---|
| Original Rule 001/2016 received and inspected | VERIFIED |
| Memorandum reconciliation | OWNER-CONFIRMED COMPLETE |
| General Assembly approval | OWNER-CONFIRMED APPROVED |
| ETB 50 / ETB 300 policy amounts | VERIFIED IN SOURCE INSTRUMENT |
| GA three-day proxy control | IMPLEMENTED AS DEADLINE VALIDATION |
| Board three-hour proxy control | IMPLEMENTED WITH DIRECTOR-ONLY RULE |
| A3 implementation authorization | AUTHORIZED BY OWNER |
| Ethiopian counsel opinion independently authenticated in repository | NOT CLAIMED |
| Production Finance journal posting | NOT IN SCOPE / BLOCKED PENDING FINANCE ADAPTER APPROVAL |
| Production deployment and merge | NOT AUTHORIZED |

This record distinguishes Owner-confirmed corporate approval from an independently issued Ethiopian counsel opinion. It does not purport to be the latter.
