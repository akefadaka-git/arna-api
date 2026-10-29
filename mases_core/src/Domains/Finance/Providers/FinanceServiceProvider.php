<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
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
use MaaleArna\MASES\Core\Domains\Finance\Services\SubledgerIntegrationService;
use MaaleArna\MASES\Core\Domains\Finance\Services\SourceLegalEntityMappingService;
use MaaleArna\MASES\Core\Domains\Finance\Contracts\SourceLegalEntityMappingContract;
use MaaleArna\MASES\Core\Domains\Finance\Services\LedgerAccountService;
use MaaleArna\MASES\Core\Domains\Finance\Services\FinancialReportService;
use MaaleArna\MASES\Core\Domains\Finance\Services\CashFlowReportService;
use MaaleArna\MASES\Core\Domains\Finance\Contracts\CashFlowReportContract;
use MaaleArna\MASES\Core\Domains\Finance\Repositories\JournalEntryRepository;
use MaaleArna\MASES\Core\Domains\Finance\Repositories\AccountingPeriodRepository;
use MaaleArna\MASES\Core\Domains\Finance\Repositories\LedgerAccountRepository;
use MaaleArna\MASES\Core\Domains\Finance\Services\LegalEntityService;
use MaaleArna\MASES\Core\Domains\Finance\Services\AccountingPeriodService;
use MaaleArna\MASES\Core\Domains\Finance\Services\TrialBalanceService;

final class FinanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LegalEntityRepository::class);
        $this->app->singleton(JournalEntryRepository::class);
        $this->app->singleton(AccountingPeriodRepository::class);
        $this->app->singleton(LedgerAccountRepository::class);
        $this->app->singleton(LegalEntityService::class);
        $this->app->singleton(LedgerAccountService::class);
        $this->app->singleton(AccountingPeriodService::class);
        $this->app->singleton(TrialBalanceService::class);
        $this->app->singleton(FinancialReportService::class);
        $this->app->singleton(CashFlowReportContract::class, CashFlowReportService::class);
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
        Event::listen(JournalCancelled::class, AuditFinanceLifecycleEvent::class);
        Event::listen(JournalReversed::class, AuditFinanceLifecycleEvent::class);
        Event::listen(AccountingPeriodOpened::class, AuditFinanceLifecycleEvent::class);
        Event::listen(AccountingPeriodClosed::class, AuditFinanceLifecycleEvent::class);
    }
}
