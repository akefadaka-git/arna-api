<?php

declare(strict_types=1);



namespace MaaleArna\Arna\Core\Domains\Shareholder\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use MaaleArna\Arna\Core\Domains\Shareholder\Events\CertificateIssued;
use MaaleArna\Arna\Core\Domains\Shareholder\Events\ShareHoldingChanged;
use MaaleArna\Arna\Core\Domains\Shareholder\Events\ShareholderRegistered;
use MaaleArna\Arna\Core\Domains\Shareholder\Events\ShareholderUpdated;
use MaaleArna\Arna\Core\Domains\Shareholder\Listeners\AuditShareholderEvent;
use MaaleArna\Arna\Core\Domains\Shareholder\Models\ShareCertificate;
use MaaleArna\Arna\Core\Domains\Shareholder\Models\ShareClass;
use MaaleArna\Arna\Core\Domains\Shareholder\Models\ShareHolding;
use MaaleArna\Arna\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\Arna\Core\Domains\Shareholder\Policies\CertificatePolicy;
use MaaleArna\Arna\Core\Domains\Shareholder\Policies\ShareClassPolicy;
use MaaleArna\Arna\Core\Domains\Shareholder\Policies\ShareHoldingPolicy;
use MaaleArna\Arna\Core\Domains\Shareholder\Policies\ShareholderPolicy;
use MaaleArna\Arna\Core\Domains\Shareholder\Repositories\ShareholderRepository;
use MaaleArna\Arna\Core\Domains\Shareholder\Services\CertificateService;
use MaaleArna\Arna\Core\Domains\Shareholder\Services\ShareClassService;
use MaaleArna\Arna\Core\Domains\Shareholder\Services\ShareHoldingService;
use MaaleArna\Arna\Core\Domains\Shareholder\Services\ShareholderService;

