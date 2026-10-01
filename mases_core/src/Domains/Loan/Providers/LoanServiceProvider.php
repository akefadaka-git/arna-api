<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use MaaleArna\MASES\Core\Domains\Loan\Console\Commands\AccrueInterestCommand;
use MaaleArna\MASES\Core\Domains\Loan\Console\Commands\DetectArrearsCommand;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanApplicationSubmitted;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanApproved;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanClosed;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanCollateralReleased;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanDisbursed;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanEnteredArrears;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanInstallmentSettled;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanPenaltyRecorded;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanRejected;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanRepaymentRecorded;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanRestructured;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanSettled;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanWrittenOff;
use MaaleArna\MASES\Core\Domains\Loan\Listeners\AuditLoanEvent;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanCollateral;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanProduct;
use MaaleArna\MASES\Core\Domains\Loan\Policies\LoanCollateralPolicy;
use MaaleArna\MASES\Core\Domains\Loan\Policies\LoanPolicy;
use MaaleArna\MASES\Core\Domains\Loan\Policies\LoanProductPolicy;
use MaaleArna\MASES\Core\Domains\Loan\Repositories\LoanAccrualRepository;
use MaaleArna\MASES\Core\Domains\Loan\Repositories\LoanCollateralRepository;
use MaaleArna\MASES\Core\Domains\Loan\Repositories\LoanInstallmentRepository;
use MaaleArna\MASES\Core\Domains\Loan\Repositories\LoanProductRepository;
use MaaleArna\MASES\Core\Domains\Loan\Repositories\LoanRepository;
use MaaleArna\MASES\Core\Domains\Loan\Services\LoanApplicationService;
use MaaleArna\MASES\Core\Domains\Loan\Services\LoanApprovalService;
use MaaleArna\MASES\Core\Domains\Loan\Services\LoanArrearsService;
use MaaleArna\MASES\Core\Domains\Loan\Services\LoanCollateralService;
use MaaleArna\MASES\Core\Domains\Loan\Services\LoanDisbursementService;
use MaaleArna\MASES\Core\Domains\Loan\Services\LoanInterestAccrualService;
use MaaleArna\MASES\Core\Domains\Loan\Services\LoanReconciliationService;
use MaaleArna\MASES\Core\Domains\Loan\Services\LoanRepaymentService;
use MaaleArna\MASES\Core\Domains\Loan\Services\LoanRestructuringService;
use MaaleArna\MASES\Core\Domains\Loan\Services\LoanScheduleService;
use MaaleArna\MASES\Core\Domains\Loan\Services\LoanStateMachine;
use MaaleArna\MASES\Core\Domains\Loan\Services\LoanWriteOffService;

