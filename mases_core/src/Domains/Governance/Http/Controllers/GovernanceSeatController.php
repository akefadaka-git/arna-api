<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceActingAssignmentService;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceSeatService;

final class GovernanceSeatController
{
    public function __construct(
        private readonly GovernanceSeatService $seats,
        private readonly GovernanceActingAssignmentService $actingAssignments,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401, 'Authentication required.');

        return response()->json([
            'success' => true,
            'data' => $this->seats->list($actor, $this->tenantId()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401, 'Authentication required.');

        $data = $request->validate([
            'governance_body_id' => ['required', 'integer', 'min:1'],
            'governance_position_id' => ['required', 'integer', 'min:1'],
            'seat_code' => ['required', 'string', 'min:1', 'max:64'],
            'label_am' => ['nullable', 'string', 'max:150'],
            'label_en' => ['nullable', 'string', 'max:150'],
        ]);

        $seat = $this->seats->createSeat(
            $actor,
            $this->tenantId(),
            (int) $data['governance_body_id'],
            (int) $data['governance_position_id'],
            $data['seat_code'],
            $data['label_am'] ?? null,
            $data['label_en'] ?? null,
        );

        return response()->json(['success' => true, 'data' => $seat], 201);
    }

    public function occupy(Request $request, int $governanceSeat): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401, 'Authentication required.');

        $data = $request->validate([
            'person_id' => ['required', 'integer', 'min:1'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'governance_membership_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $occupancy = $this->seats->occupy(
            $actor,
            $this->tenantId(),
            $governanceSeat,
            (int) $data['person_id'],
            $data['start_date'],
            $data['end_date'] ?? null,
            isset($data['governance_membership_id']) ? (int) $data['governance_membership_id'] : null,
        );

        return response()->json(['success' => true, 'data' => $occupancy], 201);
    }

    public function assignActing(Request $request, int $governanceSeat): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401, 'Authentication required.');

        $data = $request->validate([
            'substantive_occupancy_id' => ['required', 'integer', 'min:1'],
            'acting_person_id' => ['required', 'integer', 'min:1'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'source_decision_reference' => ['required', 'string', 'min:1', 'max:191'],
            'actions' => ['required', 'array', 'min:1'],
            'actions.*' => ['required', 'string', 'in:governance.appointment.manage,governance.membership.manage'],
            'reason' => ['required', 'string', 'min:1', 'max:500'],
        ]);

        $assignment = $this->actingAssignments->assign(
            $actor,
            $this->tenantId(),
            $governanceSeat,
            (int) $data['substantive_occupancy_id'],
            (int) $data['acting_person_id'],
            $data['start_date'],
            $data['end_date'],
            $data['source_decision_reference'],
            $data['actions'],
            $data['reason'],
        );

        return response()->json(['success' => true, 'data' => $assignment], 201);
    }

    public function revokeActing(Request $request, int $governanceActingAssignment): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401, 'Authentication required.');

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:1', 'max:500'],
        ]);

        $assignment = $this->actingAssignments->revoke(
            $actor,
            $this->tenantId(),
            $governanceActingAssignment,
            $data['reason'],
        );

        return response()->json(['success' => true, 'data' => $assignment]);
    }

    private function tenantId(): int
    {
        abort_unless(function_exists('mases_tenant_id'), 403, 'Validated tenant context is required.');
        $tenantId = mases_tenant_id();
        abort_if($tenantId === null, 403, 'Validated tenant context is required.');

        return (int) $tenantId;
    }
}
