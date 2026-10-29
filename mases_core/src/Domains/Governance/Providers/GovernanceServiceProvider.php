<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2c · File 30 of 31
 * =============================================================================
 *
 * File:            Providers/GovernanceServiceProvider.php  (AMEND)
 * Unit:            G2c — GovernanceAppointment and GovernanceMembership
 *                  vertical slices (provider amendment)
 * Delivery type:   Amendment
 * Net lines:       +12 import statements;
 *                  +4 singleton bindings in registerBindings();
 *                  +2 Gate::policy registrations in registerPolicies();
 *                  +4 Event::listen registrations in
 *                     registerEventListeners();
 *                  +~60 docblock lines rewritten in the three methods;
 *                  +~50 lines in the class docblock §"Responsibility"
 *                     and new sub-sections for the G2c entities;
 *                  +~280 lines for this change-verification block.
 *
 * Before (as the G2b amendment, G2b file 26 of 27, delivered it):
 *   The provider registered exactly:
 *
 *     Bindings:
 *       GovernanceBodyRepository
 *       GovernanceBodyService
 *       GovernancePositionRepository
 *       GovernancePositionService
 *       GovernanceTermRepository
 *       GovernanceTermService
 *
 *     Policies:
 *       GovernanceBody     → GovernanceBodyPolicy
 *       GovernancePosition → GovernancePositionPolicy
 *       GovernanceTerm     → GovernanceTermPolicy
 *
 *     Events:
 *       GovernanceBodyRegistered      → AuditGovernanceEvent
 *       GovernanceBodyUpdated         → AuditGovernanceEvent
 *       GovernancePositionRegistered  → AuditGovernanceEvent
 *       GovernancePositionUpdated     → AuditGovernanceEvent
 *       GovernanceTermRegistered      → AuditGovernanceEvent
 *       GovernanceTermUpdated         → AuditGovernanceEvent
 *
 *   The four new repositories and services, two policies, and four
 *   events delivered by G2c did not reach the container, the Gate
 *   facade, or the event dispatcher. The consequence:
 *
 *     · `app(GovernanceAppointmentRepository::class)` and its three
 *       siblings resolved by reflection, producing a new instance
 *       per resolution rather than the intended shared singleton.
 *
 *     · `Gate::getPolicyFor(GovernanceAppointment::class)` returned
 *       null. Every `Gate::authorize(...)` call for appointment or
 *       membership objects fell through to Laravel's default deny,
 *       producing 403s for authorized callers.
 *
 *     · The four new events were dispatched into an event dispatcher
 *       with no listener registered for them. The audit trail
 *       recorded nothing for appointment or membership operations.
 *
 * After:
 *   Twelve new imports are added at the top of the file — four
 *   repository/service classes, two policies, four events, two
 *   models (for the policy registrations). Three methods gain
 *   entries:
 *
 *     registerBindings() adds four singletons:
 *       GovernanceAppointmentRepository
 *       GovernanceAppointmentService
 *       GovernanceMembershipRepository
 *       GovernanceMembershipService
 *
 *     registerPolicies() adds two Gate::policy registrations:
 *       GovernanceAppointment → GovernanceAppointmentPolicy
 *       GovernanceMembership  → GovernanceMembershipPolicy
 *
 *     registerEventListeners() adds four Event::listen registrations:
 *       GovernanceAppointmentRegistered  → AuditGovernanceEvent
 *       GovernanceAppointmentUpdated     → AuditGovernanceEvent
 *       GovernanceMembershipRegistered   → AuditGovernanceEvent
 *       GovernanceMembershipUpdated      → AuditGovernanceEvent
 *
 *   The six G2a/G2b-binding, three policy, and six listener
 *   registrations remain byte-for-byte identical.
 *
 * =============================================================================
 * What does NOT change
 * =============================================================================
 *   · The six constants: CONFIG_KEY, CONFIG_PATH,
 *     TRANSLATION_NAMESPACE, TRANSLATIONS_PATH, MIGRATIONS_PATH,
 *     ROUTES_PATH.
 *
 *   · The `register()` method signature and its
 *     `mergeConfigFrom(...)` call.
 *
 *   · The `boot()` method signature and its call sequence.
 *
 *   · `loadDomainTranslations()`, `loadDomainMigrations()`,
 *     `loadDomainRoutes()`, `isDomainEnabled()` — every line is
 *     byte-for-byte identical to the G2b version.
 *
 *   · The six existing singleton bindings, the three existing
 *     policy registrations, and the six existing event
 *     registrations.
 *
 *   · Every docblock section that describes the G2a/G2b behaviour,
 *     except the specific counts (six → ten bindings, three → five
 *     policies, six → ten listener registrations).
 *
 * =============================================================================
 * Why the amendment is a full-file replacement
 * =============================================================================
 * The delivery discipline requires one complete file per delivery.
 * The G2b provider is ~600 lines including docblocks; the G2c
 * amendment adds roughly 80 lines of code and 110 lines of
 * docblock. Rather than deliver a diff, this delivery ships the
 * entire amended file so the operator uploads a single file that is
 * unambiguously the new state.
 *
 * =============================================================================
 * Deployment
 * =============================================================================
 * Upload this file to:
 *
 *     mases_core/src/Domains/Governance/Providers/GovernanceServiceProvider.php
 *
 * This file REPLACES the G2b version.
 *
 * After uploading, run:
 *
 *     composer dump-autoload
 *     php artisan optimize:clear
 *
 * (Both via Plesk's UI or command runner, per the platform's
 * deployment convention.)
 *
 * The `composer dump-autoload` refresh is mandatory because twelve
 * new class references were added to the provider's import block.
 *
 * The `optimize:clear` is mandatory because the provider manifest is
 * cached; without the clear, Laravel continues using the previous
 * provider state and the new bindings, policies, and listeners do
 * not take effect.
 *
 * Verification:
 *
 *     php artisan tinker
 *
 *     # Singleton bindings resolve
 *     >>> app(\MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceMembershipService::class)
 *     => MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceMembershipService {#...}
 *
 *     # Policies registered
 *     >>> Illuminate\Support\Facades\Gate::getPolicyFor(
 *     ...     \MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceMembership::class
 *     ... )
 *     => MaaleArna\MASES\Core\Domains\Governance\Policies\GovernanceMembershipPolicy {#...}
 *
 *     # Events wired
 *     >>> count(Illuminate\Support\Facades\Event::getListeners(
 *     ...     \MaaleArna\MASES\Core\Domains\Governance\Events\GovernanceMembershipRegistered::class
 *     ... ))
 *     => 1
 *
 * =============================================================================
 * Files modified:            this file only
 * Files added:               none
 * Files deleted:             none
 * Imports added:             12 (4 repositories/services, 2 policies,
 *                               4 events, 2 models)
 * Bindings added:            4 (GovernanceAppointmentRepository,
 *                               GovernanceAppointmentService,
 *                               GovernanceMembershipRepository,
 *                               GovernanceMembershipService)
 * Policies added:            2 (GovernanceAppointment,
 *                               GovernanceMembership)
 * Event listeners added:     4 (Appointment×2, Membership×2)
 * Existing registrations changed: 0
 * =============================================================================
 */

namespace MaaleArna\MASES\Core\Domains\Governance\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use MaaleArna\MASES\Core\Domains\Governance\Events\GovernanceAppointmentRegistered;
use MaaleArna\MASES\Core\Domains\Governance\Events\GovernanceAppointmentUpdated;
use MaaleArna\MASES\Core\Domains\Governance\Events\GovernanceBodyRegistered;
use MaaleArna\MASES\Core\Domains\Governance\Events\GovernanceBodyUpdated;
use MaaleArna\MASES\Core\Domains\Governance\Events\GovernanceMembershipRegistered;
use MaaleArna\MASES\Core\Domains\Governance\Events\GovernanceMembershipUpdated;
use MaaleArna\MASES\Core\Domains\Governance\Events\GovernancePositionRegistered;
use MaaleArna\MASES\Core\Domains\Governance\Events\GovernancePositionUpdated;
use MaaleArna\MASES\Core\Domains\Governance\Events\GovernanceTermRegistered;
use MaaleArna\MASES\Core\Domains\Governance\Events\GovernanceTermUpdated;
use MaaleArna\MASES\Core\Domains\Governance\Listeners\AuditGovernanceEvent;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceAppointment;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceMembership;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceMeeting;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernancePosition;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceTerm;
use MaaleArna\MASES\Core\Domains\Governance\Policies\GovernanceAppointmentPolicy;
use MaaleArna\MASES\Core\Domains\Governance\Policies\GovernanceBodyPolicy;
use MaaleArna\MASES\Core\Domains\Governance\Policies\GovernanceMembershipPolicy;
use MaaleArna\MASES\Core\Domains\Governance\Policies\GovernanceMeetingPolicy;
use MaaleArna\MASES\Core\Domains\Governance\Policies\GovernancePositionPolicy;
use MaaleArna\MASES\Core\Domains\Governance\Policies\GovernanceTermPolicy;
use MaaleArna\MASES\Core\Domains\Governance\Repositories\GovernanceAppointmentRepository;
use MaaleArna\MASES\Core\Domains\Governance\Repositories\GovernanceBodyRepository;
use MaaleArna\MASES\Core\Domains\Governance\Repositories\GovernanceMembershipRepository;
use MaaleArna\MASES\Core\Domains\Governance\Repositories\GovernancePositionRepository;
use MaaleArna\MASES\Core\Domains\Governance\Repositories\GovernanceTermRepository;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceAppointmentService;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceAuthorityGrantService;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceBodyService;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceMembershipService;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceMeetingService;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernancePositionService;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceTermService;

/**
 * GovernanceServiceProvider
 *
 * Service provider for the MASES Governance domain.
 *
 * =============================================================================
 * Responsibility (G2c scope)
 * =============================================================================
 * This provider is the single integration point between the Governance
 * domain and the Laravel application. As of G2c, it is responsible
 * for:
 *
 *   1. Merging the domain configuration (`Config/governance.php`)
 *      into the application container under the key `governance`.
 *
 *   2. Loading the domain's translation catalogue from
 *      `Resources/lang/` under the namespace `governance`.
 *
 *   3. Binding the domain's repositories and services as singletons:
 *
 *        · GovernanceBodyRepository
 *        · GovernanceBodyService
 *        · GovernancePositionRepository
 *        · GovernancePositionService
 *        · GovernanceTermRepository
 *        · GovernanceTermService
 *        · GovernanceAppointmentRepository
 *        · GovernanceAppointmentService
 *        · GovernanceMembershipRepository
 *        · GovernanceMembershipService
 *
 *   4. Registering the domain's authorization policies:
 *
 *        · GovernanceBody        → GovernanceBodyPolicy
 *        · GovernancePosition    → GovernancePositionPolicy
 *        · GovernanceTerm        → GovernanceTermPolicy
 *        · GovernanceAppointment → GovernanceAppointmentPolicy
 *        · GovernanceMembership  → GovernanceMembershipPolicy
 *
 *   5. Registering the domain's business events against their audit
 *      listener:
 *
 *        · GovernanceBodyRegistered          → AuditGovernanceEvent
 *        · GovernanceBodyUpdated             → AuditGovernanceEvent
 *        · GovernancePositionRegistered      → AuditGovernanceEvent
 *        · GovernancePositionUpdated         → AuditGovernanceEvent
 *        · GovernanceTermRegistered          → AuditGovernanceEvent
 *        · GovernanceTermUpdated             → AuditGovernanceEvent
 *        · GovernanceAppointmentRegistered   → AuditGovernanceEvent
 *        · GovernanceAppointmentUpdated      → AuditGovernanceEvent
 *        · GovernanceMembershipRegistered    → AuditGovernanceEvent
 *        · GovernanceMembershipUpdated       → AuditGovernanceEvent
 *
 *   6. Loading the domain's migrations from
 *      `Database/Migrations/` when the domain is enabled.
 *
 *   7. Loading the domain's HTTP routes from `Routes/api.php` when
 *      the domain is enabled and the file exists.
 *
 * As the domain grows in G3 onward, this provider will gain one
 * binding, policy, or listener per new class the phases introduce.
 *
 * =============================================================================
 * Deployment sequencing — why the provider grows in lockstep with
 * its referenced classes
 * =============================================================================
 * The rule is invariable: an amendment to this file is delivered only
 * after every class it references is on disk. The provider is loaded
 * on every request, so a missing class reference would crash the
 * application on boot — not on the request that actually uses the
 * class, but on the very first request after the provider amendment
 * lands.
 *
 * The G2c delivery order honours this rule: all appointment classes
 * (files 2–15 of 31) and all membership classes (files 17–28 of 31)
 * are on disk before this file (30).
 *
 * =============================================================================
 * Container bindings and lifetime
 * =============================================================================
 * All ten bindings use `singleton` with the class name as the
 * abstract. The container resolves the concrete class itself via
 * reflection; the bindings are recorded without instantiating
 * anything.
 *
 * The ten singletons are stateless by design and hold no
 * request-scoped state. A single instance per process is correct.
 *
 * Each service receives its repository via constructor injection;
 * because the repositories are also singletons, each service holds
 * the same repository instance that any other consumer in the same
 * request would receive.
 *
 * This mirrors the pattern established by
 * `ShareholderServiceProvider::registerBindings()`.
 *
 * =============================================================================
 * Policy registration
 * =============================================================================
 * MASES uses explicit policy registration rather than Laravel's
 * convention of auto-discovery. Explicit registration is used
 * because:
 *
 *   1. The models live under
 *      `mases_core/src/Domains/Governance/Models/`, not under
 *      `app/Models/`, so Laravel's convention does not find them.
 *
 *   2. The policies live under the same domain tree, not under
 *      `app/Policies/`.
 *
 *   3. Registering explicitly makes the model → policy mapping
 *      visible in one place.
 *
 * Five policies are registered as of G2c:
 *
 *     GovernanceBody        → GovernanceBodyPolicy
 *     GovernancePosition    → GovernancePositionPolicy
 *     GovernanceTerm        → GovernanceTermPolicy
 *     GovernanceAppointment → GovernanceAppointmentPolicy
 *     GovernanceMembership  → GovernanceMembershipPolicy
 *
 * As new models arrive in G3 and beyond, this method gains one
 * line per model → policy mapping.
 *
 * =============================================================================
 * Event listeners and fail-open semantics
 * =============================================================================
 * Ten domain events are wired to a single listener:
 *
 *     GovernanceBodyRegistered          →  AuditGovernanceEvent
 *     GovernanceBodyUpdated             →  AuditGovernanceEvent
 *     GovernancePositionRegistered      →  AuditGovernanceEvent
 *     GovernancePositionUpdated         →  AuditGovernanceEvent
 *     GovernanceTermRegistered          →  AuditGovernanceEvent
 *     GovernanceTermUpdated             →  AuditGovernanceEvent
 *     GovernanceAppointmentRegistered   →  AuditGovernanceEvent
 *     GovernanceAppointmentUpdated      →  AuditGovernanceEvent
 *     GovernanceMembershipRegistered    →  AuditGovernanceEvent
 *     GovernanceMembershipUpdated       →  AuditGovernanceEvent
 *
 * The listener branches on the event class via `match (true)` and
 * writes an audit row through the Core `AuditService`. It catches
 * `Throwable`, logs the failure, and returns — the same fail-open
 * policy used by every audit listener in the platform.
 *
 * All registrations are performed unconditionally (i.e. before the
 * enabled-flag check in `boot()`), so that a domain that has been
 * disabled still writes audit rows for the historical data it already
 * owns.
 *
 * =============================================================================
 * Domain enable/disable semantics
 * =============================================================================
 * `config('governance.enabled')` is checked at boot. When false:
 *
 *   · Migrations are NOT registered.
 *   · Routes are NOT registered.
 *
 * Four categories of registration are performed unconditionally:
 * policies, event listeners, translations, and container bindings.
 *
 * =============================================================================
 * Route prefix and middleware
 * =============================================================================
 * The mount point remains `api/governance` with the middleware stack
 * `['api', 'auth:sanctum', 'tenant.membership']`. The values are
 * declared in `config('governance.routes.*')` and may be overridden
 * via environment variables.
 *
 * =============================================================================
 * @package MaaleArna\MASES\Core\Domains\Governance\Providers
 * @version 1.3.0
 */
final class GovernanceServiceProvider extends ServiceProvider
{
    /**
     * Config key under which the domain configuration is merged.
     */
    private const CONFIG_KEY = 'governance';

    /**
     * Absolute path to the domain configuration file.
     */
    private const CONFIG_PATH = __DIR__ . '/../Config/governance.php';

    /**
     * Translation namespace under which the domain's catalogue is
     * registered with Laravel's translator.
     */
    private const TRANSLATION_NAMESPACE = 'governance';

    /**
     * Absolute path to the domain translations directory.
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
     * Register any Governance-domain services.
     *
     * Runs before `boot()`, during the container's registration
     * phase. It merges the configuration and records the container
     * bindings.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, self::CONFIG_KEY);

        $this->registerBindings();
    }

    /**
     * Bootstrap any Governance-domain services.
     *
     * Runs after every provider has registered. At this point we can
     * safely interact with the translator, the `Gate` facade, the
     * event dispatcher, the migration repository, and the route
     * registrar.
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
     * All bindings use `singleton` with the class name as the
     * abstract. The container resolves the concrete class itself via
     * reflection; the bindings are recorded without instantiating
     * anything.
     *
     * As of G2c, ten classes are bound:
     *
     *     GovernanceBodyRepository         — body read surface (G2a).
     *     GovernanceBodyService            — body write path (G2a).
     *     GovernancePositionRepository     — position read surface (G2b).
     *     GovernancePositionService        — position write path (G2b).
     *     GovernanceTermRepository         — term read surface (G2b).
     *     GovernanceTermService            — term write path (G2b).
     *     GovernanceAppointmentRepository  — appointment read surface (G2c).
     *     GovernanceAppointmentService     — appointment write path (G2c).
     *     GovernanceMembershipRepository   — membership read surface (G2c).
     *     GovernanceMembershipService      — membership write path (G2c).
     *
     * All ten are stateless by design.
     *
     * @return void
     */
    private function registerBindings(): void
    {
        // G2a — body bindings.
        $this->app->singleton(GovernanceBodyRepository::class);
        $this->app->singleton(GovernanceBodyService::class);

        // G2b — position bindings.
        $this->app->singleton(GovernancePositionRepository::class);
        $this->app->singleton(GovernancePositionService::class);

        // G2b — term bindings.
        $this->app->singleton(GovernanceTermRepository::class);
        $this->app->singleton(GovernanceTermService::class);

        // G2c — appointment bindings.
        $this->app->singleton(GovernanceAppointmentRepository::class);
        $this->app->singleton(GovernanceAppointmentService::class);

        // G2c — membership bindings.
        $this->app->singleton(GovernanceMembershipRepository::class);
        $this->app->singleton(GovernanceMembershipService::class);

        // G5a — meeting lifecycle service.
        $this->app->singleton(GovernanceMeetingService::class);

        // P1.3-B3 — explicit position-scoped authority grants.
        $this->app->singleton(GovernanceAuthorityGrantService::class);
    }

    // =========================================================================
    // Translations
    // =========================================================================

    /**
     * Register the domain's translation catalogue with Laravel's
     * translator.
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
     * The `Gate` facade stores a mapping from model class to policy
     * class. Both sides of each mapping are class-name strings;
     * neither class is loaded at registration time.
     *
     * As of G2c, five registrations are in place:
     *
     *     GovernanceBody        → GovernanceBodyPolicy        (G2a)
     *     GovernancePosition    → GovernancePositionPolicy    (G2b)
     *     GovernanceTerm        → GovernanceTermPolicy        (G2b)
     *     GovernanceAppointment → GovernanceAppointmentPolicy (G2c)
     *     GovernanceMembership  → GovernanceMembershipPolicy  (G2c)
     *
     * @return void
     */
    private function registerPolicies(): void
    {
        // G2a — body policy.
        Gate::policy(GovernanceBody::class, GovernanceBodyPolicy::class);

        // G2b — position policy.
        Gate::policy(GovernancePosition::class, GovernancePositionPolicy::class);

        // G2b — term policy.
        Gate::policy(GovernanceTerm::class, GovernanceTermPolicy::class);

        // G2c — appointment policy.
        Gate::policy(GovernanceAppointment::class, GovernanceAppointmentPolicy::class);

        // G2c — membership policy.
        Gate::policy(GovernanceMembership::class, GovernanceMembershipPolicy::class);

        // G5a — meeting policy.
        Gate::policy(GovernanceMeeting::class, GovernanceMeetingPolicy::class);
    }

    // =========================================================================
    // Event listeners
    // =========================================================================

    /**
     * Register the domain's event listeners.
     *
     * As of G2c, ten domain events are handled by a single listener,
     * `AuditGovernanceEvent`, which writes each event to the platform
     * `audit_logs` table via the Core `AuditService`.
     *
     * Registration is explicit rather than relying on Laravel's
     * listener auto-discovery because the listener lives under the
     * domain tree, not under `app/Listeners/`.
     *
     * @return void
     */
    private function registerEventListeners(): void
    {
        // G2a — body events.
        Event::listen(GovernanceBodyRegistered::class,        AuditGovernanceEvent::class);
        Event::listen(GovernanceBodyUpdated::class,           AuditGovernanceEvent::class);

        // G2b — position events.
        Event::listen(GovernancePositionRegistered::class,    AuditGovernanceEvent::class);
        Event::listen(GovernancePositionUpdated::class,       AuditGovernanceEvent::class);

        // G2b — term events.
        Event::listen(GovernanceTermRegistered::class,        AuditGovernanceEvent::class);
        Event::listen(GovernanceTermUpdated::class,           AuditGovernanceEvent::class);

        // G2c — appointment events.
        Event::listen(GovernanceAppointmentRegistered::class, AuditGovernanceEvent::class);
        Event::listen(GovernanceAppointmentUpdated::class,    AuditGovernanceEvent::class);

        // G2c — membership events.
        Event::listen(GovernanceMembershipRegistered::class,  AuditGovernanceEvent::class);
        Event::listen(GovernanceMembershipUpdated::class,     AuditGovernanceEvent::class);
    }

    // =========================================================================
    // Migrations
    // =========================================================================

    /**
     * Register the domain's migration directory with the migrator.
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
     * If the routes file does not exist, the method returns silently.
     *
     * @return void
     */
    private function loadDomainRoutes(): void
    {
        if (!is_file(self::ROUTES_PATH)) {
            return;
        }

        $middleware = (array) config('governance.routes.middleware', []);
        $prefix     = (string) config('governance.routes.prefix', 'api/governance');

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
     * @return bool
     */
    private function isDomainEnabled(): bool
    {
        return (bool) config('governance.enabled', true);
    }
}