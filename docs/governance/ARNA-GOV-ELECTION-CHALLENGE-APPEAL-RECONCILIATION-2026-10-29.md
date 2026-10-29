# Governance Election Challenge and Appeal Clause Reconciliation

**Record ID:** ARNA-GOV-ELECTION-LEGAL-RECON-2026-10-29  
**Status:** NOT VERIFIED — implementation must fail closed for certification until the governing deadline is evidenced.  
**Scope:** Destination repository review for the Governance election workflow.  
**Review date:** 2026-10-29

## Finding

The signed/stamped 17-page Library scan `የማሌ_ዓርና_አክሲዮን_ማኅበር_መተዳደሪያ_ደንብ.pdf` was inspected visually. Pages 1–8 are the bylaws; pages 9–17 are the memorandum/articles pages in the same scan.

**Relevant election clause located:** Bylaw **Article 12(4)** (scan page 4) states that director elections are conducted by secret ballot and describes the directors' term. It does **not** define an election-result challenge/appeal process, filing deadline, appeal authority, extensions, or a certification hold. Bylaw Article 24(4) concerns objections to the appointment of liquidators during dissolution; it is not an election-dispute clause and is not applicable to this workflow.

The repository's authority matrix `docs/governance/ARNA-GOV-MAT-PSC-001-AM.md` explicitly records the signed/stamped bylaw PDF as a Library source and cautions that clause-level interpretation must be conservative. The policy draft `docs/governance/ARNA-GOV-POL-PSC-001-AM.md` is not an adopted rule. The Shareholder Charter `docs/PHASE_2_SHAREHOLDER_CHARTER.md` is marked DRAFT / NOT APPROVED; its related decision register treats election-dispute resolution as a bylaw-dependent question, not an approved deadline.

**Reconciliation outcome:** Article 12(4) is the exact relevant election-method clause located, but no applicable challenge/appeal deadline or process clause is visible in the scanned governing instrument reviewed. No approved resolution containing that deadline was found in the repository materials reviewed. This is a bounded source finding, not a legal opinion that no other instrument or applicable statutory rule exists.

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

**PARTIAL / BLOCKED:** Article 12(4) confirms secret ballot but does not supply a challenge/appeal deadline. No approved deadline clause was found in the scanned instrument or repository resolution materials. Source-only implementation requires the exact applicable approved instrument/clause, controlled evidence reference, and independent verification. Production certification remains blocked until that source gap is resolved.
