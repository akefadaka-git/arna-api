<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tenant-scoped meeting attendance, proxy and penalty workflow.
 *
 * Money is stored as integer minor units (ETB cents). Penalties are
 * assessed only by an authorized officer; recording an absence does not
 * itself collect money. Settlement rows are requests for Finance review,
 * never direct dividend or ledger mutations.
 */
final class GovernanceMeetingAttendanceController extends Controller
{
    private function tenantId(): int
    {
        $tenantId = (int) (function_exists('mases_tenant_id') ? mases_tenant_id() : 0);
        if ($tenantId < 1) {
            throw new NotFoundHttpException('Tenant context is required.');
        }
        return $tenantId;
    }

    private function actorId(): int
    {
        return (int) auth()->id();
    }

    public function index(): JsonResponse
    {
        $tenantId = $this->tenantId();
        $rows = DB::table('governance_meetings')
            ->where('tenant_id', $tenantId)
            ->orderByDesc('starts_at')
            ->limit(100)
            ->get();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'governance_body_id' => ['required', 'integer', 'min:1'],
            'meeting_code' => ['required', 'string', 'max:64'],
            'title_am' => ['nullable', 'string', 'max:190'],
            'title_en' => ['nullable', 'string', 'max:190'],
            'meeting_kind' => ['required', 'in:general_assembly,board'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ]);
        $tenantId = $this->tenantId();
        $bodyExists = DB::table('governance_bodies')
            ->where('tenant_id', $tenantId)
            ->where('id', $data['governance_body_id'])
            ->exists();
        if (!$bodyExists) {
            throw new NotFoundHttpException('Governance body was not found in this tenant.');
        }

