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
            'meeting_category' => ['required_if:meeting_kind,general_assembly', 'nullable', 'in:ordinary,extraordinary'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ]);
        if ($data['meeting_kind'] === 'board' && !empty($data['meeting_category'])) {
            throw ValidationException::withMessages(['meeting_category' => 'Board meetings do not use a General Assembly category.']);
        }
        $tenantId = $this->tenantId();
        $body = DB::table('governance_bodies')
            ->where('tenant_id', $tenantId)
            ->where('id', $data['governance_body_id'])
            ->first(['id', 'body_type']);
        if (!$body) {
            throw new NotFoundHttpException('Governance body was not found in this tenant.');
        }
        $expectedBodyType = $data['meeting_kind'] === 'general_assembly' ? 'assembly' : 'board';
        if ($body->body_type !== $expectedBodyType) {
            throw ValidationException::withMessages([
                'meeting_kind' => 'Meeting kind must match the selected Governance body type.',
            ]);
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
                    'meeting_category' => $data['meeting_category'] ?? null,
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
        $record->proxy_events = DB::table('governance_meeting_proxy_events')->where('tenant_id', $tenantId)->where('meeting_id', $meeting)->orderBy('id')->get();
        $record->penalties = DB::table('governance_meeting_penalties')->where('tenant_id', $tenantId)->where('meeting_id', $meeting)->orderBy('id')->get();
        $record->settlements = DB::table('governance_penalty_settlements')
            ->where('tenant_id', $tenantId)
            ->whereIn('penalty_id', $record->penalties->pluck('id')->all() ?: [0])
            ->orderBy('id')
            ->get();
        $record->corrections = DB::table('governance_attendance_corrections')->where('tenant_id', $tenantId)->where('meeting_id', $meeting)->orderBy('id')->get();
        $record->disputes = DB::table('governance_penalty_disputes')->where('tenant_id', $tenantId)->where('meeting_id', $meeting)->orderBy('id')->get();

        return response()->json(['success' => true, 'data' => $record]);
    }

    public function recordAttendance(Request $request, int $meeting): JsonResponse
    {
        $data = $request->validate([
            'subject_type' => ['required', 'in:shareholder,director'],
            'subject_id' => ['required', 'integer', 'min:1'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'proxy_id' => ['nullable', 'integer', 'min:1'],
            'represented_by_user_id' => ['nullable', 'integer', 'min:1'],
            'represented_by_name' => ['nullable', 'string', 'max:150'],
            'attendance_status' => ['required', 'in:present,late,absent,excused,represented'],
            'arrived_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $tenantId = $this->tenantId();
        $meetingRecord = $this->meeting($tenantId, $meeting);
        $expectedSubjectType = $meetingRecord->meeting_kind === 'general_assembly' ? 'shareholder' : 'director';
        if ($data['subject_type'] !== $expectedSubjectType) {
            throw ValidationException::withMessages(['subject_type' => 'Participant type must match the meeting body: GA attendance is recorded against shareholders; Board attendance is recorded against active Board memberships.']);
        }

        if ($data['subject_type'] === 'shareholder') {
            $participant = DB::table('shareholders')
                ->where('tenant_id', $tenantId)
                ->where('id', $data['subject_id'])
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->first(['user_id', 'representative_user_id']);
            if (!$participant) {
                throw ValidationException::withMessages(['subject_id' => 'Shareholder is not active in this tenant.']);
            }
            $canonicalUserId = $participant->user_id ?? $participant->representative_user_id;
        } else {
            $participant = DB::table('governance_memberships')
                ->where('tenant_id', $tenantId)
                ->where('governance_body_id', $meetingRecord->governance_body_id)
                ->where('id', $data['subject_id'])
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->first(['user_id']);
            if (!$participant) {
                throw ValidationException::withMessages(['subject_id' => 'Director membership is not active in this Board.']);
            }
            $canonicalUserId = $participant->user_id;
        }
        if (isset($data['user_id']) && (int) $data['user_id'] !== (int) ($canonicalUserId ?? 0)) {
            throw ValidationException::withMessages(['user_id' => 'The supplied user does not match the canonical participant record.']);
        }

        $arrivedAt = isset($data['arrived_at']) ? CarbonImmutable::parse($data['arrived_at']) : null;
        $status = $data['attendance_status'];
        if ($arrivedAt && !in_array($status, ['excused', 'represented'], true)) {
            // The signed meeting start time is the only lateness threshold:
            // any arrival after it is late; an arrival at/before it is present.
            $status = $arrivedAt->greaterThan(CarbonImmutable::parse($meetingRecord->starts_at))
                ? 'late'
                : 'present';
        }
        if (in_array($status, ['present', 'late'], true) && !$arrivedAt) {
            throw ValidationException::withMessages(['arrived_at' => 'Arrival time is required for present or late attendance.']);
        }
        $approvedProxy = DB::table('governance_meeting_proxies')
            ->where('tenant_id', $tenantId)
            ->where('meeting_id', $meeting)
            ->where('principal_type', $data['subject_type'])
            ->where('principal_id', $data['subject_id'])
            ->where('status', 'approved')
            ->first();
        if ($status === 'represented') {
            if (!$approvedProxy || empty($data['proxy_id']) || (int) $data['proxy_id'] !== (int) $approvedProxy->id) {
                throw ValidationException::withMessages(['proxy_id' => 'Represented attendance must identify the exact approved proxy instrument for this principal and meeting.']);
            }
            if (!$arrivedAt) {
                throw ValidationException::withMessages(['arrived_at' => 'The proxy arrival time is required for represented attendance.']);
            }
            if (!empty($approvedProxy->proxy_user_id)) {
                if ((int) ($data['represented_by_user_id'] ?? 0) !== (int) $approvedProxy->proxy_user_id) {
                    throw ValidationException::withMessages(['represented_by_user_id' => 'The attending proxy user does not match the approved proxy instrument.']);
                }
            } else {
                $representedName = trim((string) ($data['represented_by_name'] ?? ''));
                $allowedNames = array_filter([
                    trim((string) ($approvedProxy->proxy_name_am ?? '')),
                    trim((string) ($approvedProxy->proxy_name_en ?? '')),
                ]);
                if ($representedName === '' || !in_array($representedName, $allowedNames, true)) {
                    throw ValidationException::withMessages(['represented_by_name' => 'The attending external proxy name must match the approved proxy instrument.']);
                }
            }
        } elseif ($approvedProxy && in_array($status, ['present', 'late'], true)) {
            throw ValidationException::withMessages(['attendance_status' => 'A principal with an approved proxy cannot also attend personally in the same meeting.']);
        } elseif (!empty($data['proxy_id']) || !empty($data['represented_by_user_id']) || !empty($data['represented_by_name'])) {
            throw ValidationException::withMessages(['proxy_id' => 'Proxy identity fields are accepted only for represented attendance.']);
        }
        if ($status === 'excused' && trim((string) ($data['notes'] ?? '')) === '') {
            throw ValidationException::withMessages(['notes' => 'Permission or supporting evidence reference is required for excused absence.']);
        }

        try {
            $id = DB::transaction(function () use ($data, $tenantId, $meeting, $status, $arrivedAt, $canonicalUserId): int {
                $id = DB::table('governance_meeting_attendance')->insertGetId([
                    'tenant_id' => $tenantId,
                    'meeting_id' => $meeting,
                    'subject_type' => $data['subject_type'],
                    'subject_id' => $data['subject_id'],
                    'user_id' => $canonicalUserId,
                    'proxy_id' => $status === 'represented' ? (int) $data['proxy_id'] : null,
                    'represented_by_user_id' => $status === 'represented' ? ($data['represented_by_user_id'] ?? null) : null,
                    'represented_by_name' => $status === 'represented' ? ($data['represented_by_name'] ?? null) : null,
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
            'proxy_user_id' => ['nullable', 'integer', 'min:1'],
            'proxy_name_am' => ['nullable', 'string', 'max:150'],
            'proxy_name_en' => ['nullable', 'string', 'max:150'],
            'proxy_reference' => ['nullable', 'string', 'max:100'],
            'instrument_reference' => ['required', 'string', 'min:3', 'max:2000'],
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
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->exists();
            if (!$principalExists) {
                throw ValidationException::withMessages(['principal_id' => 'Shareholder is not registered in this tenant.']);
            }
            if (empty($data['proxy_user_id']) && trim((string) ($data['proxy_name_am'] ?? '')) === '' && trim((string) ($data['proxy_name_en'] ?? '')) === '') {
                throw ValidationException::withMessages(['proxy_name_am' => 'Identify the GA proxy by an ARNA user account or a name. A proxy need not be a shareholder.']);
            }
        } else {
            if (empty($data['proxy_user_id'])) {
                throw ValidationException::withMessages(['proxy_user_id' => 'Board proxy must be another active director with an ARNA user account.']);
            }
            $principal = DB::table('governance_memberships')
                ->where('tenant_id', $tenantId)
                ->where('governance_body_id', $meetingRecord->governance_body_id)
                ->where('id', $data['principal_id'])
                ->where('status', 'active')
                ->select('user_id')
                ->first();
            if (!$principal || !$principal->user_id) {
                throw ValidationException::withMessages(['principal_id' => 'Board principal must be an active director with a linked user account.']);
            }
            $proxyIsAnotherDirector = DB::table('governance_memberships')
                ->where('tenant_id', $tenantId)
                ->where('governance_body_id', $meetingRecord->governance_body_id)
                ->where('user_id', $data['proxy_user_id'])
                ->where('status', 'active')
                ->where('user_id', '<>', $principal->user_id)
                ->exists();
            if (!$proxyIsAnotherDirector) {
                throw ValidationException::withMessages(['proxy_user_id' => 'Board proxy must be another active director; self-proxy and non-director proxies are not allowed.']);
            }
        }

        try {
            $id = DB::transaction(function () use ($tenantId, $meeting, $data, $submittedAt, $deadline, $isGa): int {
                DB::table('governance_meetings')
                    ->where('tenant_id', $tenantId)
                    ->where('id', $meeting)
                    ->lockForUpdate()
                    ->first();

                if (!$isGa) {
                    $alreadyHoldsProxy = DB::table('governance_meeting_proxies')
                        ->where('tenant_id', $tenantId)
                        ->where('meeting_id', $meeting)
                        ->where('proxy_user_id', $data['proxy_user_id'])
                        ->whereIn('status', ['submitted', 'approved'])
                        ->exists();
                    if ($alreadyHoldsProxy) {
                        throw ValidationException::withMessages(['proxy_user_id' => 'A director may hold no more than one Board proxy for the same meeting.']);
                    }
                }

                $id = DB::table('governance_meeting_proxies')->insertGetId([
                    'tenant_id' => $tenantId,
                    'meeting_id' => $meeting,
                    'principal_type' => $data['principal_type'],
                    'principal_id' => $data['principal_id'],
                    'proxy_user_id' => $data['proxy_user_id'] ?? null,
                    'proxy_name_am' => $data['proxy_name_am'] ?? null,
                    'proxy_name_en' => $data['proxy_name_en'] ?? null,
                    'proxy_reference' => $data['proxy_reference'] ?? null,
                    'submitted_by' => $this->actorId(),
                    'submitted_at' => $submittedAt->toDateTimeString(),
                    'deadline_at' => $deadline->toDateTimeString(),
                    'status' => 'submitted',
                    'instrument_reference' => $data['instrument_reference'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->recordProxyEvent(
                    $tenantId, $meeting, $id, 'submitted', null, 'submitted',
                    'Proxy instrument submitted.', $data['instrument_reference'] ?? null
                );
                $this->activity($tenantId, $meeting, 'proxy.submitted', ['proxy_id' => $id, 'principal_type' => $data['principal_type']]);
                return $id;
            });
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw new ConflictHttpException('A proxy is already registered for this participant and meeting.');
            }
            throw $exception;
        }

        return response()->json(['success' => true, 'data' => DB::table('governance_meeting_proxies')->where('tenant_id', $tenantId)->find($id)], 201);
    }

    public function reviewProxy(Request $request, int $proxy): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'reason' => [$request->input('decision') === 'rejected' ? 'required' : 'nullable', 'string', 'min:3', 'max:2000'],
        ]);
        $tenantId = $this->tenantId();

        $result = DB::transaction(function () use ($tenantId, $proxy, $data): object {
            $record = DB::table('governance_meeting_proxies')
                ->where('tenant_id', $tenantId)->where('id', $proxy)
                ->lockForUpdate()->first();
            if (!$record) {
                throw new NotFoundHttpException('Proxy was not found.');
            }
            if ($record->status !== 'submitted') {
                throw new ConflictHttpException('Only submitted proxies can be reviewed.');
            }
            if ((int) $record->submitted_by === $this->actorId()) {
                throw new ConflictHttpException('The proxy submitter cannot approve or reject their own proxy.');
            }
            if ($data['decision'] === 'approved') {
                if (trim((string) ($record->instrument_reference ?? '')) === '') {
                    throw new ConflictHttpException('Proxy approval requires a recorded proxy-instrument evidence reference.');
                }
                $meeting = $this->meeting($tenantId, (int) $record->meeting_id);
                if ($meeting->meeting_kind === 'board' && !empty($record->proxy_user_id)) {
                    $alreadyHoldsProxy = DB::table('governance_meeting_proxies')
                        ->where('tenant_id', $tenantId)->where('meeting_id', $record->meeting_id)
                        ->where('proxy_user_id', $record->proxy_user_id)->where('id', '<>', $proxy)
                        ->where('status', 'approved')->exists();
                    if ($alreadyHoldsProxy) {
                        throw new ConflictHttpException('This director already holds an approved Board proxy for the meeting.');
                    }
                }
            }

            $updated = DB::table('governance_meeting_proxies')
                ->where('tenant_id', $tenantId)->where('id', $proxy)->where('status', 'submitted')
                ->update(['status' => $data['decision'], 'updated_at' => now()]);
            if ($updated !== 1) {
                throw new ConflictHttpException('Proxy was already reviewed by another request.');
            }

            $this->recordProxyEvent(
                $tenantId, (int) $record->meeting_id, $proxy, 'reviewed',
                'submitted', $data['decision'], $data['reason'] ?? null,
                $record->instrument_reference ?? null
            );
            $this->activity($tenantId, (int) $record->meeting_id, 'proxy.reviewed', [
                'proxy_id' => $proxy, 'decision' => $data['decision'],
                'reason' => $data['reason'] ?? null, 'reviewed_by' => $this->actorId(),
            ]);

            return DB::table('governance_meeting_proxies')
                ->where('tenant_id', $tenantId)->find($proxy);
        });

        return response()->json(['success' => true, 'data' => $result]);
    }

    public function verifyProxyHistory(int $proxy): JsonResponse
    {
        $tenantId = $this->tenantId();
        $record = DB::table('governance_meeting_proxies')
            ->where('tenant_id', $tenantId)->where('id', $proxy)->first();
        if (!$record) {
            throw new NotFoundHttpException('Proxy was not found.');
        }

        $events = DB::table('governance_meeting_proxy_events')
            ->where('tenant_id', $tenantId)->where('proxy_id', $proxy)
            ->orderBy('id')->get();
        $previousHash = null;
        $verified = $events->isNotEmpty();
        $failedEventId = null;

        foreach ($events as $event) {
            $payload = [
                'tenant_id' => (int) $event->tenant_id,
                'meeting_id' => (int) $event->meeting_id,
                'proxy_id' => (int) $event->proxy_id,
                'event_type' => (string) $event->event_type,
                'previous_status' => $event->previous_status,
                'next_status' => $event->next_status,
                'actor_id' => (int) $event->actor_id,
                'reason' => $event->reason,
                'evidence_reference' => $event->evidence_reference,
                'previous_event_hash' => $event->previous_event_hash,
                'occurred_at' => (string) $event->occurred_at,
            ];
            $expected = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            if ($event->previous_event_hash !== $previousHash || !hash_equals((string) $event->event_hash, $expected)) {
                $verified = false;
                $failedEventId = (int) $event->id;
                break;
            }
            $previousHash = (string) $event->event_hash;
        }

        if ($verified && (string) $events->last()->next_status !== (string) $record->status) {
            $verified = false;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'status' => $events->isEmpty() ? 'BLOCKED' : ($verified ? 'PASS' : 'FAIL'),
                'proxy_id' => $proxy,
                'event_count' => $events->count(),
                'verified_through_event_id' => $verified ? ($events->last()->id ?? null) : null,
                'failed_event_id' => $failedEventId,
                'chain_root' => $previousHash,
                'integrity_verified' => $verified,
                'current_status_matches_chain' => $events->isNotEmpty() && (string) $events->last()->next_status === (string) $record->status,
                'note' => 'This verifies the stored per-proxy hash chain and current status linkage. It is not an independent blockchain anchor.',
            ],
        ]);
    }

    public function revokeProxy(Request $request, int $proxy): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        $tenantId = $this->tenantId();

        $result = DB::transaction(function () use ($tenantId, $proxy, $data): object {
            $record = DB::table('governance_meeting_proxies')
                ->where('tenant_id', $tenantId)->where('id', $proxy)
                ->lockForUpdate()->first();
            if (!$record) {
                throw new NotFoundHttpException('Proxy was not found.');
            }
            if (!in_array($record->status, ['submitted', 'approved'], true)) {
                throw new ConflictHttpException('Only submitted or approved proxies can be revoked.');
            }
            if ((int) $record->submitted_by !== $this->actorId()) {
                throw new ConflictHttpException('Only the original proxy submitter may request revocation.');
            }
            $represented = DB::table('governance_meeting_attendance')
                ->where('tenant_id', $tenantId)->where('meeting_id', $record->meeting_id)
                ->where('subject_type', $record->principal_type)->where('subject_id', $record->principal_id)
                ->where('attendance_status', 'represented')->exists();
            if ($represented) {
                throw new ConflictHttpException('A proxy already used for represented attendance cannot be revoked through this endpoint. Submit an attendance correction for independent review.');
            }

            $previous = (string) $record->status;
            DB::table('governance_meeting_proxies')
                ->where('tenant_id', $tenantId)->where('id', $proxy)
                ->whereIn('status', ['submitted', 'approved'])
                ->update(['status' => 'revoked', 'updated_at' => now()]);
            $this->recordProxyEvent(
                $tenantId, (int) $record->meeting_id, $proxy, 'revoked',
                $previous, 'revoked', $data['reason'], $record->instrument_reference ?? null
            );
            $this->activity($tenantId, (int) $record->meeting_id, 'proxy.revoked', [
                'proxy_id' => $proxy, 'reason' => $data['reason'],
            ]);
            return DB::table('governance_meeting_proxies')->where('tenant_id', $tenantId)->find($proxy);
        });

        return response()->json(['success' => true, 'data' => $result]);
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
        $status = $data['method'] === 'dividend_offset' ? 'pending_legal_finance_review' : 'pending_finance';
        $id = DB::transaction(function () use ($tenantId, $penalty, $data, $status): int {
            $record = DB::table('governance_meeting_penalties')
                ->where('tenant_id', $tenantId)->where('id', $penalty)
                ->lockForUpdate()->first();
            if (!$record) {
                throw new NotFoundHttpException('Penalty was not found.');
            }
            if ($record->status !== 'assessed') {
                throw new ConflictHttpException('Only an assessed, undisputed penalty can enter settlement review.');
            }
            $reserved = (int) DB::table('governance_penalty_settlements')
                ->where('tenant_id', $tenantId)->where('penalty_id', $penalty)
                ->whereNotIn('status', ['rejected', 'cancelled'])->sum('amount_cents');
            $remaining = (int) $record->amount_cents - $reserved;
            if ((int) $data['amount_cents'] > $remaining) {
                throw ValidationException::withMessages(['amount_cents' => 'Settlement exceeds the assessed outstanding amount.']);
            }
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
            return $id;
        });
        return response()->json(['success' => true, 'data' => DB::table('governance_penalty_settlements')->where('tenant_id', $tenantId)->find($id)], 201);
    }

    public function requestAttendanceCorrection(Request $request, int $meeting): JsonResponse
    {
        $data = $request->validate([
            'attendance_id' => ['required', 'integer', 'min:1'],
            'proposed_status' => ['required', 'in:present,late,absent,excused,represented'],
            'proposed_arrived_at' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        $tenantId = $this->tenantId();
        $this->meeting($tenantId, $meeting);
        $id = DB::transaction(function () use ($tenantId, $meeting, $data): int {
            $attendance = DB::table('governance_meeting_attendance')
                ->where('tenant_id', $tenantId)->where('meeting_id', $meeting)
                ->where('id', $data['attendance_id'])->lockForUpdate()->first();
            if (!$attendance) {
                throw new NotFoundHttpException('Attendance record was not found.');
            }
            if (DB::table('governance_meeting_penalties')->where('tenant_id', $tenantId)->where('attendance_id', $attendance->id)->exists()) {
                throw new ConflictHttpException('A penalty exists for this attendance. Resolve its dispute before requesting a correction.');
            }
            if (DB::table('governance_attendance_corrections')->where('tenant_id', $tenantId)->where('attendance_id', $attendance->id)->where('status', 'pending')->exists()) {
                throw new ConflictHttpException('A correction request is already pending.');
            }
            $id = DB::table('governance_attendance_corrections')->insertGetId([
                'tenant_id' => $tenantId, 'meeting_id' => $meeting, 'attendance_id' => $attendance->id,
                'proposed_status' => $data['proposed_status'],
                'proposed_arrived_at' => isset($data['proposed_arrived_at']) ? CarbonImmutable::parse($data['proposed_arrived_at'])->toDateTimeString() : null,
                'reason' => $data['reason'], 'status' => 'pending', 'requested_by' => $this->actorId(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->activity($tenantId, $meeting, 'attendance.correction_requested', ['correction_id' => $id, 'attendance_id' => $attendance->id]);
            return $id;
        });
        return response()->json(['success' => true, 'data' => DB::table('governance_attendance_corrections')->where('tenant_id', $tenantId)->find($id)], 201);
    }

    public function reviewAttendanceCorrection(Request $request, int $correction): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);
        $tenantId = $this->tenantId();
        $result = DB::transaction(function () use ($tenantId, $correction, $data): object {
            $record = DB::table('governance_attendance_corrections')
                ->where('tenant_id', $tenantId)->where('id', $correction)->lockForUpdate()->first();
            if (!$record) {
                throw new NotFoundHttpException('Correction request was not found.');
            }
            if ($record->status !== 'pending') {
                throw new ConflictHttpException('Correction request has already been reviewed.');
            }
            if ((int) $record->requested_by === $this->actorId()) {
                throw new ConflictHttpException('The correction requester cannot review their own request.');
            }
            if ($data['decision'] === 'approved') {
                $attendance = DB::table('governance_meeting_attendance')
                    ->where('tenant_id', $tenantId)->where('meeting_id', $record->meeting_id)
                    ->where('id', $record->attendance_id)->lockForUpdate()->first();
                if (!$attendance) {
                    throw new NotFoundHttpException('Original attendance record was not found.');
                }
                if (DB::table('governance_meeting_penalties')->where('tenant_id', $tenantId)->where('attendance_id', $attendance->id)->exists()) {
                    throw new ConflictHttpException('A penalty was created after the correction request. Resolve the penalty first.');
                }
                DB::table('governance_meeting_attendance')->where('tenant_id', $tenantId)->where('id', $attendance->id)->update([
                    'attendance_status' => $record->proposed_status,
                    'arrived_at' => $record->proposed_arrived_at,
                    'updated_at' => now(),
                ]);
            }
            DB::table('governance_attendance_corrections')->where('tenant_id', $tenantId)->where('id', $correction)->update([
                'status' => $data['decision'], 'reviewed_by' => $this->actorId(), 'reviewed_at' => now(),
                'review_reason' => $data['reason'], 'updated_at' => now(),
            ]);
            $this->activity($tenantId, (int) $record->meeting_id, 'attendance.correction_reviewed', [
                'correction_id' => $correction, 'decision' => $data['decision'], 'review_reason' => $data['reason'],
            ]);
            return DB::table('governance_attendance_corrections')->where('tenant_id', $tenantId)->find($correction);
        });
        return response()->json(['success' => true, 'data' => $result]);
    }

    public function submitPenaltyDispute(Request $request, int $penalty): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'evidence_reference' => ['nullable', 'string', 'max:2000'],
        ]);
        $tenantId = $this->tenantId();
        $id = DB::transaction(function () use ($tenantId, $penalty, $data): int {
            $record = DB::table('governance_meeting_penalties')
                ->where('tenant_id', $tenantId)->where('id', $penalty)->lockForUpdate()->first();
            if (!$record) {
                throw new NotFoundHttpException('Penalty was not found.');
            }
            if (!in_array($record->status, ['assessed', 'disputed'], true)) {
                throw new ConflictHttpException('Only an assessed, unsettled penalty can be disputed.');
            }
            if (DB::table('governance_penalty_settlements')->where('tenant_id', $tenantId)->where('penalty_id', $penalty)->whereIn('status', ['posted', 'settled'])->exists()) {
                throw new ConflictHttpException('A settled penalty cannot be disputed through this workflow.');
            }
            $id = DB::table('governance_penalty_disputes')->insertGetId([
                'tenant_id' => $tenantId, 'meeting_id' => $record->meeting_id, 'penalty_id' => $penalty,
                'reason' => $data['reason'], 'evidence_reference' => $data['evidence_reference'] ?? null,
                'status' => 'pending', 'submitted_by' => $this->actorId(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('governance_meeting_penalties')->where('tenant_id', $tenantId)->where('id', $penalty)->update(['status' => 'disputed', 'updated_at' => now()]);
            $this->activity($tenantId, (int) $record->meeting_id, 'penalty.disputed', ['penalty_id' => $penalty, 'dispute_id' => $id]);
            return $id;
        });
        return response()->json(['success' => true, 'data' => DB::table('governance_penalty_disputes')->where('tenant_id', $tenantId)->find($id)], 201);
    }

    public function reviewPenaltyDispute(Request $request, int $dispute): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:upheld,waived'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);
        $tenantId = $this->tenantId();
        $result = DB::transaction(function () use ($tenantId, $dispute, $data): object {
            $record = DB::table('governance_penalty_disputes')->where('tenant_id', $tenantId)->where('id', $dispute)->lockForUpdate()->first();
            if (!$record) {
                throw new NotFoundHttpException('Dispute was not found.');
            }
            if ($record->status !== 'pending') {
                throw new ConflictHttpException('Dispute has already been reviewed.');
            }
            if ((int) $record->submitted_by === $this->actorId()) {
                throw new ConflictHttpException('The person submitting a dispute cannot review it.');
            }
            DB::table('governance_penalty_disputes')->where('tenant_id', $tenantId)->where('id', $dispute)->update([
                'status' => $data['decision'], 'reviewed_by' => $this->actorId(), 'reviewed_at' => now(),
                'review_reason' => $data['reason'], 'updated_at' => now(),
            ]);
            DB::table('governance_meeting_penalties')->where('tenant_id', $tenantId)->where('id', $record->penalty_id)->update([
                'status' => $data['decision'] === 'waived' ? 'waived' : 'assessed', 'updated_at' => now(),
            ]);
            $this->activity($tenantId, (int) $record->meeting_id, 'penalty.dispute_reviewed', [
                'dispute_id' => $dispute, 'penalty_id' => $record->penalty_id, 'decision' => $data['decision'],
            ]);
            return DB::table('governance_penalty_disputes')->where('tenant_id', $tenantId)->find($dispute);
        });
        return response()->json(['success' => true, 'data' => $result]);
    }

    private function recordProxyEvent(
        int $tenantId,
        int $meetingId,
        int $proxyId,
        string $eventType,
        ?string $previousStatus,
        ?string $nextStatus,
        ?string $reason,
        ?string $evidenceReference,
    ): void {
        $previousHash = DB::table('governance_meeting_proxy_events')
            ->where('tenant_id', $tenantId)->where('proxy_id', $proxyId)
            ->orderByDesc('id')->value('event_hash');
        $event = [
            'tenant_id' => $tenantId,
            'meeting_id' => $meetingId,
            'proxy_id' => $proxyId,
            'event_type' => $eventType,
            'previous_status' => $previousStatus,
            'next_status' => $nextStatus,
            'actor_id' => $this->actorId(),
            'reason' => $reason,
            'evidence_reference' => $evidenceReference,
            'previous_event_hash' => $previousHash,
            'occurred_at' => now()->toDateTimeString(),
        ];
        $event['event_hash'] = hash('sha256', json_encode($event, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $event['created_at'] = now();
        $event['updated_at'] = now();
        DB::table('governance_meeting_proxy_events')->insert($event);
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