/**
 * LoanServiceProvider
 *
 * The single composition root for the Loan domain. Wires every moving
 * part the domain exposes: its config, its container bindings, its
 * policies, its event listeners, its console commands, its
 * translations, its migrations, and its HTTP routes.
 *
 * =============================================================================
 * The provider's responsibilities
 * =============================================================================
 *   1. CONFIG       — merge the domain's `Config/loan.php` (A.1) into
 *                     Laravel's config under the `loan` key.
 *
 *   2. TRANSLATIONS — load the domain's `Resources/lang` directory under
 *                     the `loan` namespace, so that every
 *                     `__('loan::...')` call resolves.
 *
 *   3. BINDINGS     — register the five repositories and twelve services
 *                     as singletons.
 *
 *   4. POLICIES     — register the three `Gate::policy()` mappings.
 *
 *   5. COMMANDS     — register the domain's two scheduled console
 *                     commands (`AccrueInterestCommand`, A.87;
 *                     `DetectArrearsCommand`, A.88) with the console
 *                     kernel, so that they are invocable by name and
 *                     schedulable via `Schedule::command(...)`.
 *
 *   6. EVENTS       — register the thirteen business events against the
 *                     single `AuditLoanEvent` listener.
 *
 *   7. MIGRATIONS   — load the domain's migration directory, gated on
 *                     `config('loan.enabled')`.
 *
 *   8. ROUTES       — load the domain's routes file, wrapped in the
 *                     configured middleware stack and URL prefix, gated
 *                     on `config('loan.enabled')` and on the routes
 *                     file's presence.
 *
 * =============================================================================
 * The enable/disable semantics
 * =============================================================================
 * The domain's config (A.1) declares an `enabled` key (env
 * `MASES_LOAN_ENABLED`, default `true`). When the flag is `false`, the
 * provider SKIPS:
 *
 *   - migration loading,
 *   - route registration.
 *
 * The provider KEEPS:
 *
 *   - the config merge,
 *   - the translations,
 *   - the container bindings,
 *   - the policy registrations,
 *   - the console command registrations,
 *   - the event listener registrations.
 *
 * The asymmetry is deliberate and mirrors the enable/disable semantics
 * of `ShareholderServiceProvider` (2A.2) and `WalletServiceProvider`
 * (2B.2):
 *
 *   - A disabled domain should be invisible to `artisan migrate` and to
 *     HTTP clients. Skipping migrations and routes achieves that.
 *
 *   - A disabled domain should NOT blind the audit trail or hide its
 *     background commands. Keeping the event listeners, the container
 *     bindings, and the command registrations in place means that if
 *     the domain is re-enabled, no wiring was left dangling. And a
 *     `Schedule::command('loan:accrue-interest')` entry continues to
 *     resolve even when the domain's HTTP surface is disabled —
 *     useful for a domain that is operational-but-frozen.
 *
 * =============================================================================
 * The bindings
 * =============================================================================
 * The provider registers the following classes as singletons. Every
 * class is stateless (its dependencies are injected via the constructor
 * and never reassigned), so a singleton lifetime is safe: no
 * cross-request state leaks between instances because there IS no
 * cross-request state.
 *
 * Repositories (five):
 *
 *   LoanRepository               (A.55)
 *   LoanProductRepository        (A.56)
 *   LoanInstallmentRepository    (A.57)
 *   LoanCollateralRepository     (A.58)
 *   LoanAccrualRepository        (A.59)
 *
 * Services (twelve):
 *
 *   LoanStateMachine             (A.26)
 *   LoanScheduleService          (A.27)
 *   LoanApplicationService       (A.28)
 *   LoanApprovalService          (A.29)
 *   LoanDisbursementService      (A.30)
 *   LoanRepaymentService         (A.31)
 *   LoanInterestAccrualService   (A.32)
 *   LoanArrearsService           (A.33)
 *   LoanRestructuringService     (A.34)
 *   LoanWriteOffService          (A.35)
 *   LoanReconciliationService    (A.36)
 *   LoanCollateralService        (A.37)
 *
 * The `singleton()` binding form is used rather than `bind()` because
 * every class is stateless; Laravel's container would produce identical
 * behavior under `bind()` for these classes, but `singleton()` makes the
 * intention explicit and avoids repeated reflection on the DI graph.
 *
 * Laravel's container can resolve all of these classes by reflection
 * even without explicit bindings — every constructor dependency is a
 * concrete class with a resolvable chain. The explicit bindings are
 * declarative: they make the domain's DI surface visible at a glance and
 * give a future CR a single place to change a binding (e.g. to bind an
 * interface to a concrete implementation).
 *
 * =============================================================================
 * The policies
 * =============================================================================
 * The provider registers three `Gate::policy()` mappings:
 *
 *   Loan::class           → LoanPolicy           (A.52)
 *   LoanProduct::class    → LoanProductPolicy    (A.53)
 *   LoanCollateral::class → LoanCollateralPolicy (A.54)
 *
 * Once registered, every `Gate::authorize(...)` call in the domain's
 * controllers resolves through the corresponding policy. See the policy
 * class docblocks for the abilities each one exposes.
 *
 * =============================================================================
 * The commands
 * =============================================================================
 * The provider registers the domain's two scheduled commands:
 *
 *   AccrueInterestCommand  (A.87)  →  `loan:accrue-interest`
 *   DetectArrearsCommand   (A.88)  →  `loan:detect-arrears`
 *
 * Registration is performed via `$this->commands([...])` in the
 * provider's `boot()` method. Once registered, the commands are:
 *
 *   · Invocable by name (`php artisan loan:accrue-interest`).
 *
 *   · Invocable from tests (`$this->artisan('loan:accrue-interest')`).
 *
 *   · Schedulable from `routes/console.php` via
 *     `Schedule::command('loan:accrue-interest')`.
 *
 * Placement rationale — a design note:
 *
 *   The original design for Phase 2C placed this registration in
 *   `routes/console.php` (C.4). That placement is not viable for
 *   class-based commands in Laravel 11: `routes/console.php` is
 *   loaded by the console kernel after every provider has booted, but
 *   the only Laravel-sanctioned way to register a class-based command
 *   is via `bootstrap/app.php`'s `->withCommands([...])` or via a
 *   provider's `$this->commands([...])`. The command classes live under
 *   the Loan domain's namespace (not under `app/Console/Commands/`), so
 *   Laravel's auto-discovery does not see them; explicit registration is
 *   required.
 *
 *   Of the two viable placements, the provider's `boot()` is preferred
 *   for this domain:
 *
 *     · The provider is the domain's composition root. Every other
 *       domain-scoped binding (config, translations, repositories,
 *       services, policies, events) is registered here. Registering
 *       the commands here keeps the domain's DI surface visible in one
 *       file.
 *
 *     · `bootstrap/app.php`'s `->withCommands([...])` would scatter the
 *       Loan domain's command surface into the application bootstrap,
 *       which is otherwise platform-wide.
 *
 *   A first attempt at the `routes/console.php` placement used
 *   `Artisan::getArtisan()->resolveCommands([...])`, which fails at
 *   runtime: `getArtisan()` returns the Symfony `Application`, not
 *   Laravel's console kernel, and `resolveCommands` does not exist on
 *   the Symfony application. The attempt is recorded here so a future
 *   reader does not repeat it.
 *
 * =============================================================================
 * The event listeners
 * =============================================================================
 * The provider registers all THIRTEEN business events against the single
 * `AuditLoanEvent` listener (A.51). The registration is unconditional
 * with respect to the `enabled` flag: see §"The enable/disable
 * semantics".
 *
 * The thirteen events are (A.38–A.50):
 *
 *   LoanApplicationSubmitted   (A.38)
 *   LoanApproved               (A.39)
 *   LoanRejected               (A.40)
 *   LoanDisbursed              (A.41)
 *   LoanRepaymentRecorded      (A.42)
 *   LoanInstallmentSettled     (A.43)
 *   LoanEnteredArrears         (A.44)
 *   LoanPenaltyRecorded        (A.45)
 *   LoanRestructured           (A.46)
 *   LoanWrittenOff             (A.47)
 *   LoanSettled                (A.48)
 *   LoanClosed                 (A.49)  — RESERVED, no dispatcher in 2C
 *   LoanCollateralReleased     (A.50)
 *
 * `LoanClosed` is registered for completeness; no Phase 2C code path
 * dispatches it (see A.49's class docblock). The listener's `match(true)`
 * arm for it is present but inert.
 *
 * =============================================================================
 * The routes
 * =============================================================================
 * The provider loads `Routes/api.php` (A.84) inside a route group built
 * from the domain's config:
 *
 *     Route::middleware(config('loan.routes.middleware'))
 *          ->prefix(config('loan.routes.prefix'))
 *          ->group($routesFile);
 *
 * The config's `routes.middleware` stack applies `api`, `auth:sanctum`,
 * and `tenant.membership`; the `routes.prefix` is `loans`. See A.1's
 * class docblock for the guarantees each middleware provides.
 *
 * The load is guarded by `is_file()` so that a deployment that omits the
 * routes file (or a test fixture that loads the provider without the
 * routes) does not fail during boot.
 *
 * =============================================================================
 * The migrations
 * =============================================================================
 * The provider loads the domain's migrations from
 * `Database/Migrations/` via `loadMigrationsFrom()`. The call is a
 * single Laravel helper that registers the path; `artisan migrate` picks
 * up the migrations on the next run.
 *
 * The migrations are conventionally timestamp-prefixed
 * (`2026_09_12_200010_create_loan_products_table.php` …) so that
 * `artisan migrate` orders them correctly relative to each other and to
 * the CORE migrations that must precede them (`versioned_policies` from
 * CORE.2, and `tenants`, `users`, `shareholders` from Phase 1 / Phase
 * 2A).
 *
 * =============================================================================
 * What this provider deliberately does NOT do
 * =============================================================================
 *   - It does NOT schedule anything. The two commands are registered
 *     here, but their daily cadence is declared in `routes/console.php`
 *     (C.4).
 *
 *   - It does NOT seed anything. Permissions and roles are seeded by
 *     `LoanPermissionsSeeder` (A.86) and `LoanRolesSeeder` (A.87).
 *
 *   - It does NOT register the domain's listeners against the Wallet
 *     domain's events, or vice versa. Cross-domain event wiring is
 *     intentionally absent: the two domains communicate through the
 *     Wallet's services, not through each other's events.
 *
 *   - It does NOT declare a `provides()` method. The domain's bindings
 *     are all concrete classes; there are no deferred providers to
 *     advertise.
 *
 *   - It does NOT register the `Policy` abilities as gates. The policies
 *     are registered against model classes via `Gate::policy()`, which
 *     is the standard Laravel authorization surface. A future CR that
 *     needs a permission-only gate (e.g. `loan.apply` used by the
 *     `LoanController::store()` action) registers that gate at the
 *     platform level, not here.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — B7 (RBAC), C4, C5
 *   Phase 2C Clarification Response, 2026-09-12  — C1–C5
 *   Phase 2C A.1                                 — `Config/loan.php`
 *   Phase 2C A.26–A.37                           — the twelve services
 *   Phase 2C A.38–A.50                           — the thirteen events
 *   Phase 2C A.51                                — `AuditLoanEvent`
 *   Phase 2C A.52–A.54                           — the three policies
 *   Phase 2C A.55–A.59                           — the five repositories
 *   Phase 2C A.84                                — `Routes/api.php`
 *   Phase 2C A.87                                — `AccrueInterestCommand`
 *   Phase 2C A.88                                — `DetectArrearsCommand`
 *   Phase 2C C.2                                 — the platform's
 *                                                  `bootstrap/providers.php`
 *                                                  modification that
 *                                                  registers this
 *                                                  provider
 *   Phase 2C C.4                                 — the platform's
 *                                                  `routes/console.php`
 *                                                  modification that
 *                                                  schedules the two
 *                                                  commands
 *   Phase 2A 2A.2                                — `ShareholderServiceProvider`
 *                                                  (the shape precedent)
 *   Phase 2B 2B.2                                — `WalletServiceProvider`
 *                                                  (the direct sibling)
 *   Step 5 (manifest) A.85                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Providers
 * @version 1.0.1
 */