        try {
            $id = DB::transaction(function () use ($data, $tenantId): int {
                $id = DB::table('governance_meetings')->insertGetId([
                    'tenant_id' => $tenantId,
                    'governance_body_id' => $data['governance_body_id'],
                    'meeting_code' => trim($data['meeting_code']),
                    'title_am' => $data['title_am'] ?? null,
                    'title_en' => $data['title_en'] ?? null,
                    'meeting_kind' => $data['meeting_kind'],
                    'starts_at' => CarbonImmutable::parse($data['starts_at'])->toDateTimeString(),
                    'ends_at' => isset($data['ends_at']) ? CarbonImmutable::parse($data['ends_at'])->toDateTimeString() : null,
                    'status' => 'scheduled',
                    'created_by' => $this->actorId(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->activity($tenantId, $id, 'meeting.created', ['meeting_code' => $data['meeting_code']]);
                return $id;
            });
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw new ConflictHttpException('Meeting code already exists in this tenant.');
            }
            throw $exception;
        }

        return response()->json([
            'success' => true,
            'data' => DB::table('governance_meetings')->where('tenant_id', $tenantId)->find($id),
        ], 201);
    }

    public function show(int $meeting): JsonResponse
    {
        $tenantId = $this->tenantId();
        $record = $this->meeting($tenantId, $meeting);
        $record->attendance = DB::table('governance_meeting_attendance')->where('tenant_id', $tenantId)->where('meeting_id', $meeting)->orderBy('id')->get();
        $record->proxies = DB::table('governance_meeting_proxies')->where('tenant_id', $tenantId)->where('meeting_id', $meeting)->orderBy('id')->get();
        $record->penalties = DB::table('governance_meeting_penalties')->where('tenant_id', $tenantId)->where('meeting_id', $meeting)->orderBy('id')->get();
        $record->settlements = DB::table('governance_penalty_settlements')
            ->where('tenant_id', $tenantId)
            ->whereIn('penalty_id', $record->penalties->pluck('id')->all() ?: [0])
            ->orderBy('id')
            ->get();

        return response()->json(['success' => true, 'data' => $record]);
    }

    public function recordAttendance(Request $request, int $meeting): JsonResponse
    {
        $data = $request->validate([
            'subject_type' => ['required', 'in:shareholder,director'],
            'subject_id' => ['required', 'integer', 'min:1'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'attendance_status' => ['required', 'in:present,late,absent,excused,represented'],
            'arrived_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $tenantId = $this->tenantId();
        $meetingRecord = $this->meeting($tenantId, $meeting);
        $arrivedAt = isset($data['arrived_at']) ? CarbonImmutable::parse($data['arrived_at']) : null;
        $status = $data['attendance_status'];
        if ($arrivedAt && $arrivedAt->greaterThan(CarbonImmutable::parse($meetingRecord->starts_at))) {
            $status = 'late';
        }
        if (in_array($status, ['present', 'late'], true) && !$arrivedAt) {
            throw ValidationException::withMessages(['arrived_at' => 'Arrival time is required for present or late attendance.']);
        }

        try {
            $id = DB::transaction(function () use ($data, $tenantId, $meeting, $status, $arrivedAt): int {
                $id = DB::table('governance_meeting_attendance')->insertGetId([
                    'tenant_id' => $tenantId,
                    'meeting_id' => $meeting,
                    'subject_type' => $data['subject_type'],
                    'subject_id' => $data['subject_id'],
                    'user_id' => $data['user_id'] ?? null,
                    'attendance_status' => $status,
                    'arrived_at' => $arrivedAt?->toDateTimeString(),
                    'recorded_by' => $this->actorId(),
                    'notes' => $data['notes'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->activity($tenantId, $meeting, 'attendance.recorded', ['attendance_id' => $id, 'status' => $status]);
                return $id;
            });
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw new ConflictHttpException('Attendance for this participant is already recorded. Use a correction workflow; do not overwrite the original entry.');
            }
            throw $exception;
        }

        return response()->json([
            'success' => true,
            'data' => DB::table('governance_meeting_attendance')->where('tenant_id', $tenantId)->find($id),
        ], 201);
    }

    public function submitProxy(Request $request, int $meeting): JsonResponse
    {
        $data = $request->validate([
            'principal_type' => ['required', 'in:shareholder,director'],
            'principal_id' => ['required', 'integer', 'min:1'],
            'proxy_user_id' => ['required', 'integer', 'min:1'],
            'instrument_reference' => ['nullable', 'string', 'max:2000'],
        ]);
        $tenantId = $this->tenantId();
        $meetingRecord = $this->meeting($tenantId, $meeting);
        $startsAt = CarbonImmutable::parse($meetingRecord->starts_at);
        $isGa = $meetingRecord->meeting_kind === 'general_assembly';
        if (($isGa && $data['principal_type'] !== 'shareholder') || (!$isGa && $data['principal_type'] !== 'director')) {
            throw ValidationException::withMessages(['principal_type' => 'Proxy principal type does not match this meeting body.']);
        }

        $deadline = $isGa ? $startsAt->subDays(3) : $startsAt->subHours(3);
        $submittedAt = CarbonImmutable::now();
        if ($submittedAt->greaterThan($deadline)) {
            throw ValidationException::withMessages(['submitted_at' => 'The proxy submission deadline has passed.']);
        }

        if ($isGa) {
            $principalExists = DB::table('shareholders')
                ->where('tenant_id', $tenantId)
                ->where('id', $data['principal_id'])
                ->exists();
            if (!$principalExists) {
                throw ValidationException::withMessages(['principal_id' => 'Shareholder is not registered in this tenant.']);
            }
        } else {
            $principal = DB::table('governance_memberships')
                ->join('governance_positions', 'governance_positions.id', '=', 'governance_memberships.governance_position_id')
                ->where('governance_memberships.tenant_id', $tenantId)
                ->where('governance_memberships.governance_body_id', $meetingRecord->governance_body_id)
                ->where('governance_memberships.id', $data['principal_id'])
                ->where('governance_memberships.status', 'active')
                ->where('governance_positions.is_officer', true)
                ->select('governance_memberships.user_id')
                ->first();
            if (!$principal || !$principal->user_id) {
                throw ValidationException::withMessages(['principal_id' => 'Board principal must be an active director with a linked user account.']);
            }
            $proxyIsAnotherDirector = DB::table('governance_memberships')
                ->join('governance_positions', 'governance_positions.id', '=', 'governance_memberships.governance_position_id')
                ->where('governance_memberships.tenant_id', $tenantId)
                ->where('governance_memberships.governance_body_id', $meetingRecord->governance_body_id)
                ->where('governance_memberships.user_id', $data['proxy_user_id'])
                ->where('governance_memberships.status', 'active')
                ->where('governance_positions.is_officer', true)
                ->where('governance_memberships.user_id', '<>', $principal->user_id)
                ->exists();
            if (!$proxyIsAnotherDirector) {
                throw ValidationException::withMessages(['proxy_user_id' => 'Board proxy must be another active director; self-proxy and non-director proxies are not allowed.']);
            }
        }

        try {
            $id = DB::table('governance_meeting_proxies')->insertGetId([
                'tenant_id' => $tenantId,
                'meeting_id' => $meeting,
                'principal_type' => $data['principal_type'],
                'principal_id' => $data['principal_id'],
                'proxy_user_id' => $data['proxy_user_id'],
                'submitted_by' => $this->actorId(),
                'submitted_at' => $submittedAt->toDateTimeString(),
                'deadline_at' => $deadline->toDateTimeString(),
                'status' => 'submitted',
                'instrument_reference' => $data['instrument_reference'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw new ConflictHttpException('A proxy is already registered for this participant and meeting.');
            }
            throw $exception;
        }

        $this->activity($tenantId, $meeting, 'proxy.submitted', ['proxy_id' => $id, 'principal_type' => $data['principal_type']]);
        return response()->json(['success' => true, 'data' => DB::table('governance_meeting_proxies')->where('tenant_id', $tenantId)->find($id)], 201);
    }

    public function reviewProxy(Request $request, int $proxy): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $tenantId = $this->tenantId();
        $record = DB::table('governance_meeting_proxies')
            ->where('tenant_id', $tenantId)->where('id', $proxy)->first();
        if (!$record) {
            throw new NotFoundHttpException('Proxy was not found.');
        }
        if ($record->status !== 'submitted') {
            throw new ConflictHttpException('Only submitted proxies can be reviewed.');
        }
        DB::table('governance_meeting_proxies')->where('tenant_id', $tenantId)->where('id', $proxy)->update([
            'status' => $data['decision'], 'updated_at' => now(),
        ]);
        $this->activity($tenantId, (int) $record->meeting_id, 'proxy.reviewed', [
            'proxy_id' => $proxy, 'decision' => $data['decision'], 'reason' => $data['reason'] ?? null,
        ]);
        return response()->json(['success' => true, 'data' => DB::table('governance_meeting_proxies')->where('tenant_id', $tenantId)->find($proxy)]);
    }

    public function assessPenalty(Request $request, int $meeting): JsonResponse
    {
        $data = $request->validate([
            'attendance_id' => ['required', 'integer', 'min:1'],
            'penalty_type' => ['required', 'in:late,absence'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $tenantId = $this->tenantId();
        $this->meeting($tenantId, $meeting);
        $attendance = DB::table('governance_meeting_attendance')
            ->where('tenant_id', $tenantId)->where('meeting_id', $meeting)->where('id', $data['attendance_id'])->first();
        if (!$attendance) {
            throw new NotFoundHttpException('Attendance record was not found.');
        }
        if ($data['penalty_type'] === 'late' && $attendance->attendance_status !== 'late') {
            throw ValidationException::withMessages(['attendance_id' => 'A late penalty requires a recorded late arrival.']);
        }
        if ($data['penalty_type'] === 'absence' && !in_array($attendance->attendance_status, ['absent'], true)) {
            throw ValidationException::withMessages(['attendance_id' => 'An absence penalty requires a recorded absence. Excused and represented participants are excluded.']);
        }
        if ($data['penalty_type'] === 'absence') {
            $hasApprovedProxy = DB::table('governance_meeting_proxies')
                ->where('tenant_id', $tenantId)
                ->where('meeting_id', $meeting)
                ->where('principal_type', $attendance->subject_type)
                ->where('principal_id', $attendance->subject_id)
                ->where('status', 'approved')
                ->exists();
            if ($hasApprovedProxy) {
                throw ValidationException::withMessages(['attendance_id' => 'An approved proxy prevents an absence penalty.']);
            }
        }
        $amount = $data['penalty_type'] === 'late' ? 5000 : 30000;

        try {
            $id = DB::table('governance_meeting_penalties')->insertGetId([
                'tenant_id' => $tenantId, 'meeting_id' => $meeting, 'attendance_id' => $attendance->id,
                'penalty_type' => $data['penalty_type'], 'amount_cents' => $amount, 'currency' => 'ETB',
                'status' => 'assessed', 'reason' => $data['reason'] ?? null, 'assessed_by' => $this->actorId(),
                'assessed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw new ConflictHttpException('This penalty has already been assessed for the attendance record.');
            }
            throw $exception;
        }
        $this->activity($tenantId, $meeting, 'penalty.assessed', ['penalty_id' => $id, 'amount_cents' => $amount]);
        return response()->json(['success' => true, 'data' => DB::table('governance_meeting_penalties')->where('tenant_id', $tenantId)->find($id)], 201);
    }

    public function recordSettlement(Request $request, int $penalty): JsonResponse
    {
        $data = $request->validate([
            'method' => ['required', 'in:cash,dividend_offset'],
            'amount_cents' => ['required', 'integer', 'min:1'],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);
        $tenantId = $this->tenantId();
        $record = DB::table('governance_meeting_penalties')->where('tenant_id', $tenantId)->where('id', $penalty)->first();
        if (!$record) {
            throw new NotFoundHttpException('Penalty was not found.');
        }
        $paid = (int) DB::table('governance_penalty_settlements')
            ->where('tenant_id', $tenantId)->where('penalty_id', $penalty)
            ->whereIn('status', ['finance_accepted', 'posted'])->sum('amount_cents');
        $remaining = (int) $record->amount_cents - $paid;
        if ($data['amount_cents'] > $remaining) {
            throw ValidationException::withMessages(['amount_cents' => 'Settlement exceeds the assessed outstanding amount.']);
        }
        $status = $data['method'] === 'dividend_offset' ? 'pending_legal_finance_review' : 'pending_finance';
        $id = DB::table('governance_penalty_settlements')->insertGetId([
            'tenant_id' => $tenantId, 'penalty_id' => $penalty, 'method' => $data['method'],
            'amount_cents' => $data['amount_cents'], 'currency' => 'ETB', 'status' => $status,
            'reference' => $data['reference'] ?? null, 'recorded_by' => $this->actorId(),
            'recorded_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->activity($tenantId, (int) $record->meeting_id, 'penalty.settlement_requested', [
            'penalty_id' => $penalty, 'settlement_id' => $id, 'method' => $data['method'],
            'amount_cents' => $data['amount_cents'], 'status' => $status,
        ]);
        return response()->json(['success' => true, 'data' => DB::table('governance_penalty_settlements')->where('tenant_id', $tenantId)->find($id)], 201);
    }

    private function meeting(int $tenantId, int $id): object
    {
        $meeting = DB::table('governance_meetings')->where('tenant_id', $tenantId)->where('id', $id)->first();
        if (!$meeting) {
            throw new NotFoundHttpException('Meeting was not found in this tenant.');
        }
        return $meeting;
    }

    private function activity(int $tenantId, int $meetingId, string $action, array $payload): void
    {
        DB::table('governance_meeting_activity')->insert([
            'tenant_id' => $tenantId, 'meeting_id' => $meetingId, 'actor_id' => $this->actorId(),
            'action' => $action, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
        ]);
    }
}
