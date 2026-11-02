<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class MeetingAttendanceService
{
    private function tenantId(): int
    {
        $tenantId = (int) (app()->bound('tenant') ? data_get(app('tenant'), 'id', 0) : 0);
        abort_if($tenantId < 1, 400, 'A tenant context is required.');
        return $tenantId;
    }

    private function actorId(): int
    {
        return (int) request()->user()->id;
    }

    private function meeting(int $id, bool $lock = false): object
    {
        $query = DB::table('governance_meetings')
            ->where('tenant_id', $this->tenantId())->where('id', $id);
        if ($lock) $query->lockForUpdate();
        $meeting = $query->first();
        if (!$meeting) throw new NotFoundHttpException('Meeting not found.');
        return $meeting;
    }

    private function membership(int $id, int $bodyId): object
    {
        $member = DB::table('governance_memberships')
            ->where('tenant_id', $this->tenantId())
            ->where('governance_body_id', $bodyId)
            ->where('id', $id)->where('status', 'active')->first();
        if (!$member) throw ValidationException::withMessages(['membership_id' => 'The member is not active in this meeting body.']);
        return $member;
    }

    private function audit(int $meetingId, string $action, string $type, int $subjectId, ?array $before, ?array $after): void
    {
        DB::table('governance_meeting_audit_events')->insert([
            'tenant_id' => $this->tenantId(), 'meeting_id' => $meetingId,
            'actor_id' => $this->actorId(), 'action' => $action,
            'subject_type' => $type, 'subject_id' => $subjectId,
            'before_values' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null,
            'after_values' => $after ? json_encode($after, JSON_THROW_ON_ERROR) : null,
            'occurred_at' => now(), 
        ]);
    }

    public function list(array $filters = []): array
    {
        $query = DB::table('governance_meetings')->where('tenant_id', $this->tenantId());
        if (!empty($filters['status'])) $query->where('status', $filters['status']);
        if (!empty($filters['meeting_kind'])) $query->where('meeting_kind', $filters['meeting_kind']);
        return $query->orderByDesc('scheduled_at')->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 20))))->toArray();
    }

    public function create(array $data): object
    {
        return DB::transaction(function () use ($data): object {
            $tenantId = $this->tenantId();
            $body = DB::table('governance_bodies')->where('tenant_id', $tenantId)
                ->where('id', $data['governance_body_id'])->first();
            if (!$body) throw ValidationException::withMessages(['governance_body_id' => 'Governance body not found in this tenant.']);
            $expectedType = $data['meeting_kind'] === 'ga' ? 'assembly' : 'board';
            if ($body->body_type !== $expectedType) throw ValidationException::withMessages(['meeting_kind' => 'Meeting kind does not match the selected governance body.']);
            $id = DB::table('governance_meetings')->insertGetId([
                'tenant_id' => $tenantId, 'governance_body_id' => $body->id,
                'meeting_kind' => $data['meeting_kind'], 'title_am' => $data['title_am'] ?? null,
                'title_en' => $data['title_en'] ?? null, 'scheduled_at' => $data['scheduled_at'],
                'timezone' => $data['timezone'] ?? 'Africa/Addis_Ababa', 'status' => 'scheduled',
                'created_by' => $this->actorId(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $meeting = $this->meeting($id);
            $this->audit($id, 'meeting.created', 'meeting', $id, null, (array) $meeting);
            return $meeting;
        });
    }

    public function show(int $id): array
    {
        $meeting = $this->meeting($id);
        return [
            'meeting' => $meeting,
            'proxies' => DB::table('governance_meeting_proxies')->where('tenant_id', $this->tenantId())->where('meeting_id', $id)->orderBy('id')->get(),
            'attendance' => DB::table('governance_meeting_attendance')->where('tenant_id', $this->tenantId())->where('meeting_id', $id)->orderBy('id')->get(),
            'penalties' => DB::table('governance_meeting_penalties')->where('tenant_id', $this->tenantId())->where('meeting_id', $id)->orderBy('id')->get(),
        ];
    }

    public function start(int $id): object
    {
        return DB::transaction(function () use ($id): object {
            $meeting = $this->meeting($id, true);
            if ($meeting->status !== 'scheduled') throw ValidationException::withMessages(['status' => 'Only a scheduled meeting can be started.']);
            $now = now();
            DB::table('governance_meetings')->where('id', $id)->update(['status' => 'open', 'started_at' => $now, 'updated_at' => $now]);
            $updated = $this->meeting($id);
            $this->audit($id, 'meeting.started', 'meeting', $id, ['status' => 'scheduled'], ['status' => 'open', 'started_at' => (string) $now]);
            return $updated;
        });
    }

    public function submitProxy(int $meetingId, array $data): object
    {
        return DB::transaction(function () use ($meetingId, $data): object {
            $meeting = $this->meeting($meetingId, true);
            if ($meeting->status !== 'scheduled') throw ValidationException::withMessages(['meeting_id' => 'Proxy instruments must be received before the meeting starts.']);
            $principal = $this->membership((int) $data['principal_membership_id'], (int) $meeting->governance_body_id);
            $appointed = CarbonImmutable::parse($data['appointed_at']);
            $received = now();
            $cutoff = CarbonImmutable::parse($meeting->scheduled_at, $meeting->timezone)
                ->sub($meeting->meeting_kind === 'ga' ? '3 days' : '3 hours');
            if ($appointed->gt($received) || $received->gt($cutoff)) {
                throw ValidationException::withMessages(['appointed_at' => 'The proxy must be appointed and received by the applicable statutory/policy deadline.']);
            }
            $representativeMembershipId = $data['representative_membership_id'] ?? null;
            if ($meeting->meeting_kind === 'board') {
                if (!$representativeMembershipId) throw ValidationException::withMessages(['representative_membership_id' => 'A Board proxy must be another active director.']);
                if ((int) $representativeMembershipId === (int) $principal->id) throw ValidationException::withMessages(['representative_membership_id' => 'A director cannot represent themselves by proxy.']);
                $this->membership((int) $representativeMembershipId, (int) $meeting->governance_body_id);
                $alreadyHolds = DB::table('governance_meeting_proxies')->where('meeting_id', $meetingId)
                    ->where('representative_membership_id', $representativeMembershipId)->where('status', 'accepted')->exists();
                if ($alreadyHolds) throw ValidationException::withMessages(['representative_membership_id' => 'A director may hold only one proxy for this meeting.']);
            }
            $id = DB::table('governance_meeting_proxies')->insertGetId([
                'tenant_id' => $this->tenantId(), 'meeting_id' => $meetingId,
                'principal_membership_id' => $principal->id,
                'representative_membership_id' => $representativeMembershipId,
                'representative_name' => $data['representative_name'],
                'representative_contact' => $data['representative_contact'] ?? null,
                'instrument_reference' => $data['instrument_reference'],
                'document_path' => $data['document_path'] ?? null,
                'appointed_at' => $appointed, 'received_at' => $received,
                'status' => 'submitted', 'created_at' => $received, 'updated_at' => $received,
            ]);
            $proxy = DB::table('governance_meeting_proxies')->where('id', $id)->first();
            $this->audit($meetingId, 'proxy.submitted', 'proxy', $id, null, (array) $proxy);
            return $proxy;
        });
    }

    public function reviewProxy(int $meetingId, int $proxyId, array $data): object
    {
        return DB::transaction(function () use ($meetingId, $proxyId, $data): object {
            $meeting = $this->meeting($meetingId, true);
            $proxy = DB::table('governance_meeting_proxies')->where('tenant_id', $this->tenantId())
                ->where('meeting_id', $meetingId)->where('id', $proxyId)->lockForUpdate()->first();
            if (!$proxy || $proxy->status !== 'submitted') throw ValidationException::withMessages(['proxy_id' => 'Only a submitted proxy can be reviewed.']);
            $status = $data['decision'] === 'accept' ? 'accepted' : 'rejected';
            if ($status === 'accepted' && $meeting->meeting_kind === 'board') {
                $duplicate = DB::table('governance_meeting_proxies')->where('meeting_id', $meetingId)
                    ->where('representative_membership_id', $proxy->representative_membership_id)
                    ->where('status', 'accepted')->exists();
                if ($duplicate) throw ValidationException::withMessages(['proxy_id' => 'This director already holds a proxy for the meeting.']);
            }
            DB::table('governance_meeting_proxies')->where('id', $proxyId)->update([
                'status' => $status, 'reviewed_by' => $this->actorId(), 'reviewed_at' => now(),
                'review_reason' => $data['reason'] ?? null, 'updated_at' => now(),
            ]);
            $updated = DB::table('governance_meeting_proxies')->where('id', $proxyId)->first();
            $this->audit($meetingId, 'proxy.'.$status, 'proxy', $proxyId, (array) $proxy, (array) $updated);
            return $updated;
        });
    }

    public function recordArrival(int $meetingId, array $data): object
    {
        return DB::transaction(function () use ($meetingId, $data): object {
            $meeting = $this->meeting($meetingId, true);
            if ($meeting->status !== 'open') throw ValidationException::withMessages(['meeting_id' => 'Attendance can only be recorded while the meeting is open.']);
            $member = $this->membership((int) $data['membership_id'], (int) $meeting->governance_body_id);
            $arrivedAt = CarbonImmutable::now($meeting->timezone);
            $proxyId = $data['proxy_id'] ?? null;
            if ($proxyId) {
                $proxy = DB::table('governance_meeting_proxies')->where('tenant_id', $this->tenantId())
                    ->where('meeting_id', $meetingId)->where('id', $proxyId)
                    ->where('principal_membership_id', $member->id)->where('status', 'accepted')->first();
                if (!$proxy) throw ValidationException::withMessages(['proxy_id' => 'An accepted proxy for this member and meeting is required.']);
            }
            $status = $arrivedAt->gt(CarbonImmutable::parse($meeting->scheduled_at, $meeting->timezone)) ? 'late' : ($proxyId ? 'represented' : 'present');
            $id = DB::table('governance_meeting_attendance')->insertGetId([
                'tenant_id' => $this->tenantId(), 'meeting_id' => $meetingId,
                'membership_id' => $member->id, 'proxy_id' => $proxyId, 'status' => $status,
                'arrived_at' => $arrivedAt, 'recorded_by' => $this->actorId(),
                'notes' => $data['notes'] ?? null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($status === 'late') $this->createPenalty($meetingId, (int) $member->id, 'late', 50.00, 'Late arrival; flat ETB 50 per meeting.');
            $attendance = DB::table('governance_meeting_attendance')->where('id', $id)->first();
            $this->audit($meetingId, 'attendance.recorded', 'attendance', $id, null, (array) $attendance);
            return $attendance;
        });
    }

    public function authorizeAbsence(int $meetingId, array $data): object
    {
        return DB::transaction(function () use ($meetingId, $data): object {
            $meeting = $this->meeting($meetingId, true);
            $member = $this->membership((int) $data['membership_id'], (int) $meeting->governance_body_id);
            $existing = DB::table('governance_meeting_attendance')->where('meeting_id', $meetingId)
                ->where('membership_id', $member->id)->lockForUpdate()->first();
            if ($existing && in_array($existing->status, ['present', 'late', 'represented'], true)) {
                throw ValidationException::withMessages(['membership_id' => 'An attended member cannot be marked as excused absent.']);
            }
            $values = [
                'tenant_id' => $this->tenantId(), 'meeting_id' => $meetingId,
                'membership_id' => $member->id, 'proxy_id' => null, 'status' => 'excused',
                'absence_authorized' => true, 'authorization_reference' => $data['authorization_reference'],
                'authorization_reason' => $data['reason'], 'authorized_by' => $this->actorId(),
                'recorded_by' => $this->actorId(), 'created_at' => now(), 'updated_at' => now(),
            ];
            if ($existing) DB::table('governance_meeting_attendance')->where('id', $existing->id)->update($values);
            else DB::table('governance_meeting_attendance')->insert($values);
            $attendance = DB::table('governance_meeting_attendance')->where('meeting_id', $meetingId)->where('membership_id', $member->id)->first();
            $this->audit($meetingId, 'attendance.excused', 'attendance', (int) $attendance->id, $existing ? (array) $existing : null, (array) $attendance);
            return $attendance;
        });
    }

    public function close(int $meetingId): object
    {
        return DB::transaction(function () use ($meetingId): object {
            $meeting = $this->meeting($meetingId, true);
            if ($meeting->status !== 'open') throw ValidationException::withMessages(['status' => 'Only an open meeting can be closed.']);
            $members = DB::table('governance_memberships')->where('tenant_id', $this->tenantId())
                ->where('governance_body_id', $meeting->governance_body_id)->where('status', 'active')->get();
            foreach ($members as $member) {
                $exists = DB::table('governance_meeting_attendance')->where('meeting_id', $meetingId)->where('membership_id', $member->id)->exists();
                if (!$exists) {
                    $attendanceId = DB::table('governance_meeting_attendance')->insertGetId([
                        'tenant_id' => $this->tenantId(), 'meeting_id' => $meetingId, 'membership_id' => $member->id,
                        'status' => 'absent', 'recorded_by' => $this->actorId(), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $this->createPenalty($meetingId, (int) $member->id, 'absence', 300.00, 'Absent without accepted proxy or authorized permission.');
                    $this->audit($meetingId, 'attendance.absent', 'attendance', $attendanceId, null, ['status' => 'absent']);
                }
            }
            DB::table('governance_meetings')->where('id', $meetingId)->update(['status' => 'closed', 'closed_at' => now(), 'updated_at' => now()]);
            $updated = $this->meeting($meetingId);
            $this->audit($meetingId, 'meeting.closed', 'meeting', $meetingId, ['status' => 'open'], ['status' => 'closed']);
            return $updated;
        });
    }

    private function createPenalty(int $meetingId, int $membershipId, string $type, float $amount, string $reason): void
    {
        $exists = DB::table('governance_meeting_penalties')->where('meeting_id', $meetingId)
            ->where('membership_id', $membershipId)->where('penalty_type', $type)->exists();
        if ($exists) return;
        $id = DB::table('governance_meeting_penalties')->insertGetId([
            'tenant_id' => $this->tenantId(), 'meeting_id' => $meetingId, 'membership_id' => $membershipId,
            'penalty_type' => $type, 'amount' => $amount, 'currency' => 'ETB',
            'status' => 'pending_review', 'reason' => $reason, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit($meetingId, 'penalty.pending_review', 'penalty', $id, null, ['amount' => $amount, 'currency' => 'ETB', 'type' => $type]);
    }

    public function reviewPenalty(int $meetingId, int $penaltyId, array $data): object
    {
        return DB::transaction(function () use ($meetingId, $penaltyId, $data): object {
            $this->meeting($meetingId, true);
            $penalty = DB::table('governance_meeting_penalties')->where('tenant_id', $this->tenantId())
                ->where('meeting_id', $meetingId)->where('id', $penaltyId)->lockForUpdate()->first();
            if (!$penalty || $penalty->status !== 'pending_review') throw ValidationException::withMessages(['penalty_id' => 'Only a pending penalty can be assessed or waived.']);
            $attendance = DB::table('governance_meeting_attendance')->where('meeting_id', $meetingId)
                ->where('membership_id', $penalty->membership_id)->first();
            if ($attendance && (int) $attendance->recorded_by === $this->actorId()) {
                throw ValidationException::withMessages(['decision' => 'The attendance recorder cannot approve or waive the resulting penalty.']);
            }
            $status = $data['decision'] === 'assess' ? 'assessed' : 'waived';
            DB::table('governance_meeting_penalties')->where('id', $penaltyId)->update([
                'status' => $status, 'assessed_by' => $this->actorId(), 'assessed_at' => now(),
                'decision_reason' => $data['reason'], 'updated_at' => now(),
            ]);
            $updated = DB::table('governance_meeting_penalties')->where('id', $penaltyId)->first();
            $this->audit($meetingId, 'penalty.'.$status, 'penalty', $penaltyId, (array) $penalty, (array) $updated);
            return $updated;
        });
    }

    public function recordPayment(int $meetingId, int $penaltyId, array $data): object
    {
        return DB::transaction(function () use ($meetingId, $penaltyId, $data): object {
            $this->meeting($meetingId, true);
            $penalty = DB::table('governance_meeting_penalties')->where('tenant_id', $this->tenantId())
                ->where('meeting_id', $meetingId)->where('id', $penaltyId)->lockForUpdate()->first();
            if (!$penalty || !in_array($penalty->status, ['assessed', 'partially_paid'], true)) {
                throw ValidationException::withMessages(['penalty_id' => 'Only an assessed and unpaid penalty can receive payment.']);
            }
            $paid = (float) DB::table('governance_meeting_penalty_payments')->where('penalty_id', $penaltyId)->sum('amount');
            $amount = (float) $data['amount'];
            if ($amount <= 0 || $amount > ((float) $penalty->amount - $paid)) {
                throw ValidationException::withMessages(['amount' => 'Payment must be positive and cannot exceed the outstanding balance.']);
            }
            $id = DB::table('governance_meeting_penalty_payments')->insertGetId([
                'tenant_id' => $this->tenantId(), 'penalty_id' => $penaltyId, 'amount' => $amount,
                'currency' => 'ETB', 'payment_method' => $data['payment_method'],
                'receipt_reference' => $data['receipt_reference'], 'paid_at' => $data['paid_at'] ?? now(),
                'recorded_by' => $this->actorId(), 'finance_reconciliation_status' => 'pending',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $newPaid = $paid + $amount;
            $newStatus = $newPaid >= (float) $penalty->amount ? 'paid' : 'partially_paid';
            DB::table('governance_meeting_penalties')->where('id', $penaltyId)->update(['status' => $newStatus, 'updated_at' => now()]);
            $payment = DB::table('governance_meeting_penalty_payments')->where('id', $id)->first();
            $this->audit($meetingId, 'penalty.payment_recorded', 'payment', $id, null, (array) $payment);
            return $payment;
        });
    }

    public function requestDividendOffset(int $meetingId, int $penaltyId, array $data): object
    {
        return DB::transaction(function () use ($meetingId, $penaltyId, $data): object {
            $this->meeting($meetingId, true);
            $penalty = DB::table('governance_meeting_penalties')->where('tenant_id', $this->tenantId())
                ->where('meeting_id', $meetingId)->where('id', $penaltyId)->lockForUpdate()->first();
            if (!$penalty || !in_array($penalty->status, ['assessed', 'partially_paid'], true)) {
                throw ValidationException::withMessages(['penalty_id' => 'Only an assessed outstanding penalty can be submitted for dividend-settlement review.']);
            }
            $paid = (float) DB::table('governance_meeting_penalty_payments')->where('penalty_id', $penaltyId)->sum('amount');
            $reserved = (float) DB::table('governance_meeting_dividend_settlement_requests')
                ->where('penalty_id', $penaltyId)
                ->whereIn('status', ['pending_finance_legal_review', 'approved', 'reconciled'])
                ->sum('requested_amount');
            $requested = (float) $data['requested_amount'];
            if ($requested <= 0 || $requested > ((float) $penalty->amount - $paid - $reserved)) {
                throw ValidationException::withMessages(['requested_amount' => 'Requested set-off cannot exceed the remaining unreserved penalty balance.']);
            }
            $id = DB::table('governance_meeting_dividend_settlement_requests')->insertGetId([
                'tenant_id' => $this->tenantId(), 'penalty_id' => $penaltyId,
                'shareholder_id' => $data['shareholder_id'], 'dividend_reference' => $data['dividend_reference'],
                'requested_amount' => $requested, 'currency' => 'ETB', 'status' => 'pending_finance_legal_review',
                'requested_by' => $this->actorId(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $settlement = DB::table('governance_meeting_dividend_settlement_requests')->where('id', $id)->first();
            $this->audit($meetingId, 'dividend_settlement.requested', 'dividend_settlement', $id, null, (array) $settlement);
            return $settlement;
        });
    }
}
