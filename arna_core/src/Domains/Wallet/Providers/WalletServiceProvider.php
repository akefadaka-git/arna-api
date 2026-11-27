<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Wallet\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use MaaleArna\Arna\Core\Domains\Wallet\Events\ObligationRecorded;
use MaaleArna\Arna\Core\Domains\Wallet\Events\PaymentAllocated;
use MaaleArna\Arna\Core\Domains\Wallet\Events\PaymentAllocationReversed;
use MaaleArna\Arna\Core\Domains\Wallet\Events\PaymentReceived;
use MaaleArna\Arna\Core\Domains\Wallet\Events\WalletAdjusted;
use MaaleArna\Arna\Core\Domains\Wallet\Events\WalletEntryReversed;
use MaaleArna\Arna\Core\Domains\Wallet\Events\WalletOpened;
use MaaleArna\Arna\Core\Domains\Wallet\Listeners\AuditWalletEvent;
use MaaleArna\Arna\Core\Domains\Wallet\Models\Obligation;
use MaaleArna\Arna\Core\Domains\Wallet\Models\Payment;
use MaaleArna\Arna\Core\Domains\Wallet\Models\Wallet;
use MaaleArna\Arna\Core\Domains\Wallet\Policies\ObligationPolicy;
use MaaleArna\Arna\Core\Domains\Wallet\Policies\PaymentPolicy;
use MaaleArna\Arna\Core\Domains\Wallet\Policies\WalletPolicy;
use MaaleArna\Arna\Core\Domains\Wallet\Repositories\AllocationRepository;
use MaaleArna\Arna\Core\Domains\Wallet\Repositories\ObligationRepository;
use MaaleArna\Arna\Core\Domains\Wallet\Repositories\PaymentRepository;
use MaaleArna\Arna\Core\Domains\Wallet\Repositories\WalletRepository;
use MaaleArna\Arna\Core\Domains\Wallet\Services\AllocationService;
use MaaleArna\Arna\Core\Domains\Wallet\Services\ObligationService;
use MaaleArna\Arna\Core\Domains\Wallet\Services\PaymentService;
use MaaleArna\Arna\Core\Domains\Wallet\Services\StatementService;
use MaaleArna\Arna\Core\Domains\Wallet\Services\WalletService;

/**
 * WalletServiceProvider
 *
 * Service provider for the ARNA Wallet domain.
 *
 * =============================================================================
 * Responsibility
 * =============================================================================
 * This provider is the single integration point between the Wallet domain
 * and the Laravel application. It is responsible for:
 *
 *   1. Merging the domain configuration (`Config/wallet.php`, 2B.1) into the
 *      application container under the key `wallet`.
 *
 *   2. Loading the domain's translation catalogue from `Resources/lang/`
 *      under the namespace `wallet`, so that every `__('wallet::...')`
 *      call resolves. See §"Translation loading" below.
 *
 *   3. Binding the domain's four repositories and five services as
 *      singletons, so that within a single request every consumer receives
 *      the same instance.
 *
 *   4. Registering the domain's three authorization policies so that
 *      Gate::authorize('view', $wallet) resolves to WalletPolicy,
 *      Gate::authorize('view', $obligation) resolves to ObligationPolicy,
 *      and Gate::authorize('view', $payment) resolves to PaymentPolicy.
 *
 *   5. Wiring the domain's seven business events to their single audit
 *      listener, AuditWalletEvent. That listener translates each event into
 *      a row in the platform's `audit_logs` table via the Core AuditService.
 *
 *   6. Loading the domain's migrations from `Database/Migrations/` when the
 *      domain is enabled.
 *
 *   7. Loading the domain's routes from `Routes/api.php` when the domain is
 *      enabled and the file exists.
 *
 * =============================================================================
 * Registers Wallet config, bindings, policies, events, migrations and routes.
 * @package MaaleArna\Arna\Core\Domains\Wallet\Providers
 * @version 1.0.1
 */
final class WalletServiceProvider extends ServiceProvider
{
    /**
     * Config key under which the domain configuration is merged.
     */
    private const CONFIG_KEY = 'wallet';

