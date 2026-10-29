<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Providers;

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use MaaleArna\MASES\Core\Audit\Listeners\LogFailedLogin;
use MaaleArna\MASES\Core\Audit\Listeners\LogLogout;
use MaaleArna\MASES\Core\Audit\Listeners\LogSuccessfulLogin;
use MaaleArna\MASES\Core\Audit\Services\AuditService;
use MaaleArna\MASES\Core\Auth\Events\FailedLogin;
use MaaleArna\MASES\Core\Authorization\Events\RolePermissionsChanged;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Events\AuthorizationFailureEvent;
use MaaleArna\MASES\Core\Domains\Finance\Services\JournalSourceReferenceResolverRegistry;
use MaaleArna\MASES\Core\Domains\Finance\Services\JournalSourceEntityMappingResolver;
use MaaleArna\MASES\Core\Listeners\InvalidateAuthorizationCacheListener;
use MaaleArna\MASES\Core\Listeners\LogAuthorizationFailureListener;
use MaaleArna\MASES\Core\Localization\Calendar\EthiopianCalendar;
use MaaleArna\MASES\Core\Localization\Services\LocalizationService;
use MaaleArna\MASES\Core\Platform\Contracts\TenantContextResolverInterface;
use MaaleArna\MASES\Core\Security\Services\SecurityEventService;
use MaaleArna\MASES\Core\Tenancy\Services\RequestTenantResolver;
use MaaleArna\MASES\Core\Tenancy\Services\TenantContextService;

/**
 * CoreServiceProvider
 *
 * Primary service provider for the MASES Core Platform Foundation.
 *
 * Responsibilities:
 *   1. Merge the core configuration (`mases.php`) into the application config.
 *   2. Load all Core migrations from `mases_core/src/Migrations/`.
 *   3. Bind Core contracts and services into the container.
 *   4. Register Core event listeners (authorization failures, role-permission
 *      cache invalidation, and the authentication audit trail — see below).
 *   5. Publish the Core configuration for customisation.
 *
 * This provider is registered in `bootstrap/providers.php`. The
 * `AppServiceProvider` is registered first, so it can override any Core
 * binding if necessary. Every Core capability that the platform expects
 * to be available platform-wide is wired up here.
 *
 * =============================================================================
 * Revision history
 * =============================================================================
 * 1.0.0  — initial delivery. Core container bindings, migration loading,
 *          config merge, and the Phase 1 Amendment A9 authentication
 *          audit-trail listener registrations
 *          (LogSuccessfulLogin, LogFailedLogin, LogLogout) plus
 *          LogAuthorizationFailureListener.
 *
 * 1.0.1  — D-11d. Role-permission cache invalidation wired up.
 *
 *          The `InvalidateAuthorizationCacheListener` is registered
 *          against `RolePermissionsChanged`. This is the last of four
 *          sub-CRs that close H-17:
 *
 *            D-11a — `RolePermissionsChanged` event class
 *            D-11b — `Role::syncPermissions()` dispatches the event
 *            D-11c — `InvalidateAuthorizationCacheListener` listener class
 *            D-11d — this registration (activation)
 *
 *          Effect after deployment: when any code calls
 *          `Role::syncPermissions()` and the underlying pivot `sync()`
 *          succeeds, the event fires, the listener runs synchronously,
 *          and `AuthorizationService::clearUserCache()` is called for
 *          every user who holds the mutated role. This closes the
 *          privilege-retention window that existed between the moment
 *          a role lost a permission and the moment the 300-second
 *          authorization-cache TTL expired for each affected user.
 *
 *          The registration is placed in the Authorization section of
 *          `registerEventListeners()`, adjacent to the existing
 *          `AuthorizationFailureEvent` registration. Both listeners
 *          belong to the Authorization capability and their spatial
 *          proximity in this file is deliberate.
 *
 *          No other binding, migration, config, or listener registration
 *          is changed.
 *
 * =============================================================================
 * Event listener registration (Phase 1 Amendment A9; extended by D-11d)
 * =============================================================================
 * All Core event listeners are registered explicitly in
 * `registerEventListeners()` rather than relying on Laravel's listener
 * auto-discovery. Explicit registration is used here because:
 *
 *   1. These listeners live outside `app/Listeners` (they are part of the
 *      Core package under `mases_core/src/`), where Laravel's auto-discovery
 *      does not look.
 *
 *   2. Registration order is deterministic — the dispatcher processes
 *      listeners in registration order, which matters if a future listener
 *      needs to observe the state established by an earlier one.
 *
 *   3. The full set of Core listeners is visible in one place, making it
 *      easy to audit what happens in response to each event.
 *
 * The following Core listeners are registered:
 *
 *   AuthorizationFailureEvent  →  LogAuthorizationFailureListener
 *     Persists authorization denials as `security_events` rows for
 *     intrusion detection and compliance review.
 *
 *   RolePermissionsChanged  →  InvalidateAuthorizationCacheListener
 *     Clears the `AuthorizationService` per-user cache for every user
 *     who holds a role whose permission set was just synced. Closes
 *     the privilege-retention window on role-permission revocation.
 *
 *   Illuminate\Auth\Events\Login  →  LogSuccessfulLogin
 *     Persists successful logins to `audit_logs`. The corresponding
 *     security-events row is written synchronously by
 *     AuthenticationService, not by this listener.
 *
 *   MaaleArna\MASES\Core\Auth\Events\FailedLogin  →  LogFailedLogin
 *     Persists failed logins to `audit_logs`. Laravel's own `Failed`
 *     event is not used because it is only dispatched by
 *     `Auth::attempt()`, which the platform deliberately avoids.
 *
 *   Illuminate\Auth\Events\Logout  →  LogLogout
 *     Persists logouts to `audit_logs`.
 *
 * The three authentication listeners (LogSuccessfulLogin, LogFailedLogin,
 * LogLogout) write to `audit_logs` via the shared `AuditService` singleton.
 * They do NOT write to `security_events` — that responsibility belongs to
 * `SecurityEventService`, called inline from `AuthenticationService`, and
 * duplicating it here would produce two security rows per event.
 *
 * @package MaaleArna\MASES\Core\Providers
 * @version 1.0.1
 */
