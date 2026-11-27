<?php

declare(strict_types=1);

/*
 * -----------------------------------------------------------------------------
 * ARNA WALLET DOMAIN — CONFIGURATION
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
 * Historical changes are recorded in Git.
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
    'enabled' => env('ARNA_WALLET_ENABLED', true),

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
        //   tenant.membership    — Resolves the tenant via `arna_tenant_id()`,
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
        'emit_events' => env('ARNA_WALLET_AUDIT_EMIT_EVENTS', true),

    ],

];