    /**
     * Absolute path to the domain configuration file (2B.1).
     */
    private const CONFIG_PATH = __DIR__ . '/../Config/wallet.php';

    /**
     * Translation namespace under which the domain's catalogue is
     * registered with Laravel's translator.
     *
     * Matches the `wallet::` prefix every `__()` call in the domain's
     * resources uses, and matches the CONFIG_KEY so that the config
     * surface, the translation namespace, and the env-var prefix
     * (`ARNA_WALLET_*`) all share a single name.
     */
    private const TRANSLATION_NAMESPACE = 'wallet';

    /**
     * Absolute path to the domain translations directory.
     *
     * Contains one subdirectory per locale (`am/`, `en/`), each holding
     * one file per catalogue group.
     *
     * The directory is not required to exist at the time this provider
     * boots. Laravel's `loadTranslationsFrom()` records the path and
     * consults it lazily on the first `__('wallet::...')` call. See the
     * class docblock §"Translation loading" for the full rationale.
     */
    private const TRANSLATIONS_PATH = __DIR__ . '/../Resources/lang';

    /**
     * Absolute path to the domain migrations directory (2B.49–2B.54).
     */
    private const MIGRATIONS_PATH = __DIR__ . '/../Database/Migrations';

    /**
     * Absolute path to the domain routes file (2B.48).
     */
    private const ROUTES_PATH = __DIR__ . '/../Routes/api.php';

    // =========================================================================
    // Lifecycle
    // =========================================================================

    /**
     * Register any Wallet-domain services.
     *
     * Runs before `boot()`, during the container's registration phase. It
     * merges the configuration (so that `config('wallet.*')` is available
     * to every later provider) and records the container bindings.
     *
     * Nothing in this method touches the filesystem except the config file,
     * which is guaranteed to exist because 2B.1 was deployed and verified.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, self::CONFIG_KEY);

        $this->registerBindings();
    }

    /**
     * Bootstrap any Wallet-domain services.
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
     * Four repositories and five services are bound. The dependency graph
     * among them is acyclic (see Architecture §16.2):
     *
     *   WalletService       → WalletRepository
     *   ObligationService   → WalletRepository, ObligationRepository
     *   PaymentService      → WalletRepository, PaymentRepository
     *   AllocationService   → WalletRepository, ObligationRepository,
     *                         PaymentRepository, AllocationRepository
     *   StatementService    → WalletRepository, ObligationRepository,
     *                         PaymentRepository, AllocationRepository
     *
     * Because every binding is a singleton and the container resolves
     * constructor dependencies lazily, the order of the `singleton()` calls
     * below is presentational only — it does not affect resolution.
     *
     * @return void
     */
    private function registerBindings(): void
    {
        // Repositories.
        $this->app->singleton(WalletRepository::class);
        $this->app->singleton(ObligationRepository::class);
        $this->app->singleton(PaymentRepository::class);
        $this->app->singleton(AllocationRepository::class);

        // Services.
        $this->app->singleton(WalletService::class);
        $this->app->singleton(ObligationService::class);
        $this->app->singleton(PaymentService::class);
        $this->app->singleton(AllocationService::class);
        $this->app->singleton(StatementService::class);
    }

    // =========================================================================
    // Translations
    // =========================================================================