/**
 * ShareholderServiceProvider
 *
 * Service provider for the ARNA Shareholder domain.
 *
 * =============================================================================
 * Responsibility
 * =============================================================================
 * This provider is the single integration point between the Shareholder
 * domain and the Laravel application. It is responsible for:
 *
 *   1. Merging the domain configuration (`Config/shareholder.php`) into
 *      the application container under the key `shareholder`.
 *
 *   2. Loading the domain's translation catalogue from `Resources/lang/`
 *      under the namespace `shareholder`, so that every
 *      `__('shareholder::...')` call resolves. See §"Translation loading".
 *
 *   3. Binding the domain's five services and one repository as singletons
 *      so that within a single request every consumer receives the same
 *      instance.
 *
 *   4. Registering the domain's four authorization policies so that
 *      Gate::authorize('view', $shareholder) resolves to ShareholderPolicy,
 *      Gate::authorize('view', $holding) resolves to ShareHoldingPolicy,
 *      Gate::authorize('view', $certificate) resolves to CertificatePolicy,
 *      and Gate::authorize('view', $shareClass) resolves to ShareClassPolicy.
 *
 *   5. Wiring the domain's four business events to their single audit
 *      listener, AuditShareholderEvent. That listener translates each event
 *      into a row in the platform's `audit_logs` table via the Core
 *      AuditService.
 *
 *   6. Loading the domain's migrations from
 *      `Database/Migrations/` when the domain is enabled.
 *
 *   7. Loading the domain's routes from `Routes/api.php` when the domain is
 *      enabled and the file exists.
 *
 * =============================================================================
 * Deployment sequencing — read before registering
 * =============================================================================
 * This provider is NOT registered in `bootstrap/providers.php` by the
 * Phase 2A manifest. It is deliberately registered as a separate Change
 * Request ("A14 — Register ShareholderServiceProvider") once every class
 * it references has been deployed, so that when the provider first boots
 * there are no missing classes.
 *
 * The provider is nonetheless written defensively so that an accidental
 * early registration does not crash the application:
 *
 *   - Route loading is guarded by `is_file()`; a missing `Routes/api.php`
 *     results in routes being skipped rather than a fatal `require` error.
 *
 *   - Migration loading tolerates a missing directory silently, because
 *     Laravel's `loadMigrationsFrom()` merely records the path and globs
 *     it later.
 *
 *   - Translation loading tolerates a missing directory silently, because
 *     Laravel's `loadTranslationsFrom()` records the path and consults it
 *     on the first `__('shareholder::...')` call. A deployment that has
 *     not yet uploaded the catalogue will return the translation key
 *     verbatim, which is the correct diagnostic surface.
 *
 *   - Service bindings, policy registrations, and event listeners are all
 *     recorded by class-name string. The container and the event dispatcher
 *     resolve the classes lazily on first use, so the target class files
 *     do not need to exist at boot time.
 *
 * =============================================================================
 * Domain enable/disable semantics
 * =============================================================================
 * `config('shareholder.enabled')` (declared in `Config/shareholder.php`)
 * is checked at boot. When false:
 *
 *   - Migrations are NOT registered — `php artisan migrate` will not touch
 *     the domain's tables.
 *   - Routes are NOT registered — the domain is invisible to HTTP clients.
 *
 * Three categories of registration are performed unconditionally:
 *
 *   - Policies and event listeners, because they operate on data that may
 *     already exist from a previous enabled period. Disabling the domain
 *     should not blind the audit trail or open an authorization gap.
 *
 *   - Translations, because they are data rather than behaviour.
 *     Historical rows — audit logs, retained shareholders, archived
 *     certificates — remain on disk and can be rendered by any consumer
 *     that reads them. The `type` and `status` catalogues must resolve
 *     for those rows even when the domain is disabled, so that their
 *     labels render correctly. The same pattern is used by
 *     WalletServiceProvider and LoanServiceProvider, which load their
 *     translations before the enabled-flag check.
 *
 * =============================================================================
 * Container bindings and lifetime
 * =============================================================================
 * All five services and the repository are bound as singletons. This
 * mirrors the pattern established by CoreServiceProvider for
 * `AuditService`, `AuthorizationService`, `SecurityEventService`, and
 * `FileService`. The services are stateless by design — they hold no
 * request-scoped state — so a single instance per process is correct.
 *
 * Do not change these to scoped or transient bindings without an approved
 * Change Request; the ShareHoldingService in particular relies on being a
 * shared instance to preserve any internal invariants it may accumulate
 * within a request lifecycle.
 *
 * =============================================================================
 * Policy registration
 * =============================================================================
 * ARNA uses explicit policy registration rather than Laravel's convention
 * of auto-discovery. Explicit registration is used because:
 *
 *   1. The models live under `arna_core/src/Domains/Shareholder/Models/`,
 *      not under `app/Models/`, so Laravel's convention does not find them.
 *
 *   2. The policies live under the same domain tree, not under
 *      `app/Policies/`.
 *
 *   3. Registering explicitly makes the model → policy mapping visible in
 *      one place, which is consistent with how Core registers event
 *      listeners (see `CoreServiceProvider::registerEventListeners`).
 *
 * Four policies are registered in Phase 2.3:
 *
 *   Shareholder      → ShareholderPolicy
 *   ShareHolding     → ShareHoldingPolicy
 *   ShareCertificate → CertificatePolicy        (added by P2.3)
 *   ShareClass       → ShareClassPolicy         (added by P2.3)
 *
 * The two additions extend the model→policy mapping to the two remaining
 * domain models. `CertificatePolicy` wires the previously-inert
 * `certificate.view` and `certificate.view_own` slugs; `ShareClassPolicy`
 * wires the previously-inert `share_class.manage` slug (as a superset of
 * `share_class.view`). See the respective policy class docblocks for the
 * grant logic.
 *
 * =============================================================================
 * Translation loading
 * =============================================================================
 * The domain's `Resources/lang/` directory is registered with Laravel's
 * translator under the namespace `shareholder`. Four catalogue files
 * are on disk:
 *
 *     am/type.php     — the six ShareholderType labels, Amharic
 *     am/status.php   — the two status labels, Amharic
 *     en/type.php     — the English fallback for type
 *     en/status.php   — the English fallback for status
 *
 * The ShareholderResource resolves `type_label` and `status_label`
 * through `__('shareholder::type.*')` and `__('shareholder::status.*')`.
 * Without the namespace registration, those calls return their key
 * strings verbatim — the reason the portal's identity card and the
 * workspace's shareholder pages rendered `shareholder::type.individual`
 * and `shareholder::status.active` rather than their Amharic labels.
 *
 * The Amharic catalogue is primary; the English catalogue is the
 * fallback surface. Laravel resolves the current locale first, then
 * falls back to `fallback_locale` (also `en` in ARNA). A key missing
 * from `am/*.php` therefore resolves against `en/*.php` if present,
 * rather than returning the key string.
 *
 * The registration is done via `loadTranslationsFrom()`, which records
 * the path with Laravel's translator. No `publishes()` call is needed —
 * the four catalogue files are part of the domain's source, not a
 * config artefact that a deployment might override.
 *
 * @package MaaleArna\Arna\Core\Domains\Shareholder\Providers
 * @version 2.1.0
 */
