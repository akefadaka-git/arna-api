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
use MaaleArna\MASES\Core\Domains\FileManagement\Services\FileService;
use MaaleArna\MASES\Core\Domains\Finance\Services\JournalSourceReferenceResolverRegistry;
use MaaleArna\MASES\Core\Domains\Finance\Services\JournalSourceEntityMappingResolver;
use MaaleArna\MASES\Core\Domains\Documents\Contracts\DocumentSignatureEvidenceVerifier;
use MaaleArna\MASES\Core\Domains\Documents\Services\SodiumDocumentSignatureEvidenceVerifier;
use MaaleArna\MASES\Core\Domains\Documents\Services\ImmutableDocumentBinaryStore;
use MaaleArna\MASES\Core\Domains\Documents\Services\DocumentWorkflowAuthorizationService;
use MaaleArna\MASES\Core\Domains\Documents\Contracts\DocumentWorkflowAuthorizer;
use MaaleArna\MASES\Core\Domains\Documents\Services\DocumentWorkflowAggregateService;
use MaaleArna\MASES\Core\Listeners\InvalidateAuthorizationCacheListener;
use MaaleArna\MASES\Core\Listeners\LogAuthorizationFailureListener;
use MaaleArna\MASES\Core\Localization\Calendar\EthiopianCalendar;
use MaaleArna\MASES\Core\Localization\Services\LocalizationService;
use MaaleArna\MASES\Core\Platform\Contracts\TenantContextResolverInterface;
use MaaleArna\MASES\Core\Security\Services\SecurityEventService;
use MaaleArna\MASES\Core\Tenancy\Services\RequestTenantResolver;
use MaaleArna\MASES\Core\Tenancy\Services\TenantContextService;

/**
 * Registers core platform bindings, migrations, policies and event listeners.
 * Domain services and shared infrastructure are wired here.
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

        $this->app->singleton(
            FileService::class,
            static fn($app): FileService => new FileService(
                $app->make(AuditService::class)
            )
        );

        // Preserve the legacy Core\\Files service identifier as a container
        // alias. There is one implementation and one singleton instance.
        $this->app->alias(
            FileService::class,
            \MaaleArna\MASES\Core\Files\Services\FileService::class
        );

        // --- Documents workflow authorization -------------------------------
        // RBAC is combined with explicit tenant/body/position Governance authority.
        $this->app->singleton(DocumentWorkflowAuthorizationService::class);
        $this->app->singleton(SodiumDocumentSignatureEvidenceVerifier::class);
        $this->app->singleton(
            DocumentSignatureEvidenceVerifier::class,
            static fn ($app): DocumentSignatureEvidenceVerifier => $app->make(SodiumDocumentSignatureEvidenceVerifier::class)
        );
        $this->app->singleton(ImmutableDocumentBinaryStore::class);
        $this->app->singleton(DocumentWorkflowAggregateService::class);
        $this->app->singleton(
            DocumentWorkflowAuthorizer::class,
            static fn ($app): DocumentWorkflowAuthorizer => $app->make(DocumentWorkflowAuthorizationService::class)
        );

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