    /**
     * Register the domain's translation catalogue with Laravel's
     * translator.
     *
     * The directory under `Resources/lang/` is recorded under the
     * namespace `wallet`. Once registered, every `__('wallet::...')`
     * call resolves against the active locale's file, with fallback to
     * the `fallback_locale` catalogue when a key is absent from the
     * active locale.
     *
     * The call is safe when the directory does not exist: Laravel's
     * `loadTranslationsFrom()` records the path and consults it lazily
     * on the first lookup. A missing catalogue therefore produces the
     * raw translation key in the response, which is the correct
     * diagnostic surface — the same behaviour that was visible before
     * this method existed, and now a signal of a missing file rather
     * than a missing registration.
     *
     * No `publishes()` call is made. The catalogue is part of the
     * domain's source tree; there is no per-deployment override for it,
     * and none is anticipated by the current manifest.
     *
     * The call is deliberately unconditional with respect to the
     * domain-enabled flag. See the class docblock §"Domain
     * enable/disable semantics" for the rationale.
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
     * Three policies are registered, one per addressable aggregate:
     *
     *   Wallet       → WalletPolicy       (2B.37)
     *   Obligation   → ObligationPolicy   (2B.38)
     *   Payment      → PaymentPolicy      (2B.39)
     *
     * The remaining three models — PaymentAllocation,
     * PaymentAllocationReversal, WalletEntry — are deliberately not mapped.
     * They are addressed through the parent Wallet's policy; see the class
     * docblock §"Policy registration" for the rationale.
     *
     * @return void
     */
    private function registerPolicies(): void
    {
        Gate::policy(Wallet::class,     WalletPolicy::class);
        Gate::policy(Obligation::class, ObligationPolicy::class);
        Gate::policy(Payment::class,    PaymentPolicy::class);
    }

    // =========================================================================
    // Event listeners
    // =========================================================================

    /**
     * Register the domain's event listeners.
     *
     * All seven domain events are handled by a single listener,
     * `AuditWalletEvent` (2B.47), which writes each event to the platform
     * `audit_logs` table via the Core `AuditService`. The listener
     * distinguishes the event type internally from the event instance it
     * receives.
     *
     * Registration is explicit rather than relying on Laravel's listener
     * auto-discovery because the listener lives under the domain tree, not
     * under `app/Listeners/`.
     *
     * The mapping mirrors Architecture §14.1 verbatim.
     *
     * @return void
     */
    private function registerEventListeners(): void
    {
        Event::listen(WalletOpened::class,              AuditWalletEvent::class);
        Event::listen(ObligationRecorded::class,        AuditWalletEvent::class);
        Event::listen(PaymentReceived::class,           AuditWalletEvent::class);
        Event::listen(PaymentAllocated::class,          AuditWalletEvent::class);
        Event::listen(PaymentAllocationReversed::class, AuditWalletEvent::class);
        Event::listen(WalletAdjusted::class,            AuditWalletEvent::class);
        Event::listen(WalletEntryReversed::class,       AuditWalletEvent::class);
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
     * Migration filename prefixes guarantee cross-provider ordering: the
     * Core Policy migration (`100001`, CORE.2) sorts before every Wallet
     * migration (`100010`–`100060`), so the `wallets.*_policy_version_id`
     * FKs resolve regardless of provider registration order. See Step 6
     * v1.1.0 §1 for the full filename sequence.
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
     * The routes file (`Routes/api.php`, 2B.48) contains only the specific
     * URIs for each resource; the outer middleware stack and URI prefix are
     * applied here from configuration so that operators can adjust the
     * mount point without editing the routes file.
     *
     * If the routes file does not yet exist — e.g. this provider was
     * deployed and registered before 2B.48 landed — the method returns
     * silently. This is the defensive guard that allows the provider to be
     * deployed safely at any point in the 2B delivery sequence.
     *
     * The middleware stack and prefix are read from
     * `wallet.routes.middleware` and `wallet.routes.prefix` respectively,
     * both declared in `Config/wallet.php` (2B.1). The defaults declared
     * there are `['api', 'auth:sanctum', 'tenant.membership']` and
     * `'wallets'` — the same stack the Shareholder domain uses, adapted to
     * the wallet resource.
     *
     * @return void
     */
    private function loadDomainRoutes(): void
    {
        if (!is_file(self::ROUTES_PATH)) {
            return;
        }

        $middleware = (array) config('wallet.routes.middleware', []);
        $prefix     = (string) config('wallet.routes.prefix', 'wallets');

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
     * The value is read from `config('wallet.enabled')`, which is declared
     * in `Config/wallet.php` (2B.1) and defaulted to `true`. The default
     * parameter in the call covers the theoretical case where the provider
     * has been registered before the config file was merged.
     *
     * @return bool
     */
    private function isDomainEnabled(): bool
    {
        return (bool) config('wallet.enabled', true);
    }
}