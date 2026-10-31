# Documents numbering and incoming-registration runtime gate

**Scope:** PR #53, exact head commit to be recorded after source freeze.  
**Execution status:** PREPARED — NOT RUN.  
**Safety boundary:** No migration, test, CI, database, deployment, or merge operation is performed by this preparation.

## 1. Immutable source target

Before execution, record the exact PR head SHA, base SHA, and the workflow's checked-out SHA. Dispatch the clean-install workflow against the PR head SHA, not a moving branch name. The workflow must fail if the checked-out SHA differs from the requested SHA. Preserve the workflow URL, run ID, conclusion, and per-job logs.

## 2. Static and framework checks

- PHP syntax/lint for every new or changed PHP file in PR #53, including incoming controller, service, model, migration, permission catalogue, provider and routes.
- Composer autoload/class discovery for the new Documents classes.
- Laravel route listing confirms authenticated tenant middleware on POST /api/documents/incoming and GET /api/documents/incoming/{uuid}.
- Permission seeder dry-run or isolated test confirms documents.receive and documents.incoming.view are present; no operational role receives them beyond existing tenant/global scope grants.
- Confirm TrimStrings and ConvertEmptyStringsToNull exclusions preserve sender_reference exactly, including leading/trailing whitespace and empty string.

## 3. Migration verification — isolated disposable MySQL schema only

- Run all prerequisite migrations in declared order, then the two numbering migrations and incoming-registration migration.
- Verify all FK targets and composite unique keys exist on the supported MySQL version.
- Verify rollback in reverse order and clean re-apply.
- Verify migration does not rewrite legacy document rows or allocate numbers.
- Do not point this test at staging or production data.

## 4. Incoming-registration acceptance matrix

1. Register with sender_reference containing leading/trailing spaces, punctuation, Amharic text and leading zeros; persisted value and API response must be byte-for-byte identical.
2. Register with a null sender reference and with an empty-string reference; null and empty remain distinct.
3. Confirm incoming_registration_id is a server-generated UUID and never equals or replaces sender_reference by assignment.
4. Confirm no outgoing canonical number or outgoing counter is allocated for an incoming registration.
5. Same tenant, same Idempotency-Key and same payload returns the same registration UUID and one registration event.
6. Same tenant/key with a different payload returns conflict; concurrent same-key requests persist one registration/event.
7. Same sender reference may be registered more than once when the source/receipt is distinct; no uniqueness constraint may collapse it.
8. Cross-tenant entity, BB, file, registration lookup and idempotency attempts fail closed.
9. Missing documents.receive, documents.incoming.view, tenant membership, or file.view denies the corresponding operation.
10. Draft, archived, soft-deleted, foreign-tenant and changed file versions are rejected; published same-tenant file is accepted.
11. Registration row and event commit atomically; injected event-write failure leaves neither.
12. Actor attribution, received_at, registered_at, legal entity, receiving unit, BB and file version are persisted correctly.

## 5. Outgoing-numbering and lifecycle regression

- All 11 TT mappings and legacy aliases; BB and sequence boundaries; EC year rollover.
- Drafts remain unnumbered; issuance transaction rolls back number/document/event together.
- Same/different idempotency payload behavior; concurrent sequence allocation; exhaustion at 9999.
- Void/revoke preserves canonical number; replacement gets a new number and only one replacement can win.
- Tenant and legal-entity composite FK violations are rejected.

## 6. Presentation parity

For each existing consumer discovered in the source review, compare the exact stored documents.canonical_number value across API show/list, search, export, PDF, print and verification output. No consumer may reconstruct the number from current BB registry, current date, sequence, or document type. Historical records must display their persisted canonical value even after Organization codes are retired.

Current PR source exposes canonical_number and display_number from the persisted model accessor. This PR does not contain PDF/print/export/search/verification renderer implementations; those consumers remain NOT ESTABLISHED until their owning source paths are identified and verified. Do not mark this section passed from API projection evidence alone.

## 7. Security and concurrency gates

- Review authorization cache invalidation/revocation semantics under concurrent issuance/registration. Existing generic RBAC cache decision is not proven serialized with the row-lock protocol.
- Exercise MySQL row-lock behavior; SQLite tests are not sufficient evidence for concurrency.
- Verify tenant membership, explicit document permissions and file.view independently.
- Verify UUID lookup never crosses tenant boundary and sender_reference is not used as an identifier.

## 8. Evidence and closure rule

Record each criterion as VERIFIED, PARTIAL, MISSING, UNRESOLVED, UNKNOWN or NOT RUN. Attach exact commit SHA, workflow URL/run ID, job results, migration output and test report. No production-ready claim, PR readiness transition, merge, deployment, or database mutation is implied by this document. Close the runtime gate only when every required criterion has reproducible evidence and the remaining authorization-concurrency and presentation-consumer gaps are resolved.
