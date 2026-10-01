<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Policy\Providers;

use Illuminate\Support\ServiceProvider;
use MaaleArna\MASES\Core\Policy\Services\VersionedPolicyRegistry;

/**
 * PolicyServiceProvider
 *
 * Service provider for the MASES Core versioned-policy registry.
 *
 * =============================================================================
 * Responsibility
 * =============================================================================
 * This provider is the single integration point between the Core versioned-
 * policy capability and the Laravel application. It is responsible for:
 *
 *   1. Merging the capability configuration (`Config/policy.php`, CORE.1)
 *      into the application container under the key `policy`.
 *
 *   2. Binding the registry service (`VersionedPolicyRegistry`, CORE.5) as
 *      a singleton, so that within a single request every consumer receives
 *      the same instance.
 *
 *   3. Loading the capability's migrations from `Database/Migrations/`
 *      (CORE.2) when the capability is enabled.
 *
 * The registry is domain-agnostic. This provider therefore:
 *
 *   - Registers NO authorization policies. The registry has no models that
 *     participate in the domain policy layer; consumer domains authorize
 *     their own access to policy-version references.
 *
 *   - Registers NO event listeners. The registry does not dispatch domain
 *     events. Policy creation is an administrative act, not a business
 *     operation with an audit-stream identity in the domain sense; if a
 *     future phase requires auditing policy creation, a CR adds an event.
 *
 *   - Loads NO routes. The registry exposes no HTTP surface. Consumers read
 *     and write policies through the registry service from their own
 *     controllers (or, more commonly, through their own service layer).
 *
 * =============================================================================
 * Deployment sequencing — read before registering
 * =============================================================================
 * This provider is NOT registered in `bootstrap/providers.php` by the
 * CORE.4 delivery alone. It is registered as a separate step (see the
 * Step 5 manifest, ENH.1) after CORE.5 (`VersionedPolicyRegistry`) has been
 * deployed, so that when the provider first boots there are no missing
 * classes.
 *
 * The provider is nonetheless written defensively so that an accidental
 * early registration does not crash the application:
 *
 *   - The singleton binding records the registry by class name. The
 *     container resolves it lazily on first use; the target class file
 *     does not need to exist at boot time.
 *
 *   - Migration loading is guarded by the enabled flag (config-driven).
 *     When the capability is disabled, no migration directory is
 *     registered with the migrator.
 *
 * =============================================================================
 * Capability enable/disable semantics
 * =============================================================================
 * `config('policy.enabled')` (declared in CORE.1) is checked at boot. When
 * false:
 *
 *   - Migrations are NOT registered — `php artisan migrate` will not touch
 *     the `versioned_policies` table.
 *
 *   - The registry binding is STILL recorded. Disabling the capability
 *     suppresses schema management, not the ability to resolve the class.
 *     Consumer domains (notably Wallet) hold nullable policy-version
 *     references; the binding remains available for consumers that choose
 *     to read from an already-migrated table under an operator-controlled
 *     scenario.
 *
 * The asymmetry is deliberate. Compare with ShareholderServiceProvider
 * (2A.2), which registers policies and events unconditionally but gates
 * migrations and routes. Policy registration and event registration are
 * ongoing runtime concerns; migration loading is a schema-management
 * concern. The Core Policy capability has no policies or events, so the
 * only asymmetry that applies is between migration loading (gated) and
 * container binding (not gated).
 *
 * =============================================================================
 * Container bindings and lifetime
 * =============================================================================
 * `VersionedPolicyRegistry` is bound as a singleton. The registry is
 * stateless — it holds no request-scoped state — so a single instance per
 * process is correct. This mirrors the pattern established by
 * `CoreServiceProvider` for `AuditService`, `AuthorizationService`,
 * `SecurityEventService`, and `FileService`, and by
 * `ShareholderServiceProvider` (2A.2) for its five services and one
 * repository.
 *
 * Do not change the binding to scoped or transient without an approved
 * Change Request. The registry is a stateless facade over the
 * `versioned_policies` table; scoping would offer no isolation benefit and
 * would allocate redundant instances per request.
 *
 * =============================================================================
 * Config merge semantics
 * =============================================================================
 * `mergeConfigFrom(self::CONFIG_PATH, self::CONFIG_KEY)` merges the values
 * from `Config/policy.php` under the key `policy`. Laravel's merge is
 * shallow: if a deployment publishes its own `config/policy.php` (a
 * CR-worthy customization), the published file's top-level keys override
 * the capability's defaults, but the capability's untouched keys remain
 * available.
 *
 * The config file is guaranteed to exist because CORE.1 was deployed and
 * verified before this provider. If the file were somehow missing,
 * `mergeConfigFrom()` would raise a file-not-found error at boot; the
 * capability would fail loudly rather than silently operating on empty
 * configuration.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Architecture v1.2.0 §8      — Versioned-policy registry integration
 *   Architecture v1.2.0 §8.7    — Registry boundary conditions
 *   Step 5 (manifest) CORE.4    — This file
 *   Step 5 (manifest) ENH.1     — bootstrap/providers.php registration
 *   Step 6 v1.1.0 §3.1          — versioned_policies table specification
 *
 * @package MaaleArna\MASES\Core\Policy\Providers
 * @version 1.0.0
 */
