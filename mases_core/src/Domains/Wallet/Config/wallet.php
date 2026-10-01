<?php

declare(strict_types=1);

/*
 * -----------------------------------------------------------------------------
 * MASES WALLET DOMAIN — CONFIGURATION
 * -----------------------------------------------------------------------------
 *
 * The Wallet domain holds a member's statement-of-account ledger with Maale
 * Arna. It records obligations the member owes (or is owed), payments
 * received, and the explicit allocation of those payments to specific
 * obligations — under policy slots whose contents are deferred (A7, A8,
 * D110).
 *
 * This file declares the domain's operational switches and its HTTP
 * surface. It does NOT contain business rules. Specifically, it contains
 * no interest rate, no allocation priority, no KYC threshold, and no
 * currency list — all four are either deferred to a future Change Request
 * (per the frozen architecture §18) or enforced at the service layer.
 *
 * =============================================================================
 * Revision history
 * =============================================================================
 * 1.0.0  — initial delivery (Step 5 manifest 2B.1). Domain toggle, HTTP
 *          surface (prefix `wallets`, middleware stack), and audit
 *          switch.
 *
 * 1.1.0  — HTTP prefix corrected from `wallets` to `api/wallets`:
 *          · The provider mounts the domain's routes at
 *            `/{prefix}/{path}`, so with the original prefix the
 *            domain's nine routes resolved to `/wallets`,
 *            `/wallets/{wallet}`, `/wallets/{wallet}/adjust`, and so
 *            on. The frontend's wallet API module
 *            (features/wallet/api.ts) calls paths starting with
 *            `/api/wallets`, matching every other domain's URL
 *            convention on the platform:
 *
 *              auth          → /api/login, /api/register, …
 *              files         → /api/files, …
 *              shareholders  → /api/shareholders, …
 *              wallet        → /api/wallets, …  (this revision)
 *
 *            The mismatch produced a 404 on every wallet request from
 *            the frontend. The corrected prefix makes the Wallet
 *            domain a URL sibling of the other domains.
 *          · The header's claim that the Wallet prefix "mirrors the
 *            Shareholder domain's `shareholders` prefix" was
 *            inaccurate — the Shareholder routes file's own docblock
 *            states its default prefix resolves to
 *            `GET /api/shareholders`. The corrected Wallet prefix
 *            brings the two domains into alignment.
 *          · The two docblock sections that described the prefix
 *            (§"Configuration keys" → `routes.prefix` and the "URI
 *            prefix" block inside the `routes` array) were rewritten
 *            to state the correct value and its reasoning.
 *          · No change to `enabled`, `routes.middleware`, or
 *            `audit.emit_events`. The middleware stack and the audit
 *            switch are byte-for-byte identical to 1.0.0. The
 *            middleware in particular was verified at deployment time
 *            to be exactly `['api', 'auth:sanctum', 'tenant.membership']`,
 *            with the per-route `auth.permission:*` guards applied by
 *            the routes file itself.
 *          · MINOR version bump. The change corrects a single config
 *            value, but it alters the URL surface the domain exposes:
 *            the prior URLs (`/wallets`, `/wallets/{wallet}`, …) no
 *            longer match after this change. A non-backward-compatible
 *            URL change is MINOR under the deployment's versioning
 *            policy.
 *          · Deployment note: this change requires `php artisan
 *            route:clear` if route caching is enabled on the host.
 *            The corrected URIs take effect on the next request after
 *            the cache is cleared. See the Wallet domain's deployment
 *            notes for the Plesk procedure.
 *
 * =============================================================================
 * Configuration keys
 * =============================================================================
 *   enabled               Whether the domain is active. When false:
 *                           - the WalletServiceProvider does not load the
 *                             migration directory,
 *                           - the WalletServiceProvider does not load the
 *                             HTTP routes,
 *                           - the domain is invisible to artisan migrate
 *                             and to HTTP clients.
 *
 *                         Policies, event listeners, and container bindings
 *                         remain registered unconditionally so that a
 *                         disable/enable cycle does not blind the audit
 *                         trail or break the container for consumers that
 *                         hold nullable wallet references. This mirrors the
 *                         ShareholderServiceProvider (2A.2) enable/disable
 *                         semantics.
 *
 *   routes.prefix         URI prefix for the domain's HTTP routes. The
 *                         provider mounts the domain's route file under
 *                         this prefix, so full URIs are formed as
 *                         `/{prefix}/{route-path}`. The default is
 *                         `api/wallets`, which resolves the index endpoint
 *                         at `GET /api/wallets` and the nested statement
 *                         endpoint at `GET /api/wallets/{wallet}/statement`.
 *                         This matches the platform's URL convention
 *                         shared by the auth, files, and shareholder
 *                         domains. See §"Revision history" for the 1.1.0
 *                         correction that restored this value from the
 *                         original `wallets`.
 *
 *   routes.middleware     Middleware stack applied to every wallet route.
 *                         The default stack mirrors the Shareholder domain
 *                         and provides the three protections every 2B
 *                         endpoint requires:
 *                           - `api`              : Laravel's API middleware
 *                                                  group (throttle,
 *                                                  substitutes bindings).
 *                           - `auth:sanctum`     : authenticated-user gate.
 *                           - `tenant.membership`: tenant resolution +
 *                                                  membership check +
 *                                                  forged-header
 *                                                  rejection.
 *
 *                         The stack is NOT to be modified without a Change
 *                         Request: each entry carries a specific
 *                         authorization guarantee that the isolation test
 *                         suite (2B.67) depends on.
 *
 *   audit.emit_events     Whether the domain dispatches its seven business
 *                         events to the audit listener. When false, the
 *                         services still execute their ledger writes and
 *                         return their results; only the after-commit event
 *                         dispatch is suppressed. Default: true.
 *
 *                         This is the operator's switch for suppressing the
 *                         audit trail during a maintenance window. It must
 *                         NOT be set to false in production except under
 *                         documented operational control.
 *
 * =============================================================================
 * What this file deliberately does NOT contain
 * =============================================================================
 *   interest_rate         Open per A8. The wallet's interest policy slot
 *                         (wallets.interest_policy_version_id) references
 *                         the Core versioned-policy registry; no rate,
 *                         method, cadence, capitalization, minimum balance,
 *                         eligibility, or formula is declared here.
 *
 *   allocation_priority   Open per D110. Allocation is explicit; no
 *                         universal priority order is encoded. The wallet's
 *                         allocation policy slot
 *                         (wallets.allocation_policy_version_id) references
 *                         the registry.
 *
 *   kyc                   Open per A7. The wallet's KYC policy slot
 *                         (wallets.kyc_policy_version_id) references the
 *                         registry; the KYC field matrix is not frozen.
 *
 *   currency_list         Not required. Each wallet carries its own
 *                         `currency` column (Q8); the invariant that
 *                         obligations and payments match the wallet's
 *                         currency is enforced at the service layer, not by
 *                         a config-level allow-list.
 *
 *   entry_types           Not required. The closed vocabulary lives on the
 *                         WalletEntryType enum (2B.4), which is the single
 *                         source of truth. Duplicating it in config would
 *                         create a second source that could drift.
 *
 *   obligation_components Not required. The closed vocabulary lives on the
 *                         ObligationComponent enum (2B.3), same rationale.
 *
 * =============================================================================
 * Environment variables
 * =============================================================================
 *   MASES_WALLET_ENABLED              default true
 *   MASES_WALLET_AUDIT_EMIT_EVENTS    default true
 *
 * The routes prefix is NOT env-driven. It is a structural property of the
 * domain's URL contract, not an operational switch — a deployment that
 * changed the prefix would break every client that calls the domain's
 * routes. A future CR that wants env-driven configuration for a
 * multi-region deployment changes the value here and adds a matching
 * `env()` fallback in the same change.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Architecture v1.2.0 §1      — Purpose and scope
 *   Architecture v1.2.0 §2      — Bounded context and domain boundary
 *   Architecture v1.2.0 §13     — RBAC integration
 *   Architecture v1.2.0 §14     — Audit integration
 *   Architecture v1.2.0 §17     — Migration ownership and structure
 *   Architecture v1.2.0 §18     — Explicitly deferred items
 *   Architecture v1.2.0 §19     — Q1–Q8 resolutions
 *   Step 5 (manifest) 2B.1      — This file
 *   Step 7 v1.1.0 §A.3          — Seeder structure (permissions seeded by
 *                                 2B.55, roles by 2B.56; not config-driven)
 *   Step 7 v1.1.0 §C.1          — Nine-route API structure; prefix and
 *                                 middleware are consumed from here
 */

