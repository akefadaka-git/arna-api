# ARNA Governance Election Policy — Owner Decisions

**Record ID:** ARNA-GOV-ELECTION-POLICY-OWNER-2026-10-29  
**Decision date:** 2026-10-29  
**Authority:** Explicit Owner decision recorded in the ARNA API Governance election PR discussion.  
**Status:** Owner-approved implementation policy; not a merge or deployment authorization.

## Decisions

| Control | Approved value | Required behavior |
|---|---|---|
| Nomination | Both | Permit self-nomination and authorized third-party nomination. Record the nomination source. Third-party nomination must record candidate consent. |
| Election quorum | Half the frozen eligible-voter register | At least 50% of the frozen eligible register must cast a ballot. The register is frozen when the initial voting round opens. |
| Winner | Majority with runoff | A candidate must receive strictly more than 50% of valid ballots in the round. If no candidate reaches that threshold, conduct a runoff between the top two candidates. |
| Final tie | Rerun | A tied final runoff result is not certifiable; start an audited rerun round with the eligible candidate set and preserve prior rounds and tallies. |
| Challenge/appeal window | Bylaw-defined | Use the exact applicable approved bylaw/resolution clause. No universal duration is inferred. |
| Certification authority | Independent | A certifier must have the separate certification permission and must not have administered the election. Certification is blocked by an unresolved or upheld challenge/appeal. |

## Audit and ballot secrecy

- Store a per-round eligible-voter count, ballots cast, valid ballots, quorum threshold/result, majority threshold, candidate tallies, winner if any, and the round disposition.
- Maintain a round-specific eligible-voter cast marker so one member can cast at most one ballot per round while remaining eligible for later rounds.
- Ballot records contain the selected nomination and a receipt hash but do not contain the voter's membership/user identifier. Audit events must not create a voter-to-choice link.
- Retain each challenge/appeal, evidence references, disposition, reviewer, and timestamps.
- Require a bylaw/resolution citation and source-evidence reference for the challenge deadline, then require a separate authorized reviewer to verify that record. This software verification is an evidence-control step; it does not itself establish that a cited document is legally authoritative.

## Fail-closed unresolved cases

1. **Bylaw clause not found in the destination repository as of 2026-10-29.** See `ARNA-GOV-ELECTION-CHALLENGE-APPEAL-RECONCILIATION-2026-10-29.md`. Certification remains blocked until the actual approved instrument, exact clause, deadline calculation, and source evidence are supplied and independently verified.
2. **Tie at the runoff-selection cutoff.** The Owner approved a final-tie rerun, but did not specify how to select exactly two runoff candidates when a tie crosses the second-place cutoff in the initial round. The implementation blocks this state as `runoff_selection_blocked` rather than inventing a tie-break or misapplying the final-tie rule.
3. **Quorum failure.** The Owner approved the participation threshold but did not specify the disposition of a failed-quorum round. The implementation records `quorum_failed` and blocks certification; it does not silently convert this into an authorized rerun.
4. **Challenge and certification permissions.** The permission catalogue declares dedicated challenge and review permissions, but does not grant them to generic roles. A separate authority assignment is required; no reviewer or certifier is invented by code.

## Change-control boundary

This policy record and the source changes are on the Governance development branch only. No tests, workflows, database operations, merges, or deployments are included in this change. The four existing Organization/Governance PRs must remain open and unmerged until exact-head source review and shared-router reconciliation are complete, followed by separate Owner authorization for any merge.
