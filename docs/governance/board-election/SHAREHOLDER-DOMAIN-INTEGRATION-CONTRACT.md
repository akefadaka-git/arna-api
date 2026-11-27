# Shareholder ↔ Board Election Integration Contract

- Record: ARNA-GOV-SHD-INT-001
- Date: 2026-11-27 (EAT)
- Status: Proposed cross-domain A3 contract; implementation NOT STARTED.

## 1. Domain ownership

| Capability | Owner | Boundary |
|---|---|---|
| Shareholder identity/register/provenance/verification | Shareholder | Governance consumes a read-only, tenant-scoped projection |
| Share classes, holdings, append-only ledger, certificates | Shareholder | No duplicate balances or election-side writes |
| Canonical Person/User identity and tenant access | People/Auth | Resolve actors server-side; client IDs are not identity proof |
| Election authority, election aggregate, committee and recusal | Governance | Governance owns the election lifecycle |
| Candidate intake, evidence, qualification, objections/appeals | Governance | Apply only approved, versioned rules |
| Voter-register preparation, reconciliation, approval and freeze | Governance | Election-specific immutable snapshot, not a live shareholder query |
| Methods, ballots, tally, certification and disputes | Governance | Fail closed until legal/security/operational gates close |
| Notices and UI | Notifications/App | Consume authorized API/events; no independent voting state |

Board Election is distinct from General Assembly administration. A GA resolution may authorize a Board election, but the workflows and authority records remain distinct.

## 2. Eligibility and voting entitlement — fail closed

1. A shareholder record alone does not establish candidate eligibility, voter eligibility, voting weight or nomination rights.
2. Do not impose shareholder-only candidate eligibility unless operative Articles, applicable law and the approved election rule version require it. Candidate eligibility remains evidence-driven.
3. The Owner-directed software policy recorded in the Module 3 A2 baseline is: a locally registered ARNA account with the Shareholder role may vote only when the person is an approved shareholder in the authoritative election record-date snapshot; the intended software weight is one vote per eligible share (1 share = 1 vote). This policy is not legal activation: the operative registered Memorandum/Articles, current statutory register, paid-up/transfer evidence, class rights, record date and election resolution remain unresolved. Therefore no vote entitlement or weight may be issued until those gates are verified and the applicable rule version is approved. Never infer weight from current projection or seed data.
4. Shareholders, beneficial owners, representatives and proxies are distinct legal/identity relationships. A representative is not automatically a shareholder or voter.
5. Quorum, majority/tie, nomination windows, challenge/appeal deadlines, proxy rules and certification authority require authoritative sources and versioned election rules. Unknown values remain unresolved.
6. Physical, electronic, proxy and hybrid are architecture categories, not activation approval. Electronic/hybrid voting remains disabled pending applicable legal, cryptographic/security and operational acceptance.

## 3. Read-only integration contract

Governance may consume a tenant- and election-scoped projection containing only the fields required by the approved rule:
- stable shareholder identifier and canonical Person reference where applicable;
- tenant and record status;
- source provenance, verification state and verifier/timestamp;
- share class/quantity only if the approved rule requires them;
- source version marker and canonical snapshot digest.

Do not expose addresses, identity documents, contact details, beneficial-owner details or certificate artefacts unless the approved purpose and access policy require them.

The Governance voter-register workflow must record election, tenant, rule version, source snapshot/version and preparer; flag unverified, inactive, duplicate, conflicting or provenance-incomplete records; require independent reconciliation and approval/freeze; persist a canonical digest and frozen timestamp; preserve source-to-voter traceability; and reject cross-tenant or stale source references.

The frozen register is an election-specific Governance artifact, not a second authoritative Shareholder register.

## 4. Write and event boundaries

- Governance cannot write shareholder identity, register, share class, holding projection, holding ledger or certificate records.
- Shareholder changes do not silently rewrite a frozen electorate. Post-freeze corrections use a separately approved and audited election exception/dispute process.
- Shareholder events may signal source changes but cannot automatically change candidate status, voter eligibility, vote weight or election outcomes.
- Prefer versioned read APIs and durable outbox/event references over direct cross-domain database writes.
- Any transfer/correction must complete in Shareholder and then be reconciled under the election's approved freeze/exception policy.

