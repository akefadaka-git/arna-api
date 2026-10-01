<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — CR-L1
 * =============================================================================
 *
 * File:            Config/loan.php  (AMEND)
 * Unit:            CR-L1 — Portal self-service loans endpoint
 * Delivery type:   Amendment — one-line prefix correction; docblock
 *                  amendments recording the change and its precedent.
 * Net lines:       +1 changed default; ~40 lines docblock.
 *
 * Before:
 *   The routes prefix defaulted to 'loans':
 *
 *       'prefix' => (string) env('MASES_LOAN_ROUTE_PREFIX', 'loans'),
 *
 *   The provider applies this prefix verbatim. With the default, every
 *   Loan route mounted at /loans/* — not /api/loans/*. The observed
 *   `php artisan route:list` output confirmed the defect:
 *
 *       GET loans  api.loans.index  ›  …
 *
 *   Every other domain that mounts under the application's /api
 *   prefix (Shareholder, Wallet) declares its prefix with the `api/`
 *   segment included, because the provider's route-group call does
 *   NOT apply the `api/` segment itself. The Shareholder domain had
 *   the identical defect — its default was `shareholders`, corrected
 *   to `api/shareholders` earlier in this workstream.
 *
 *   The consequence for CR-L1: a new `GET /loans/me` route added to
 *   the domain's route file would have mounted at `/loans/me`, not
 *   `/api/loans/me`. The frontend's API client prepends
 *   `VITE_API_BASE_URL` — which resolves to the /api host — so the
 *   route would have been unreachable through the standard client
 *   path and inconsistent with the two existing self-service
 *   endpoints (`/api/shareholders/me`, `/api/wallets/me`).
 *
 * After:
 *   The default is `api/loans`:
 *
 *       'prefix' => (string) env('MASES_LOAN_ROUTE_PREFIX', 'api/loans'),
 *
 *   Every Loan route now mounts at /api/loans/*. The route:list output
 *   after deployment should show:
 *
 *       GET api/loans  api.loans.index  ›  …
 *
 *   matching the Shareholder and Wallet domains. The new
 *   `GET /api/loans/me` route (CR-L1 file 3) will land at the correct
 *   URL with no further prefix work.
 *
 * Dependency note:
 *   · The environment variable name is unchanged:
 *     `MASES_LOAN_ROUTE_PREFIX`. The deployment must ensure the value
 *     is either unset (so the new default applies) or explicitly set
 *     to `api/loans`. If the deployed .env carries
 *     `MASES_LOAN_ROUTE_PREFIX=loans` from before this CR, the config
 *     amendment alone will not take effect — the env var wins over
 *     the default. See the change-summary's deployment note.
 *
 *   · No other config key changes. `enabled`, `routes.middleware`,
 *     and `audit.emit_events` are byte-for-byte identical.
 *
 *   · No code change is required in LoanServiceProvider. The provider
 *     reads `config('loan.routes.prefix')` and applies it verbatim; a
 *     corrected config is sufficient.
 *
 *   · This amendment is a prerequisite for CR-L1 file 3 (the route
 *     addition) to produce a reachable URL. It ships in the same CR
 *     so that the two land together.
 *
 * =============================================================================
 */

