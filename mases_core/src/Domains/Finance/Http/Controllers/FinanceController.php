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
use MaaleArna\MASES\Core\Domains\Finance\Services\JournalEntryService;
use MaaleArna\MASES\Core\Domains\Finance\Services\LegalEntityService;
use MaaleArna\MASES\Core\Domains\Finance\Services\TrialBalanceService;
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

    public function createAccount(Request $request): JsonResponse
    {
        $data = $request->validate([
            'legal_entity_id' => ['required', 'integer'],
            'parent_id' => ['nullable', 'integer'],
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/', Rule::unique('ledger_accounts', 'code')->where(fn ($query) => $query->where('tenant_id', $this->tenantId())->where('legal_entity_id', (int) $request->input('legal_entity_id')))],
            'name_am' => ['nullable', 'string', 'max:180'],
            'name_en' => ['required', 'string', 'max:180'],
            'account_type' => ['required', Rule::in(LedgerAccount::TYPES)],
            'normal_balance' => ['required', Rule::in(LedgerAccount::NORMAL_BALANCES)],
            'is_posting_allowed' => ['sometimes', 'boolean'],
        ]);
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

    public function createPeriod(Request $request): JsonResponse
    {
        $data = $request->validate([
            'legal_entity_id' => ['required', 'integer'],
            'code' => ['required', 'string', 'max:32'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
        ]);
        $tenantId = $this->tenantId();

        $period = DB::transaction(function () use ($data, $tenantId): AccountingPeriod {
            $entity = LegalEntity::query()->where('tenant_id', $tenantId)
                ->lockForUpdate()->findOrFail((int) $data['legal_entity_id']);
            $overlap = AccountingPeriod::query()->where('tenant_id', $tenantId)
                ->where('legal_entity_id', $entity->id)->where('starts_on', '<=', $data['ends_on'])
                ->where('ends_on', '>=', $data['starts_on'])->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['starts_on' => 'Accounting periods for one legal entity cannot overlap.']);
            }

            return AccountingPeriod::query()->create([
                ...$data, 'tenant_id' => $tenantId, 'status' => AccountingPeriod::OPEN,
            ]);
        });

        return response()->json(['success' => true, 'data' => $period], 201);
    }

    public function closePeriod(Request $request, int $periodId): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $period = AccountingPeriod::query()->where('tenant_id', $this->tenantId())->findOrFail($periodId);
        DB::transaction(function () use ($period, $request, $data): void {
            $locked = AccountingPeriod::query()->where('tenant_id', $this->tenantId())->lockForUpdate()->findOrFail($period->id);
            if ($locked->status !== AccountingPeriod::OPEN) {
                throw ValidationException::withMessages(['status' => 'Only an open period can be closed.']);
            }
            $drafts = JournalEntry::query()->where('tenant_id', $this->tenantId())
                ->where('accounting_period_id', $locked->id)->where('status', JournalEntry::DRAFT)->exists();
            if ($drafts) {
                throw ValidationException::withMessages(['period' => 'Resolve or discard all draft journals before closing this period.']);
            }
            $locked->status = AccountingPeriod::CLOSED;
            $locked->closed_by = (int) $request->user()->getAuthIdentifier();
            $locked->closed_at = now();
            $locked->close_reason = $data['reason'];
            $locked->save();
        });
        return response()->json(['success' => true, 'data' => $period->fresh()]);
    }

    public function journals(Request $request): JsonResponse
    {
        $data = $request->validate(['legal_entity_id' => ['required', 'integer']]);
        $entity = $this->assertEntityExists((int) $data['legal_entity_id']);

        return response()->json(['success' => true, 'data' => $this->journalEntries->paginateForEntity(
            $this->tenantId(), (int) $entity->id, $this->perPage($request),
        )]);
    }

    public function createJournal(Request $request): JsonResponse
    {
        $data = $request->validate([
            'legal_entity_id' => ['required', 'integer'],
            'journal_number' => ['required', 'string', 'max:64'],
            'effective_date' => ['required', 'date'],
            'currency' => ['sometimes', 'string', 'size:3', Rule::in(['ETB'])],
            'description' => ['required', 'string', 'max:500'],
            'source_type' => ['nullable', 'string', 'max:64'],
            'source_id' => ['nullable', 'string', 'max:128'],
            'idempotency_key' => ['required', 'string', 'max:128'],
            'lines' => ['required', 'array', 'min:2', 'max:200'],
            'lines.*.account_id' => ['required', 'integer'],
            'lines.*.debit_amount' => ['required', 'string', 'regex:/^\\d{1,16}(?:\\.\\d{1,4})?$/'],
            'lines.*.credit_amount' => ['required', 'string', 'regex:/^\\d{1,16}(?:\\.\\d{1,4})?$/'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
        ]);
        $journal = $this->journals->createDraft($this->tenantId(), (int) $request->user()->getAuthIdentifier(), $data, $data['lines']);
        return response()->json(['success' => true, 'data' => $journal], 201);
    }

    public function cancelJournal(Request $request, int $journalId): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
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

    public function reverseJournal(Request $request, int $journalId): JsonResponse
    {
        $data = $request->validate([
            'journal_number' => ['required', 'string', 'max:64'],
            'effective_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
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
