<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Finance\Models\AccountingPeriod;
use MaaleArna\MASES\Core\Domains\Finance\Models\JournalEntry;
use MaaleArna\MASES\Core\Domains\Finance\Models\LedgerAccount;
use MaaleArna\MASES\Core\Domains\Finance\Models\LegalEntity;
use MaaleArna\MASES\Core\Domains\Finance\Http\Requests\StoreLegalEntityRequest;
use MaaleArna\MASES\Core\Domains\Finance\Http\Requests\ReverseJournalRequest;
use MaaleArna\MASES\Core\Domains\Finance\Http\Requests\CancelJournalRequest;
use MaaleArna\MASES\Core\Domains\Finance\Http\Requests\StoreJournalRequest;
use MaaleArna\MASES\Core\Domains\Finance\Http\Requests\StoreAccountingPeriodRequest;
use MaaleArna\MASES\Core\Domains\Finance\Http\Requests\StoreLedgerAccountRequest;
use MaaleArna\MASES\Core\Domains\Finance\Services\JournalEntryService;
use MaaleArna\MASES\Core\Domains\Finance\Services\LegalEntityService;
use MaaleArna\MASES\Core\Domains\Finance\Services\TrialBalanceService;
use MaaleArna\MASES\Core\Domains\Finance\Services\AccountingPeriodService;
use MaaleArna\MASES\Core\Domains\Finance\Repositories\LegalEntityRepository;
use MaaleArna\MASES\Core\Domains\Finance\Repositories\JournalEntryRepository;
use MaaleArna\MASES\Core\Domains\Finance\Repositories\AccountingPeriodRepository;
use MaaleArna\MASES\Core\Domains\Finance\Repositories\LedgerAccountRepository;
use MaaleArna\MASES\Core\Domains\Finance\Http\Resources\LegalEntityResource;

use MaaleArna\MASES\Core\Tenancy\Services\TenantContextService;

final class FinanceController extends Controller
{
    public function __construct(
        private readonly TenantContextService $tenantContext,
        private readonly LegalEntityRepository $entitiesRepository,
        private readonly LegalEntityService $legalEntities,
        private readonly JournalEntryService $journals,
        private readonly TrialBalanceService $trialBalance,
        private readonly LedgerAccountRepository $ledgerAccounts,
        private readonly AccountingPeriodRepository $accountingPeriods,
        private readonly AccountingPeriodService $accountingPeriodService,
        private readonly JournalEntryRepository $journalEntries,
    ) {
    }