final class CoreServiceProvider extends ServiceProvider
{
    /**
     * The base path to the Core source directory.
     * Used to resolve absolute paths for config and resources.
     */
    private const CORE_SRC_PATH = __DIR__ . '/..';

    /**
     * The tag used when publishing the Core configuration file.
     */
    private const CONFIG_PUBLISH_TAG = 'mases-config';

    // =========================================================================
    // Lifecycle
    // =========================================================================

    /**
     * Register any core application services.
     *
     * @return void
     */
    public function register(): void
    {
        // Merge the default core configuration with the application's published config.
        $this->mergeConfigFrom(
            $this->getConfigPath('mases.php'),
            'mases'
        );

        $this->registerCoreBindings();
    }

    /**
     * Bootstrap any core application services.
     *
     * @return void
     */
    public function boot(): void
    {
        // Load all Core migrations. Using base_path() ensures the absolute
        // path resolves correctly regardless of the working directory.
        $this->loadMigrationsFrom(base_path('mases_core/src/Migrations'));

        // Publish the core configuration so applications can override it.
        $this->publishes([
            $this->getConfigPath('mases.php') => config_path('mases.php'),
        ], self::CONFIG_PUBLISH_TAG);

        $this->registerEventListeners();
    }

    // =========================================================================
    // Bindings
    // =========================================================================

