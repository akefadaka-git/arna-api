# Election Objections and Appeals — A3 Implementation Contract

**Status:** Owner-approved conditionally; legal evidence blocked  
**Approval:** Owner decision `approve_conditional`  
**Scope:** Objection intake, evidence traceability, first-instance adjudication, appeal intake, independent appeal adjudication, and certification gating  
**PR:** #151 (must remain open, draft, and unmerged)

## 1. Binding governance constraints

1. Legal evidence remains **BLOCKED**.
2. Do not infer or invent standing, competent authority, adjudicator appointment, appeal rights, objection/appeal deadlines, remedies, quorum, voting thresholds, or finality rules.
3. Any operation whose legality depends on one of those unverified facts must fail closed.
4. No configuration default, environment override, seed, or fallback may silently activate an unverified legal rule.
5. Preserve original submissions, evidence references, decisions, and audit history. Never overwrite a prior decision or evidence record to simulate a correction.
6. This work does not authorize merge, deployment, production migration, election operation, or electronic voting.

## 2. Existing foundation at A3 start

The PR head already contains:
- `governance_election_objections` and `governance_election_appeals` tables;
- initial objection and appeal submission controller methods;
- a certification guard that checks unresolved objection and appeal statuses;
- separate election committee appointment, conflict, recusal, and security-event controls.

The current objection and appeal submission methods are not yet routed. There are no first-instance objection or appeal adjudication methods. Existing schema has only a single optional evidence-reference field per case and no dedicated append-only case evidence/event ledger.

## 3. Legal-rule activation contract

The application must treat the following as unverified until an authoritative instrument is supplied, reviewed, and represented by an explicit versioned configuration/data record:

| Rule | Required authoritative evidence | Until verified |
|---|---|---|
| Objection standing | Applicable Articles, GA/Board instrument, or other competent instrument | Do not represent receipt as a legally admitted objection |
| First-instance authority | Appointment/delegation instrument identifying the competent adjudicator | No first-instance decision |
| Objection filing window | Authoritative deadline and its start/event basis | Do not calculate or reject on a guessed deadline |
| Appeal standing/right | Instrument establishing who may appeal and on what basis | No appeal admission |
| Appellate authority | Appointment/delegation instrument identifying independent appellate authority | No appeal decision |
| Appeal deadline | Authoritative period and start/event basis | No deadline calculation or expiry |
| Remedies/finality | Instrument establishing available outcomes and effect | No unsupported remedy or finality transition |

Activation must require all applicable references, rule versions, and effective dates to be present and internally consistent. A boolean flag alone is insufficient evidence.

## 4. Case lifecycle and safe receipt

A receipt may be recorded for preservation and administrative visibility, but where standing or filing rules are unverified it must be explicitly labelled as **received pending legal rules**, not admitted, timely, or legally filed. Receipt timestamps are system-receipt timestamps only; they must not be described as legal filing dates or used to infer deadline compliance.

No decision, appeal-window expiry, or certification eligibility may be inferred from a receipt-only case.

## 5. Required implementation slices

### A3.1 — Rules and authority gate
- Add a versioned, fail-closed legal-rule contract.
- Require explicit references for standing, first-instance authority, appellate authority, objection deadline, appeal deadline, and remedies.
- No seed grants adjudication permission or appoints adjudicators.

### A3.2 — Objection intake and retrieval
- Preserve tenant/election scoping and authenticated shareholder identity.
- Validate referenced nomination belongs to the same tenant and election.
- Distinguish receipt from legal admission while rules are blocked.
- Provide authorized case retrieval without cross-tenant or unrelated-shareholder disclosure.

### A3.3 — Evidence and event integrity
- Add append-only evidence metadata and case-event records.
- Record actor, event type, case identity, timestamp, evidence reference, and SHA-256 where available.
- Preserve previous values and chain/order evidence; never overwrite original evidence.
- Fail closed on malformed hashes or cross-case references.

### A3.4 — First-instance decision
- Require verified legal rules and explicit case-adjudication permission.
- Require verified authority reference and rules version.
- Enforce tenant/election scoping, appointment status, conflict/recusal, and separation from the affected candidate/party where applicable.
- Use a row lock and transaction to prevent duplicate or racing decisions.
- Until the legal gate is complete, decision attempts must be rejected without mutating case state.

### A3.5 — Appeal intake
- Require a recorded, legally admissible first-instance decision.
- Require verified appeal standing and deadline rule before admission.
- Enforce one appeal per eligible appellant/case only if the verified rules support that constraint; otherwise do not hard-code it as law.
- Preserve evidence and the challenged decision.

### A3.6 — Independent appeal adjudication
- Require verified appellate authority and separate permission.
- Prohibit the original decision-maker from deciding the appeal.
- Apply appointment, conflict, and recusal checks.
- Record reasoned outcome, authority reference, rules version, evidence, and timestamp.
- No legal rule means no decision transition.

### A3.7 — Certification gate
- Block certification while any case is pending legal admission, open, or otherwise unresolved.
- Block certification while an applicable appeal window cannot be established from verified rules.
- Preserve the existing independent-tally-preparer/certifier separation and evidence-hash checks.
- Ratification remains a separate authorized step.

## 6. Security and acceptance tests

Required tests include:
- unauthenticated requests rejected;
- tenant mismatch and cross-election nomination references rejected;
- non-shareholder receipt attempts rejected;
- unauthorized case listing/retrieval denied;
- receipt status never represented as admitted or timely while legal rules are blocked;
- missing/partial legal-rule references block adjudication and appeal admission;
- no database mutation on blocked decision attempts;
- inactive, unappointed, conflicted, or recused adjudicators rejected;
- original decision-maker cannot decide an appeal;
- concurrent decision attempts produce at most one recorded outcome;
- evidence hash mismatch and cross-case evidence references rejected;
- certification blocked by pending/receipt-only cases and unresolved appeals;
- existing nomination, committee, register, tally, and ratification regressions remain green.

## 7. Explicit non-goals

- No invented legal deadlines, standing, adjudicator powers, remedies, quorum, or voting thresholds.
- No automated legal judgment or AI adjudication.
- No electronic voting.
- No alteration of the historical share register.
- No merge or deployment authorization.

## 8. Release gate

A3 implementation may be developed and tested with synthetic fixtures while legal rules remain blocked. Production/legal activation requires separate evidence reconciliation and explicit Owner approval. PR #151 must remain draft, open, and unmerged until separately authorized.
