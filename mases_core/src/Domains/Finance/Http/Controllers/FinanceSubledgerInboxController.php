<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use MaaleArna\MASES\Core\Domains\Finance\Enums\JournalSourceType;
use MaaleArna\MASES\Core\Domains\Finance\Services\FinanceSubledgerInboxService;

final class FinanceSubledgerInboxController extends Controller
{
    public function __construct(private readonly FinanceSubledgerInboxService $inbox)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(['received', 'processing', 'posted', 'quarantined'])],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->inbox->listForTenant($this->tenantId(), $data['status'] ?? null),
        ]);
    }

    public function receive(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source_type' => ['required', 'string', Rule::in(array_map(
                static fn (JournalSourceType $type): string => $type->value,
                JournalSourceType::cases(),
            ))],
            'source_event_id' => ['required', 'string', 'max:128'],
            'source_version' => ['required', 'string', 'max:64'],
            'event_type' => ['required', 'string', 'max:120'],
            'payload' => ['required', 'array'],
            'evidence_ref' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $result = $this->inbox->receive(
            $this->tenantId(),
            $data['source_type'],
            $data['source_event_id'],
            $data['source_version'],
            $data['event_type'],
            $data['payload'],
            $data['evidence_ref'] ?? null,
        );

        return response()->json([
            'success' => true,
            'data' => $result['event'],
            'idempotent_replay' => $result['idempotent_replay'],
        ], $result['idempotent_replay'] ? 200 : 202);
    }

    private function tenantId(): int
    {
        $tenantId = function_exists('mases_tenant_id') ? mases_tenant_id() : null;
        abort_if($tenantId === null, 403, 'An authenticated tenant context is required.');

        return (int) $tenantId;
    }
}