final class ShareholderServiceProvider extends ServiceProvider
{
    /**
     * Config key under which the domain configuration is merged.
     */
    private const CONFIG_KEY = 'shareholder';

    /**
     * Absolute path to the domain configuration file.
     */
    private const CONFIG_PATH = __DIR__ . '/../Config/shareholder.php';

    /**
     * Translation namespace under which the domain's catalogue is
     * registered with Laravel's translator.
     *
     * Matches the `shareholder::` prefix every `__()` call in the
     * domain's resources uses.
     */
    private const TRANSLATION_NAMESPACE = 'shareholder';

    /**
     * Absolute path to the domain translations directory.
     *
     * Contains one subdirectory per locale (`am/`, `en/`), each holding
     * one file per catalogue group (`type.php`, `status.php`).
     */
    private const TRANSLATIONS_PATH = __DIR__ . '/../Resources/lang';

    /**
     * Absolute path to the domain migrations directory.
     */
    private const MIGRATIONS_PATH = __DIR__ . '/../Database/Migrations';

    /**
     * Absolute path to the domain routes file.
     */
    private const ROUTES_PATH = __DIR__ . '/../Routes/api.php';

    // =========================================================================
    // Lifecycle
    // =========================================================================

    /**
     * Register any Shareholder-domain services.
     *
     * Runs before `boot()`, during the container's registration phase. It
     * merges the configuration (so that `config('shareholder.*')` is
     * available to every later provider) and records the container bindings.
     *
     * Nothing in this method touches the filesystem except the config file,
     * which is guaranteed to exist because 2A.1 was deployed and verified.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, self::CONFIG_KEY);

        $this->registerBindings();
    }

    /**
     * Bootstrap any Shareholder-domain services.
     *
     * Runs after every provider has registered. At this point we can safely
     * interact with `Gate`, `Event`, `Route`, the translator, and the
     * migration repository.
     *
     * Order of operations inside this method is deliberate:
     *
     *   1. Translations, policies, and event listeners are registered first
     *      and unconditionally, so the translation catalogue, the audit
     *      trail, and the authorization layer remain intact regardless of
     *      the enabled flag. See the class docblock §"Domain enable/disable
     *      semantics".
     *
     *   2. The enabled flag is then checked; if the domain is disabled,
     *      migrations and routes are skipped.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->loadDomainTranslations();
        $this->registerPolicies();
        $this->registerEventListeners();

        if (!$this->isDomainEnabled()) {
            return;
        }

        $this->loadDomainMigrations();
        $this->loadDomainRoutes();
    }

    // =========================================================================
    // Bindings
    // =========================================================================

    /**
     * Register the domain's container bindings.
     *
     * All bindings use `singleton` with the class name as the abstract. The
     * container resolves the concrete class itself via reflection; the
     * bindings are therefore recorded without instantiating anything, and
     * will not fail if the class files are not yet present at boot time.
     *
     * @return void
     */
    private function registerBindings(): void
    {
        $this->app->singleton(ShareholderRepository::class);
        $this->app->singleton(ShareholderService::class);
        $this->app->singleton(ShareClassService::class);
        $this->app->singleton(ShareHoldingService::class);
        $this->app->singleton(CertificateService::class);
    }

    // =========================================================================
    // Translations
    // =========================================================================

    /**
     * Register the domain's translation catalogue with Laravel's
     * translator.
     *
     * The four catalogue files under `Resources/lang/` are recorded under
     * the namespace `shareholder`. Once registered, every
     * `__('shareholder::type.*')` and `__('shareholder::status.*')` call
     * in the domain's resources resolves against the active locale's
     * file, with fallback to the `fallback_locale` catalogue when a key
     * is absent from the active locale.
     *
     * The call is safe when the directory does not exist: Laravel's
     * `loadTranslationsFrom()` records the path and consults it lazily on
     * the first lookup. A missing catalogue therefore produces the raw
     * translation key in the response, which is the correct diagnostic
     * surface — the same behaviour that was visible before this method
     * existed, and now a signal of a missing file rather than a missing
     * registration.
     *
     * No `publishes()` call is made. The catalogue is part of the
     * domain's source tree; there is no per-deployment override for it,
     * and none is anticipated by the current manifest.
     *
     * @return void
     */
    private function loadDomainTranslations(): void
    {
        $this->loadTranslationsFrom(
            self::TRANSLATIONS_PATH,
            self::TRANSLATION_NAMESPACE,
        );
    }

