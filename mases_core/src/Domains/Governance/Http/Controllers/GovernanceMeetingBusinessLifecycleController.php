<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceMeetingLegalGate;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Illuminate\Validation\ValidationException;

/**
 * Substantive, tenant-scoped General Assembly workflow.
 *
 * Every consequential action is fail-closed on independently verified legal
 * evidence. Thresholds and class voting weights are read only from verified
 * evidence parameters; this controller supplies no statutory defaults.
 *
 * Portal notice rows are queued here. External email/SMS dispatch is not
 * represented as successful delivery; delivery must be separately recorded
 * with a provider/reference artifact.
 */
final class GovernanceMeetingBusinessLifecycleController extends Controller
{
    private function tenantId(): int
    {
        $id = (int) (function_exists('mases_tenant_id') ? mases_tenant_id() : 0);
        if ($id < 1) {
            throw new NotFoundHttpException('Tenant context is required.');
        }
        return $id;
    }

    private function actorId(): int
    {
        return (int) auth()->id();
    }

    private function meeting(int $tenantId, int $meetingId): object
    {
        $meeting = DB::table('governance_meetings')
            ->where('tenant_id', $tenantId)->where('id', $meetingId)->first();
        if (!$meeting || $meeting->meeting_kind !== 'general_assembly') {
            throw new NotFoundHttpException('General Assembly meeting was not found in this tenant.');
        }
        return $meeting;
    }

    private function activity(int $tenantId, int $meetingId, string $action, array $payload = []): void
    {
        DB::table('governance_meeting_activity')->insert([
            'tenant_id' => $tenantId,
            'meeting_id' => $meetingId,
            'actor_id' => $this->actorId(),
            'action' => $action,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'occurred_at' => now(),
        ]);
    }

    /** @return array{basis:object,parameters:array<string,mixed>} */
    private function verifiedEvidence(int $tenantId, int $meetingId, string $action, string $key): array
    {
        $basis = DB::table('governance_meeting_legal_bases')
            ->where('tenant_id', $tenantId)->where('meeting_id', $meetingId)->first();
        if (!$basis) {
            throw new ConflictHttpException('Action blocked: verified legal basis is missing.');
        }
        $rows = DB::table('governance_meeting_legal_evidence')
            ->where('tenant_id', $tenantId)->where('legal_basis_id', $basis->id)
            ->where('action', $action)->get();
        $evidence = [];
        $selected = null;
        foreach ($rows as $row) {
            $parameters = $row->rule_parameters ? json_decode($row->rule_parameters, true) : [];
            $evidence[$row->evidence_key] = [
                'status' => $row->status,
                'source_reference' => $row->source_reference,
                'approved_at' => $row->approved_at,
                'approved_by' => $row->approved_by,
            ];
            if ($row->evidence_key === $key) {
                $selected = $row;
            }
        }
        try {
            (new GovernanceMeetingLegalGate())->assertAllowed($action, [
                'legal_form' => $basis->legal_form,
                'category' => $basis->meeting_category,
                'legal_basis_status' => $basis->status,
                'legal_basis_reference' => $basis->source_reference,
                'legal_basis_verified_at' => $basis->verified_at,
                'legal_basis_verified_by' => $basis->verified_by,
                'created_by' => $basis->created_by,
            ], $evidence);
        } catch (DomainException $exception) {
            throw new ConflictHttpException($exception->getMessage());
        }
        if (!$selected || $selected->status !== 'verified') {
            throw new ConflictHttpException('Required verified evidence item is missing: '.$key);
        }
        return [
            'basis' => $basis,
            'parameters' => $selected->rule_parameters ? (array) json_decode($selected->rule_parameters, true) : [],
        ];
    }

    private function reviewDecision(int $submitterId, string $status, string $decision): void
    {
        if ($status !== 'pending_review') {
            throw new ConflictHttpException('Only pending records can be reviewed.');
        }
        if ($submitterId === $this->actorId()) {
            throw new ConflictHttpException('The submitter cannot review their own record.');
        }
        if (!in_array($decision, ['verified', 'rejected'], true)) {
            throw ValidationException::withMessages(['decision' => 'Decision must be verified or rejected.']);
        }
    }