final class LoanServiceProvider extends ServiceProvider
{
    /**
     * The config key under which the domain's config is merged.
     *
     * Matches the env-var prefix (`MASES_LOAN_*`) and the translation
     * namespace (`loan::*`).
     */
    private const CONFIG_KEY = 'loan';

    /**
     * The translation namespace under which the domain's translations
     * are loaded.
     *
     * Matches the config key and the `__('loan::...')` call sites
     * scattered across the domain's requests, resources, and controllers.
     */
    private const TRANSLATION_NAMESPACE = 'loan';

    /**
     * The event classes the provider registers against the single
     * `AuditLoanEvent` listener.
     *
     * Declared as a private constant so that the registration loop and
     * any future test that enumerates the registered listeners read from
     * a single source of truth. See the class docblock §"The event
     * listeners" for the list's composition.
     *
     * @var list<class-string>
     */
    private const AUDITED_EVENTS = [
        LoanApplicationSubmitted::class,
        LoanApproved::class,
        LoanRejected::class,
        LoanDisbursed::class,
        LoanRepaymentRecorded::class,
        LoanInstallmentSettled::class,
        LoanEnteredArrears::class,
        LoanPenaltyRecorded::class,
        LoanRestructured::class,
        LoanWrittenOff::class,
        LoanSettled::class,
        LoanClosed::class,
        LoanCollateralReleased::class,
    ];

