<?php

declare(strict_types=1);

/*
 * Application provider registry.
 * LocalizationServiceProvider follows CoreServiceProvider and precedes
 * authentication/domain providers. Do not register the legacy
 * ArnaCoreServiceProvider: it duplicates bindings and references stale migration paths.
 */

/*
 * -----------------------------------------------------------------------------
 * ARNA Core Platform — Service Provider Registry
 * -----------------------------------------------------------------------------
 *
 * This file is the central registry for all service providers that
 * should be loaded during the Laravel application bootstrap. It replaces
 * the legacy `config/app.php` providers array in Laravel 11+ and is the
 * single source of truth for provider registration.
 *
 * Providers are instantiated and their `register()` and `boot()` methods
 * are called in the order they appear in this array.
 *
 * -----------------------------------------------------------------------------
 * CRITICAL: CoreServiceProvider must be registered here
 * -----------------------------------------------------------------------------
 *
 * The `MaaleArna\Arna\Core\Providers\CoreServiceProvider` is responsible
 * for:
 *
 *   1. Loading all Core migrations from `arna_core/src/Migrations/`
 *      → Without this, the `php artisan migrate` command will not
 *        discover any ARNA migration files and will report "Nothing
 *        to migrate."
 *
 *   2. Merging the core configuration from
 *      `arna_core/src/Config/arna.php`
 *      → This makes platform settings (tenancy, localization, security,
 *        etc.) available via `config('arna.*')`.
 *
 *   3. Registering core container bindings (singletons and interfaces)
 *      → TenantContextResolverInterface → RequestTenantResolver
 *      → TenantContextService (singleton)
 *      → AuthorizationService (singleton)
 *      → AuditService (singleton)
 *      → FileService (singleton)
 *      → SecurityEventService (singleton)
 *      → EthiopianCalendar (singleton)
 *      → LocalizationService (singleton)
 *
 *   4. Publishing the core configuration for customisation
 *      → `php artisan vendor:publish --tag=arna-config`
 *
 * If this provider is missing from this file, the platform will NOT work
 * correctly: migrations will fail, services will not be bound, and
 * configuration will be missing.
 *
 * -----------------------------------------------------------------------------
 * LocalizationServiceProvider
 * -----------------------------------------------------------------------------
 *
 * The `MaaleArna\Arna\Core\Providers\LocalizationServiceProvider`
 * registers the localization capability's additional services that
 * CoreServiceProvider does not bind:
 *
 *   1. `EthiopianFormatter` — the formatter that produces localized
 *      date strings from EthiopianCalendar output.
 *
 *   2. `LocaleManager` — the per-request locale resolution and
 *      persistence service.
 *
 *   3. `LocalizationManager` — the façade-facing aggregator that
 *      composes EthiopianCalendar, EthiopianFormatter, and
 *      LocaleManager into a single injectable service.
 *
 *   4. The `arna.localization` container alias, which the
 *      `LocalizationFacade` (at
 *      `MaaleArna\Arna\Core\Localization\LocalizationFacade`) uses
 *      as its accessor.
 *
 * The provider also re-binds `EthiopianCalendar`. This duplicates
 * `CoreServiceProvider`'s binding. Laravel's container uses the last
 * registration, so the Localization provider's instance wins. The
 * two are functionally equivalent — both read
 * `config('arna.localization.timezone')` with the same default — so
 * the duplicate is inert. A future CR may consolidate the two
 * registrations into one; that is not this delivery's scope.
 *
 * The provider runs after `CoreServiceProvider` (which establishes
 * the base localization bindings) and before every other provider, so
 * a domain that injects `LocalizationManager` receives the bound
 * instance.
 *
 * -----------------------------------------------------------------------------
 * AuthServiceProvider (Phase 1 continuation — global permission gate)
 * -----------------------------------------------------------------------------
 *
 * The `MaaleArna\Arna\Core\Auth\Providers\AuthServiceProvider` is the
 * integration point for the platform's GLOBAL authorization gate. It
 * registers a `Gate::before` callback that resolves RAW ability strings
 * — abilities dispatched without a model argument, such as
 * `Gate::authorize('loan.apply')` — through the Core
 * `AuthorizationService::hasPermission()`.
 *
 * Without this provider:
 *
 *   · Model-aware abilities (`Gate::authorize('view', $loan)`) continue
 *     to work, because they resolve through each domain's registered
 *     policies.
 *
 *   · Raw abilities (`Gate::authorize('loan.apply')`) resolve to
 *     Laravel's default deny, producing an `AUTH_PERMISSION_DENIED`
 *     envelope even for callers who hold the permission in the
 *     database.
 *
 * The provider runs AFTER `CoreServiceProvider` (because it depends on
 * the `AuthorizationService` binding, which Core owns) and BEFORE every
 * domain provider (so the gate is installed before any domain code is
 * booted).
 *
 * -----------------------------------------------------------------------------
 * PolicyServiceProvider (Phase 2B — CORE.4 / ENH.1a)
 * -----------------------------------------------------------------------------
 *
 * The `MaaleArna\Arna\Core\Policy\Providers\PolicyServiceProvider` is
 * the integration point for the Core versioned-policy registry — the
 * capability introduced in Phase 2B to satisfy the three architectural
 * mandates that converged on a common primitive:
 *
 *   - A7   KYC/AML capability must be architected centrally and versioned.
 *   - A8   Savings interest must be configured through a versioned policy
 *          architecture, with the rule itself deferred.
 *   - D110 Payment-allocation rules must be policy-driven and versioned,
 *          with the priority order itself deferred.
 *
 * The provider is responsible for:
 *
 *   1. Merging `arna_core/src/Policy/Config/policy.php` into the
 *      container under the key `policy`.
 *
 *   2. Binding `VersionedPolicyRegistry` as a singleton.
 *
 *   3. Loading the capability's migrations from
 *      `arna_core/src/Policy/Database/Migrations/` when the capability
 *      is enabled.
 *
 * The provider has NO policies, NO event listeners, and NO routes — the
 * registry is a capability, not a domain.
 *
 * -----------------------------------------------------------------------------
 * ShareholderServiceProvider (Phase 2A — CR-017 / A14)
 * -----------------------------------------------------------------------------
 *
 * The `MaaleArna\Arna\Core\Domains\Shareholder\Providers\ShareholderServiceProvider`
 * is the Shareholder domain's integration point. It is responsible for:
 *
 *   1. Merging `arna_core/src/Domains/Shareholder/Config/shareholder.php`
 *      into the container under the key `shareholder`.
 *
 *   2. Loading the domain's translations from
 *      `arna_core/src/Domains/Shareholder/Resources/lang/` under the
 *      namespace `shareholder`.
 *
 *   3. Binding the domain's five services and one repository as
 *      singletons.
 *
 *   4. Registering the domain's four authorization policies
 *      (`ShareholderPolicy`, `ShareHoldingPolicy`, `CertificatePolicy`,
 *      `ShareClassPolicy`) against the respective models.
 *
 *   5. Registering the domain's four business events
 *      (`ShareholderRegistered`, `ShareholderUpdated`,
 *      `ShareHoldingChanged`, `CertificateIssued`) against the
 *      `AuditShareholderEvent` listener.
 *
 *   6. Loading the domain's migrations from
 *      `arna_core/src/Domains/Shareholder/Database/Migrations/`.
 *
 *   7. Loading the domain's HTTP routes from
 *      `arna_core/src/Domains/Shareholder/Routes/api.php` when the
 *      domain is enabled and the file exists.
 *
 * -----------------------------------------------------------------------------
 * WalletServiceProvider (Phase 2B — 2B.2 / ENH.1b)
 * -----------------------------------------------------------------------------
 *
 * The `MaaleArna\Arna\Core\Domains\Wallet\Providers\WalletServiceProvider`
 * is the Wallet domain's integration point. It is registered by ENH.1b
 * — the second half of the split ENH.1 — which is applied only after
 * every class the provider references has been deployed.
 *
 * The provider is responsible for:
 *
 *   1. Merging `arna_core/src/Domains/Wallet/Config/wallet.php` into
 *      the container under the key `wallet`.
 *
 *   2. Binding the domain's four repositories and five services as
 *      singletons.
 *
 *   3. Registering the domain's three authorization policies
 *      (`WalletPolicy`, `ObligationPolicy`, `PaymentPolicy`) against
 *      the respective models.
 *
 *   4. Registering the domain's seven business events against the
 *      `AuditWalletEvent` listener.
 *
 *   5. Loading the domain's migrations and HTTP routes when the domain
 *      is enabled.
 *
 * The Wallet provider runs AFTER `PolicyServiceProvider` because the
 * Wallet domain's `wallets` table carries three nullable FKs to
 * `versioned_policies`.
 *
 * -----------------------------------------------------------------------------
 * LoanServiceProvider (Phase 2C — A.85 / C.2)
 * -----------------------------------------------------------------------------
 *
 * The `MaaleArna\Arna\Core\Domains\Loan\Providers\LoanServiceProvider`
 * is the Loan domain's integration point. It is registered by C.2, the
 * second of the ten modifications in Phase 2C's Block C.
 *
 * The provider is responsible for:
 *
 *   1. Merging `arna_core/src/Domains/Loan/Config/loan.php` into the
 *      container under the key `loan`.
 *
 *   2. Loading the domain's translations from
 *      `arna_core/src/Domains/Loan/Resources/lang/` under the
 *      namespace `loan`.
 *
 *   3. Binding the domain's five repositories and twelve services as
 *      singletons.
 *
 *   4. Registering the domain's three authorization policies
 *      (`LoanPolicy`, `LoanProductPolicy`, `LoanCollateralPolicy`)
 *      against the respective models.
 *
 *   5. Registering the domain's thirteen business events against the
 *      `AuditLoanEvent` listener.
 *
 *   6. Loading the domain's migrations and HTTP routes when the domain
 *      is enabled.
 *
 * The Loan provider runs AFTER `WalletServiceProvider` because the
 * Loan domain's services depend on Wallet-domain singletons.
 *
 * -----------------------------------------------------------------------------
 * GovernanceServiceProvider (Phase 2E — G1 + G2a + G2b)
 * -----------------------------------------------------------------------------
 *
 * The `MaaleArna\Arna\Core\Domains\Governance\Providers\GovernanceServiceProvider`
 * is the Governance domain's integration point. As of G2b it is
 * responsible for:
 *
 *   1. Merging `arna_core/src/Domains/Governance/Config/governance.php`
 *      into the container under the key `governance`.
 *
 *   2. Loading the domain's translations from
 *      `arna_core/src/Domains/Governance/Resources/lang/` under the
 *      namespace `governance`.
 *
 *   3. Binding six repositories and services as singletons:
 *        - GovernanceBodyRepository, GovernanceBodyService
 *        - GovernancePositionRepository, GovernancePositionService
 *        - GovernanceTermRepository, GovernanceTermService
 *
 *   4. Registering three authorization policies:
 *        - GovernanceBody     → GovernanceBodyPolicy
 *        - GovernancePosition → GovernancePositionPolicy
 *        - GovernanceTerm     → GovernanceTermPolicy
 *
 *   5. Registering six business events against the
 *      `AuditGovernanceEvent` listener:
 *        - GovernanceBodyRegistered, GovernanceBodyUpdated
 *        - GovernancePositionRegistered, GovernancePositionUpdated
 *        - GovernanceTermRegistered, GovernanceTermUpdated
 *
 *   6. Loading the domain's migrations and HTTP routes when the domain
 *      is enabled.
 *
 * Governance has no dependency on Wallet or Loan. The placement at
 * position 9 (after Localization is inserted) reflects the array's
 * chronological registration order.
 *
 * -----------------------------------------------------------------------------
 * Observation — ArnaCoreServiceProvider (NOT registered)
 * -----------------------------------------------------------------------------
 *
 * A second file exists at
 * `arna_core/src/Providers/ArnaCoreServiceProvider.php`. It declares
 * an overlapping set of bindings (EthiopianCalendar, EthiopianFormatter,
 * LocaleManager, LocalizationManager), attempts to load migrations from
 * paths that do not exist in the current layout
 * (`Migrations/Platform`, `Migrations/Shareholder`, etc.), and
 * publishes resources that overlap with CoreServiceProvider.
 *
 * The file is NOT registered in this array. Registering it would
 * duplicate bindings, reference non-existent directories, and
 * potentially fail at boot. It appears to be a Phase 1 artefact
 * superseded by the current provider structure.
 *
 * A future CR should decide whether to delete it, archive it, or
 * refactor it into a compatible shape. It is documented here as an
 * observation only; no action is taken.
 *
 * -----------------------------------------------------------------------------
 * Provider Order
 * -----------------------------------------------------------------------------
 *
 * The order in which providers are listed is important:
 *
 *   1. App\Providers\AppServiceProvider
 *      - Standard Laravel entry point. Runs first.
 *
 *   2. MaaleArna\Arna\Core\Providers\CoreServiceProvider
 *      - Loads Core infrastructure: migrations, configuration,
 *        container bindings, Core event listeners. Must run before any
 *        provider that depends on Core services.
 *
 *   3. MaaleArna\Arna\Core\Providers\LocalizationServiceProvider
 *      - Binds `EthiopianFormatter`, `LocaleManager`,
 *        `LocalizationManager`, and the `arna.localization` alias.
 *        Runs after Core (which binds `EthiopianCalendar` and
 *        `LocalizationService`) and before Auth and the domain
 *        providers, so any domain consumer of `LocalizationManager`
 *        receives the bound instance.
 *
 *   4. MaaleArna\Arna\Core\Auth\Providers\AuthServiceProvider
 *      - Installs the platform's global permission gate. Runs after
 *        Core because it depends on the `AuthorizationService` binding.
 *        Runs before every domain provider so the gate is available to
 *        all domain code.
 *
 *   5. MaaleArna\Arna\Core\Domains\Shareholder\Providers\ShareholderServiceProvider
 *      - Runs after Auth, because its policy resolution and event
 *        registration rely on Core singletons (`AuditService`,
 *        `AuthorizationService`).
 *
 *   6. MaaleArna\Arna\Core\Policy\Providers\PolicyServiceProvider
 *      - Runs after Core. Domains (notably Wallet and Loan) depend on
 *        the versioned-policy registry.
 *
 *   7. MaaleArna\Arna\Core\Domains\Wallet\Providers\WalletServiceProvider
 *      - Runs after PolicyServiceProvider, because the Wallet domain's
 *        `wallets` table carries three nullable FKs to
 *        `versioned_policies`.
 *
 *   8. MaaleArna\Arna\Core\Domains\Loan\Providers\LoanServiceProvider
 *      - Runs after WalletServiceProvider, because the Loan domain's
 *        services depend on Wallet-domain singletons.
 *
 *   9. MaaleArna\Arna\Core\Domains\Governance\Providers\GovernanceServiceProvider
 *      - Runs after LoanServiceProvider. Governance has no dependency
 *        on Wallet or Loan; the placement reflects the chronological
 *        registration order (Core → Localization → Auth → Shareholder
 *        → Policy → Wallet → Loan → Governance).
 *
 *   11. (FUTURE) Phase 2D and 2F domain providers
 *      - Transfers/Dividends/Meetings/Voting/Elections (Phase 2D) and
 *        Communications (Phase 2F). Each added under a small CR as its
 *        first test file lands.
 *
 * -----------------------------------------------------------------------------
 * After Changing This File
 * -----------------------------------------------------------------------------
 *
 * If you modify this file, you must clear the service provider cache:
 *
 *     php artisan optimize:clear
 *
 * Or, if `config:cache` is enabled, re-cache:
 *
 *     php artisan config:cache
 *
 * Otherwise, Laravel continues using the cached provider list and your
 * changes will not take effect.
 * -----------------------------------------------------------------------------
 */