    public function createNotice(Request $request, int $meeting): JsonResponse
    {
        $data = $request->validate([
            'subject_am' => ['required', 'string', 'max:190'],
            'subject_en' => ['nullable', 'string', 'max:190'],
            'body_am' => ['required', 'string', 'min:10'],
            'body_en' => ['nullable', 'string'],
        ]);
        $tenantId = $this->tenantId();
        $this->meeting($tenantId, $meeting);
        $issued = DB::table('governance_meeting_notices')
            ->where('tenant_id', $tenantId)->where('meeting_id', $meeting)->where('status', 'issued')->exists();
        if ($issued) {
            throw new ConflictHttpException('An issued notice freezes the notice content and agenda.');
        }
        $revision = (int) DB::table('governance_meeting_notices')
            ->where('tenant_id', $tenantId)->where('meeting_id', $meeting)->max('revision') + 1;
        $canonical = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $id = DB::table('governance_meeting_notices')->insertGetId([
            'tenant_id' => $tenantId, 'meeting_id' => $meeting, 'revision' => $revision,
            'subject_am' => $data['subject_am'], 'subject_en' => $data['subject_en'] ?? null,
            'body_am' => $data['body_am'], 'body_en' => $data['body_en'] ?? null,
            'content_sha256' => hash('sha256', $canonical), 'status' => 'pending_review',
            'created_by' => $this->actorId(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->activity($tenantId, $meeting, 'meeting.notice.created', ['notice_id' => $id, 'revision' => $revision]);
        return response()->json(['success' => true, 'data' => DB::table('governance_meeting_notices')->where('tenant_id', $tenantId)->find($id)], 201);
    }

    public function reviewNotice(Request $request, int $notice): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approved,rejected'], 'reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $tenantId = $this->tenantId();
        $row = DB::table('governance_meeting_notices')->where('tenant_id', $tenantId)->where('id', $notice)->first();
        if (!$row) {
            throw new NotFoundHttpException('Notice was not found.');
        }
        $this->reviewDecision((int) $row->created_by, $row->status === 'pending_review' ? 'pending_review' : $row->status, $data['decision'] === 'approved' ? 'verified' : 'rejected');
        DB::table('governance_meeting_notices')->where('tenant_id', $tenantId)->where('id', $notice)->update([
            'status' => $data['decision'], 'reviewed_by' => $this->actorId(),
            'reviewed_at' => now(), 'review_reason' => $data['reason'], 'updated_at' => now(),
        ]);
        $this->activity($tenantId, (int) $row->meeting_id, 'meeting.notice.reviewed', ['notice_id' => $notice, 'decision' => $data['decision']]);
        return response()->json(['success' => true, 'data' => DB::table('governance_meeting_notices')->where('tenant_id', $tenantId)->find($notice)]);
    }

    public function issueNotice(int $meeting, int $notice): JsonResponse
    {
        $tenantId = $this->tenantId();
        $meetingRow = $this->meeting($tenantId, $meeting);
        $rule = $this->verifiedEvidence($tenantId, $meeting, 'notice', 'notice_rule')['parameters'];
        $days = filter_var($rule['notice_period_days'] ?? null, FILTER_VALIDATE_INT);
        if ($days === false || $days < 0) {
            throw new ConflictHttpException('Verified notice evidence must contain a non-negative notice_period_days parameter.');
        }
        $deadline = CarbonImmutable::parse($meetingRow->starts_at)->subDays($days);
        if (now()->greaterThan($deadline)) {
            throw new ConflictHttpException('Notice issue is outside the verified notice-period window.');
        }
        return DB::transaction(function () use ($tenantId, $meeting, $notice): JsonResponse {
            $row = DB::table('governance_meeting_notices')->where('tenant_id', $tenantId)
                ->where('meeting_id', $meeting)->where('id', $notice)->lockForUpdate()->first();
            if (!$row || $row->status !== 'approved') {
                throw new ConflictHttpException('Only an independently approved notice can be issued.');
            }
            $shareholders = DB::table('shareholders')->where('tenant_id', $tenantId)
                ->where('is_active', true)->whereNull('deleted_at')
                ->get(['id', 'user_id', 'representative_user_id']);
            if ($shareholders->isEmpty()) {
                throw new ConflictHttpException('No active shareholders are available for notice issuance.');
            }
            foreach ($shareholders as $shareholder) {
                DB::table('governance_meeting_notice_recipients')->insertOrIgnore([
                    'tenant_id' => $tenantId, 'notice_id' => $notice,
                    'shareholder_id' => $shareholder->id,
                    'recipient_user_id' => $shareholder->user_id ?? $shareholder->representative_user_id,
                    'channel' => 'portal', 'status' => 'queued',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('governance_meeting_notices')->where('tenant_id', $tenantId)->where('id', $notice)
                ->update(['status' => 'issued', 'issued_at' => now(), 'updated_at' => now()]);
            $this->activity($tenantId, $meeting, 'meeting.notice.issued', ['notice_id' => $notice, 'recipient_count' => $shareholders->count()]);
            return response()->json([
                'success' => true,
                'data' => DB::table('governance_meeting_notices')->where('tenant_id', $tenantId)->find($notice),
                'delivery' => ['queued' => $shareholders->count(), 'dispatch_status' => 'not_sent_by_this_endpoint'],
            ]);
        });
    }

    public function recordNoticeDelivery(Request $request, int $recipient): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:delivered,failed'],
            'delivery_reference' => ['required', 'string', 'max:255'],
            'failure_reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $tenantId = $this->tenantId();
        $row = DB::table('governance_meeting_notice_recipients as r')
            ->join('governance_meeting_notices as n', 'n.id', '=', 'r.notice_id')
            ->where('r.tenant_id', $tenantId)->where('r.id', $recipient)
            ->where('n.status', 'issued')->first(['r.*', 'n.meeting_id']);
        if (!$row) {
            throw new NotFoundHttpException('Issued notice recipient was not found.');
        }
        DB::table('governance_meeting_notice_recipients')->where('tenant_id', $tenantId)->where('id', $recipient)->update([
            'status' => $data['status'], 'delivery_reference' => $data['delivery_reference'],
            'delivered_at' => $data['status'] === 'delivered' ? now() : null,
            'failure_reason' => $data['failure_reason'] ?? null,
            'recorded_by' => $this->actorId(), 'updated_at' => now(),
        ]);
        $this->activity($tenantId, (int) $row->meeting_id, 'meeting.notice.delivery_recorded', ['recipient_id' => $recipient, 'status' => $data['status']]);
        return response()->json(['success' => true, 'data' => DB::table('governance_meeting_notice_recipients')->where('tenant_id', $tenantId)->find($recipient)]);
    }

    public function createAgendaItem(Request $request, int $meeting): JsonResponse
    {
        $data = $request->validate([
            'sort_order' => ['required', 'integer', 'min:1'],
            'title_am' => ['required', 'string', 'max:255'],
            'title_en' => ['nullable', 'string', 'max:255'],
            'description_am' => ['nullable', 'string'],
            'description_en' => ['nullable', 'string'],
        ]);
        $tenantId = $this->tenantId();
        $this->meeting($tenantId, $meeting);
        if (DB::table('governance_meeting_notices')->where('tenant_id', $tenantId)->where('meeting_id', $meeting)->where('status', 'issued')->exists()) {
            throw new ConflictHttpException('Agenda is frozen after notice issuance.');
        }
        $id = DB::table('governance_meeting_agenda_items')->insertGetId([
            'tenant_id' => $tenantId, 'meeting_id' => $meeting,
            ...$data, 'status' => 'open', 'created_by' => $this->actorId(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->activity($tenantId, $meeting, 'meeting.agenda_item.created', ['agenda_item_id' => $id]);
        return response()->json(['success' => true, 'data' => DB::table('governance_meeting_agenda_items')->where('tenant_id', $tenantId)->find($id)], 201);
    }

    public function snapshotElectorate(Request $request, int $meeting): JsonResponse
    {
        $data = $request->validate([
            'record_date' => ['required', 'date'],
            'source_reference' => ['required', 'string', 'max:2000'],
            'source_sha256' => ['required', 'regex:/^[a-fA-F0-9]{64}$/'],
        ]);
        $tenantId = $this->tenantId();
        $this->meeting($tenantId, $meeting);
        $votingRule = $this->verifiedEvidence($tenantId, $meeting, 'voting', 'voting_rights_rule')['parameters'];
        $weights = $votingRule['votes_per_share_by_class'] ?? null;
        if (!is_array($weights) || $weights === []) {
            throw new ConflictHttpException('Verified voting-rights evidence must define votes_per_share_by_class.');
        }
        $holdings = DB::table('share_holdings as h')
            ->join('shareholders as s', 's.id', '=', 'h.shareholder_id')
            ->join('share_classes as c', 'c.id', '=', 'h.share_class_id')
            ->where('s.tenant_id', $tenantId)->where('s.is_active', true)
            ->whereNull('s.deleted_at')->where('h.current_quantity', '>', 0)
            ->get(['h.id as holding_id', 'h.shareholder_id', 'h.share_class_id', 'h.current_quantity', 'c.code']);
        if ($holdings->isEmpty()) {
            throw new ConflictHttpException('No active positive share holdings exist; an electorate snapshot cannot be created.');
        }
        $members = [];
        $totalShares = 0.0;
        $totalPower = 0.0;
        foreach ($holdings as $holding) {
            $latest = DB::table('share_holding_entries')->where('share_holding_id', $holding->holding_id)->orderByDesc('id')->first();
            if (!$latest || abs((float) $latest->balance_after - (float) $holding->current_quantity) > 0.00001) {
                throw new ConflictHttpException('ShareHolding projection does not reconcile to its append-only ledger; snapshot blocked.');
            }
            $weight = $weights[$holding->code] ?? null;
            if (!is_numeric($weight) || (float) $weight <= 0) {
                throw new ConflictHttpException('Verified voting-rights evidence has no positive votes-per-share weight for class '.$holding->code.'.');
            }
            $shares = (float) $holding->current_quantity;
            $power = $shares * (float) $weight;
            $members[] = [
                'shareholder_id' => (int) $holding->shareholder_id,
                'share_class_id' => (int) $holding->share_class_id,
                'shares' => $holding->current_quantity,
                'votes_per_share' => (string) $weight,
                'voting_power' => number_format($power, 4, '.', ''),
            ];
            $totalShares += $shares;
            $totalPower += $power;
        }
        $revision = (int) DB::table('governance_meeting_electorate_snapshots')
            ->where('tenant_id', $tenantId)->where('meeting_id', $meeting)->max('revision') + 1;
        $snapshotId = DB::transaction(function () use ($tenantId, $meeting, $data, $revision, $members, $totalShares, $totalPower): int {
            $id = DB::table('governance_meeting_electorate_snapshots')->insertGetId([
                'tenant_id' => $tenantId, 'meeting_id' => $meeting, 'revision' => $revision,
                'record_date' => $data['record_date'], 'source_reference' => $data['source_reference'],
                'source_sha256' => strtolower($data['source_sha256']),
                'total_shares' => number_format($totalShares, 4, '.', ''),
                'total_voting_power' => number_format($totalPower, 4, '.', ''),
                'status' => 'pending_review', 'created_by' => $this->actorId(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($members as $member) {
                DB::table('governance_meeting_electorate_members')->insert([
                    'tenant_id' => $tenantId, 'snapshot_id' => $id, ...$member,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $this->activity($tenantId, $meeting, 'meeting.electorate.snapshot_created', ['snapshot_id' => $id, 'revision' => $revision]);
            return $id;
        });
        return response()->json(['success' => true, 'data' => DB::table('governance_meeting_electorate_snapshots')->where('tenant_id', $tenantId)->find($snapshotId)], 201);
    }

    public function reviewElectorate(Request $request, int $snapshot): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:verified,rejected'], 'reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $tenantId = $this->tenantId();
        $row = DB::table('governance_meeting_electorate_snapshots')->where('tenant_id', $tenantId)->where('id', $snapshot)->first();
        if (!$row) {
            throw new NotFoundHttpException('Electorate snapshot was not found.');
        }
        $this->reviewDecision((int) $row->created_by, $row->status, $data['decision'] === 'verified' ? 'verified' : 'rejected');
        DB::table('governance_meeting_electorate_snapshots')->where('tenant_id', $tenantId)->where('id', $snapshot)->update([
            'status' => $data['decision'], 'reviewed_by' => $this->actorId(), 'reviewed_at' => now(),
            'review_reason' => $data['reason'], 'updated_at' => now(),
        ]);
        $this->activity($tenantId, (int) $row->meeting_id, 'meeting.electorate.reviewed', ['snapshot_id' => $snapshot, 'decision' => $data['decision']]);
        return response()->json(['success' => true, 'data' => DB::table('governance_meeting_electorate_snapshots')->where('tenant_id', $tenantId)->find($snapshot)]);
    }

    public function assessQuorum(int $meeting, int $snapshot): JsonResponse
    {
        $tenantId = $this->tenantId();
        $this->meeting($tenantId, $meeting);
        $rule = $this->verifiedEvidence($tenantId, $meeting, 'quorum', 'quorum_rule')['parameters'];
        $num = filter_var($rule['threshold_numerator'] ?? null, FILTER_VALIDATE_INT);
        $den = filter_var($rule['threshold_denominator'] ?? null, FILTER_VALIDATE_INT);
        $basis = $rule['basis'] ?? null;
        if ($num === false || $den === false || $num < 1 || $den < 1 || $num > $den || !in_array($basis, ['shares', 'voting_power', 'shareholders'], true)) {
            throw new ConflictHttpException('Verified quorum evidence must define valid threshold_numerator, threshold_denominator and basis.');
        }
        $snap = DB::table('governance_meeting_electorate_snapshots')->where('tenant_id', $tenantId)
            ->where('meeting_id', $meeting)->where('id', $snapshot)->where('status', 'verified')->first();
        if (!$snap) {
            throw new ConflictHttpException('A verified electorate snapshot is required before quorum assessment.');
        }
        $members = DB::table('governance_meeting_electorate_members')->where('tenant_id', $tenantId)->where('snapshot_id', $snapshot)->get();
        $eligible = 0.0;
        $present = 0.0;
        foreach ($members as $member) {
            $unit = $basis === 'shares' ? (float) $member->shares : (float) $member->voting_power;
            $eligible += $basis === 'shareholders' ? 1 : $unit;
            $attendance = DB::table('governance_meeting_attendance')->where('tenant_id', $tenantId)
                ->where('meeting_id', $meeting)->where('subject_type', 'shareholder')
                ->where('subject_id', $member->shareholder_id)
                ->whereIn('attendance_status', ['present', 'late', 'represented'])->first();
            if ($attendance) {
                $present += $basis === 'shareholders' ? 1 : $unit;
            }
        }
        if ($eligible <= 0) {
            throw new ConflictHttpException('Electorate denominator is zero.');
        }
        $required = (float) ceil($eligible * $num / $den);
        $id = DB::table('governance_meeting_quorum_assessments')->insertGetId([
            'tenant_id' => $tenantId, 'meeting_id' => $meeting, 'snapshot_id' => $snapshot,
            'eligible_units' => number_format($eligible, 4, '.', ''),
            'present_units' => number_format($present, 4, '.', ''),
            'required_units' => number_format($required, 4, '.', ''),
            'threshold_numerator' => $num, 'threshold_denominator' => $den,
            'basis' => $basis, 'quorum_met' => $present >= $required,
            'status' => 'pending_review', 'calculated_by' => $this->actorId(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->activity($tenantId, $meeting, 'meeting.quorum.assessed', ['assessment_id' => $id, 'quorum_met' => $present >= $required]);
        return response()->json(['success' => true, 'data' => DB::table('governance_meeting_quorum_assessments')->where('tenant_id', $tenantId)->find($id)], 201);
    }

    public function reviewQuorum(Request $request, int $assessment): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:verified,rejected'], 'reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $tenantId = $this->tenantId();
        $row = DB::table('governance_meeting_quorum_assessments')->where('tenant_id', $tenantId)->where('id', $assessment)->first();
        if (!$row) {
            throw new NotFoundHttpException('Quorum assessment was not found.');
        }
        $this->reviewDecision((int) $row->calculated_by, $row->status, $data['decision']);
        DB::table('governance_meeting_quorum_assessments')->where('tenant_id', $tenantId)->where('id', $assessment)->update([
            'status' => $data['decision'], 'reviewed_by' => $this->actorId(), 'reviewed_at' => now(),
            'review_reason' => $data['reason'], 'updated_at' => now(),
        ]);
        $this->activity($tenantId, (int) $row->meeting_id, 'meeting.quorum.reviewed', ['assessment_id' => $assessment, 'decision' => $data['decision']]);
        return response()->json(['success' => true, 'data' => DB::table('governance_meeting_quorum_assessments')->where('tenant_id', $tenantId)->find($assessment)]);
    }

    public function createMotion(Request $request, int $meeting): JsonResponse
    {
        $data = $request->validate([
            'agenda_item_id' => ['nullable', 'integer', 'min:1'],
            'motion_number' => ['required', 'integer', 'min:1'],
            'text_am' => ['required', 'string', 'min:5'],
            'text_en' => ['nullable', 'string'],
        ]);
        $tenantId = $this->tenantId();
        $this->meeting($tenantId, $meeting);
        if (!empty($data['agenda_item_id']) && !DB::table('governance_meeting_agenda_items')
            ->where('tenant_id', $tenantId)->where('meeting_id', $meeting)->where('id', $data['agenda_item_id'])->exists()) {
            throw new NotFoundHttpException('Agenda item was not found in this meeting.');
        }
        $id = DB::table('governance_meeting_motions')->insertGetId([
            'tenant_id' => $tenantId, 'meeting_id' => $meeting,
            'agenda_item_id' => $data['agenda_item_id'] ?? null, 'motion_number' => $data['motion_number'],
            'text_am' => $data['text_am'], 'text_en' => $data['text_en'] ?? null,
            'status' => 'proposed', 'proposed_by' => $this->actorId(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->activity($tenantId, $meeting, 'meeting.motion.proposed', ['motion_id' => $id]);
        return response()->json(['success' => true, 'data' => DB::table('governance_meeting_motions')->where('tenant_id', $tenantId)->find($id)], 201);
    }

    public function secondMotion(int $motion): JsonResponse
    {
        $tenantId = $this->tenantId();
        $row = DB::table('governance_meeting_motions')->where('tenant_id', $tenantId)->where('id', $motion)->first();
        if (!$row) {
            throw new NotFoundHttpException('Motion was not found.');
        }
        if ($row->status !== 'proposed' || (int) $row->proposed_by === $this->actorId()) {
            throw new ConflictHttpException('A motion must be seconded by a different authorized participant while proposed.');
        }
        DB::table('governance_meeting_motions')->where('tenant_id', $tenantId)->where('id', $motion)->update([
            'status' => 'seconded', 'seconded_by' => $this->actorId(), 'updated_at' => now(),
        ]);
        $this->activity($tenantId, (int) $row->meeting_id, 'meeting.motion.seconded', ['motion_id' => $motion]);
        return response()->json(['success' => true, 'data' => DB::table('governance_meeting_motions')->where('tenant_id', $tenantId)->find($motion)]);
    }

    public function openVoting(int $motion): JsonResponse
    {
        $tenantId = $this->tenantId();
        $row = DB::table('governance_meeting_motions')->where('tenant_id', $tenantId)->where('id', $motion)->first();
        if (!$row) {
            throw new NotFoundHttpException('Motion was not found.');
        }
        $this->verifiedEvidence($tenantId, (int) $row->meeting_id, 'voting', 'voting_rights_rule');
        $quorum = DB::table('governance_meeting_quorum_assessments')->where('tenant_id', $tenantId)
            ->where('meeting_id', $row->meeting_id)->where('status', 'verified')->orderByDesc('id')->first();
        if (!$quorum || !$quorum->quorum_met) {
            throw new ConflictHttpException('Voting cannot open without a verified quorum assessment that meets quorum.');
        }
        if ($row->status !== 'seconded') {
            throw new ConflictHttpException('Only a seconded motion can enter voting.');
        }
        DB::table('governance_meeting_motions')->where('tenant_id', $tenantId)->where('id', $motion)->update([
            'status' => 'voting_open', 'voting_opened_at' => now(), 'updated_at' => now(),
        ]);
        $this->activity($tenantId, (int) $row->meeting_id, 'meeting.motion.voting_opened', ['motion_id' => $motion]);
        return response()->json(['success' => true, 'data' => DB::table('governance_meeting_motions')->where('tenant_id', $tenantId)->find($motion)]);
    }

    public function castBallot(Request $request, int $motion): JsonResponse
    {
        $data = $request->validate(['shareholder_id' => ['required', 'integer', 'min:1'], 'choice' => ['required', 'in:for,against,abstain']]);
        $tenantId = $this->tenantId();
        $row = DB::table('governance_meeting_motions')->where('tenant_id', $tenantId)->where('id', $motion)->first();
        if (!$row || $row->status !== 'voting_open') {
            throw new ConflictHttpException('Motion is not open for voting.');
        }
        $snapshot = DB::table('governance_meeting_electorate_snapshots')->where('tenant_id', $tenantId)
            ->where('meeting_id', $row->meeting_id)->where('status', 'verified')->orderByDesc('revision')->first();
        if (!$snapshot) {
            throw new ConflictHttpException('A verified electorate snapshot is required.');
        }
        $power = DB::table('governance_meeting_electorate_members')->where('tenant_id', $tenantId)
            ->where('snapshot_id', $snapshot->id)->where('shareholder_id', $data['shareholder_id'])->sum('voting_power');
        if ((float) $power <= 0) {
            throw new ConflictHttpException('Shareholder has no voting power in the verified electorate snapshot.');
        }
        $attendance = DB::table('governance_meeting_attendance')->where('tenant_id', $tenantId)
            ->where('meeting_id', $row->meeting_id)->where('subject_type', 'shareholder')
            ->where('subject_id', $data['shareholder_id'])->whereIn('attendance_status', ['present', 'late', 'represented'])->first();
        if (!$attendance) {
            throw new ConflictHttpException('Only present or validly represented shareholders may cast a ballot.');
        }
        $shareholder = DB::table('shareholders')->where('tenant_id', $tenantId)->where('id', $data['shareholder_id'])
            ->first(['user_id', 'representative_user_id']);
        $actor = $this->actorId();
        if ($attendance->attendance_status === 'represented') {
            $proxy = DB::table('governance_meeting_proxies')->where('tenant_id', $tenantId)
                ->where('meeting_id', $row->meeting_id)->where('principal_type', 'shareholder')
                ->where('principal_id', $data['shareholder_id'])->where('status', 'approved')->first();
            if (!$proxy || (int) ($proxy->proxy_user_id ?? 0) !== $actor) {
                throw new ConflictHttpException('Represented ballots must be cast by the authenticated approved proxy user.');
            }
        } elseif (!$shareholder || $actor !== (int) ($shareholder->user_id ?? $shareholder->representative_user_id ?? 0)) {
            throw new ConflictHttpException('Authenticated actor is not the shareholder or its registered representative.');
        }
        $id = DB::table('governance_meeting_ballots')->insertGetId([
            'tenant_id' => $tenantId, 'meeting_id' => $row->meeting_id, 'motion_id' => $motion,
            'shareholder_id' => $data['shareholder_id'], 'cast_by' => $actor,
            'choice' => $data['choice'], 'voting_power' => number_format((float) $power, 4, '.', ''),
            'cast_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->activity($tenantId, (int) $row->meeting_id, 'meeting.ballot.cast', ['motion_id' => $motion, 'ballot_id' => $id]);
        return response()->json(['success' => true, 'data' => ['id' => $id, 'choice' => $data['choice'], 'voting_power' => number_format((float) $power, 4, '.', '')]], 201);
    }

    public function closeVoting(Request $request, int $motion): JsonResponse
    {
        $data = $request->validate(['resolution_number' => ['required', 'string', 'max:64'], 'text_am' => ['nullable', 'string'], 'text_en' => ['nullable', 'string']]);
        $tenantId = $this->tenantId();
        $row = DB::table('governance_meeting_motions')->where('tenant_id', $tenantId)->where('id', $motion)->first();
        if (!$row || $row->status !== 'voting_open') {
            throw new ConflictHttpException('Only an open motion can be closed.');
        }
        $rule = $this->verifiedEvidence($tenantId, (int) $row->meeting_id, 'resolution', 'resolution_approval_rule')['parameters'];
        $num = filter_var($rule['threshold_numerator'] ?? null, FILTER_VALIDATE_INT);
        $den = filter_var($rule['threshold_denominator'] ?? null, FILTER_VALIDATE_INT);
        $basis = $rule['basis'] ?? null;
        if ($num === false || $den === false || $num < 1 || $den < 1 || $num > $den || !in_array($basis, ['votes_cast', 'eligible_voting_power'], true)) {
            throw new ConflictHttpException('Verified resolution evidence must define valid threshold_numerator, threshold_denominator and basis.');
        }
        $ballots = DB::table('governance_meeting_ballots')->where('tenant_id', $tenantId)->where('motion_id', $motion)->get();
        $for = (float) $ballots->where('choice', 'for')->sum('voting_power');
        $against = (float) $ballots->where('choice', 'against')->sum('voting_power');
        $abstain = (float) $ballots->where('choice', 'abstain')->sum('voting_power');
        $denominator = $basis === 'votes_cast'
            ? $for + $against + $abstain
            : (float) DB::table('governance_meeting_electorate_snapshots')->where('tenant_id', $tenantId)
                ->where('meeting_id', $row->meeting_id)->where('status', 'verified')->orderByDesc('revision')->value('total_voting_power');
        if ($denominator <= 0) {
            throw new ConflictHttpException('Resolution denominator is zero.');
        }
        $passed = ($for * $den) >= ($denominator * $num);
        $resolutionId = DB::transaction(function () use ($tenantId, $row, $motion, $data, $num, $den, $for, $against, $abstain, $passed): int {
            $id = DB::table('governance_meeting_resolutions')->insertGetId([
                'tenant_id' => $tenantId, 'meeting_id' => $row->meeting_id, 'motion_id' => $motion,
                'resolution_number' => $data['resolution_number'],
                'text_am' => $data['text_am'] ?? $row->text_am, 'text_en' => $data['text_en'] ?? $row->text_en,
                'votes_for' => number_format($for, 4, '.', ''),
                'votes_against' => number_format($against, 4, '.', ''),
                'votes_abstain' => number_format($abstain, 4, '.', ''),
                'threshold_numerator' => $num, 'threshold_denominator' => $den,
                'result' => $passed ? 'passed' : 'rejected', 'recorded_by' => $this->actorId(),
                'decided_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('governance_meeting_motions')->where('tenant_id', $tenantId)->where('id', $motion)->update([
                'status' => 'voting_closed', 'voting_closed_at' => now(), 'updated_at' => now(),
            ]);
            $this->activity($tenantId, (int) $row->meeting_id, 'meeting.resolution.recorded', ['resolution_id' => $id, 'result' => $passed ? 'passed' : 'rejected']);
            return $id;
        });
        return response()->json(['success' => true, 'data' => DB::table('governance_meeting_resolutions')->where('tenant_id', $tenantId)->find($resolutionId)], 201);
    }

    public function createMinutes(Request $request, int $meeting): JsonResponse
    {
        $data = $request->validate(['content_am' => ['required', 'string', 'min:20'], 'content_en' => ['nullable', 'string']]);
        $tenantId = $this->tenantId();
        $this->meeting($tenantId, $meeting);
        $this->verifiedEvidence($tenantId, $meeting, 'minutes', 'minutes_approval_rule');
        $snapshot = [
            'meeting' => DB::table('governance_meetings')->where('tenant_id', $tenantId)->find($meeting),
            'agenda' => DB::table('governance_meeting_agenda_items')->where('tenant_id', $tenantId)->where('meeting_id', $meeting)->orderBy('sort_order')->get(),
            'attendance' => DB::table('governance_meeting_attendance')->where('tenant_id', $tenantId)->where('meeting_id', $meeting)->orderBy('id')->get(),
            'quorum' => DB::table('governance_meeting_quorum_assessments')->where('tenant_id', $tenantId)->where('meeting_id', $meeting)->where('status', 'verified')->orderByDesc('id')->first(),
            'motions' => DB::table('governance_meeting_motions')->where('tenant_id', $tenantId)->where('meeting_id', $meeting)->orderBy('motion_number')->get(),
            'resolutions' => DB::table('governance_meeting_resolutions')->where('tenant_id', $tenantId)->where('meeting_id', $meeting)->orderBy('id')->get(),
            'notices' => DB::table('governance_meeting_notices')->where('tenant_id', $tenantId)->where('meeting_id', $meeting)->orderBy('revision')->get(),
        ];
        $snapshotJson = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $version = (int) DB::table('governance_meeting_minutes')->where('tenant_id', $tenantId)->where('meeting_id', $meeting)->max('version') + 1;
        $id = DB::table('governance_meeting_minutes')->insertGetId([
            'tenant_id' => $tenantId, 'meeting_id' => $meeting, 'version' => $version,
            'content_am' => $data['content_am'], 'content_en' => $data['content_en'] ?? null,
            'record_snapshot' => $snapshotJson,
            'content_sha256' => hash('sha256', $data['content_am']."\n".($data['content_en'] ?? '')."\n".$snapshotJson),
            'status' => 'draft', 'created_by' => $this->actorId(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->activity($tenantId, $meeting, 'meeting.minutes.draft_created', ['minutes_id' => $id, 'version' => $version]);
        return response()->json(['success' => true, 'data' => DB::table('governance_meeting_minutes')->where('tenant_id', $tenantId)->find($id)], 201);
    }

    public function submitMinutes(int $minutes): JsonResponse
    {
        $tenantId = $this->tenantId();
        $row = DB::table('governance_meeting_minutes')->where('tenant_id', $tenantId)->where('id', $minutes)->first();
        if (!$row) {
            throw new NotFoundHttpException('Minutes were not found.');
        }
        if ($row->status !== 'draft' || (int) $row->created_by !== $this->actorId()) {
            throw new ConflictHttpException('Only the author may submit an unsubmitted minutes draft.');
        }
        DB::table('governance_meeting_minutes')->where('tenant_id', $tenantId)->where('id', $minutes)->update(['status' => 'pending_review', 'updated_at' => now()]);
        $this->activity($tenantId, (int) $row->meeting_id, 'meeting.minutes.submitted', ['minutes_id' => $minutes]);
        return response()->json(['success' => true, 'data' => DB::table('governance_meeting_minutes')->where('tenant_id', $tenantId)->find($minutes)]);
    }

    public function reviewMinutes(Request $request, int $minutes): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approved,rejected'], 'reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $tenantId = $this->tenantId();
        $row = DB::table('governance_meeting_minutes')->where('tenant_id', $tenantId)->where('id', $minutes)->first();
        if (!$row) {
            throw new NotFoundHttpException('Minutes were not found.');
        }
        $this->reviewDecision((int) $row->created_by, $row->status, $data['decision'] === 'approved' ? 'verified' : 'rejected');
        $this->verifiedEvidence($tenantId, (int) $row->meeting_id, 'minutes', 'minutes_approval_rule');
        DB::table('governance_meeting_minutes')->where('tenant_id', $tenantId)->where('id', $minutes)->update([
            'status' => $data['decision'], 'reviewed_by' => $this->actorId(), 'reviewed_at' => now(),
            'review_reason' => $data['reason'], 'updated_at' => now(),
        ]);
        $this->activity($tenantId, (int) $row->meeting_id, 'meeting.minutes.reviewed', ['minutes_id' => $minutes, 'decision' => $data['decision']]);
        return response()->json(['success' => true, 'data' => DB::table('governance_meeting_minutes')->where('tenant_id', $tenantId)->find($minutes)]);
    }
}