    // =========================================================================
    // Policies
    // =========================================================================

    /**
     * Register the domain's authorization policies.
     *
     * The Gate facade stores a mapping from model class to policy class.
     * Both sides of each mapping are class-name strings; neither class is
     * loaded at registration time. The policy is resolved from the
     * container only when the first authorization check for the mapped
     * model is performed.
     *
     * The four registrations are:
     *
     *   Shareholder      → ShareholderPolicy    (Phase 2A)
     *   ShareHolding     → ShareHoldingPolicy   (Phase 2A)
     *   ShareCertificate → CertificatePolicy    (Phase 2.3)
     *   ShareClass       → ShareClassPolicy     (Phase 2.3)
     *
     * @return void
     */
    private function registerPolicies(): void
    {
        Gate::policy(Shareholder::class, ShareholderPolicy::class);
        Gate::policy(ShareHolding::class, ShareHoldingPolicy::class);
        Gate::policy(ShareCertificate::class, CertificatePolicy::class);
        Gate::policy(ShareClass::class, ShareClassPolicy::class);
    }

    // =========================================================================
    // Event listeners
    // =========================================================================

    /**
     * Register the domain's event listeners.
     *
     * All four domain events are handled by a single listener,
     * `AuditShareholderEvent`, which writes each event to the platform
     * `audit_logs` table via the Core `AuditService`. The listener
     * distinguishes the event type internally from the event instance it
     * receives.
     *
     * Registration is explicit rather than relying on Laravel's listener
     * auto-discovery because the listener lives under the domain tree, not
     * under `app/Listeners/`.
     *
     * @return void
     */
    private function registerEventListeners(): void
    {
        Event::listen(ShareholderRegistered::class, AuditShareholderEvent::class);
        Event::listen(ShareholderUpdated::class,    AuditShareholderEvent::class);
        Event::listen(ShareHoldingChanged::class,   AuditShareholderEvent::class);
        Event::listen(CertificateIssued::class,     AuditShareholderEvent::class);
    }

    // =========================================================================
    // Migrations
    // =========================================================================

    /**
     * Register the domain's migration directory with the migrator.
     *
     * `loadMigrationsFrom()` records the path and does not validate its
     * existence, so calling it before the migration files have been
     * authored is safe. When `php artisan migrate` runs, the migrator
     * globs the directory and processes every file it finds; an empty
     * directory is treated as "no pending migrations".
     *
     * @return void
     */
    private function loadDomainMigrations(): void
    {
        $this->loadMigrationsFrom(self::MIGRATIONS_PATH);
    }

    // =========================================================================
    // Routes
    // =========================================================================

    /**
     * Register the domain's HTTP routes.
     *
     * The routes file (`Routes/api.php`, delivered as 2A.36) contains only
     * the specific URIs for each resource; the outer middleware stack and
     * URI prefix are applied here from configuration so that operators can
     * adjust the mount point without editing the routes file.
     *
     * If the routes file does not yet exist — e.g. this provider was
     * deployed and registered before 2A.36 landed — the method returns
     * silently. This is the defensive guard that allows the provider to be
     * deployed safely at any point in the 2A delivery sequence.
     *
     * @return void
     */
    private function loadDomainRoutes(): void
    {
        if (!is_file(self::ROUTES_PATH)) {
            return;
        }

        $middleware = (array) config('shareholder.routes.middleware', []);
        $prefix     = (string) config('shareholder.routes.prefix', 'api/shareholders');

        Route::middleware($middleware)
            ->prefix($prefix)
            ->group(function (): void {
                require self::ROUTES_PATH;
            });
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Determine whether the domain is enabled via configuration.
     *
     * The value is read from `config('shareholder.enabled')`, which is
     * declared in `Config/shareholder.php` and defaulted to `true`. The
     * default parameter in the call covers the theoretical case where the
     * provider has been registered before the config file was merged.
     *
     * @return bool
     */
    private function isDomainEnabled(): bool
    {
        return (bool) config('shareholder.enabled', true);
    }
}