return [
    /*
     * -------------------------------------------------------------------------
     * 1. Application Service Provider
     * -------------------------------------------------------------------------
     *
     * Standard Laravel entry point. This provider is generated by default
     * and can be used for any application-specific registrations that are
     * not part of the reusable Core platform.
     */
    App\Providers\AppServiceProvider::class,

    /*
     * -------------------------------------------------------------------------
     * 2. ARNA Core Platform Foundation Provider
     * -------------------------------------------------------------------------
     *
     * Registers the entire Core infrastructure: migrations, configuration,
     * container bindings, and the Core event listeners (Phase 1 Amendments
     * A6, A7b, A8, A9). Must run before any domain provider or capability
     * provider that depends on Core services.
     */
    MaaleArna\Arna\Core\Providers\CoreServiceProvider::class,

    /*
     * -------------------------------------------------------------------------
     * 3. Localization Service Provider
     * -------------------------------------------------------------------------
     *
     * Binds the localization capability's additional services that
     * CoreServiceProvider does not bind:
     *
     *   - `EthiopianFormatter`
     *   - `LocaleManager`
     *   - `LocalizationManager`
     *   - the `arna.localization` alias (used by LocalizationFacade)
     *
     * Also re-binds `EthiopianCalendar`; the duplicate binding is
     * functionally equivalent to CoreServiceProvider's and the last
     * registration wins. See the file header §"LocalizationServiceProvider"
     * for the rationale and the deferred consolidation.
     */

    /*
     * -------------------------------------------------------------------------
     * 4. Auth Service Provider (global permission gate)
     * -------------------------------------------------------------------------
     *
     * Installs the platform's global permission gate — a `Gate::before`
     * callback that resolves RAW ability strings (abilities dispatched
     * without a model argument, such as `Gate::authorize('loan.apply')`)
     * through the Core `AuthorizationService::hasPermission()`.
     *
     * Runs after CoreServiceProvider (which binds `AuthorizationService`)
     * and before every domain provider.
     */
    MaaleArna\Arna\Core\Auth\Providers\AuthServiceProvider::class,

    /*
     * -------------------------------------------------------------------------
     * 5. Shareholder Domain Provider (Phase 2A — CR-017 / A14)
     * -------------------------------------------------------------------------
     *
     * Registers the Shareholder domain's configuration, container
     * bindings, policies, event listeners, migrations, and HTTP routes.
     */
    MaaleArna\Arna\Core\Domains\Shareholder\Providers\ShareholderServiceProvider::class,

    /*
     * -------------------------------------------------------------------------
     * 6. Core Policy Capability Provider (Phase 2B — CORE.4 / ENH.1a)
     * -------------------------------------------------------------------------
     *
     * Registers the Core versioned-policy registry: configuration under
     * `config('policy.*')`, the `VersionedPolicyRegistry` singleton, and
     * the capability's migration directory.
     */
    MaaleArna\Arna\Core\Policy\Providers\PolicyServiceProvider::class,

    /*
     * -------------------------------------------------------------------------
     * 7. Wallet Domain Provider (Phase 2B — 2B.2 / ENH.1b)
     * -------------------------------------------------------------------------
     *
     * Registers the Wallet domain's configuration, container bindings,
     * policies, event listeners, migrations, and HTTP routes.
     */
    MaaleArna\Arna\Core\Domains\Wallet\Providers\WalletServiceProvider::class,

    /*
     * -------------------------------------------------------------------------
     * 8. Loan Domain Provider (Phase 2C — A.85 / C.2)
     * -------------------------------------------------------------------------
     *
     * Registers the Loan domain's configuration, translations, container
     * bindings, policies, event listeners, migrations, and HTTP routes.
     */
    MaaleArna\Arna\Core\Domains\Loan\Providers\LoanServiceProvider::class,

    /*
     * -------------------------------------------------------------------------
     * 9. Governance Domain Provider (Phase 2E — G1 + G2a + G2b)
     * -------------------------------------------------------------------------
     *
     * Registers the Governance domain's configuration, translations,
     * container bindings, policies, event listeners, migrations, and
     * HTTP routes.
     *
     * As of G2b, three entities are registered: GovernanceBody,
     * GovernancePosition, GovernanceTerm.
     */
    MaaleArna\Arna\Core\Domains\Governance\Providers\GovernanceServiceProvider::class,

    /*
     * -------------------------------------------------------------------------
     * 10. Finance Domain Provider
     * -------------------------------------------------------------------------
     *
     * Registers Finance services, translations, domain configuration, Finance-owned
     * migrations, and the Finance API routes from the domain directory.
     */
    MaaleArna\Arna\Core\Domains\Finance\Providers\FinanceServiceProvider::class,

    /*
     * -------------------------------------------------------------------------
     * 11. Future Domain Providers (Phase 2D and 2F)
     * -------------------------------------------------------------------------
     *
     * Phase 2D (Transfers/Dividends/Meetings/Voting/Elections) and
     * Phase 2F (Communications) follow the same pattern: one provider
     * per domain, each added under a small CR as its first test file
     * lands.
     *
     * Governance/Board was previously listed here; it was moved to
     * position 9 by G1 (Phase 2E) and extended by G2a and G2b.
     *
     * Cross-Domain Coordination (a distinct phase) and Portal development
     * consume the central API; they do not add providers to this file.
     */
];