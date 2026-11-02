# ARNA Board Election — Authority Evidence Record

- Record ID: ARNA-GOV-ELE-AUTH-001
- Status: OWNER-IDENTIFIED GA INSTRUMENT RECEIVED / IMAGE-REVIEWED / TRANSCRIPTION PARTIAL
- Applies to: Electoral Committee framework and Board Election System
- Reported adoption date: ነሐሴ 01/2018 E.C.
- Evidence received: 2026-11-02
- Evidence file supplied in conversation: `image-1793613020291.jpg`
- SHA-256: `8e86788a2575207b1dbd538c42d8310d99271a1976112537dbd8fe8720bd3fae`

## Evidence statement

The Owner identifies the supplied handwritten page as the signed General Assembly resolution or certified minutes supporting adoption of the Electoral Committee framework on ነሐሴ 01/2018 E.C. The original image is preserved as the evidence received for this record. The image is handwritten and portions are not reliably legible at the supplied resolution. This repository record therefore does not invent a resolution number, signatories, vote count, or unreadable clauses.

The Owner's identification is recorded as the provenance for implementation. The image itself has not been independently authenticated against a company-held original or certified register. The status is therefore evidence-received and Owner-identified, not a legal opinion or independent authenticity certification.

## Adopted framework baseline carried into implementation

The previously supplied Governance blueprint states the following Electoral Committee rules. They are treated as the adopted implementation baseline under the Owner's confirmation:

1. Purpose: administer nomination, vetting, and election of Board members in accordance with the Articles of Association; the framework also names Supervisory Committee elections.
2. Eligibility authority: the Committee validates candidate eligibility.
3. Composition: three General Assembly members who are not Board members; one Legal Advisor ex officio; one Supervisory Committee member.
4. Quorum: three members.
5. Meetings: weekly during election cycles and as needed otherwise.
6. Conflict of interest: a Committee member who is a candidate or an immediate family member of a candidate must not vote on the affected matter.
7. Dissolution: the Committee dissolves 30 days after election results are ratified.
8. Accountability: post-election performance review by the General Assembly.

These are framework rules only. The software must not infer individual appointees, current committee membership, candidate eligibility criteria not specified in the operative Articles, voting weights, proxy entitlements, quorum for the General Assembly, or result thresholds from this framework.

## Implementation controls

- Record the adoption date as ነሐሴ 01/2018 E.C.; do not fabricate a Gregorian conversion.
- Preserve the supplied image's SHA-256 and provenance in the authority evidence register.
- Committee membership, role assignment, recusals, quorum checks, meeting records, and dissolution must be versioned and audited.
- The legal advisor is ex officio; do not silently treat that position as a voting member. The framework does not state whether the legal advisor votes, so the application must keep voting eligibility configurable and default to non-voting until the operative instrument resolves it.
- A conflicted member is excluded from the affected decision's vote; retain the conflict declaration, recusal, decision and audit evidence.
- Committee eligibility validation is distinct from final election result certification.
- Keep electronic voting disabled; this GA framework image does not establish a separate electronic-voting authorization.
- Block irreversible result certification if the operative Articles, election-specific legal rules, voter register, or required independent reconciliation are not verified.

## Evidence classification and remaining controls

| Evidence item | Status | Treatment |
|---|---|---|
| GA adoption date and adoption of framework | OWNER-IDENTIFIED from supplied resolution/minutes image | Use as approved policy baseline |
| Handwritten page contents | PARTIALLY LEGIBLE | Do not infer illegible clauses |
| Previously supplied Governance blueprint | OWNER-SUPPLIED FRAMEWORK TEXT | Implement only explicit committee rules listed above |
| Authenticity against company original/certified register | NOT INDEPENDENTLY VERIFIED | Preserve provenance; no fabricated verification |
| Certified operative Memorandum and Articles and amendments | NOT PRESENT IN THIS evidence packet | Required before legal eligibility/voting rules are finalized |
| Current shareholder register and shareholding ledger as of record date | NOT PRESENT IN THIS evidence packet | Required before freezing the voter register |
| Legal interpretation of eligibility, voting weights, proxy rights, quorum and certification | NOT INDEPENDENTLY VERIFIED | Fail closed on irreversible certification |

## Change control

This record may be upgraded to DOCUMENT-VERIFIED after the supplied image is independently matched to the company's signed original or certified minute book and the operative Articles are reconciled. Any discrepancy must be logged; never silently overwrite the Owner's attestation or infer missing clauses.