/*
 * -----------------------------------------------------------------------------
 * MASES LOAN DOMAIN — CONFIGURATION
 * -----------------------------------------------------------------------------
 *
 * The Loan domain holds the company's lending capability with Maale Arna
 * shareholders. It records the loan lifecycle (application → approval →
 * disbursement → repayment → closure), the contractual amortization
 * schedule, and the collateral linkage. It does NOT hold the monetary
 * ledger — that belongs to the Wallet domain (Phase 2B).
 *
 * The domain is strictly company-as-lender → shareholder-as-borrower
 * (D129). It is single-tenant scoped: every loan belongs to exactly one
 * tenant, and the borrower must be a shareholder of that tenant.
 *
 * This file declares the domain's operational switches and its HTTP
 * surface. It does NOT contain business rules. Specifically, it contains
 * no interest rate, no amortization parameter, no product value, no fee,
 * no penalty, no term range, no minimum or maximum principal, and no
 * collateral coverage ratio — all financial rules are either (a) declared
 * per product in the `loan_products` table, (b) stored as versioned
 * payloads in the Core Versioned Policy Registry under the single
 * `loan_product_rules` type, or (c) frozen in the code paths that
 * implement the domain's approved algorithms.
 *
 * =============================================================================
 * Frozen decisions relevant to this file
 * =============================================================================
 *   D129  — Loan direction: company → shareholder only. No member-to-member
 *           lending, no shareholder-to-company lending, no cross-tenant
 *           lending. The Loan aggregate carries `borrower_shareholder_id`;
 *           the lender is the company by construction.
 *
 *   A9    — Interest method: declining balance, annual rate, monthly
 *           periods, Actual/365 day-count, half-up rounding, 4-decimal
 *           internal precision, BCMath arithmetic. No compounding of
 *           ordinary contractual interest.
 *
 *   D135  — Amortization: equal-installment / annuity. No bullet loans,
 *           no interest-only loans, no custom schedules, no combination
 *           methods in the initial Phase 2C implementation.
 *
 *   D132  — Term: months as the unit; monthly repayment frequency; terms
 *           are product-specific.
 *
 *   D133  — Arrears: penalty/late-charge obligation. Not ordinary
 *           contractual interest. No compounding, no capitalization. The
 *           penalty rate and grace period are policy-data (per product).
 *
 *   A10   — Collateral: optional and product-configurable. External
 *           collateral is Loan-domain data. Shareholding collateral is
 *           supported through a pledge relationship that is deferred to
 *           a Phase 2A Change Request (see CR-2C-COLLATERAL-PLEDGE-FK-001);
 *           the Loan side declares the linkage column but does not create
 *           the FK until that CR lands.
 *
 *   B6    — Product storage: hybrid. `loan_products` stores product
 *           identity and basic metadata; the Core Versioned Policy
 *           Registry stores the versioned financial rules under the
 *           single `loan_product_rules` policy type (Extra 2). Product
 *           identity is tenant-scoped (Extra 1).
 *
 *   B1    — Wallet reference types: exactly two values are written to the
 *           Wallet by the Loan domain: `'Loan'` for obligations
 *           (disbursement, interest, penalty) and `'LoanRepayment'` for
 *           payments. No `'LoanInterest'`, `'LoanPenalty'`, or
 *           `'LoanDisbursement'` values are introduced.
 *
 *   B3    — Interest recording: periodic. Interest becomes a Wallet
 *           obligation when the relevant installment's due date arrives.
 *           One accrual per installment, not daily compounding.
 *
 *   C4    — Loan state machine: twelve states, frozen verbatim:
 *
 *               draft, submitted, under_review, approved, rejected,
 *               disbursed, active, in_arrears, restructured,
 *               written_off, settled, closed
 *
 *           The transition matrix is enforced by `LoanStateMachine`.
 *
 *   C5    — Reconciliation: mandatory internal reconciliation at
 *           transaction boundaries + on-demand endpoint. Comparison is
 *           against the loan-linked Wallet obligation population, never
 *           against the wallet's entire `current_balance`.
 *
 * =============================================================================
 * What this file is NOT
 * =============================================================================
 *   - It is NOT a product catalogue. Loan product identities live in the
 *     `loan_products` table (tenant-scoped).
 *
 *   - It is NOT a policy payload. Financial rules live in the
 *     `versioned_policies` registry under the `loan_product_rules` type.
 *
 *   - It is NOT a currency selector. Currency is inherited from the
 *     borrower's Wallet (Q8 single-currency invariant, Phase 2B).
 *
 *   - It is NOT a permissions registry. Permissions are seeded by
 *     `LoanPermissionsSeeder`.
 *
 *   - It is NOT a state registry. States are declared on the
 *     `LoanStatus` enum.
 *
 *   - It is NOT an event registry. Events are dispatched by the domain
 *     services and registered in `LoanServiceProvider`.
 *
 * =============================================================================
 * Configuration keys
 * =============================================================================
 *   enabled           Whether the domain is active. When false:
 *                       - the LoanServiceProvider does not load the
 *                         migration directory,
 *                       - the LoanServiceProvider does not load the HTTP
 *                         routes,
 *                       - the domain is invisible to artisan migrate and
 *                         to HTTP clients.
 *
 *                     Policies, event listeners, and container bindings
 *                     remain registered unconditionally so that a
 *                     disable/enable cycle does not blind the audit
 *                     trail or break the container. This mirrors the
 *                     ShareholderServiceProvider (2A.2) and
 *                     WalletServiceProvider (2B.2) enable/disable
 *                     semantics exactly.
 *
 *   routes.prefix     URI prefix for the domain's HTTP routes. The
 *                     provider mounts the domain's route file under this
 *                     prefix. Default: `api/loans`.
 *
 *                     The prefix MUST include the `api/` segment when
 *                     the domain's routes should mount under the
 *                     application's API surface. The provider's
 *                     route-group call does NOT add the `api/` segment
 *                     itself; the config is the single source of the
 *                     full prefix. This is the same convention the
 *                     Shareholder and Wallet domains follow — see
 *                     `config/shareholder.php`'s corrected default and
 *                     `config/wallet.php`'s matching value.
 *
 *                     Corrected in CR-L1: the previous default was
 *                     `loans` (missing the `api/` segment), which
 *                     mounted every route at `/loans/*` instead of
 *                     `/api/loans/*`. The defect was latent until CR-L1
 *                     needed to add a self-service route at
 *                     `/api/loans/me`; the correction ships with CR-L1.
 *
 *   routes.middleware Middleware stack applied to every loan route. The
 *                     default stack mirrors the Shareholder and Wallet
 *                     domains and provides the three protections every
 *                     endpoint requires:
 *                       - `api`              : Laravel's API middleware
 *                                              group.
 *                       - `auth:sanctum`     : authenticated-user gate.
 *                       - `tenant.membership`: tenant resolution +
 *                                              membership check +
 *                                              forged-header rejection.
 *
 *                     The stack is NOT to be modified without a Change
 *                     Request: each entry carries a specific
 *                     authorization guarantee that the isolation test
 *                     suite depends on.
 *
 *   audit.emit_events Whether the domain dispatches its thirteen business
 *                     events to the audit listener. When false, the
 *                     services still execute their writes and return
 *                     their results; only the after-commit event
 *                     dispatch is suppressed. Default: true.
 *
 *                     This is the operator's switch for suppressing the
 *                     audit trail during a maintenance window. It must
 *                     NOT be set to false in production except under
 *                     documented operational control.
 *
 * =============================================================================
 * What this file deliberately does NOT contain
 * =============================================================================
 *   interest_rate              Per-product policy-data. A9 freezes the
 *                              METHOD (declining balance, annual rate,
 *                              monthly, Actual/365, half-up, 4dp,
 *                              BCMath); the RATE is product-specific.
 *
 *   amortization_parameters    Per-product policy-data. D135 freezes the
 *                              METHOD (equal installment / annuity); the
 *                              parameters follow from principal, rate,
 *                              and term.
 *
 *   term_minimum /             Per-product policy-data. D132 freezes the
 *   term_maximum               unit (months) and the frequency (monthly);
 *                              the specific bounds are product-specific.
 *
 *   principal_minimum /        Per-product policy-data.
 *   principal_maximum
 *
 *   fee_schedule               Per-product policy-data.
 *
 *   penalty_rate /             Per-product policy-data. D133 freezes the
 *   penalty_grace_days         form (a penalty/late-charge obligation);
 *                              the rate and the grace threshold are
 *                              policy-controlled.
 *
 *   collateral_coverage_ratio  Per-product policy-data. A10 makes
 *                              collateral optional and product-
 *                              configurable; the coverage ratio is
 *                              product-specific.
 *
 *   currency_list              Not required. Currency is inherited from
 *                              the borrower's Wallet (Q8); the invariant
 *                              that the loan's currency equals the
 *                              wallet's currency is enforced at the
 *                              service layer, not by a config-level
 *                              allow-list.
 *
 *   state_list                 Not required. The closed vocabulary of
 *                              twelve states lives on the `LoanStatus`
 *                              enum, which is the single source of truth.
 *
 *   permission_list            Not required. The fifteen loan permissions
 *                              are declared by `LoanPermissionsSeeder`,
 *                              which is the single source of truth.
 *
 *   reference_type_list        Not required. The two reference-type values
 *                              the Loan domain writes to the Wallet
 *                              (`'Loan'`, `'LoanRepayment'`) are declared
 *                              in the Wallet's `WalletService::REFERENCE_TYPE_MAP`
 *                              per CR-2C-WALLET-REFTYPE-001, and are
 *                              enforced at the Wallet service layer.
 *                              Duplicating them here would create a
 *                              second source that could drift.
 *
 * =============================================================================
 * Environment variables
 * =============================================================================
 *   MASES_LOAN_ENABLED              default true
 *   MASES_LOAN_ROUTE_PREFIX         default 'api/loans'   (was 'loans')
 *   MASES_LOAN_AUDIT_EMIT_EVENTS    default true
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — A9, A10, D129, D130,
 *                                                  D132, D133, D135
 *   Phase 2C Clarification Response, 2026-09-12  — C1–C5, Extra 1, Extra 2
 *   Phase 2B Wallet Architecture v1.2.0          — Q8, single-currency
 *                                                  invariant, reference-
 *                                                  type mapping
 *   Phase 2C Final Reconciliation, 2026-09-12    — Reference-id ordering,
 *                                                  loan-list authorization,
 *                                                  reconciliation invariant
 *   config/shareholder.php                       — the corrected-prefix
 *                                                  precedent
 *   config/wallet.php                            — the sibling default
 *   CR-L1                                        — This amendment
 *   Step 6 (manifest) 2C.1                       — This file
 */

