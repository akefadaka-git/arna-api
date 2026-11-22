# ARNA Finance — A3.4 currency precision and FX conversion contract

**Status:** implementation foundation; not production acceptance.

## Currency precision policy

Finance initially enables a deliberately bounded currency catalogue: ETB, USD, EUR, GBP, CAD, AUD, CHF, CNY, INR, KES, TZS, UGX, JPY, KRW, KWD, BHD, OMR, JOD and IQD. The policy defines the minor-unit precision for each supported code. Unknown currency codes fail closed; a three-letter syntax check alone does not enable a currency.

The catalogue is an initial implementation set, not a claim that every ISO 4217 currency is supported. Additions require an explicit reviewed policy change and test coverage. Existing ledger storage remains decimal(20,4); the policy enforces the currency's externally meaningful amount precision at conversion boundaries.

## Rate and conversion semantics

- An FX rate is expressed as quote-currency units for one base-currency unit.
- A conversion may use only an approved FinanceExchangeRate resolved for the tenant, pair and transaction date by FinanceExchangeRateService::resolveApproved.
- The conversion result carries the exact rate ID, rate, effective date, source, source/quote currencies and rounding mode as evidence.
- Arithmetic uses decimal-string integer operations. PHP floats and binary floating-point rounding are prohibited.
- Conversion rounds half up to the quote currency's configured minor-unit precision.
- Same-currency conversions, unsupported currencies, invalid amount precision and pending/rejected rates fail closed.

## Explicit exclusions

This change provides a deterministic conversion primitive only. It does not:
- enable foreign-currency journal posting;
- change legal-entity base currency or existing ETB ledger semantics;
- perform revaluation, realized/unrealized FX gain/loss posting, or consolidation translation;
- create rate data, select an external rate provider, or seed rates;
- authorize automatic accounting actions.

The existing journal service continues to reject foreign-currency journals. A later, separately verified posting-contract stage must define functional-currency accounting, transaction-currency storage, realized/unrealized gain/loss treatment, and reversal semantics before that guard can be changed.

## Verification criteria

- Supported-currency precision and fail-closed unknown-code behavior.
- Approved-rate-only conversion.
- Exact decimal multiplication with no float dependency.
- Half-up rounding for 0-, 2- and 3-minor-unit currencies.
- Source amount precision enforcement.
- Exact-head CI lint and Finance regression runs.

No production migrations or deployment are required for this additive service foundation.