final class PolicyServiceProvider extends ServiceProvider
{
    /**
     * Config key under which the capability configuration is merged.
     */
    private const CONFIG_KEY = 'policy';

    /**
     * Absolute path to the capability configuration file (CORE.1).
     */
    private const CONFIG_PATH = __DIR__ . '/../Config/policy.php';

    /**
     * Absolute path to the capability migrations directory (CORE.2).
     */
    private const MIGRATIONS_PATH = __DIR__ . '/../Database/Migrations';

    // =========================================================================
    // Lifecycle
    // =========================================================================

    /**
     * Register any Policy-capability services.
     *
     * Runs before `boot()`, during the container's registration phase. It
     * merges the configuration (so that `config('policy.*')` is available
     * to every later provider) and records the container binding.
     *
     * The binding is registered unconditionally, even when the capability
     * is disabled — see the class docblock §"Capability enable/disable
     * semantics" for the rationale.
     *
     * Nothing in this method touches the filesystem except the config file,
     * which is guaranteed to exist because CORE.1 was deployed and verified.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, self::CONFIG_KEY);

        $this->registerBindings();
    }

    /**
     * Bootstrap any Policy-capability services.
     *
     * Runs after every provider has registered. At this point we can safely
     * interact with the migration repository.
     *
     * The order is simple because the capability has no policies, no event
     * listeners, and no routes:
     *
     *   1. If the capability is disabled, return early — no migrations are
     *      registered with the migrator.
     *
     *   2. Otherwise, register the migration directory.
     *
     * @return void
     */
    public function boot(): void
    {
        if (!$this->isCapabilityEnabled()) {
            return;
        }

        $this->loadCapabilityMigrations();
    }

    // =========================================================================
    // Bindings
    // =========================================================================

    /**
     * Register the capability's container binding.
     *
     * The binding uses `singleton` with the class name as the abstract. The
     * container resolves the concrete class itself via reflection; the
     * binding is therefore recorded without instantiating anything, and
     * will not fail if the class file is not yet present at boot time.
     *
     * @return void
     */
    private function registerBindings(): void
    {
        $this->app->singleton(VersionedPolicyRegistry::class);
    }

    // =========================================================================
    // Migrations
    // =========================================================================

    /**
     * Register the capability's migration directory with the migrator.
     *
     * `loadMigrationsFrom()` records the path and does not validate its
     * existence, so calling it before the migration file has been authored
     * is safe. When `php artisan migrate` runs, the migrator globs the
     * directory and processes every file it finds; an empty directory is
     * treated as "no pending migrations".
     *
     * The directory is not guarded by an `is_file()` check, in contrast to
     * the routes loading in ShareholderServiceProvider (2A.2). The reason:
     * `loadMigrationsFrom()` takes a directory, not a file; a directory
     * that does not exist yet is legal for the migrator's glob semantics.
     * See CORE.2 §"Verification" — the migration file is deployed before
     * this provider is registered.
     *
     * @return void
     */
    private function loadCapabilityMigrations(): void
    {
        $this->loadMigrationsFrom(self::MIGRATIONS_PATH);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Determine whether the capability is enabled via configuration.
     *
     * The value is read from `config('policy.enabled')`, which is declared
     * in `Config/policy.php` (CORE.1) and defaulted to `true`. The default
     * parameter in the call covers the theoretical case where the provider
     * has been registered before the config file was merged.
     *
     * @return bool
     */
    private function isCapabilityEnabled(): bool
    {
        return (bool) config('policy.enabled', true);
    }
}