## 5. Authorization and segregation of duties

- Enforce tenant/object authorization in API policies; frontend guards are presentation only.
- Keep register verification, election authority evidence review, candidate review, voter-register preparation/reconciliation/approval, ballot custody/count and certification separately permissioned.
- Preparer, reconciler and approver must be distinct actors.
- Do not add default role grants in this integration. Produce a permission-policy-route matrix and obtain explicit approval for role assignments.
- Platform administrator privilege does not confer corporate authority.

## 6. Required integration tests

- Cross-tenant shareholder/person/holding/election access is denied.
- Direct-linked identity is server-resolved; representatives/proxies are not conflated.
- Unverified, inactive, duplicate and incomplete-provenance records fail closed or enter an exception queue.
- Source changes between preparation and freeze are detected.
- Snapshot digest is deterministic; frozen records resist application and database mutation.
- Preparer ≠ reconciler ≠ approver is enforced at service and HTTP layers.
- Unresolved legal rule means no voting entitlement or vote-weight output.
- Election actions never mutate shareholder balances, certificates or register rows.
- Audit/outbox failure behavior is explicit and transactional where required.

## 7. Existing PR lineage — not main functionality

- API #126: AGE A2 baseline documentation.
- API #128–#146: voter-register lifecycle, authorization, immutability and custody/count stack; merge only in dependency order.
- API #149: committee security foundation; depends on election-core tables.
- API #151/#161: election-core, authority evidence, committee appointments and candidate-intake lineage; #161 records limited A4 closures but remains unmerged.
- API #157: evidence-neutral dispute foundation, stacked/unmerged.
- App #31: initial Board Election workspace.
- App #68: four-method scope alignment, stacked on #31.
- App #120: newer Governance meetings/election workspace candidate; requires refresh against current main.

Select one canonical API dependency chain and one App workspace candidate. Rebase onto current main, reconcile exact diffs, then run exact-head API, MySQL migration, authorization, tenant-isolation, App type/build, browser and accessibility verification. Do not merge stacked PRs independently or wholesale.

## 8. Legal/operational blockers

Authenticate operative Articles/amendments, applicable law, current shareholder register and election-authorizing resolution; obtain independent Ethiopian corporate-law review of candidate/voter eligibility, share-class rights, methods, quorum, majority/tie, proxy, challenge/appeal and certification; approve rule version and authority/committee appointments; complete independent security review, ballot secrecy/custody controls and operational rehearsal. Architecture documents and PR descriptions are not legal opinions or authenticated source instruments.

## 9. Delivery sequence

| Stage | Work | Exit evidence |
|---|---|---|
| A2 | Shareholder API/App inventory | API #237 and App #142 accepted/merged as documentation baseline |
| A2 addendum | Cross-domain Board Election contract | This record and App companion |
| A3.1 | Reconcile current API election-core lineage | Exact current-main dependency graph and route/migration/permission inventory |
| A3.2 | Read-only Shareholder adapter and snapshot provenance | API contract, tenant tests, deterministic digest and audit evidence |
| A3.3 | Voter-register preparation/reconciliation/freeze | Independent actors, immutability, concurrency and DB tests |
| A3.4 | App Workspace/Portal integration | Typed API, route tests, accessible states; no false eligibility/ballot UI |
| A4 | Independent legal/security and exact-head verification | Review record and exact-SHA PASS evidence |
| A5 | Deployment/activation | Separate Owner approval |

## 10. Disposition

**CONTRACT DEFINED; IMPLEMENTATION NOT STARTED; LEGAL AND ACTIVATION GATES OPEN.** This record does not authorize merging election implementation PRs, role grants, production migration, election creation, ballots, tally, certification or deployment. A3 requires a separately approved bounded scope.
