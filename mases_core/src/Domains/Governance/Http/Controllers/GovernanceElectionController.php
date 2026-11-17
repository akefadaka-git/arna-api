<?php
declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceElection;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceElectionLifecycleService;

final class GovernanceElectionController extends Controller
{
    public function __construct(private readonly GovernanceElectionLifecycleService $lifecycle) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', GovernanceElection::class);
        $tenantId = $this->tenantId();
        abort_if($tenantId === null, 404);

        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);
        $paginator = GovernanceElection::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', GovernanceElection::class);
        $tenantId = $this->tenantId();
        abort_if($tenantId === null, 404);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:80'],
            'title' => ['required', 'string', 'max:255'],
            'governance_body_id' => ['nullable', 'integer', 'min:1'],
            'record_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        if (!empty($validated['governance_body_id'])) {
            $bodyExists = \MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($validated['governance_body_id'])
                ->exists();
            abort_unless($bodyExists, 404);
        }

        $election = $this->lifecycle->createPlanned(
            $tenantId,
            $validated['code'],
            $validated['title'],
            $validated['governance_body_id'] ?? null,
            $validated['record_date'] ?? null,
        );

        return response()->json(['data' => $election], 201);
    }

    public function show(GovernanceElection $governanceElection): JsonResponse
    {
        Gate::authorize('view', $governanceElection);
        return response()->json(['data' => $governanceElection]);
    }

    private function tenantId(): ?int
    {
        if (!function_exists('mases_tenant_id')) {
            return null;
        }
        $tenantId = mases_tenant_id();
        return $tenantId === null ? null : (int) $tenantId;
    }
}
