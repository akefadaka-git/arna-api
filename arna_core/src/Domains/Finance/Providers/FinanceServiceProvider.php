<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Finance\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use MaaleArna\Arna\Core\Domains\Finance\Repositories\LegalEntityRepository;
use MaaleArna\Arna\Core\Domains\Finance\Models\LegalEntity;
use MaaleArna\Arna\Core\Domains\Finance\Models\LedgerAccount;
use MaaleArna\Arna\Core\Domains\Finance\Models\AccountingPeriod;
use MaaleArna\Arna\Core\Domains\Finance\Models\JournalEntry;
use MaaleArna\Arna\Core\Domains\Finance\Models\JournalSourceEntityMapping;
use MaaleArna\Arna\Core\Domains\Finance\Policies\LegalEntityPolicy;
use MaaleArna\Arna\Core\Domains\Finance\Policies\LedgerAccountPolicy;
use MaaleArna\Arna\Core\Domains\Finance\Policies\AccountingPeriodPolicy;
use MaaleArna\Arna\Core\Domains\Finance\Policies\JournalEntryPolicy;
use MaaleArna\Arna\Core\Domains\Finance\Policies\JournalSourceEntityMappingPolicy;
use MaaleArna\Arna\Core\Domains\Finance\Events\LegalEntityCreated;
use MaaleArna\Arna\Core\Domains\Finance\Events\LedgerAccountCreated;
use MaaleArna\Arna\Core\Domains\Finance\Events\JournalDraftCreated;
use MaaleArna\Arna\Core\Domains\Finance\Events\JournalPosted;
use MaaleArna\Arna\Core\Domains\Finance\Events\JournalCancelled;
use MaaleArna\Arna\Core\Domains\Finance\Events\JournalReversed;
use MaaleArna\Arna\Core\Domains\Finance\Events\AccountingPeriodOpened;
use MaaleArna\Arna\Core\Domains\Finance\Events\AccountingPeriodClosed;
use MaaleArna\Arna\Core\Domains\Finance\Listeners\AuditFinanceLifecycleEvent;
use MaaleArna\Arna\Core\Domains\Finance\Listeners\PersistFinanceJournalPostedOutbox;
use MaaleArna\Arna\Core\Domains\Finance\Services\FinanceOutboxDispatcher;
use MaaleArna\Arna\Core\Domains\Finance\Services\FinanceSubledgerInboxService;
use MaaleArna\Arna\Core\Domains\Finance\Services\FinanceIntegrationHealthService;
use MaaleArna\Arna\Core\Domains\Finance\Contracts\FinanceIntelligenceProviderContract;
use MaaleArna\Arna\Core\Domains\Finance\Services\FinanceIntelligenceService;
use MaaleArna\Arna\Core\Domains\Finance\Services\UnavailableFinanceIntelligenceProvider;
use MaaleArna\Arna\Core\Domains\Finance\Services\SubledgerIntegrationService;
use MaaleArna\Arna\Core\Domains\Finance\Services\SourceLegalEntityMappingService;
use MaaleArna\Arna\Core\Domains\Finance\Contracts\SourceLegalEntityMappingContract;
use MaaleArna\Arna\Core\Domains\Finance\Contracts\FinancialReportContract;
use MaaleArna\Arna\Core\Domains\Finance\Services\LedgerAccountService;
use MaaleArna\Arna\Core\Domains\Finance\Services\FinancialReportService;
use MaaleArna\Arna\Core\Domains\Finance\Repositories\JournalEntryRepository;
use MaaleArna\Arna\Core\Domains\Finance\Repositories\AccountingPeriodRepository;
use MaaleArna\Arna\Core\Domains\Finance\Repositories\LedgerAccountRepository;
use MaaleArna\Arna\Core\Domains\Finance\Services\LegalEntityService;
use MaaleArna\Arna\Core\Domains\Finance\Services\AccountingPeriodService;
use MaaleArna\Arna\Core\Domains\Finance\Services\TrialBalanceService;
use MaaleArna\Arna\Core\Domains\Finance\Services\FinanceConsolidationService;
use MaaleArna\Arna\Core\Domains\Finance\Services\FinanceConsolidationMappingService;
use MaaleArna\Arna\Core\Domains\Finance\Services\FinanceExchangeRateService;
use MaaleArna\Arna\Core\Domains\Finance\Services\FinanceCurrencyConverter;

