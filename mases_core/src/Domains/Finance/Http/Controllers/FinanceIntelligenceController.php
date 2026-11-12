<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use MaaleArna\MASES\Core\Domains\Finance\Support\FinanceCalendarDateAdapter;
use MaaleArna\MASES\Core\Domains\Finance\Models\LegalEntity;
use MaaleArna\MASES\Core\Domains\Finance\Services\FinanceIntelligenceService;

final class FinanceIntelligenceController extends Controller
{
    public function __construct(
        private readonly FinanceIntelligenceService $intelligence,
        private readonly FinanceCalendarDateAdapter $calendar,
    )
    {
    }

    public function evidence(Request $request): JsonResponse
    {
        $data = $request->validate([
            'legal_entity_id' => ['required', 'integer', 'min:1'],
            'as_of' => ['required_without:as_of_ec', 'prohibited_with:as_of_ec', 'date_format:Y-m-d'],
            'as_of_ec' => ['required_without:as_of', 'prohibited_with:as_of', 'string', 'regex:/^\\d{4}-\\d{2}-\\d{2}$/],
        ]);
        $entity = LegalEntity::query()->where('tenant_id', $this->tenantId())->findOrFail((int) $data['legal_entity_id']);

        return response()->json([
            'success' => true,
            'data' => $this->intelligence->evidenceSnapshot((int) $entity->tenant_id, (int) $entity->id, $this->resolveAsOf($data)),
        ]);
    }

    public function proposals(Request $request): JsonResponse
    {
        $data = $request->validate(['legal_entity_id' => ['required', 'integer', 'min:1']]);
        $entity = LegalEntity::query()->where('tenant_id', $this->tenantId())->findOrFail((int) $data['legal_entity_id']);

        return response()->json([
            'success' => true,
            'data' => $this->intelligence->proposals((int) $entity->tenant_id, (int) $entity->id),
        ]);
    }

    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'legal_entity_id' => ['required', 'integer', 'min:1'],
            'task_type' => ['required', 'string', Rule::in(['variance_review', 'cash_flow_review', 'ledger_anomaly_review', 'period_close_review'])],
            'as_of' => ['required_without:as_of_ec', 'prohibited_with:as_of_ec', 'date_format:Y-m-d'],
            'as_of_ec' => ['required_without:as_of', 'prohibited_with:as_of', 'string', 'regex:/^\\d{4}-\\d{2}-\\d{2}$/],
            'question' => ['required', 'string', 'min:8', 'max:2000'],
        ]);
        $entity = LegalEntity::query()->where('tenant_id', $this->tenantId())->findOrFail((int) $data['legal_entity_id']);
        $proposal = $this->intelligence->generateProposal(
            (int) $entity->tenant_id,
            (int) $entity->id,
            (int) $request->user()->getAuthIdentifier(),
            $data['task_type'],
            $this->resolveAsOf($data),
            $data['question'],
        );

        return response()->json(['success' => true, 'data' => $proposal], 201);
    }

    public function approve(Request $request, int $proposalId): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $proposal = $this->intelligence->approve(
            $this->tenantId(),
            $proposalId,
            (int) $request->user()->getAuthIdentifier(),
            $data['reason'],
        );

        return response()->json(['success' => true, 'data' => $proposal]);
    }

    public function reject(Request $request, int $proposalId): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $proposal = $this->intelligence->reject(
            $this->tenantId(),
            $proposalId,
            (int) $request->user()->getAuthIdentifier(),
            $data['reason'],
        );

        return response()->json(['success' => true, 'data' => $proposal]);
    }


    private function resolveAsOf(array $data): string
    {
        if (isset($data['as_of'])) {
            return $data['as_of'];
        }

        try {
            return $this->calendar->toGregorianDate($data['as_of_ec']);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'as_of_ec' => 'The Ethiopian report date is invalid.',
            ]);
        }
    }

    private function tenantId(): int
    {
        $tenantId = function_exists('mases_tenant_id') ? mases_tenant_id() : null;
        abort_if($tenantId === null, 403, 'An authenticated tenant context is required.');

        return (int) $tenantId;
    }
}
