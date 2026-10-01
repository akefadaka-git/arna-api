<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use MaaleArna\MASES\Core\Domains\Wallet\Events\ObligationRecorded;
use MaaleArna\MASES\Core\Domains\Wallet\Events\PaymentAllocated;
use MaaleArna\MASES\Core\Domains\Wallet\Events\PaymentAllocationReversed;
use MaaleArna\MASES\Core\Domains\Wallet\Events\PaymentReceived;
use MaaleArna\MASES\Core\Domains\Wallet\Events\WalletAdjusted;
use MaaleArna\MASES\Core\Domains\Wallet\Events\WalletEntryReversed;
use MaaleArna\MASES\Core\Domains\Wallet\Events\WalletOpened;
use MaaleArna\MASES\Core\Domains\Wallet\Listeners\AuditWalletEvent;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Obligation;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Payment;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;
use MaaleArna\MASES\Core\Domains\Wallet\Policies\ObligationPolicy;
use MaaleArna\MASES\Core\Domains\Wallet\Policies\PaymentPolicy;
use MaaleArna\MASES\Core\Domains\Wallet\Policies\WalletPolicy;
use MaaleArna\MASES\Core\Domains\Wallet\Repositories\AllocationRepository;
use MaaleArna\MASES\Core\Domains\Wallet\Repositories\ObligationRepository;
use MaaleArna\MASES\Core\Domains\Wallet\Repositories\PaymentRepository;
use MaaleArna\MASES\Core\Domains\Wallet\Repositories\WalletRepository;
use MaaleArna\MASES\Core\Domains\Wallet\Services\AllocationService;
use MaaleArna\MASES\Core\Domains\Wallet\Services\ObligationService;
use MaaleArna\MASES\Core\Domains\Wallet\Services\PaymentService;
use MaaleArna\MASES\Core\Domains\Wallet\Services\StatementService;
use MaaleArna\MASES\Core\Domains\Wallet\Services\WalletService;