final class FinanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../Config/finance.php', 'finance');

        $this->app->singleton(LegalEntityRepository::class);
        $this->app->singleton(JournalEntryRepository::class);
        $this->app->singleton(AccountingPeriodRepository::class);
        $this->app->singleton(LedgerAccountRepository::class);
        $this->app->singleton(LegalEntityService::class);
        $this->app->singleton(LedgerAccountService::class);
        $this->app->singleton(AccountingPeriodService::class);
        $this->app->singleton(TrialBalanceService::class);
        $this->app->singleton(FinanceConsolidationService::class);
        $this->app->singleton(FinanceConsolidationMappingService::class);
        $this->app->singleton(FinanceExchangeRateService::class);
        $this->app->singleton(FinanceCurrencyConverter::class);
        $this->app->singleton(FinanceOutboxDispatcher::class);
        $this->app->singleton(FinanceSubledgerInboxService::class);
        $this->app->singleton(FinanceIntegrationHealthService::class);
        $this->app->singleton(FinanceIntelligenceProviderContract::class, UnavailableFinanceIntelligenceProvider::class);
        $this->app->singleton(FinanceIntelligenceService::class);
        $this->app->singleton(FinancialReportService::class);
        $this->app->singleton(FinancialReportContract::class, FinancialReportService::class);
        $this->app->singleton(SubledgerIntegrationService::class);
        $this->app->singleton(SourceLegalEntityMappingContract::class, SourceLegalEntityMappingService::class);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__ . '/../Resources/lang', 'finance');
        Gate::policy(LegalEntity::class, LegalEntityPolicy::class);
        Gate::policy(LedgerAccount::class, LedgerAccountPolicy::class);
        Gate::policy(AccountingPeriod::class, AccountingPeriodPolicy::class);
        Gate::policy(JournalEntry::class, JournalEntryPolicy::class);
        Gate::policy(JournalSourceEntityMapping::class, JournalSourceEntityMappingPolicy::class);
        Event::listen(LegalEntityCreated::class, AuditFinanceLifecycleEvent::class);
        Event::listen(LedgerAccountCreated::class, AuditFinanceLifecycleEvent::class);
        Event::listen(JournalDraftCreated::class, AuditFinanceLifecycleEvent::class);
        Event::listen(JournalPosted::class, AuditFinanceLifecycleEvent::class);
        Event::listen(JournalPosted::class, PersistFinanceJournalPostedOutbox::class);
        Event::listen(JournalCancelled::class, AuditFinanceLifecycleEvent::class);
        Event::listen(JournalReversed::class, AuditFinanceLifecycleEvent::class);
        Event::listen(AccountingPeriodOpened::class, AuditFinanceLifecycleEvent::class);
        Event::listen(AccountingPeriodClosed::class, AuditFinanceLifecycleEvent::class);

        if (!(bool) config('finance.enabled', true)) {
            return;
        }

        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
        $this->loadDomainRoutes();
    }

    private function loadDomainRoutes(): void
    {
        $routesPath = __DIR__ . '/../Routes/api.php';
        if (!is_file($routesPath)) {
            return;
        }

        $routes = (array) config('finance.routes', []);
        $middleware = (array) ($routes['middleware'] ?? ['api', 'auth:sanctum', 'tenant.membership']);
        $prefix = (string) ($routes['prefix'] ?? 'api/finance');
        $namePrefix = (string) ($routes['name_prefix'] ?? 'api.finance.');

        Route::middleware($middleware)
            ->prefix($prefix)
            ->name($namePrefix)
            ->group(static function () use ($routesPath): void {
                require $routesPath;
            });
    }
}
