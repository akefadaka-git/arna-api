# ARNA Central Error Handling & Autonomous Diagnostics

## Scope and compatibility
The existing Laravel exception renderer and App single Axios transport remain canonical. This release adds a governed error catalogue and deterministic read-only diagnostic hints; it does not replace the response envelope or existing codes.

## Error code contract
- Stable SCREAMING_SNAKE_CASE; never localize codes.
- Domain prefixes: AUTH_, TENANT_, ORG_, PEOPLE_, FINANCE_, DIVIDEND_, GOVERNANCE_, ELECTION_, DOCUMENT_, FILE_, INTEGRATION_, SYSTEM_.
- HTTP status expresses transport semantics; error code expresses stable business/technical meaning.
- Existing codes remain valid. Unknown legacy codes remain renderable and should be surfaced as catalogue drift in telemetry.
- Messages are safe for end users; internal exception detail belongs only in protected logs.

## Existing code inventory
The registry includes the established CoreException taxonomy and codes discovered in the AuthenticationException, AuthorizationException, FileException, and TenantException classes, plus the explicit authentication controller and tenancy middleware response codes. Unknown codes remain compatible but are not considered catalogued; add a registration and test whenever a new code is introduced. This inventory is additive and must be expanded when additional domain code sources are reconciled.

## Self-diagnosis and remediation model
1. Detect: collect exception class, stable code, HTTP status, timestamp, request/correlation ID and bounded safe context.
2. Correlate: link frontend request ID, API logs, tenant-scoped audit/security events, queue job ID and deployment SHA where available.
3. Diagnose: deterministic rules classify likely cause and recommend bounded checks.
4. Propose: generate a patch proposal and regression test in a branch; never patch production directly.
5. Verify: run focused tests, relevant suites, static analysis, security checks and acceptance gates.
6. Approve: Owner or delegated domain authority approves changes affecting code, schema, financial data, identity, permissions, governance or production.
7. Recover: execute only explicitly allowlisted idempotent actions with rate limits, audit trail, rollback and post-action verification.

## Hard safety boundaries
- No autonomous SQL writes, schema migrations, ledger postings/reversals, dividend allocations, ballot/vote changes, membership/role grants, secret rotation, file deletion, deployment or branch merge.
- Read-only diagnostics may run automatically.
- Automatic recovery is limited to explicitly configured, idempotent operational actions and must be auditable.
- Unknown or ambiguous incidents are escalated, not guessed.
- Diagnostic output must not expose SQL, tokens, passwords, personal data, stack traces or secrets to clients.

## Severity
- info: expected cancellation or normal control flow.
- warning: validation and business-rule rejection.
- error: failed operation or dependency.
- critical: security or integrity risk requiring immediate review.
- fatal: platform-wide outage.

## Operational controls
Use correlation IDs end-to-end; validate inbound request IDs before echoing them. Keep structured logs access-controlled and tenant-aware. Add alert thresholds, deduplication, incident ownership, retention policy, and dashboards as deployment-specific configuration. Do not claim monitoring is active until a log sink and alert destination are configured and tested.

## Verification gates
Test exact code/status mappings, envelope compatibility, unknown-code handling, request-ID validation, secret redaction, tenant isolation, diagnostic determinism, and the prohibition on write/remediation side effects.