    /**
     * The repository classes the provider binds as singletons.
     *
     * @var list<class-string>
     */
    private const REPOSITORIES = [
        LoanRepository::class,
        LoanProductRepository::class,
        LoanInstallmentRepository::class,
        LoanCollateralRepository::class,
        LoanAccrualRepository::class,
    ];

    /**
     * The service classes the provider binds as singletons.
     *
     * @var list<class-string>
     */
    private const SERVICES = [
        LoanStateMachine::class,
        LoanScheduleService::class,
        LoanApplicationService::class,
        LoanApprovalService::class,
        LoanDisbursementService::class,
        LoanRepaymentService::class,
        LoanInterestAccrualService::class,
        LoanArrearsService::class,
        LoanRestructuringService::class,
        LoanWriteOffService::class,
        LoanReconciliationService::class,
        LoanCollateralService::class,
    ];

    /**
     * The console command classes the provider registers with the
     * console kernel.
     *
     * See the class docblock §"The commands" for the placement rationale
     * and the history of the failed `routes/console.php` attempt.
     *
     * @var list<class-string>
     */
    private const COMMANDS = [
        AccrueInterestCommand::class,
        DetectArrearsCommand::class,
    ];

    // =========================================================================
    // Register
    // =========================================================================

    /**
     * Register the domain's container bindings and merge its config.
     *
     * Called by Laravel during the container's registration phase, before
     * the application's boot phase. The bindings are declared here — not
     * in `boot()` — because Laravel resolves bindings during the
     * registration phase and a listener or command that runs early in
     * boot may already need them.
     *
     * The config merge is also done here (via `mergeConfigFrom()`), so
     * that any `config('loan.*')` call in the application's boot phase
     * finds the domain's values.
     *
     * @return void
     */
    public function register(): void
    {
        // ---------------------------------------------------------------------
        // 1. Config merge.
        // ---------------------------------------------------------------------
        $this->mergeConfigFrom(
            __DIR__ . '/../Config/loan.php',
            self::CONFIG_KEY,
        );

        // ---------------------------------------------------------------------
        // 2. Container bindings.
        //
        // Every repository and service is stateless and safe as a
        // singleton. See the class docblock §"The bindings".
        // ---------------------------------------------------------------------
        foreach (self::REPOSITORIES as $repository) {
            $this->app->singleton($repository);
        }

        foreach (self::SERVICES as $service) {
            $this->app->singleton($service);
        }
    }