/**
 * WalletServiceProvider
 *
 * Service provider for the MASES Wallet domain.
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
 * Revision history
 * =============================================================================
 * 1.0.0  — initial delivery. Config merge, container bindings, policy
 *          registration, event-listener registration, migration loading,
 *          route loading.
 *
 * 1.0.1  — D-13 / H-20. Structural translation-loading parity with the
 *          Shareholder and Loan providers.
 *
 *          BEFORE
 *          ------
 *          This provider did not call `loadTranslationsFrom()` at any
 *          point. The Wallet domain therefore had no translation
 *          namespace registered with Laravel's translator. Every
 *          `__('wallet::...')` call — present or future — resolved to
 *          the raw key string, and any catalogue that a deployment
 *          might place under `Resources/lang/` would have been inert.
 *
 *          AFTER
 *          -----
 *          `boot()` now calls `loadDomainTranslations()` as its first
 *          statement, registering the domain's `Resources/lang/`
 *          directory under the namespace `wallet`. This mirrors the
 *          pattern established by ShareholderServiceProvider v2.1.0
 *          and LoanServiceProvider v1.0.0, both of which load their
 *          translations unconditionally, before the domain-enabled
 *          check.
 *
 *          STRUCTURAL ONLY — CATALOGUE ABSENT
 *          ----------------------------------
 *          The Wallet `Resources/lang/` directory does not currently
 *          exist on the server. This change adds the registration
 *          call; it does NOT author any catalogue files. Laravel's
 *          `loadTranslationsFrom()` records the path without
 *          validating its existence, so the call is safe when the
 *          directory is absent: the first `__('wallet::...')` lookup
 *          finds no file and returns the key string verbatim — the
 *          same diagnostic surface that existed before this change.
 *
 *          This revision is therefore a **latent parity gap fix**, not
 *          a live user-facing defect fix. It prepares the provider for
 *          a future in which Wallet catalogues are authored; it does
 *          not, by itself, restore any working translation. Actual
 *          catalogue authoring, if later required, is a separate CR.
 *
 *          WHAT DOES NOT CHANGE
 *          ---------------------
 *          · The config merge in `register()`.
 *          · All four repository and five service bindings.
 *          · All three policy registrations.
 *          · All seven event-listener registrations.
 *          · `loadDomainMigrations()` and `loadDomainRoutes()` and
 *            their guards.
 *          · The `isDomainEnabled()` helper.
 *          · The enabled/disabled semantics: translations (like
 *            policies and event listeners) are registered
 *            unconditionally, before the flag is checked, because they
 *            are data rather than behaviour.
 *
 *          No API change. No signature change. No caller modification.
 *          No test change required — no Wallet test exercises the
 *          translator (confirmed during D-13 inspection).
 *
 * =============================================================================
 * Deployment sequencing — read before registering
 * =============================================================================
 * This provider is deployed as 2B.2 but NOT registered in
 * `bootstrap/providers.php` by that delivery alone. It is registered as a
 * separate follow-up edit — ENH.1b — after every class it references has
 * been deployed, so that when the provider first boots there are no missing
 * classes.
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
 *     lazily on the first `__('wallet::...')` call. A deployment that has
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
 * `config('wallet.enabled')` (declared in `Config/wallet.php`, 2B.1) is
 * checked at boot. When false:
 *
 *   - Migrations are NOT registered — `php artisan migrate` will not touch
 *     the domain's tables.
 *
 *   - Routes are NOT registered — the domain is invisible to HTTP clients.
 *
 * Three categories of registration are performed unconditionally:
 *
 *   - Policies and event listeners, because they operate on data that may
 *     already exist from a previous enabled period, and disabling the
 *     domain should not blind the audit trail.
 *
 *   - Translations, because they are data rather than behaviour.
 *     Historical rows — audit logs, retained wallets, archived ledger
 *     entries — remain on disk and can be rendered by any consumer that
 *     reads them. The catalogue must resolve for those rows even when the
 *     domain is disabled, so that their labels render correctly. The same
 *     pattern is used by ShareholderServiceProvider and
 *     LoanServiceProvider, which load their translations before the
 *     enabled-flag check.
 *
 * This mirrors the ShareholderServiceProvider (2A.2) enable/disable
 * semantics exactly.
 *
 * =============================================================================
 * Container bindings and lifetime
 * =============================================================================
 * All four repositories and five services are bound as singletons. This
 * mirrors the pattern established by CoreServiceProvider for
 * `AuditService`, `AuthorizationService`, `SecurityEventService`, and
 * `FileService`, and by ShareholderServiceProvider (2A.2) for its five
 * services and one repository.
 *
 * The services are stateless by design — they hold no request-scoped state
 * — so a single instance per process is correct. StatementService is
 * likewise stateless: it reads from the ledger and constructs a projection
 * on demand; it caches nothing between calls.
 *
 * Do not change these to scoped or transient bindings without an approved
 * Change Request. `AllocationService` in particular relies on shared
 * repository instances to preserve any invariants accumulated within a
 * request lifecycle.
 *
 * =============================================================================
 * Policy registration
 * =============================================================================
 * MASES uses explicit policy registration rather than Laravel's convention
 * of auto-discovery. The reasoning is the same as recorded in 2A.2:
 *
 *   1. The models live under `mases_core/src/Domains/Wallet/Models/`, not
 *      under `app/Models/`, so Laravel's convention does not find them.
 *
 *   2. The policies live under the same domain tree, not under
 *      `app/Policies/`.
 *
 *   3. Registering explicitly makes the model → policy mapping visible in
 *      one place, which is consistent with how Core registers event
 *      listeners and how the Shareholder domain registers its two
 *      policies.
 *
 * Three policies are registered. The domain has six models; three do not
 * have policies:
 *
 *   PaymentAllocation          → addressed through its parent Wallet's
 *                                policy (WalletPolicy::view()). Allocations
 *                                are never addressable independently.
 *
 *   PaymentAllocationReversal  → same; addressed through the parent Wallet.
 *
 *   WalletEntry                → read-only; surfaced through the statement
 *                                (StatementController) and the wallet
 *                                resource. There is no direct entry route.
 *                                The wallet-level policy is the access
 *                                gate.
 *
 * The WalletPolicy additionally exposes a `viewStatement()` ability that
 * the StatementController authorizes against. That ability is a policy
 * concern, not a registration concern — see the policy file (2B.37).
 *
 * =============================================================================
 * Event listeners
 * =============================================================================
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
 * The seven events are:
 *
 *   WalletOpened                (2B.40)
 *   ObligationRecorded          (2B.41)
 *   PaymentReceived             (2B.42)
 *   PaymentAllocated            (2B.43)
 *   PaymentAllocationReversed   (2B.44)
 *   WalletAdjusted              (2B.45)
 *   WalletEntryReversed         (2B.46)
 *
 * =============================================================================
 * Translation loading
 * =============================================================================
 * The domain's `Resources/lang/` directory is registered with Laravel's
 * translator under the namespace `wallet`. Every `__('wallet::...')` call
 * in the domain's resources, controllers, or exceptions resolves against
 * the active locale's catalogue, with fallback to the `fallback_locale`
 * catalogue when a key is absent from the active locale.
 *
 * The registration is done via `loadTranslationsFrom()`, which records
 * the path with Laravel's translator. No `publishes()` call is made —
 * the catalogue is part of the domain's source tree, not a config
 * artefact that a deployment might override.
 *
 * CATALOGUE STATUS
 * ----------------
 * The `Resources/lang/` directory does not currently exist on disk. This
 * registration is therefore a structural pre-wiring: it makes the
 * provider ready to serve translated labels once catalogues are
 * authored. When a `__('wallet::...')` call is made and no catalogue
 * file exists, Laravel's translator returns the key string verbatim —
 * the correct diagnostic surface for a missing catalogue, and the same
 * behaviour that existed before this revision.
 *
 * Actual catalogue authoring (e.g. `am/type.php`, `en/type.php`) is a
 * separate CR. This provider does not create or reference specific
 * catalogue files; it merely registers the directory.
 *
 * =============================================================================
 * Config merge semantics
 * =============================================================================
 * `mergeConfigFrom(self::CONFIG_PATH, self::CONFIG_KEY)` merges the values
 * from `Config/wallet.php` (2B.1) under the key `wallet`. Laravel's merge
 * is shallow: if a deployment publishes its own `config/wallet.php` (a
 * CR-worthy customization), the published file's top-level keys override
 * the domain's defaults, but the domain's untouched keys remain available.
 *
 * The config file is guaranteed to exist because 2B.1 was deployed and
 * verified before this provider. If the file were somehow missing,
 * `mergeConfigFrom()` would raise a file-not-found error at boot; the
 * domain would fail loudly rather than silently operating on empty
 * configuration.
 *
 * =============================================================================
 * Route mounting
 * =============================================================================
 * The routes file (`Routes/api.php`, 2B.48) contains only the specific
 * URIs for each resource; the outer middleware stack and URI prefix are
 * applied here from configuration (`wallet.routes.middleware` and
 * `wallet.routes.prefix`, declared in 2B.1) so that operators can adjust
 * the mount point without editing the routes file.
 *
 * The default prefix is `wallets`; the default middleware stack is
 * `['api', 'auth:sanctum', 'tenant.membership']`. Both are mandatory for
 * the tenant-isolation guarantees tested by 2B.67.
 *
 * If the routes file does not yet exist — e.g. this provider was deployed
 * and registered before 2B.48 landed — the method returns silently. This
 * is the defensive guard that allows the provider to be deployed safely at
 * any point in the 2B delivery sequence.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Architecture v1.2.0 §2      — Bounded context and domain boundary
 *   Architecture v1.2.0 §13     — RBAC integration
 *   Architecture v1.2.0 §14     — Audit integration
 *   Architecture v1.2.0 §16     — Repositories and services
 *   Architecture v1.2.0 §17     — Migration ownership and structure
 *   Step 5 (manifest) 2B.2      — This file
 *   Step 5 (manifest) ENH.1b    — bootstrap/providers.php (second half)
 *   Step 6 v1.1.0 §1            — Filename ordering and prefixes
 *   Step 7 v1.1.0 §C.1          — Nine-route API structure
 *   Step 7 v1.1.0 §C.6          — Cross-tenant child-validation invariant
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Providers
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
     * (`MASES_WALLET_*`) all share a single name.
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