    /**
     * Register Core container bindings.
     *
     * All bindings are intentionally non-contextual singletons (or contracts
     * bound to concrete classes) so the Core behaves consistently regardless
     * of which entry point (HTTP, CLI, queue worker) boots the framework.
     *
     * @return void
     */
    private function registerCoreBindings(): void
    {
        // --- Tenancy ---------------------------------------------------------

        // The tenant resolver is stateless and may be swapped per environment.
        $this->app->bind(
            TenantContextResolverInterface::class,
            RequestTenantResolver::class
        );

        // The tenant context must be consistent across the request lifecycle.
        $this->app->singleton(
            TenantContextService::class,
            static fn(): TenantContextService => new TenantContextService()
        );

        // --- Authorization ---------------------------------------------------

        $this->app->singleton(
            AuthorizationService::class,
            static fn(): AuthorizationService => new AuthorizationService()
        );

        // Alias for convenience in deeply nested DI chains.
        $this->app->alias(AuthorizationService::class, 'mases.authorization');

        // --- Security --------------------------------------------------------

        $this->app->singleton(
            SecurityEventService::class,
            static fn(): SecurityEventService => new SecurityEventService()
        );

        // --- Audit -----------------------------------------------------------

        $this->app->singleton(
            AuditService::class,
            static fn(): AuditService => new AuditService()
        );

        // --- Files -----------------------------------------------------------

        // --- Finance source-reference validation ----------------------------
        // The mapping resolver is authoritative for Shareholder, Wallet, and Loan
        // records only. Other source types stay disabled until their own resolver
        // proves source ownership and legal-entity eligibility.
        $this->app->singleton(JournalSourceEntityMappingResolver::class);
        $this->app->tag(
            [JournalSourceEntityMappingResolver::class],
            'finance.journal_source_reference_resolvers'
        );

        // Resolvers are opt-in and must prove tenant plus legal-entity scope.
        // With no tagged adapters, the registry remains empty and rejects all
        // external source types (fail-closed). Domain providers may tag only
        // authoritative implementations under this exact tag.
        $this->app->singleton(
            JournalSourceReferenceResolverRegistry::class,
            static fn ($app): JournalSourceReferenceResolverRegistry =>
                new JournalSourceReferenceResolverRegistry(
                    $app->tagged('finance.journal_source_reference_resolvers')
                )
        );

        // --- Localization ----------------------------------------------------

        // The Ethiopian calendar engine is stateless and safe to share.
        // Its timezone is read from config, so a single instance is enough.
        $this->app->singleton(
            EthiopianCalendar::class,
            static function ($app): EthiopianCalendar {
                $tz = (string) config(
                    'mases.localization.timezone',
                    config('app.timezone', 'Africa/Addis_Ababa')
                );

                return new EthiopianCalendar(new \DateTimeZone($tz));
            }
        );

        $this->app->singleton(
            LocalizationService::class,
            static fn ($app): LocalizationService => new LocalizationService(
                $app->make(EthiopianCalendar::class)
            )
        );
    }

    // =========================================================================
    // Events
    // =========================================================================

    /**
     * Register Core event listeners.
     *
     * Listeners registered here are part of the platform's security and
     * audit trail. They are intentionally explicit (rather than relying
     * on Laravel's auto-discovery) because they live outside `app/Listeners`.
     *
     * See the class docblock section "Event listener registration" for the
     * rationale and the full list of registrations.
     *
     * @return void
     */
    private function registerEventListeners(): void
    {
        // ---------------------------------------------------------------------
        // Authorization
        // ---------------------------------------------------------------------
        // Persists authorization denials to `security_events` for intrusion
        // detection and compliance review.
        Event::listen(
            AuthorizationFailureEvent::class,
            LogAuthorizationFailureListener::class
        );

        // Clears the per-user authorization cache for every user who holds
        // a role whose permission set was just synced. Closes the
        // privilege-retention window between a role losing a permission
        // and the 300-second authorization-cache TTL expiring for each
        // affected user. See D-11a/b/c/d.
        Event::listen(
            RolePermissionsChanged::class,
            InvalidateAuthorizationCacheListener::class
        );

        // ---------------------------------------------------------------------
        // Authentication audit trail (Phase 1 Amendment A9)
        // ---------------------------------------------------------------------
        // Writes to `audit_logs` via AuditService. These listeners are
        // complementary to the synchronous `security_events` writes
        // performed inline by AuthenticationService — see the class
        // docblock for the distinction.

        // Successful logins.
        Event::listen(
            Login::class,
            LogSuccessfulLogin::class
        );

        // Failed logins. `FailedLogin` is a MASES-owned event dispatched
        // from AuthenticationService::login() (Phase 1 Change Request
        // CR-006). Laravel's own `Failed` event is not used because it is
        // only fired by Auth::attempt(), which the platform avoids.
        Event::listen(
            FailedLogin::class,
            LogFailedLogin::class
        );

        // Logouts.
        Event::listen(
            Logout::class,
            LogLogout::class
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Get the absolute path to a configuration file inside the Core source.
     *
     * @param string $filename
     *
     * @return string
     */
    private function getConfigPath(string $filename): string
    {
        return self::CORE_SRC_PATH . '/Config/' . $filename;
    }
}