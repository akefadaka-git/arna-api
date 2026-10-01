<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — P2.3 follow-up
 * =============================================================================
 *
 * File:            Config/shareholder.php
 * Unit:            Register the Shareholder routes at /api/shareholders
 * Delivery type:   Amendment
 * Net lines:       +1 line changed; +~15 docblock lines rewritten
 *
 * Before:
 *   The routes block declared:
 *
 *       'prefix' => env('MASES_SHAREHOLDER_ROUTE_PREFIX', 'shareholders'),
 *
 *   With the default in place and no env override, the provider's
 *   loadDomainRoutes() applied `->prefix('shareholders')` to the whole
 *   route group. The resulting URIs were therefore:
 *
 *       /shareholders              (index, store)
 *       /shareholders/me           (me)
 *       /shareholders/classes      (classes.index)
 *       /shareholders/holdings     (holdings.index)
 *       /shareholders/certificates (certificates.index)
 *       …
 *
 *   Every consumer of the Shareholder domain — the workspace SPA, the
 *   portal SPA, and the artisan route:list output — expects the routes
 *   to sit under the API's `/api` prefix. The Wallet domain's routes
 *   are already mounted at `/api/wallets`; the Loan domain's at
 *   `/api/loans`; the Core domain's at `/api/files` and `/api/user`.
 *   The Shareholder domain was the only one whose prefix omitted the
 *   `api/` segment.
 *
 *   The consequence was that every portal request to
 *   `https://api.maalearna.com.et/api/shareholders/me` fell through to
 *   the fallback route and returned 404 ROUTE_NOT_FOUND. The portal's
 *   identity composable (useMyShareholder) treated the 404 as an error
 *   and rendered the ErrorState on every mount.
 *
 *   The defect was latent because the provider's `loadDomainRoutes()`
 *   method was not executing at all until earlier this session — the
 *   file at `Routes/api.php` was missing or misnamed, and the is_file()
 *   guard silently returned. Once the file was placed correctly and the
 *   provider began loading it, the prefix mismatch became the visible
 *   fault.
 *
 * After:
 *   The routes block declares:
 *
 *       'prefix' => env('MASES_SHAREHOLDER_ROUTE_PREFIX', 'api/shareholders'),
 *
 *   The default now includes the `api/` segment, matching the
 *   convention every other domain uses. The resulting URIs are:
 *
 *       /api/shareholders
 *       /api/shareholders/me
 *       /api/shareholders/classes
 *       /api/shareholders/holdings
 *       /api/shareholders/certificates
 *       …
 *
 *   The env override remains available: a deployment can still set
 *   MASES_SHAREHOLDER_ROUTE_PREFIX in .env to point the routes at a
 *   different mount path without touching source.
 *
 * =============================================================================
 * Convention alignment
 * =============================================================================
 * Comparing the four registered domains' `routes.prefix` defaults:
 *
 *     Core           api/files        (mounted at /api/files/*)
 *     Wallet         api/wallets      (mounted at /api/wallets/*)
 *     Loan           api/loans        (mounted at /api/loans/*)
 *     Shareholder    api/shareholders (this fix)
 *
 *   Every domain's prefix begins with `api/`. The `api` entry in each
 *   domain's `routes.middleware` array is a middleware alias (the
 *   framework's API middleware group), not a URL prefix — the two are
 *   distinct concepts and the config correctly treats them as such.
 *
 *   After this fix, the four domains are consistent.
 *
 * =============================================================================
 * Deployment
 * =============================================================================
 * This is a config change. After uploading the file, the Laravel config
 * cache MUST be cleared:
 *
 *     php artisan config:clear
 *
 * Without the clear, the previously cached config continues to apply and
 * the routes remain at the old prefix. Verify with:
 *
 *     php artisan route:list --path=api/shareholders
 *
 * Expected: fourteen rows, every URI starting with `api/shareholders/`.
 *
 * No route file change is needed — the provider reads the prefix from
 * this config at boot time.
 *
 * Files modified:            this file only
 * Files added:               none
 * Files deleted:             none
 * Value changed:             1 (routes.prefix default)
 * =============================================================================
 */

/*
|--------------------------------------------------------------------------
| MASES Shareholder Domain — Configuration
|--------------------------------------------------------------------------
|
| This file defines the domain-level constants and defaults for the
| MASES Shareholder domain. Every service, model, request, migration and
| resource under mases_core/src/Domains/Shareholder/ MUST read these values
| from config('shareholder.*') rather than hard-coding them locally.
|
| The config is merged into the application container by
| ShareholderServiceProvider (2A.2) under the key `shareholder`.
|
| -----------------------------------------------------------------------------
| Source decisions
| -----------------------------------------------------------------------------
|   D3  — Share classes: FOUNDERS (መስራች) and STANDARD (መደበኛ).
|   D4  — Par value: ETB 10,000 per share.
|   D5  — Currency: ETB (ብር).
|   D6  — Fractional shares NOT permitted; fractional currency payment IS.
|   D7  — Treasury / buyback permitted.
|   D8  — A shareholder is a natural person or a legally recognized legal
|         entity that lawfully owns shares of Maale Arna Share Company and
|         is recorded as the holder in the official shareholder register.
|   D9  — Permitted shareholder types: individual, company, trust,
|         cooperative, government, religious_body.
|   D11 — Representative model. A legal-entity shareholder acts through a
|         designated authorized human representative User. The entity is
|         not a login account. The representative is not the owner.
|   D16 — ShareHolding = current-state projection. ShareHoldingEntry =
|         append-only ledger. Balance-changing operations flow through the
|         ShareHoldingService only, inside a DB transaction, with row lock.
|   D12 — KYC field matrix is pending. This config declares KYC as
|         disabled; the KYC schema is defined by a future manifest.
|
| -----------------------------------------------------------------------------
| Capital facts (recorded for seeding and reference)
| -----------------------------------------------------------------------------
| The corporate evidence for Phase 2A establishes:
|
|     Founders' shares:          145
|     Par value per share:       ETB 10,000
|     Total stated/par value:    ETB 1,450,000  (145 × 10,000)
|
| These are SEED REFERENCE values only. They are NOT a classification of
| registered / authorized / subscribed / issued / paid-up capital. That
| classification is a future decision and MUST NOT be embedded in schema
| or code.
|
| A separate internal-issuance figure (10,000,000 ETB / 1,000 shares) was
| discussed and later corrected. Only the founders' figure above is
| authoritative for Phase 2A seeding.
|
| -----------------------------------------------------------------------------
| Amharic-first / English-second
| -----------------------------------------------------------------------------
| All name-bearing entities store both `name_am` and `name_en`. This config
| supplies the canonical class codes and their bilingual display labels.
| The label strings themselves live on the seed data (share_classes rows),
| not in this config — this config only declares which codes are valid.
|
| -----------------------------------------------------------------------------
| Editing rules
| -----------------------------------------------------------------------------
|   - Values in this file are technical constants. Changing them can break
|     migrations, seeders and tests.
|   - Any change to a value declared here requires a Change Request per
|     Master Prompt Section 58.
|   - Operator-tunable values (those wrapped in env()) may be changed via
|     .env without a CR.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Master switch for the Shareholder domain. When false, the
    | ShareholderServiceProvider SHOULD skip route registration and refuse
    | write operations. Read-only access may still be permitted for audit
    | and reporting reasons.
    |
    | Default: true — the domain is live in Phase 2A.
    |
    */
    'enabled' => (bool) env('MASES_SHAREHOLDER_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Share Classes
    |--------------------------------------------------------------------------
    |
    | The canonical share-class codes recognised by the domain. These are
    | immutable identifiers stored in the `code` column of `share_classes`.
    |
    | Two classes exist in Phase 2A:
    |
    |   FOUNDERS  — held by the 29 founder shareholders, 145 shares total.
    |               The Amharic label is መስራች.
    |
    |   STANDARD  — for internal use. The Amharic label is መደበኛ.
    |               The English label "Standard" is a working label; the
    |               canonical corporate term must be reconciled against the
    |               Articles/Memorandum before production disclosure.
    |
    | Codes MUST be uppercase A–Z, digits and underscore only. The
    | StoreShareClassRequest (2A.18) enforces this rule; the
    | ShareClass model (2A.6) rejects unknown codes at read time.
    |
    */
    'share_classes' => [

        'codes' => [
            'FOUNDERS',
            'STANDARD',
        ],

        // The class every newly registered shareholder attaches to unless
        // explicitly assigned otherwise. Phase 2A defaults to STANDARD
        // because FOUNDERS is a closed historical set.
        'default_code' => env('MASES_SHAREHOLDER_DEFAULT_CLASS', 'STANDARD'),

        // The class considered "founder" for the disclosure gate in D2.
        // Only holders of this class are eligible for public disclosure.
        'founder_code' => 'FOUNDERS',

    ],

    /*
    |--------------------------------------------------------------------------
    | Par Value & Currency
    |--------------------------------------------------------------------------
    |
    | The par value is a per-share monetary figure, expressed in the domain
    | currency. Stored as a string so that arbitrary precision is preserved
    | across the decimal boundary. Callers that require numeric arithmetic
    | MUST use bcmath or a decimal value object — never float.
    |
    | D4: par value = 10,000 ETB.
    | D5: currency  = ETB.
    |
    */
    'par_value' => [
        'amount'   => env('MASES_SHAREHOLDER_PAR_VALUE', '10000'),
        'currency' => env('MASES_SHAREHOLDER_CURRENCY', 'ETB'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Quantity Precision & Fractional Shares
    |--------------------------------------------------------------------------
    |
    | `precision` reflects the DECIMAL scale used in the `quantity`,
    | `current_quantity`, `balance_before` and `balance_after` columns.
    |
    | D16-X2 approved DECIMAL(20, 4), which gives four decimal places of
    | headroom for future business requirements WITHOUT authorising fractional
    | shares today.
    |
    | D6: fractional shares are NOT permitted. The `fractional_shares_allowed`
    | flag is therefore false, and the ShareHoldingService (2A.14) and
    | StoreShareHoldingRequest (2A.19) MUST reject any quantity whose scale
    | exceeds zero.
    |
    | `fractional_currency_allowed` is true: monetary values such as
    | subscription payments, dividends or transfer consideration MAY hold
    | fractional currency amounts.
    |
    */
    'quantity' => [
        'precision'                    => 4,
        'scale'                        => 20,
        'fractional_shares_allowed'    => false,
        'fractional_currency_allowed'  => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Shareholder Types
    |--------------------------------------------------------------------------
    |
    | D9: the permitted shareholder types. These map 1:1 to the values stored
    | in `shareholders.shareholder_type`. They are also the values used by
    | the ShareholderType enum (2A.3).
    |
    | For every type except `individual`, a designated representative User
    | is required (D11). `individual` shareholders may optionally be linked
    | to a User for self-service access.
    |
    */
    'shareholder_types' => [
        'individual',
        'company',
        'trust',
        'cooperative',
        'government',
        'religious_body',
    ],

    /*
    |--------------------------------------------------------------------------
    | Representative Policy
    |--------------------------------------------------------------------------
    |
    | Under D11 the relationship between a legal-entity shareholder and the
    | human who operates its MASES account is explicitly a *representation*,
    | not ownership.
    |
    | `require_for_types` — the shareholder types for which a representative
    | User is mandatory. `individual` is deliberately excluded because an
    | individual may represent themselves.
    |
    | `max_active_per_shareholder` — the number of simultaneously active
    | representatives permitted per legal-entity shareholder. A cap of 1
    | is the strict reading of D11; historical representatives are retained
    | through the audit trail rather than through additional live rows.
    |
    */
    'representative' => [
        'require_for_types'          => [
            'company',
            'trust',
            'cooperative',
            'government',
            'religious_body',
        ],
        'max_active_per_shareholder' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Ledger Entry Types
    |--------------------------------------------------------------------------
    |
    | D16-R3 / D16-R8: the closed vocabulary of entry types recorded on
    | `share_holding_entries.entry_type`. Additional values require a
    | Change Request.
    |
    | Semantics:
    |
    |   subscription    — a new issuance of shares to a shareholder.
    |   transfer_in     — shares received from another shareholder.
    |   transfer_out    — shares sent to another shareholder.
    |   allocation      — a bulk allocation event (e.g. dividend-in-shares,
    |                     bonus issue).
    |   adjustment      — a manual administrative correction that is NOT a
    |                     reversal of a specific prior entry.
    |   reversal        — the inverse of a prior entry, linked via
    |                     `reversed_entry_id`.
    |
    */
    'entry_types' => [
        'subscription',
        'transfer_in',
        'transfer_out',
        'allocation',
        'adjustment',
        'reversal',
    ],

    /*
    |--------------------------------------------------------------------------
    | Negative Balance Policy
    |--------------------------------------------------------------------------
    |
    | D16-R5: a posted transaction must never drive a ShareHolding balance
    | below zero. The invariant is enforced by ShareHoldingService inside the
    | transaction, before the entry and the balance update are committed.
    |
    | The value is declared here (rather than hard-coded in the service) so
    | that a future business decision can relax it without editing the
    | service class — though doing so will still require a Change Request.
    |
    */
    'allow_negative_balance' => false,

    /*
    |--------------------------------------------------------------------------
    | Certificate Lifecycle
    |--------------------------------------------------------------------------
    |
    | The four states a ShareCertificate may hold. Values map 1:1 to the
    | CertificateStatus enum (2A.5) and to the values stored on
    | `share_certificates.status`.
    |
    |   draft      — a prepared certificate not yet issued.
    |   issued     — an active certificate.
    |   cancelled  — withdrawn; the underlying holding may or may not remain.
    |   replaced   — superseded by another certificate; retained for history.
    |
    */
    'certificate' => [

        'statuses' => [
            'draft',
            'issued',
            'cancelled',
            'replaced',
        ],

        // The status a newly created certificate enters. Phase 2A issues
        // directly — draft is reserved for a future workflow.
        'default_status' => 'issued',

        // `certificate_number` is a nullable string populated manually in
        // Phase 2A. No automatic allocation, no sequence counter.
        'number' => [
            'auto_generate'   => false,
            'nullable'        => true,
            'max_length'      => 64,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Treasury / Buyback
    |--------------------------------------------------------------------------
    |
    | D7: treasury and buyback are permitted. This flag is informational for
    | Phase 2A — the actual treasury operations belong to a later phase.
    | When true, the ShareClass model may carry treasury indicators and the
    | ShareHoldingService may accept treasury-shaped entries whose target
    | shareholder is the company itself (once such a shareholder record is
    | created through a Change Request).
    |
    */
    'treasury' => [
        'enabled' => (bool) env('MASES_SHAREHOLDER_TREASURY_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Concurrency Control
    |--------------------------------------------------------------------------
    |
    | D16-R6: any balance-changing operation MUST lock the ShareHolding row
    | for the duration of the transaction. `lock_for_update` selects the
    | SQL strategy; `SELECT ... FOR UPDATE` is the safe default on MySQL
    | InnoDB.
    |
    | Do not change this to a cache lock or advisory lock without an
    | approved Change Request — the persisted state must be protected at
    | the row level.
    |
    */
    'concurrency' => [
        'lock_for_update' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit
    |--------------------------------------------------------------------------
    |
    | Domain events are written to the platform `audit_logs` table via the
    | Core AuditService. `emit_events` controls whether the domain dispatches
    | its business events at all; when false, the AuditShareholderEvent
    | listener receives nothing and no audit row is written for the domain.
    |
    | Keep true in every environment except a controlled migration window.
    |
    */
    'audit' => [
        'emit_events' => (bool) env('MASES_SHAREHOLDER_AUDIT_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Route Prefix & Middleware
    |--------------------------------------------------------------------------
    |
    | The base path under which every Shareholder route is registered by the
    | domain's Routes/api.php (2A.36). The default `api/shareholders`
    | produces URLs such as:
    |
    |     GET  /api/shareholders
    |     POST /api/shareholders
    |     GET  /api/shareholders/{id}
    |     GET  /api/shareholders/me
    |     GET  /api/shareholders/holdings
    |
    | The `api/` segment is part of the prefix — it is not added by the
    | middleware. The `api` entry in the middleware array below is a
    | middleware alias (the framework's API middleware group), not a URL
    | prefix. Every other domain in the platform follows the same
    | convention: Core at `api/files`, Wallet at `api/wallets`, Loan at
    | `api/loans`. This domain was the only one whose default omitted the
    | `api/` segment until the P2.3 follow-up.
    |
    | The middleware list is applied to the whole route group before the
    | provider registers it. Middleware aliases are the ones registered in
    | bootstrap/app.php — do not introduce new aliases here.
    |
    | The value is env-overridable so that a deployment can mount the
    | domain's routes at a different path without editing this file.
    |
    */
    'routes' => [
        'prefix'     => env('MASES_SHAREHOLDER_ROUTE_PREFIX', 'api/shareholders'),
        'middleware' => [
            'api',
            'auth:sanctum',
            'tenant.membership',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Features
    |--------------------------------------------------------------------------
    |
    | Fine-grained toggles for pieces of the domain that are implemented but
    | not yet enabled by default. Each flag corresponds to an approved
    | decision; flipping a flag off disables the corresponding capability
    | without a schema change.
    |
    | `kyc_enabled` is present and hardcoded to false. It MUST remain false
    | until the D12 field matrix is approved and a separate KYC manifest is
    | frozen. No KYC model, migration, route or service is defined by this
    | domain while the flag is false.
    |
    */
    'features' => [
        'certificates_enabled'  => (bool) env('MASES_SHAREHOLDER_CERTIFICATES', true),
        'treasury_enabled'      => (bool) env('MASES_SHAREHOLDER_TREASURY_ENABLED', true),
        'public_disclosure'     => (bool) env('MASES_SHAREHOLDER_PUBLIC_DISCLOSURE', true),
        'kyc_enabled'           => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Reference Data
    |--------------------------------------------------------------------------
    |
    | Seeding reference for the founding corporate structure. These values are
    | read by the Shareholder domain seeders ONLY when bootstrapping the
    | Founders' share class. They are not used at runtime by any service.
    |
    | These values reflect the corporate evidence recorded at the time of
    | Phase 2A freeze. A change to any of them requires a Change Request
    | because it may affect historical reporting.
    |
    */
    'reference' => [
        'founders' => [
            'share_count'             => 145,
            'par_value_per_share'     => '10000',
            'total_stated_value'      => '1450000',
            'currency'                => 'ETB',
        ],
    ],

];