    // =========================================================================
    // Boot
    // =========================================================================

    /**
     * Boot the domain's non-binding wiring.
     *
     * Called by Laravel during the application's boot phase, after every
     * provider's `register()` method has run. The order of the steps
     * below matches the order of the responsibilities documented on the
     * class docblock.
     *
     * @return void
     */
    public function boot(): void
    {
        // ---------------------------------------------------------------------
        // 1. Translations.
        //
        // Loads the domain's locale files under the `loan` namespace, so
        // that every `__('loan::...')` call resolves. The call is safe
        // if the directory does not exist (Laravel's
        // `loadTranslationsFrom` is a no-op for a missing directory).
        // ---------------------------------------------------------------------
        $this->loadTranslationsFrom(
            __DIR__ . '/../Resources/lang',
            self::TRANSLATION_NAMESPACE,
        );

        // ---------------------------------------------------------------------
        // 2. Policies.
        //
        // Registered unconditionally: a disabled domain still needs its
        // authorization surface to be consistent, so that a re-enable
        // does not leave a policy unresolved.
        // ---------------------------------------------------------------------
        $this->registerPolicies();

        // ---------------------------------------------------------------------
        // 3. Console commands.
        //
        // Registers the domain's two scheduled commands with the console
        // kernel, so that they are invocable by name and schedulable via
        // `Schedule::command(...)` in `routes/console.php`. Registered
        // unconditionally, like the policies and the event listeners —
        // see the class docblock §"The enable/disable semantics".
        // ---------------------------------------------------------------------
        $this->commands(self::COMMANDS);

        // ---------------------------------------------------------------------
        // 4. Event listeners.
        //
        // Registered unconditionally for the same reason as the
        // policies. See the class docblock §"The enable/disable
        // semantics".
        // ---------------------------------------------------------------------
        $this->registerEventListeners();

        // ---------------------------------------------------------------------
        // 5. Migrations and routes.
        //
        // Both are gated on the `enabled` config flag. See the class
        // docblock §"The enable/disable semantics".
        // ---------------------------------------------------------------------
        if (!(bool) config(self::CONFIG_KEY . '.enabled', true)) {
            return;
        }

        $this->loadDomainMigrations();
        $this->loadDomainRoutes();
    }

