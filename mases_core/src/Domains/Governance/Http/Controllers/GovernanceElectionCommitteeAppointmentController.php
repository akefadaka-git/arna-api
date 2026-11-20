<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceElectionCommitteeAppointmentService;

final class GovernanceElectionCommitteeAppointmentController extends Controller
{
    public function __construct(private readonly GovernanceElectionCommitteeAppointmentService $appointments)
    {
    }

    public function index(Request $request, int $election): JsonResponse
    {
        $tenantId = $this->tenantId();
        $actor = $this->actor($request);
        return response()->json(['data' => $this->appointments->list($tenantId, $election, $actor)]);
    }

    public function store(Request $request, int $election): JsonResponse
    {
        $tenantId = $this->tenantId();
        $data = $request->validate([
            'person_id' => ['required', 'integer', 'min:1'],
            'user_id' => ['required', 'integer', 'min:1'],
            'appointment_evidence_id' => ['required', 'integer', 'min:1'],
            'appointment_reference' => ['required', 'string', 'min:3', 'max:160'],
            'member_role' => ['required', 'string', 'max:32'],
            'ex_officio' => ['sometimes', 'boolean'],
            'appointed_at' => ['required', 'regex:/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:Z|[+-]\\d{2}:\\d{2})$/'],
        ]);
        $member = $this->appointments->appoint(
            $tenantId, $election, (int) $data['person_id'], (int) $data['user_id'],
            (int) $data['appointment_evidence_id'], $data['appointment_reference'],
            $data['member_role'], (bool) ($data['ex_officio'] ?? false),
            $data['appointed_at'], $this->actor($request),
        );
        return response()->json(['data' => $member], 201);
    }

    public function revoke(Request $request, int $election, int $member): JsonResponse
    {
        $tenantId = $this->tenantId();
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $revoked = $this->appointments->revoke(
            $tenantId, $election, $member, $data['reason'], $this->actor($request),
        );
        return response()->json(['data' => $revoked]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        return $actor;
    }

    private function tenantId(): int
    {
        if (!function_exists('mases_tenant_id')) {
            abort(404);
        }
        $tenantId = mases_tenant_id();
        abort_if($tenantId === null, 404);
        return (int) $tenantId;
    }
}
