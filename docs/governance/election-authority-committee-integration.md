# Election authority and committee integration

## Integration owner

The election lifecycle aggregate and state machine in PR #176 remain canonical. Authority evidence and committee appointment functionality from PR #161 has been selectively adapted to that model. The existing committee-security tables, service, controller, routes, and conflict/recusal tests were already present on `main`; the lifecycle branch extends them to consume verified appointments and emit atomic appointment/revocation security events.

## Dependency order

1. **Canonical election lifecycle:** create the tenant-scoped election aggregate and lifecycle transition ledger.
2. **Authority evidence:** record a published File Management document, calculate its SHA-256 digest from stored bytes, and persist instrument metadata.
3. **Evidence review:** a reviewer distinct from the recorder accepts or rejects the record with a reason.
4. **Authority verification:** a verifier distinct from the recorder rereads the published document, rechecks its digest, and binds the verified record to the election.
5. **Lifecycle authorization:** authorization requires the verified record to match the election, tenant, source document, digest, and instrument reference. The server generates the canonical authority snapshot.
6. **Committee appointments:** appointments require an authorized election and the same canonical verified authority evidence.
7. **Committee security:** appointment and revocation state changes and their hash-chained security events share one transaction. Committee decisions require a current authority-backed appointment, active tenant access, and no disqualifying conflict or recusal.

## Selective reconciliation decisions

### PR #161 — authority and committee appointments

Integrated:
- authority evidence record/review/verify workflow;
- published-document and stored-byte digest verification;
- tenant-scoped committee member appointments and revocations;
- committee appointment and authority evidence models and API endpoints.

Adapted:
- evidence storage now binds to a File Management document ID and server-computed digest rather than accepting a caller-supplied storage reference and digest;
- the verifier must be distinct from the recorder;
- appointments require lifecycle status `authorized` and the election's canonical verified authority record;
- appointment and revocation events are written through the existing committee-security ledger.

Not imported wholesale:
- the duplicate `governance_elections` table migration;
- the older `planned` / `nominating` status model;
- duplicate election creation and nomination-opening transitions. Those responsibilities remain in the PR #176 lifecycle state machine.

### PR #151 — committee security

The committee administrator, conflict-of-interest, recusal, and hash-chained event foundation was already present on `main`. Its older aggregate and lifecycle history was not merged into PR #176. The integration adds the missing appointment/revocation event hooks and requires verified authority-backed appointments before committee decisions.

## Migration and deployment controls

The authority and committee appointment schema is additive and forward-only. The earlier lifecycle migration's legacy committee reconciliation procedure is documented in [election-committee-legacy-reconciliation.md](election-committee-legacy-reconciliation.md). Neither migration has been run against production data. Production migration and deployment remain separate owner-approved gates.

## Review boundary

Development-stage code review and automated verification are performed as part of A3/A4. An independent review is reserved for the pre-deployment gate; it is not a prerequisite for each development iteration.
