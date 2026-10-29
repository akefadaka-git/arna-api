# Governance Election Challenge and Appeal Clause Reconciliation

**Record ID:** ARNA-GOV-ELECTION-LEGAL-RECON-2026-10-29  
**Status:** NOT VERIFIED — implementation must fail closed for certification until the governing deadline is evidenced.  
**Scope:** Destination repository review for the Governance election workflow.  
**Review date:** 2026-10-29

## Finding

The destination repository was reviewed for an authoritative constitution/articles/bylaw/resolution source and a specific election challenge or appeal clause. The inspected repository contains governance control drafts and policy/authority matrices, but the available materials do not identify an approved, source-verifiable clause that sets an election challenge or appeal deadline.

The following repository documents are **not** a substitute for the governing instrument:
- `docs/governance/ARNA-GOV-MAT-PSC-001-AM.md` states that it records the current authority evidence and does not itself create legal authority; it says the company's constitutional documents, bylaws, applicable law, and properly approved resolutions take precedence.
- `docs/governance/ARNA-GOV-POL-PSC-001-AM.md` identifies itself as a draft and says it is not binding until the competent corporate body approves and publishes it.
- `docs/PHASE_2_SHAREHOLDER_CHARTER.md` is explicitly identified by the matrix as a scaffold, not approved.

No exact article/section/clause number, approved resolution identifier, effective date, or quoted deadline can therefore be reconciled from the available source evidence. This is an evidence gap, not a finding that no such rule exists outside the repository.

## Owner policy already approved

The Owner selected **bylaw-defined** for the challenge/appeal window. This record preserves that decision and does not replace it with a default number of days.

## Implementation control until the source is supplied

1. Store the bylaw/resolution citation, a controlled source-evidence reference, and the resolved deadline against each election.\n2. Require a separately authorized reviewer to verify the recorded source/deadline; the reviewer must be distinct from election-administration actors. This verification records an evidence-control attestation, not a substitute for locating the authoritative instrument.
3. Do not permit certification when either is absent, when the deadline cannot be derived, or while an in-window challenge/appeal remains unresolved.
4. Preserve challenge submission, evidence references, disposition, reviewer identity, timestamps, and audit events.
5. Do not infer a deadline from the meeting quorum rule, a training manual, a draft policy, a general election convention, or the Owner's policy choice.
6. Once the authoritative instrument is supplied, record its exact identifier, clause, effective version/date, applicable deadline, and how the deadline is calculated (including calendar/business-day and timezone rules). Any interpretation not explicit in the source must be escalated to the authorized governance/legal reviewer.

## Evidence required to close this reconciliation

- The current approved articles/bylaws/constitution or the approved resolution governing this election type.
- Exact clause/article/section number and source document version.
- Evidence of approval and effective date.
- The clause's challenge/appeal filing window and any exceptions or extension rules.
- Confirmation of the authorized body/person who decides challenges and appeals.

## Gate

**PARTIAL / BLOCKED:** Owner policy is known; the company-specific deadline clause is not evidenced. Source-only implementation now requires a bylaw citation, controlled source-evidence reference, and independent verification. Production readiness and certification remain blocked until the governing source is reconciled.
