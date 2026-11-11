# A3 — Ethiopian Calendar integration for Calendar

**Status:** implementation committed; exact-head CI and independent review remain required.

## Calendar-system contract

- Gregorian civil dates remain the canonical database representation for all-day event storage and query indexing. They are timezone-free YYYY-MM-DD values, not UTC instants.
- The existing EthiopianCalendar and immutable EthiopianDate implementation is the only E.C. conversion engine. Calendar does not introduce a second date-conversion algorithm.
- The event timezone (default Africa/Addis_Ababa) is used to derive E.C. presentation dates for all-day events.
- Timed events remain absolute instants normalized to UTC with their IANA event timezone retained. Event serialization exposes starts_at_ec and ends_at_ec, including the E.C. date, Amharic/English date formats, local Gregorian clock, and Ethiopian six-hour-offset clock. This is presentation metadata and does not redefine an instant or the event's IANA timezone.

## API integration

- All-day event creation accepts either Gregorian starts_on / ends_on or Ethiopian starts_on_ec / ends_on_ec component objects (year, month, day). A request cannot supply both representations for the same endpoint.
- All-day event serialization exposes starts_on_ec and ends_on_ec objects containing components, ISO-like E.C. date, Amharic formatted date, and English formatted date.
- Timed event serialization exposes starts_at_ec and ends_at_ec with E.C. date plus Ethiopian-time conversion from the canonical EthiopianCalendar::toEthiopianTime() implementation.
- Event list filters accept Gregorian from / to or E.C. from_ec / to_ec ranges, never both for one boundary.
- Recurrence expansion accepts either Gregorian or E.C. range boundaries.
- Recurrence rules may declare calendar_system=ethiopian. This is restricted to all-day civil-date events. Ethiopian until_ec is converted and normalized to the canonical Gregorian inclusive until bound.

## E.C.-native recurrence

- Daily and weekly recurrences remain elapsed civil-day/week steps.
- Monthly E.C. recurrence advances across 13 months per E.C. year, including Pagume.
- Yearly E.C. recurrence retains the Ethiopian month/day and clamps invalid day 6 in common-year Pagume to day 5; it does not drift the original anchor.
- Monthly/yearly occurrences are calculated from the original anchor, not iteratively from a clamped prior occurrence.
- Occurrence responses include Gregorian canonical dates and derived E.C. date payloads.
- Existing occurrence cancellation/override identity remains based on the canonical Gregorian civil-day start, preserving existing persistence compatibility.

## Verification required

- Known-date conversion and event serialization.
- Thirteen-month transition and Pagume leap/common-year clamping.
- Gregorian and E.C. range input validation and mutual exclusion.
- Inclusive E.C. recurrence end-date behavior.
- Existing Calendar lifecycle, authorization, reminder, reservation, MySQL clean-install and migration-idempotence suites.
- Independent review of calendar-system semantics, timezone boundaries, API compatibility, and recurrence exception handling.

No production database change, deployment, merge, or migration against production is included.