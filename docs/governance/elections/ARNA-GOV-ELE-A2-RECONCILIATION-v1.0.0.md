# ARNA Board Election System — A2 Reconciliation Baseline

**Document:** ARNA-GOV-ELE-A2-001  
**Version:** 1.0.0  
**Status:** A2 source reconciliation baseline; A3 implementation in progress.  
**Authority:** Owner-governed. This document does not adopt unresolved legal rules.

## Reconciled foundation

The destination API supplies shareholder identity, share classes, current holdings, append-only shareholding entries, Governance bodies, positions, terms, appointments, memberships, authority grants, tenant membership, RBAC, Core audit and Core Files. These are reuse dependencies.

The Governance election status vocabulary and election-record document category are metadata foundations, not an election engine. Electronic voting remains disabled by existing Governance configuration and is not enabled by this work.

## A3 implementation boundary

The election capability belongs inside Governance and consumes Shareholder as the authoritative register source. It must not create a second shareholder identity or holding ledger.

The first vertical slice is the election aggregate, verified legal authority reference, immutable voter-register snapshot, voter entitlement rows with approved rule version and evidence reference, and physical ballot issuance/custody receipt with one ballot per registered voter.

The service does not infer voting weight from live holdings. It accepts only an externally reconciled entitlement set and preserves source and rule references. A separate integration must reconstruct and validate as-of holdings from the append-only ShareHoldingEntry ledger before production use.

## Explicitly disabled or deferred

- Electronic voting, online ballot casting and email/SMS voting tokens.
- AI or smart-contract tallying or certification.
- Any default one-share-one-vote rule, proxy rule, candidate eligibility rule, committee composition, quorum, term, notice period or certification authority not supported by approved operative instruments.
- Automatic election activation or certification.

## Remaining A3 work

HTTP controllers, Form Requests, policies and permission catalogue; event/audit listener registration; proxy, candidate eligibility and objection workflows; independent physical count, reconciliation, complaints/appeals and certification; App routes, API client and role-specific workspaces; automated tests, clean-install migration verification, MySQL concurrency and security regression.

## Acceptance gates

No election may enter voting without verified authority and an approved rule version. No register may be frozen twice. A voter appears once per election. A physical voter receives at most one ballot. Every ballot custody transition is tenant-scoped and evidenced. No electronic voting route exists in this slice.

No migration has been run against a database. No production deployment or merge is authorized by this document.