return [

    // =========================================================================
    // Domain toggle
    // =========================================================================
    //
    // When false, LoanServiceProvider skips migration loading and route
    // registration. Container bindings, policies, and event listeners
    // remain registered — see the header block for the rationale. This is
    // the operator's switch for disabling the domain without schema changes.
    //
    // The default is true because Phase 2C delivers the loan as a live
    // capability; deployments that need it disabled set the environment
    // variable to false.
    // -------------------------------------------------------------------------
    'enabled' => (bool) env('MASES_LOAN_ENABLED', true),

    // =========================================================================
    // HTTP surface
    // =========================================================================
    //
    // Consumed by LoanServiceProvider::loadDomainRoutes() (A.85). The
    // provider wraps the routes file (A.84) in a group built from these
    // values.
    // -------------------------------------------------------------------------
    'routes' => [

        // ---------------------------------------------------------------------
        // URI prefix.
        //
        // Full URIs are formed as `/{prefix}/{route-path}`. With the
        // default prefix, the index endpoint resolves at `/api/loans`
        // and the nested release endpoint at
        // `/api/loans/{loan}/collateral/{collateral}/release`.
        //
        // The `api/` segment is part of the config value — the
        // provider's route-group call does NOT add it. This matches the
        // Shareholder domain's corrected default (`api/shareholders`)
        // and the Wallet domain's matching value (`wallets`, which
        // resolves under the application's default `api/` group in the
        // Wallet provider's registration — see that domain's config for
        // the exact arrangement).
        //
        // The prefix is deliberately plural and lowercase to match
        // Laravel's resource-route convention. The route file declares
        // a static `/products` path BEFORE the dynamic `/{loan}` path,
        // per the ordering rule frozen in Stage 6 §4.16. The CR-L1
        // amendment adds a static `/me` path, also declared before the
        // dynamic `/{loan}` route.
        //
        // Corrected in CR-L1. See the file-level change-verification
        // block for the defect and its consequences.
        // ---------------------------------------------------------------------
        'prefix' => (string) env('MASES_LOAN_ROUTE_PREFIX', 'api/loans'),

        // ---------------------------------------------------------------------
        // Middleware stack.
        //
        // Applied to every route in the domain's routes file. The three
        // entries provide:
        //
        //   api                  — Laravel's API middleware group.
        //   auth:sanctum         — Bearer-token authentication gate.
        //                          Rejects unauthenticated requests with 401.
        //   tenant.membership    — Resolves the tenant via `mases_tenant_id()`,
        //                          verifies the authenticated user is a
        //                          member, and rejects forged X-Tenant-ID
        //                          headers with 403 TENANT_ACCESS_DENIED.
        //
        // The stack is mandatory for the isolation guarantees tested by the
        // Loan-domain TenantIsolationTest suite. Do not modify without a
        // Change Request.
        // ---------------------------------------------------------------------
        'middleware' => [
            'api',
            'auth:sanctum',
            'tenant.membership',
        ],

    ],

    // =========================================================================
    // Audit
    // =========================================================================
    //
    // Consumed by every Loan service's dispatchAfterCommit() helper. The
    // thirteen domain events (LoanApplicationSubmitted, LoanApproved,
    // LoanRejected, LoanDisbursed, LoanRepaymentRecorded,
    // LoanInstallmentSettled, LoanEnteredArrears, LoanPenaltyRecorded,
    // LoanRestructured, LoanWrittenOff, LoanSettled, LoanClosed,
    // LoanCollateralReleased) are registered against the AuditLoanEvent
    // listener in LoanServiceProvider (A.85).
    // -------------------------------------------------------------------------
    'audit' => [

        // ---------------------------------------------------------------------
        // Emit domain events.
        //
        // When false, the thirteen domain events are NOT dispatched. The
        // services still execute their writes (loan rows, installment rows,
        // Wallet obligations, Wallet payments, etc.); only the after-commit
        // event dispatch is suppressed.
        //
        // The default is true. Setting it to false suppresses the audit
        // trail produced by the AuditLoanEvent listener. This is an
        // operator-controlled switch for use during a maintenance window;
        // it should never be left false in production.
        //
        // Note: the append-only Wallet ledger is INDEPENDENT of this switch.
        // Wallet writes always occur; only the Loan-domain event-driven
        // audit trail is affected. Likewise, the loan's own persisted
        // state and schedule are unaffected.
        // ---------------------------------------------------------------------
        'emit_events' => (bool) env('MASES_LOAN_AUDIT_EMIT_EVENTS', true),

    ],

];