# ARNA Finance — A3.5 journal outbox and subledger boundary

**Status:** transactional journal-posted outbox foundation; source-specific adapters remain fail-closed pending authoritative owner-domain contracts.

## Transactional event persistence

A successful journal-post operation emits finance.journal.posted. The synchronous Finance listener reads the committed journal snapshot and persists a versioned event record to finance_outbox_messages within the same database transaction. The journal posting and outbox event therefore commit or roll back together.

The event payload carries tenant and legal-entity scope, actor, journal identity and date, currency, source provenance, posting timestamp, and ordered debit/credit lines. Monetary amounts remain decimal strings. The event key is deterministic per tenant and posted journal, preventing duplicate outbox rows on replay.

## Delivery lifecycle

The dispatcher supports:
- atomic claim of the oldest available message using a database row lock;
- a unique lease token per claim;
- recovery of processing messages whose worker lease expired;
- acknowledgement only by the worker holding the current lease;
- bounded exponential retry delay after delivery failure;
- persisted attempt count and a bounded diagnostic error string.

The dispatcher intentionally exposes claim/acknowledge/retry operations, not a hard-coded network publisher. A transport adapter must be separately configured and authorized. Until then messages remain durable and pending; no claim of external delivery is made.

## Subledger ownership and safety

Finance's existing SubledgerPostingContract and JournalSourceReferenceResolver contracts remain the integration boundaries. SubledgerIntegrationService rejects external sources unless exactly one authoritative adapter supports the source type. JournalSourceReferenceResolverRegistry rejects references unless exactly one resolver proves tenant and legal-entity ownership.

No broad or guessed adapters were registered. A concrete adapter requires the owning domain's canonical read/authorization interface, a stable source-state eligibility rule, explicit legal-entity mapping, idempotency contract, and source-specific tests. The Finance adapter must not mutate source-domain records.

## Explicit exclusions

- No foreign-currency journal posting.
- No automatic payments, settlement, or autonomous AI posting.
- No direct source-domain writes.
- No external publisher/queue transport configured.
- No production deployment or database migration execution.

## Verification

A3.5 acceptance requires exact-head CI for:
- fresh MySQL migration of finance_outbox_messages;
- journal posting creates one durable outbox record;
- event payload scope and ordered decimal lines;
- journal and outbox rollback atomically;
- claim lease, retry backoff, stale-token rejection, and acknowledgement;
- existing Finance posting, reversal, audit, and tenant isolation regressions.
