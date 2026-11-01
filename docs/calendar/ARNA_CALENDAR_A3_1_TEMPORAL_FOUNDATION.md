# A3.1 — Calendar Temporal Foundation

**Status:** implementation increment 1; not a release or A4 authorization.

## Contract
- `LocalDate` is a strict Gregorian ISO civil date (YYYY-MM-DD), with no timezone or time-of-day.
- `CalendarInstant` is an absolute instant normalized to UTC plus a separately retained IANA event timezone.
- E.C. input converts through the existing canonical `EthiopianDate` / `EthiopianCalendar` implementation. No second conversion engine is introduced.
- Default event timezone is `Africa/Addis_Ababa`; callers may supply another valid IANA zone.
- APIs must reject instants without Z/explicit offset and must never interpret date-only values as UTC midnight.
- Recurrence must later expand in the retained event timezone. This increment does not implement recurrence, persistence, APIs or reminder dispatch.

## Implemented in this increment
- `Contracts/LocalDate.php`: strict date validation, EthiopianDate conversion, explicit timezone materialization.
- `Contracts/CalendarInstant.php`: offset-required instant parsing, UTC normalization and event-zone retention.

## Remaining A3 work
1. Add tests for E.C. leap/Pagume boundaries, invalid dates, UTC/Addis boundaries, date-only invariance and foreign-zone DST.
2. Audit legacy calendar call sites and define compatibility/deprecation plan.
3. Design tenant-scoped Calendar schema/API/policies and idempotency/concurrency rules.
4. Implement Calendar Core event/attendee/link/audit records and APIs after schema review.
5. Implement durable reminder outbox only after event persistence contract is stable.
6. App workspace route/views follow stable API contract; no client-side reminder authority.

## Safety boundary
No Governance quorum/voting implementation, Finance mutation, social credentials, external calendar sync, production deployment or merge is included.
