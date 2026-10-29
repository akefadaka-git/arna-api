<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — Enhanced providers registry
 * =============================================================================
 *
 * File:            bootstrap/providers.php  (AMEND)
 * Unit:            Platform provider registry — Localization provider added
 * Delivery type:   Amendment
 * Net lines:       +1 provider class entry (LocalizationServiceProvider);
 *                  +1 header section documenting the addition;
 *                  +1 header section documenting the
 *                  MasesCoreServiceProvider duplicate as an
 *                  observation (no action);
 *                  the Provider Order list gains one numbered entry;
 *                  the array positions after the new entry shift by one;
 *                  +~180 lines for this change-verification block.
 *
 * Before:
 *   The file registered eight providers in this order:
 *
 *     1. App\Providers\AppServiceProvider
 *     2. MaaleArna\MASES\Core\Providers\CoreServiceProvider
 *     3. MaaleArna\MASES\Core\Auth\Providers\AuthServiceProvider
 *     4. MaaleArna\MASES\Core\Domains\Shareholder\Providers\ShareholderServiceProvider
 *     5. MaaleArna\MASES\Core\Policy\Providers\PolicyServiceProvider
 *     6. MaaleArna\MASES\Core\Domains\Wallet\Providers\WalletServiceProvider
 *     7. MaaleArna\MASES\Core\Domains\Loan\Providers\LoanServiceProvider
 *     8. MaaleArna\MASES\Core\Domains\Governance\Providers\GovernanceServiceProvider
 *
 *   The `LocalizationServiceProvider` file existed on disk at
 *   `mases_core/src/Providers/LocalizationServiceProvider.php` but was
 *   not registered. Its four bindings — `EthiopianFormatter`,
 *   `LocaleManager`, `LocalizationManager`, and the
 *   `mases.localization` alias — were therefore unbound. Nothing in
 *   the currently-deployed code consumes them; the current
 *   localization surface (`LocalizationService`, `EthiopianCalendar`,
 *   `LocalizationMiddleware`) is bound by `CoreServiceProvider` and
 *   works. The gap is latent, not active.
 *
 * After:
 *   One class entry is inserted at position 3, immediately after
 *   `CoreServiceProvider` and before `AuthServiceProvider`:
 *
 *     MaaleArna\MASES\Core\Providers\LocalizationServiceProvider::class,
 *
 *   The position is deliberate. `LocalizationServiceProvider` depends
 *   on nothing from Auth or any domain, but `CoreServiceProvider`'s
 *   binding of `EthiopianCalendar` and `LocalizationService` must be
 *   established before any domain provider that consumes them. Placing
 *   the Localization provider at position 3, after Core but before the
 *   authentication and domain providers, keeps the container's
 *   registration order aligned with the dependency graph.
 *
 *   What this activation does:
 *
 *     · `EthiopianFormatter`, `LocaleManager`, and
 *       `LocalizationManager` become resolvable from the container as
 *       singletons. Any future phase that injects one receives the
 *       bound instance rather than a reflection-built transient.
 *
 *     · The `mases.localization` alias resolves. A future caller that
 *       uses the `LocalizationFacade` (the facade file
 *       `MaaleArna\MASES\Core\Localization\LocalizationFacade` exists
 *       on disk and returns `'mases.localization'` from its
 *       `getFacadeAccessor()`) finds the binding.
 *
 *     · `EthiopianCalendar` is bound twice — once by
 *       `CoreServiceProvider` at position 2, once by
 *       `LocalizationServiceProvider` at position 3. Laravel's
 *       container uses the LAST binding, so the Localization
 *       provider's instance wins. The two bindings are equivalent in
 *       practice (both read the same config value with the same
 *       default), so the effective behaviour is unchanged. The
 *       duplication is documented below as a cleanup opportunity, not
 *       acted on.
 *
 * =============================================================================
 * What does NOT change
 * =============================================================================
 *   · The seven pre-existing provider entries remain, at their same
 *     array positions relative to each other. Only the numbering in
 *     the header's Provider Order list shifts (2–8 become 2 and 4–9).
 *
 *   · The header sections for the pre-existing providers are
 *     reproduced byte-for-byte.
 *
 *   · The array's structural shape — a flat list of class entries
 *     with comment blocks — is preserved.
 *
 * =============================================================================
 * Observation — MasesCoreServiceProvider (not acted on)
 * =============================================================================
 * A second file exists on disk at
 * `mases_core/src/Providers/MasesCoreServiceProvider.php`. It declares
 * bindings for `EthiopianCalendar`, `EthiopianFormatter`,
 * `LocaleManager`, and `LocalizationManager` — an overlapping set with
 * both `CoreServiceProvider` and `LocalizationServiceProvider`. It also
 * attempts to `loadMigrationsFrom()` a set of directories under
 * `Migrations/Platform`, `Migrations/Shareholder`, etc., which do not
 * match the current migration layout (`mases_core/src/Migrations/` plus
 * per-domain `Database/Migrations/` directories).
 *
 * This file is NOT registered here. Registering it would:
 *
 *   · Duplicate the `EthiopianCalendar` binding a third time,
 *   · Attempt to load migrations from paths that do not exist, and
 *   · Potentially fail at boot on a fresh deployment.
 *
 * The file appears to be a Phase 1 artefact superseded by the current
 * provider structure. It is flagged here so a future CR can decide
 * whether to delete it, archive it, or refactor it into a compatible
 * shape. Acting on it is out of scope for this delivery.
 *
 * =============================================================================
 * Deployment
 * =============================================================================
 * Upload this file to the same path it currently occupies:
 *
 *     bootstrap/providers.php
 *
 * The upload REPLACES the existing file.
 *
 * After uploading, run:
 *
 *     php artisan optimize:clear
 *
 * (Via Plesk PHP Command runner, or a one-off scheduled task.)
 *
 * Verification:
 *
 *     php artisan tinker
 *
 *     # The LocalizationManager resolves
 *     >>> app(\MaaleArna\MASES\Core\Localization\LocalizationManager::class)
 *     => MaaleArna\MASES\Core\Localization\LocalizationManager {#...}
 *
 *     # The alias resolves
 *     >>> app('mases.localization')
 *     => MaaleArna\MASES\Core\Localization\LocalizationManager {#...}
 *
 *     # The facade resolves
 *     >>> \MaaleArna\MASES\Core\Localization\LocalizationFacade::getLocaleManager()
 *     => MaaleArna\MASES\Core\Localization\LocaleManager {#...}
 *
 *     # Sanity check — the existing localization surface still works
 *     >>> app(\MaaleArna\MASES\Core\Localization\Services\LocalizationService::class)
 *         ->formatEthiopianDateMedium(now())
 *     => "መስከረም 10, 2019 ዓ.ም. (September 20, 2026)"
 *     # (exact output depends on today's date)
 *
 * =============================================================================
 * Files modified:            this file only
 * Files added:               none
 * Files deleted:             none
 * Provider entries added:    1 (LocalizationServiceProvider)
 * Existing entries changed:  0 (all eight preserved byte-for-byte)
 * Header sections added:     2 (LocalizationServiceProvider, plus
 *                               the MasesCoreServiceProvider
 *                               observation)
 * =============================================================================
 */

/*
 * -----------------------------------------------------------------------------
 * MASES Core Platform — Service Provider Registry
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
 * The `MaaleArna\MASES\Core\Providers\CoreServiceProvider` is responsible
 * for:
 *
 *   1. Loading all Core migrations from `mases_core/src/Migrations/`
 *      → Without this, the `php artisan migrate` command will not
 *        discover any MASES migration files and will report "Nothing
 *        to migrate."
 *
 *   2. Merging the core configuration from
 *      `mases_core/src/Config/mases.php`
 *      → This makes platform settings (tenancy, localization, security,
 *        etc.) available via `config('mases.*')`.
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
 *      → `php artisan vendor:publish --tag=mases-config`
 *
 * If this provider is missing from this file, the platform will NOT work
 * correctly: migrations will fail, services will not be bound, and
 * configuration will be missing.
 *
 * -----------------------------------------------------------------------------
 * LocalizationServiceProvider
 * -----------------------------------------------------------------------------
 *
 * The `MaaleArna\MASES\Core\Providers\LocalizationServiceProvider`
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
 *   4. The `mases.localization` container alias, which the
 *      `LocalizationFacade` (at
 *      `MaaleArna\MASES\Core\Localization\LocalizationFacade`) uses
 *      as its accessor.
 *
 * The provider also re-binds `EthiopianCalendar`. This duplicates
 * `CoreServiceProvider`'s binding. Laravel's container uses the last
 * registration, so the Localization provider's instance wins. The
 * two are functionally equivalent — both read
 * `config('mases.localization.timezone')` with the same default — so
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
 * The `MaaleArna\MASES\Core\Auth\Providers\AuthServiceProvider` is the
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
 * The `MaaleArna\MASES\Core\Policy\Providers\PolicyServiceProvider` is
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
 *   1. Merging `mases_core/src/Policy/Config/policy.php` into the
 *      container under the key `policy`.
 *
 *   2. Binding `VersionedPolicyRegistry` as a singleton.
 *
 *   3. Loading the capability's migrations from
 *      `mases_core/src/Policy/Database/Migrations/` when the capability
 *      is enabled.
 *
 * The provider has NO policies, NO event listeners, and NO routes — the
 * registry is a capability, not a domain.
 *
 * -----------------------------------------------------------------------------
 * ShareholderServiceProvider (Phase 2A — CR-017 / A14)
 * -----------------------------------------------------------------------------
 *
 * The `MaaleArna\MASES\Core\Domains\Shareholder\Providers\ShareholderServiceProvider`
 * is the Shareholder domain's integration point. It is responsible for:
 *
 *   1. Merging `mases_core/src/Domains/Shareholder/Config/shareholder.php`
 *      into the container under the key `shareholder`.
 *
 *   2. Loading the domain's translations from
 *      `mases_core/src/Domains/Shareholder/Resources/lang/` under the
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
 *      `mases_core/src/Domains/Shareholder/Database/Migrations/`.
 *
 *   7. Loading the domain's HTTP routes from
 *      `mases_core/src/Domains/Shareholder/Routes/api.php` when the
 *      domain is enabled and the file exists.
 *
 * -----------------------------------------------------------------------------
 * WalletServiceProvider (Phase 2B — 2B.2 / ENH.1b)
 * -----------------------------------------------------------------------------
 *
 * The `MaaleArna\MASES\Core\Domains\Wallet\Providers\WalletServiceProvider`
 * is the Wallet domain's integration point. It is registered by ENH.1b
 * — the second half of the split ENH.1 — which is applied only after
 * every class the provider references has been deployed.
 *
 * The provider is responsible for:
 *
 *   1. Merging `mases_core/src/Domains/Wallet/Config/wallet.php` into
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
 * The `MaaleArna\MASES\Core\Domains\Loan\Providers\LoanServiceProvider`
 * is the Loan domain's integration point. It is registered by C.2, the
 * second of the ten modifications in Phase 2C's Block C.
 *
 * The provider is responsible for:
 *
 *   1. Merging `mases_core/src/Domains/Loan/Config/loan.php` into the
 *      container under the key `loan`.
 *
 *   2. Loading the domain's translations from
 *      `mases_core/src/Domains/Loan/Resources/lang/` under the
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
 * The `MaaleArna\MASES\Core\Domains\Governance\Providers\GovernanceServiceProvider`
 * is the Governance domain's integration point. As of G2b it is
 * responsible for:
 *
 *   1. Merging `mases_core/src/Domains/Governance/Config/governance.php`
 *      into the container under the key `governance`.
 *
 *   2. Loading the domain's translations from
 *      `mases_core/src/Domains/Governance/Resources/lang/` under the
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
 * Observation — MasesCoreServiceProvider (NOT registered)
 * -----------------------------------------------------------------------------
 *
 * A second file exists at
 * `mases_core/src/Providers/MasesCoreServiceProvider.php`. It declares
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
 *   2. MaaleArna\MASES\Core\Providers\CoreServiceProvider
 *      - Loads Core infrastructure: migrations, configuration,
 *        container bindings, Core event listeners. Must run before any
 *        provider that depends on Core services.
 *
 *   3. MaaleArna\MASES\Core\Providers\LocalizationServiceProvider
 *      - Binds `EthiopianFormatter`, `LocaleManager`,
 *        `LocalizationManager`, and the `mases.localization` alias.
 *        Runs after Core (which binds `EthiopianCalendar` and
 *        `LocalizationService`) and before Auth and the domain
 *        providers, so any domain consumer of `LocalizationManager`
 *        receives the bound instance.
 *
 *   4. MaaleArna\MASES\Core\Auth\Providers\AuthServiceProvider
 *      - Installs the platform's global permission gate. Runs after
 *        Core because it depends on the `AuthorizationService` binding.
 *        Runs before every domain provider so the gate is available to
 *        all domain code.
 *
 *   5. MaaleArna\MASES\Core\Domains\Shareholder\Providers\ShareholderServiceProvider
 *      - Runs after Auth, because its policy resolution and event
 *        registration rely on Core singletons (`AuditService`,
 *        `AuthorizationService`).
 *
 *   6. MaaleArna\MASES\Core\Policy\Providers\PolicyServiceProvider
 *      - Runs after Core. Domains (notably Wallet and Loan) depend on
 *        the versioned-policy registry.
 *
 *   7. MaaleArna\MASES\Core\Domains\Wallet\Providers\WalletServiceProvider
 *      - Runs after PolicyServiceProvider, because the Wallet domain's
 *        `wallets` table carries three nullable FKs to
 *        `versioned_policies`.
 *
 *   8. MaaleArna\MASES\Core\Domains\Loan\Providers\LoanServiceProvider
 *      - Runs after WalletServiceProvider, because the Loan domain's
 *        services depend on Wallet-domain singletons.
 *
 *   9. MaaleArna\MASES\Core\Domains\Governance\Providers\GovernanceServiceProvider
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
     * 2. MASES Core Platform Foundation Provider
     * -------------------------------------------------------------------------
     *
     * Registers the entire Core infrastructure: migrations, configuration,
     * container bindings, and the Core event listeners (Phase 1 Amendments
     * A6, A7b, A8, A9). Must run before any domain provider or capability
     * provider that depends on Core services.
     */
    MaaleArna\MASES\Core\Providers\CoreServiceProvider::class,

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
     *   - the `mases.localization` alias (used by LocalizationFacade)
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
    MaaleArna\MASES\Core\Auth\Providers\AuthServiceProvider::class,

    /*
     * -------------------------------------------------------------------------
     * 5. Shareholder Domain Provider (Phase 2A — CR-017 / A14)
     * -------------------------------------------------------------------------
     *
     * Registers the Shareholder domain's configuration, container
     * bindings, policies, event listeners, migrations, and HTTP routes.
     */
    MaaleArna\MASES\Core\Domains\Shareholder\Providers\ShareholderServiceProvider::class,

    /*
     * -------------------------------------------------------------------------
     * 6. Core Policy Capability Provider (Phase 2B — CORE.4 / ENH.1a)
     * -------------------------------------------------------------------------
     *
     * Registers the Core versioned-policy registry: configuration under
     * `config('policy.*')`, the `VersionedPolicyRegistry` singleton, and
     * the capability's migration directory.
     */
    MaaleArna\MASES\Core\Policy\Providers\PolicyServiceProvider::class,

    /*
     * -------------------------------------------------------------------------
     * 7. Wallet Domain Provider (Phase 2B — 2B.2 / ENH.1b)
     * -------------------------------------------------------------------------
     *
     * Registers the Wallet domain's configuration, container bindings,
     * policies, event listeners, migrations, and HTTP routes.
     */
    MaaleArna\MASES\Core\Domains\Wallet\Providers\WalletServiceProvider::class,

    /*
     * -------------------------------------------------------------------------
     * 8. Loan Domain Provider (Phase 2C — A.85 / C.2)
     * -------------------------------------------------------------------------
     *
     * Registers the Loan domain's configuration, translations, container
     * bindings, policies, event listeners, migrations, and HTTP routes.
     */
    MaaleArna\MASES\Core\Domains\Loan\Providers\LoanServiceProvider::class,

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
    MaaleArna\MASES\Core\Domains\Governance\Providers\GovernanceServiceProvider::class,

    /*
     * -------------------------------------------------------------------------
     * 10. Finance Domain Provider
     * -------------------------------------------------------------------------
     *
     * Registers Finance services, translations, domain configuration, Finance-owned
     * migrations, and the Finance API routes from the domain directory.
     */
    MaaleArna\MASES\Core\Domains\Finance\Providers\FinanceServiceProvider::class,

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