    public function entities(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->entitiesRepository
            ->paginateForTenant($this->tenantId(), $this->perPage($request))]);
    }

    public function createEntity(StoreLegalEntityRequest $request): JsonResponse
    {
        $entity = $this->legalEntities->create(
            $this->tenantId(),
            $request->validated(),
        );

        return response()->json(['success' => true, 'data' => LegalEntityResource::make($entity)->resolve($request)], 201);
    }

    public function accounts(Request $request): JsonResponse
    {
        $data = $request->validate(['legal_entity_id' => ['required', 'integer']]);
        $entity = $this->assertEntityExists((int) $data['legal_entity_id']);

        return response()->json(['success' => true, 'data' => $this->ledgerAccounts->paginateForEntity(
            $this->tenantId(), (int) $entity->id, $this->perPage($request),
        )]);
    }

    public function createAccount(StoreLedgerAccountRequest $request): JsonResponse
    {
        $data = $request->validated();
        $tenantId = $this->tenantId();
        $entity = $this->assertEntityExists((int) $data['legal_entity_id']);
        if (($data['parent_id'] ?? null) !== null) {
            $parent = LedgerAccount::query()->where('tenant_id', $tenantId)
                ->where('legal_entity_id', $entity->id)->findOrFail((int) $data['parent_id']);
            if ($parent->account_type !== $data['account_type']) {
                throw ValidationException::withMessages(['parent_id' => 'Parent and child accounts must use the same account type.']);
            }
        }

        $account = LedgerAccount::query()->create([
            ...$data,
            'tenant_id' => $tenantId,
            'legal_entity_id' => $entity->id,
            'code' => strtoupper($data['code']),
            'status' => 'active',
            'is_posting_allowed' => $data['is_posting_allowed'] ?? true,
        ]);
        return response()->json(['success' => true, 'data' => $account], 201);
    }

    public function periods(Request $request): JsonResponse
    {
        $data = $request->validate(['legal_entity_id' => ['required', 'integer']]);
        $entity = $this->assertEntityExists((int) $data['legal_entity_id']);

        return response()->json(['success' => true, 'data' => $this->accountingPeriods->paginateForEntity(
            $this->tenantId(), (int) $entity->id, $this->perPage($request),
        )]);
    }

    public function createPeriod(StoreAccountingPeriodRequest $request): JsonResponse
    {
        $period = $this->accountingPeriodService->create($this->tenantId(), $request->validated());

        return response()->json(['success' => true, 'data' => $period], 201);
    }

    public function closePeriod(Request $request, int $periodId): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $period = $this->accountingPeriodService->close(
            $this->tenantId(),
            (int) $request->user()->getAuthIdentifier(),
            $periodId,
            $data['reason'],
        );

        return response()->json(['success' => true, 'data' => $period]);
    }

    public function journals(Request $request): JsonResponse
    {
        $data = $request->validate(['legal_entity_id' => ['required', 'integer']]);
        $entity = $this->assertEntityExists((int) $data['legal_entity_id']);

        return response()->json(['success' => true, 'data' => $this->journalEntries->paginateForEntity(
            $this->tenantId(), (int) $entity->id, $this->perPage($request),
        )]);
    }

    public function createJournal(StoreJournalRequest $request): JsonResponse
    {
        $data = $request->validated();
        $journal = $this->journals->createDraft($this->tenantId(), (int) $request->user()->getAuthIdentifier(), $data, $data['lines']);
        return response()->json(['success' => true, 'data' => $journal], 201);
    }

    public function cancelJournal(CancelJournalRequest $request, int $journalId): JsonResponse
    {
        $data = $request->validated();
        $journal = $this->journals->cancelDraft(
            $this->tenantId(),
            (int) $request->user()->getAuthIdentifier(),
            $journalId,
            $data['reason'],
        );

        return response()->json(['success' => true, 'data' => $journal]);
    }

    public function postJournal(Request $request, int $journalId): JsonResponse
    {
        $journal = $this->journals->post($this->tenantId(), (int) $request->user()->getAuthIdentifier(), $journalId);
        return response()->json(['success' => true, 'data' => $journal]);
    }

    public function reverseJournal(ReverseJournalRequest $request, int $journalId): JsonResponse
    {
        $data = $request->validated();
        $journal = $this->journals->reverse(
            $this->tenantId(), (int) $request->user()->getAuthIdentifier(), $journalId,
            $data['journal_number'], $data['effective_date'], $data['reason'],
        );
        return response()->json(['success' => true, 'data' => $journal], 201);
    }

    public function trialBalance(Request $request): JsonResponse
    {
        $data = $request->validate([
            'legal_entity_id' => ['required', 'integer'],
            'as_of' => ['required', 'date'],
        ]);
        $entity = $this->assertEntityExists((int) $data['legal_entity_id']);
        $rows = $this->trialBalance->forEntity(
            $this->tenantId(),
            (int) $entity->id,
            (string) $data['as_of'],
        );

        return response()->json(['success' => true, 'data' => [
            'legal_entity_id' => $entity->id,
            'currency' => $entity->base_currency,
            'as_of' => $data['as_of'],
            'accounts' => $rows,
        ]]);
    }

    private function tenantId(): int
    {
        $tenantId = $this->tenantContext->getTenantId();
        abort_if($tenantId === null, 403, 'A resolved tenant context is required.');
        return (int) $tenantId;
    }

    private function assertEntityExists(int $entityId): LegalEntity
    {
        return $this->entitiesRepository->findForTenant($this->tenantId(), $entityId)
            ?? abort(404);
    }

    private function perPage(Request $request): int
    {
        return min(max($request->integer('per_page', 25), 1), 100);
    }
}