    // =========================================================================
    // Private — policy registration
    // =========================================================================

    /**
     * Register the domain's three policies against their models.
     *
     * @return void
     */
    private function registerPolicies(): void
    {
        Gate::policy(Loan::class, LoanPolicy::class);
        Gate::policy(LoanProduct::class, LoanProductPolicy::class);
        Gate::policy(LoanCollateral::class, LoanCollateralPolicy::class);
    }

    // =========================================================================
    // Private — event listener registration
    // =========================================================================

    /**
     * Register the thirteen business events against the single
     * `AuditLoanEvent` listener.
     *
     * @return void
     */
    private function registerEventListeners(): void
    {
        foreach (self::AUDITED_EVENTS as $eventClass) {
            Event::listen($eventClass, AuditLoanEvent::class);
        }
    }

    // =========================================================================
    // Private — migration loading
    // =========================================================================

    /**
     * Load the domain's migrations.
     *
     * The migrations are conventionally timestamp-prefixed so that
     * `artisan migrate` orders them correctly relative to each other and
     * to the CORE and Phase-1 / Phase-2A migrations they depend on.
     *
     * @return void
     */
    private function loadDomainMigrations(): void
    {
        $migrationsPath = __DIR__ . '/../Database/Migrations';

        if (!is_dir($migrationsPath)) {
            return;
        }

        $this->loadMigrationsFrom($migrationsPath);
    }

    // =========================================================================
    // Private — route loading
    // =========================================================================

    /**
     * Load the domain's routes under the configured middleware stack and
     * URL prefix.
     *
     * The routes file is guarded by `is_file()` so that a deployment that
     * omits it does not fail during boot. The middleware stack and URL
     * prefix are read from the domain's config (A.1); see that file's
     * class docblock for the guarantees each entry provides.
     *
     * @return void
     */
    private function loadDomainRoutes(): void
    {
        $routesPath = __DIR__ . '/../Routes/api.php';

        if (!is_file($routesPath)) {
            return;
        }

        /** @var list<string> $middleware */
        $middleware = (array) config(
            self::CONFIG_KEY . '.routes.middleware',
            ['api', 'auth:sanctum', 'tenant.membership'],
        );

        $prefix = (string) config(
            self::CONFIG_KEY . '.routes.prefix',
            'loans',
        );

        Route::middleware($middleware)
            ->prefix($prefix)
            ->group($routesPath);
    }
}