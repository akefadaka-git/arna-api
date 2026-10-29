<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceMeeting;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceMembership;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class GovernanceMeetingWorkflowController extends Controller
{
    public function agenda(Request $request, GovernanceMeeting $governanceMeeting): JsonResponse
    {
        $this->authorizeMeeting($request, $governanceMeeting, 'view');
        $items = DB::table('governance_meeting_agenda_items')
            ->where('tenant_id', $this->tenantId())->where('meeting_id', $governanceMeeting->id)
            ->orderBy('position')->orderBy('id')->get();
        return response()->json(['success' => true, 'data' => $items]);
    }

    public function addAgendaItem(Request $request, GovernanceMeeting $governanceMeeting): JsonResponse
    {
        $this->authorizeMeeting($request, $governanceMeeting, 'update');
        $data = $request->validate([
            'position' => ['required', 'integer', 'min:1', 'max:1000'],
            'title_am' => ['nullable', 'string', 'max:200'],
            'title_en' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:10000'],
        ]);
        if (blank($data['title_am'] ?? null) && blank($data['title_en'] ?? null)) {
            throw ValidationException::withMessages(['title_am' => ['Provide an Amharic or English agenda title.']]);
        }
        if ($governanceMeeting->status !== 'draft') {
            throw new ConflictHttpException('Agenda can only be edited while the meeting is a draft.');
        }
        $id = DB::table('governance_meeting_agenda_items')->insertGetId([
            'tenant_id' => $this->tenantId(), 'meeting_id' => $governanceMeeting->id,
            'position' => $data['position'], 'title_am' => $data['title_am'] ?? null,
            'title_en' => $data['title_en'] ?? null, 'description' => $data['description'] ?? null,
            'status' => 'pending', 'created_by' => (int) $request->user()->getAuthIdentifier(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit($governanceMeeting, (int) $request->user()->getAuthIdentifier(), 'agenda_item.created', null, ['agenda_item_id' => $id] + $data);
        return response()->json(['success' => true, 'data' => DB::table('governance_meeting_agenda_items')->where('id', $id)->first()], 201);
    }

    public function attendance(Request $request, GovernanceMeeting $governanceMeeting): JsonResponse
    {
        $this->authorizeMeeting($request, $governanceMeeting, 'view');
        $rows = DB::table('governance_meeting_attendance as a')
            ->join('governance_memberships as m', 'm.id', '=', 'a.governance_membership_id')
            ->where('a.tenant_id', $this->tenantId())->where('a.meeting_id', $governanceMeeting->id)
            ->select('a.id', 'a.governance_membership_id', 'a.attendance_status', 'a.recorded_at', 'a.notes', 'm.person_id', 'm.status as membership_status')
            ->orderBy('m.person_id')->get();
        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function recordAttendance(Request $request, GovernanceMeeting $governanceMeeting): JsonResponse
    {
        $this->authorizeMeeting($request, $governanceMeeting, 'update');
        $data = $request->validate([
            'governance_membership_id' => ['required', 'integer', 'min:1'],
            'attendance_status' => ['required', 'in:present,absent,excused,remote'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        if (!in_array($governanceMeeting->status, ['scheduled', 'in_progress', 'completed'], true)) {
            throw new ConflictHttpException('Attendance may only be recorded for scheduled or active/completed meetings.');
        }
        $membership = GovernanceMembership::query()->whereKey($data['governance_membership_id'])
            ->where('governance_body_id', $governanceMeeting->governance_body_id)
            ->where('tenant_id', $this->tenantId())->firstOrFail();
        $actor = (int) $request->user()->getAuthIdentifier();
        $row = DB::transaction(function () use ($data, $governanceMeeting, $membership, $actor): object {
            $existing = DB::table('governance_meeting_attendance')->where('meeting_id', $governanceMeeting->id)
                ->where('governance_membership_id', $membership->id)->lockForUpdate()->first();
            $values = ['attendance_status' => $data['attendance_status'], 'recorded_at' => now(), 'recorded_by' => $actor, 'notes' => $data['notes'] ?? null, 'updated_at' => now()];
            if ($existing) {
                DB::table('governance_meeting_attendance')->where('id', $existing->id)->update($values);
                $id = $existing->id;
            } else {
                $id = DB::table('governance_meeting_attendance')->insertGetId($values + [
                    'tenant_id' => $this->tenantId(), 'meeting_id' => $governanceMeeting->id,
                    'governance_membership_id' => $membership->id, 'created_at' => now(),
                ]);
            }
            return DB::table('governance_meeting_attendance')->where('id', $id)->first();
        });
        $this->audit($governanceMeeting, $actor, 'attendance.recorded', null, ['membership_id' => $membership->id, 'status' => $data['attendance_status']]);
        return response()->json(['success' => true, 'data' => $row]);
    }

    public function evaluateQuorum(Request $request, GovernanceMeeting $governanceMeeting): JsonResponse
    {
        $this->authorizeMeeting($request, $governanceMeeting, 'view');
        $tenantId = $this->tenantId();
        $eligible = GovernanceMembership::query()->where('tenant_id', $tenantId)
            ->where('governance_body_id', $governanceMeeting->governance_body_id)
            ->where('status', 'active')->count();
        $present = DB::table('governance_meeting_attendance as a')
            ->join('governance_memberships as m', 'm.id', '=', 'a.governance_membership_id')
            ->where('a.tenant_id', $tenantId)->where('a.meeting_id', $governanceMeeting->id)
            ->where('m.status', 'active')->whereIn('a.attendance_status', ['present', 'remote'])->count();
        $threshold = max(1, (int) ceil($eligible * 0.5));
        $snapshot = ['rule' => 'active_members_at_evaluation', 'eligible_voting_members' => $eligible, 'present_voting_members' => $present, 'threshold_percent' => 50, 'threshold_count' => $threshold, 'quorum_met' => $eligible > 0 && $present >= $threshold, 'evaluated_at' => now()->toIso8601String()];
        DB::table('governance_meetings')->where('tenant_id', $tenantId)->where('id', $governanceMeeting->id)->update([
            'quorum_snapshot_json' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'updated_at' => now(),
        ]);
        $this->audit($governanceMeeting, (int) $request->user()->getAuthIdentifier(), 'quorum.evaluated', null, $snapshot);
        return response()->json(['success' => true, 'data' => $snapshot]);
    }

    public function auditEvents(Request $request, GovernanceMeeting $governanceMeeting): JsonResponse
    {
        $this->authorizeMeeting($request, $governanceMeeting, 'view');
        $events = DB::table('governance_meeting_audit_events')->where('tenant_id', $this->tenantId())
            ->where('meeting_id', $governanceMeeting->id)->orderByDesc('occurred_at')->limit(200)->get();
        return response()->json(['success' => true, 'data' => $events]);
    }

    private function authorizeMeeting(Request $request, GovernanceMeeting $meeting, string $ability): void
    {
        if ((int) $meeting->tenant_id !== $this->tenantId()) {
            throw new NotFoundHttpException();
        }
        Gate::authorize($ability, $meeting);
    }

    private function tenantId(): int
    {
        $tenantId = (int) app('tenant.context')->id();
        if ($tenantId < 1) {
            throw new NotFoundHttpException();
        }
        return $tenantId;
    }

    /** @param array<string, mixed>|null $before @param array<string, mixed>|null $after */
    private function audit(GovernanceMeeting $meeting, ?int $actorId, string $event, ?array $before, ?array $after): void
    {
        DB::table('governance_meeting_audit_events')->insert([
            'tenant_id' => $this->tenantId(), 'meeting_id' => $meeting->id, 'actor_id' => $actorId,
            'event_type' => $event, 'before_json' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null,
            'after_json' => $after ? json_encode($after, JSON_THROW_ON_ERROR) : null,
            'correlation_id' => request()->headers->get('X-Correlation-ID'),
            'occurred_at' => now(),
        ]);
    }
}
