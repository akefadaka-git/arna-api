<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use MaaleArna\MASES\Core\AI\Contracts\ModelGatewayContract;
use MaaleArna\MASES\Core\AI\Contracts\ToolPermissionContract;
use MaaleArna\MASES\Core\AI\Contracts\DomainEvidenceSourceContract;
use MaaleArna\MASES\Core\AI\Services\DisabledModelGateway;
use MaaleArna\MASES\Core\AI\Services\DenyAllToolPermission;
use MaaleArna\MASES\Core\AI\Services\UnavailableDomainEvidenceSource;
use MaaleArna\MASES\Core\AI\Services\PermissionAwareRetrievalService;
use MaaleArna\MASES\Core\AI\Registry\AgentDefinition;
use MaaleArna\MASES\Core\AI\Registry\AgentRegistry;
use MaaleArna\MASES\Core\AI\Services\ModelExecutionPolicy;
use MaaleArna\MASES\Core\Domains\Finance\Repositories\LegalEntityRepository;
use MaaleArna\MASES\Core\Domains\Finance\Models\LegalEntity;
use MaaleArna\MASES\Core\Domains\Finance\Models\LedgerAccount;
use MaaleArna\MASES\Core\Domains\Finance\Models\AccountingPeriod;
use MaaleArna\MASES\Core\Domains\Finance\Models\JournalEntry;
use MaaleArna\MASES\Core\Domains\Finance\Models\JournalSourceEntityMapping;
use MaaleArna\MASES\Core\Domains\Finance\Policies\LegalEntityPolicy;
use MaaleArna\MASES\Core\Domains\Finance\Policies\LedgerAccountPolicy;
use MaaleArna\MASES\Core\Domains\Finance\Policies\AccountingPeriodPolicy;
use MaaleArna\MASES\Core\Domains\Finance\Policies\JournalEntryPolicy;
use MaaleArna\MASES\Core\Domains\Finance\Policies\JournalSourceEntityMappingPolicy;
use MaaleArna\MASES\Core\Domains\Finance\Events\LegalEntityCreated;
use MaaleArna\MASES\Core\Domains\Finance\Events\LedgerAccountCreated;
use MaaleArna\MASES\Core\Domains\Finance\Events\JournalDraftCreated;
use MaaleArna\MASES\Core\Domains\Finance\Events\JournalPosted;
use MaaleArna\MASES\Core\Domains\Finance\Events\JournalCancelled;
use MaaleArna\MASES\Core\Domains\Finance\Events\JournalReversed;
use MaaleArna\MASES\Core\Domains\Finance\Events\AccountingPeriodOpened;
use MaaleArna\MASES\Core\Domains\Finance\Events\AccountingPeriodClosed;
use MaaleArna\MASES\Core\Domains\Finance\Listeners\AuditFinanceLifecycleEvent;
use MaaleArna\MASES\Core\Domains\Finance\Listeners\PersistFinanceJournalPostedOutbox;
use MaaleArna\MASES\Core\Domains\Finance\Services\FinanceOutboxDispatcher;
use MaaleArna\MASES\Core\Domains\Finance\Services\FinanceSubledgerInboxService;
use MaaleArna\MASES\Core\Domains\Finance\Services\FinanceIntegrationHealthService;
use MaaleArna\MASES\Core\Domains\Finance\Contracts\FinanceIntelligenceProviderContract;
use MaaleArna\MASES\Core\Domains\Finance\Services\FinanceIntelligenceService;
use MaaleArna\MASES\Core\Domains\Finance\Services\UnavailableFinanceIntelligenceProvider;
use MaaleArna\MASES\Core\Domains\Finance\Services\SubledgerIntegrationService;
use MaaleArna\MASES\Core\Domains\Finance\Services\SourceLegalEntityMappingService;
use MaaleArna\MASES\Core\Domains\Finance\Contracts\SourceLegalEntityMappingContract;
use MaaleArna\MASES\Core\Domains\Finance\Contracts\FinancialReportContract;
use MaaleArna\MASES\Core\Domains\Finance\Services\LedgerAccountService;
use MaaleArna\MASES\Core\Domains\Finance\Services\FinancialReportService;
use MaaleArna\MASES\Core\Domains\Finance\Repositories\JournalEntryRepository;
use MaaleArna\MASES\Core\Domains\Finance\Repositories\AccountingPeriodRepository;
use MaaleArna\MASES\Core\Domains\Finance\Repositories\LedgerAccountRepository;
use MaaleArna\MASES\Core\Domains\Finance\Services\LegalEntityService;
use MaaleArna\MASES\Core\Domains\Finance\Services\AccountingPeriodService;
use MaaleArna\MASES\Core\Domains\Finance\Services\TrialBalanceService;
use MaaleArna\MASES\Core\Domains\Finance\Services\FinanceConsolidationService;
use MaaleArna\MASES\Core\Domains\Finance\Services\FinanceConsolidationMappingService;
use MaaleArna\MASES\Core\Domains\Finance\Services\FinanceExchangeRateService;
use MaaleArna\MASES\Core\Domains\Finance\Services\FinanceCurrencyConverter;

final class FinanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../Config/finance.php', 'finance');
        $this->mergeConfigFrom(base_path('config/arna_ai.php'), 'arna_ai');

        // ARNA AI v1 foundation: fail-closed provider, tools and retrieval.
        $this->app->singleton(ModelGatewayContract::class, DisabledModelGateway::class);
        $this->app->singleton(ToolPermissionContract::class, DenyAllToolPermission::class);
        $this->app->singleton(DomainEvidenceSourceContract::class, UnavailableDomainEvidenceSource::class);
        $this->app->singleton(AgentRegistry::class, static fn (): AgentRegistry => new AgentRegistry([
            new AgentDefinition('executive_intelligence', 'Executive Intelligence', 'Governed executive analysis and decision support.', [], ['governance', 'organization', 'finance']),
            new AgentDefinition('finance_intelligence', 'Finance Intelligence', 'Read-only financial analysis and evidence summaries.', [], ['finance']),
            new AgentDefinition('governance_intelligence', 'Governance Intelligence', 'Governance records and policy analysis.', [], ['governance', 'documents']),
            new AgentDefinition('people_intelligence', 'People Intelligence', 'Permission-scoped people and workforce insights.', [], ['people']),
            new AgentDefinition('document_intelligence', 'Document Intelligence', 'Document understanding with source traceability.', [], ['documents', 'files']),
            new AgentDefinition('communication_intelligence', 'Communication Intelligence', 'Communication drafting and summarization.', [], ['communication']),
            new AgentDefinition('operations_intelligence', 'Operations Intelligence', 'Operational analysis and exception summaries.', [], ['operations']),
            new AgentDefinition('analytics_intelligence', 'Analytics Intelligence', 'Cross-domain analytics from authorized evidence.', [], ['analytics']),
            new AgentDefinition('calendar_planning_intelligence', 'Calendar and Planning Intelligence', 'Calendar-aware planning and summaries.', [], ['calendar', 'tasks']),
        ]));
        $this->app->singleton(PermissionAwareRetrievalService::class);
        $this->app->singleton(ModelExecutionPolicy::class);

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