return [

    // =========================================================================
    // Domain toggle
    // =========================================================================
    //
    // When false, WalletServiceProvider skips migration loading and route
    // registration. Container bindings, policies, and event listeners
    // remain registered — see the header block for the rationale. This is
    // the operator's switch for disabling the domain without schema changes.
    //
    // The default is true because Phase 2B delivers the wallet as a live
    // capability; deployments that need it disabled set the environment
    // variable to false.
    // -------------------------------------------------------------------------
    'enabled' => env('MASES_WALLET_ENABLED', true),

    // =========================================================================
    // HTTP surface
    // =========================================================================
    //
    // Consumed by WalletServiceProvider::loadDomainRoutes() (2B.2). The
    // provider wraps the routes file (2B.48) in a group built from these
    // values.
    // -------------------------------------------------------------------------
    'routes' => [

        // ---------------------------------------------------------------------
        // URI prefix.
        //
        // Full URIs are formed as `/{prefix}/{route-path}`. The prefix
        // `api/wallets` (this revision's corrected value; the original
        // 1.0.0 file had `wallets`) resolves the index endpoint at
        // `GET /api/wallets` and the nested statement endpoint at
        // `GET /api/wallets/{wallet}/statement`.
        //
        // The `api/` segment is not decorative. Every other domain on the
        // platform is served under `/api/`:
        //
        //     auth          → /api/login, /api/register, /api/verify-email/…
        //     files         → /api/files, /api/files/{id}, …
        //     shareholders  → /api/shareholders, /api/shareholders/classes, …
        //
        // The Wallet domain is a URL sibling of those domains. Its route
        // NAME prefix is already `api.wallets.` — see the routes file's
        // own docblock. Only the URL prefix was missing the `api/`
        // segment in 1.0.0; this revision restores it.
        //
        // The prefix is deliberately plural and lowercase to match
        // Laravel's resource-route convention.
        // ---------------------------------------------------------------------
        'prefix' => 'api/wallets',

        // ---------------------------------------------------------------------
        // Middleware stack.
        //
        // Applied to every route in the domain's routes file. The three
        // entries provide:
        //
        //   api                  — Laravel's API middleware group.
        //   auth:sanctum         — Bearer-token authentication gate. Rejects
        //                          unauthenticated requests with 401.
        //   tenant.membership    — Resolves the tenant via `mases_tenant_id()`,
        //                          verifies the authenticated user is a
        //                          member, and rejects forged X-Tenant-ID
        //                          headers with 403 TENANT_ACCESS_DENIED.
        //
        // The stack is mandatory for the isolation guarantees tested by
        // 2B.67. Do not modify without a Change Request.
        //
        // The per-route `auth.permission:*` guards are applied by the
        // routes file itself, not by this stack. The verified middleware
        // for each of the nine routes at deployment time was:
        //
        //   wallets.index              → …, auth.permission:wallet.view
        //   wallets.store              → …, auth.permission:wallet.open
        //   wallets.show               → …  (no per-route guard; policy)
        //   wallets.adjust             → …, auth.permission:wallet.adjust
        //   wallets.obligations.store  → …, auth.permission:wallet.obligation.create
        //   wallets.payments.store     → …, auth.permission:wallet.payment.record
        //   wallets.allocations.store  → …, auth.permission:wallet.allocation.create
        //   wallets.allocations.reverse→ …, auth.permission:wallet.allocation.reverse
        //   wallets.statement.show     → …  (no per-route guard; policy)
        //
        // The two unguarded routes delegate authorization to the policy
        // layer, matching the pattern documented in the routes file's
        // own header.
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
    // Consumed by every Wallet service's dispatchAfterCommit() helper. See
    // Architecture §14 for the event inventory and the after-commit
    // discipline.
    // -------------------------------------------------------------------------
    'audit' => [

        // ---------------------------------------------------------------------
        // Emit domain events.
        //
        // When false, the seven domain events (WalletOpened,
        // ObligationRecorded, PaymentReceived, PaymentAllocated,
        // PaymentAllocationReversed, WalletAdjusted, WalletEntryReversed)
        // are NOT dispatched. The services still execute their ledger
        // writes; only the after-commit event dispatch is suppressed.
        //
        // The default is true. Setting it to false suppresses the audit
        // trail produced by the AuditWalletEvent listener (2B.47). This is
        // an operator-controlled switch for use during a maintenance
        // window; it should never be left false in production.
        //
        // Note: the append-only wallet ledger (wallet_entries) is
        // INDEPENDENT of this switch. Ledger writes always occur; only the
        // event-driven audit trail is affected.
        // ---------------------------------------------------------------------
        'emit_events' => env('MASES_WALLET_AUDIT_EMIT_EVENTS', true),

    ],

];