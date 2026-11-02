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
            $legalEntityId = $data['finance_legal_entity_id'] ?? null;
            if ($legalEntityId !== null) {
                $entity = DB::table('legal_entities')->where('tenant_id', $tenantId)
                    ->where('id', $legalEntityId)->where('status', 'active')->first();
                if (!$entity) throw ValidationException::withMessages(['finance_legal_entity_id' => 'The Finance legal entity must be active and belong to this tenant.']);
            }
            $id = DB::table('governance_meetings')->insertGetId([
                'tenant_id' => $tenantId, 'governance_body_id' => $body->id,
                'finance_legal_entity_id' => $legalEntityId,
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
            'payments' => DB::table('governance_meeting_penalty_payments as payments')
                ->join('governance_meeting_penalties as penalties', 'penalties.id', '=', 'payments.penalty_id')
                ->where('payments.tenant_id', $this->tenantId())->where('penalties.meeting_id', $id)
                ->select('payments.*')->orderBy('payments.id')->get(),
            'dividend_settlement_requests' => DB::table('governance_meeting_dividend_settlement_requests as settlements')
                ->join('governance_meeting_penalties as penalties', 'penalties.id', '=', 'settlements.penalty_id')
                ->where('settlements.tenant_id', $this->tenantId())->where('penalties.meeting_id', $id)
                ->select('settlements.*')->orderBy('settlements.id')->get(),
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
            $appointed = CarbonImmutable::parse($data['appointed_at'], $meeting->timezone);
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
            if ($status === 'late') $this->createPenalty($meetingId, (int) $member->id, 'late', '50.00', 'Late arrival; flat ETB 50 per meeting.');
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
                    $this->createPenalty($meetingId, (int) $member->id, 'absence', '300.00', 'Absent without accepted proxy or authorized permission.');
                    $this->audit($meetingId, 'attendance.absent', 'attendance', $attendanceId, null, ['status' => 'absent']);
                }
            }
            DB::table('governance_meetings')->where('id', $meetingId)->update(['status' => 'closed', 'closed_at' => now(), 'updated_at' => now()]);
            $updated = $this->meeting($meetingId);
            $this->audit($meetingId, 'meeting.closed', 'meeting', $meetingId, ['status' => 'open'], ['status' => 'closed']);
            return $updated;
        });
    }

    private function minorUnits(string|int|float $amount): int
    {
        $value = trim((string) $amount);
        if (!preg_match('/^\\d+(?:\\.\\d{1,4})?$/', $value)) {
            throw ValidationException::withMessages(['amount' => 'Money amounts must be non-negative decimal values with at most four decimal places.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '0');
        return ((int) $whole * 10000) + (int) str_pad($fraction, 4, '0');
    }

    private function createPenalty(int $meetingId, int $membershipId, string $type, string $amount, string $reason): void
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
            if (!$penalty || !in_array($penalty->status, ['pending_review', 'disputed'], true)) {
                throw ValidationException::withMessages(['penalty_id' => 'Only a pending or disputed penalty can be assessed or waived.']);
            }
            if ($penalty->status === 'disputed' && (int) $penalty->disputed_by === $this->actorId()) {
                throw ValidationException::withMessages(['decision' => 'The dispute reviewer cannot decide their own dispute.']);
            }
            $attendance = DB::table('governance_meeting_attendance')->where('meeting_id', $meetingId)
                ->where('membership_id', $penalty->membership_id)->first();
            if ($penalty->status === 'pending_review' && $attendance && (int) $attendance->recorded_by === $this->actorId()) {
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


    public function disputePenalty(int $meetingId, int $penaltyId, array $data): object
    {
        return DB::transaction(function () use ($meetingId, $penaltyId, $data): object {
            $this->meeting($meetingId, true);
            $penalty = DB::table('governance_meeting_penalties')->where('tenant_id', $this->tenantId())
                ->where('meeting_id', $meetingId)->where('id', $penaltyId)->lockForUpdate()->first();
            if (!$penalty || !in_array($penalty->status, ['assessed', 'partially_paid'], true)) {
                throw ValidationException::withMessages(['penalty_id' => 'Only an assessed or partially paid penalty can be disputed.']);
            }
            DB::table('governance_meeting_penalties')->where('id', $penaltyId)->update([
                'status' => 'disputed', 'disputed_by' => $this->actorId(),
                'disputed_at' => now(), 'dispute_reason' => $data['reason'], 'updated_at' => now(),
            ]);
            $updated = DB::table('governance_meeting_penalties')->where('id', $penaltyId)->first();
            $this->audit($meetingId, 'penalty.disputed', 'penalty', $penaltyId, (array) $penalty, (array) $updated);
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
            $paidMinor = $this->minorUnits((string) DB::table('governance_meeting_penalty_payments')->where('penalty_id', $penaltyId)->sum('amount'));
            $reservedMinor = $this->minorUnits((string) DB::table('governance_meeting_dividend_settlement_requests')
                ->where('penalty_id', $penaltyId)
                ->whereIn('status', ['pending_finance_legal_review', 'approved', 'reconciled'])
                ->sum('requested_amount'));
            $amountMinor = $this->minorUnits((string) $data['amount']);
            $penaltyMinor = $this->minorUnits((string) $penalty->amount);
            if ($amountMinor <= 0 || $amountMinor > ($penaltyMinor - $paidMinor - $reservedMinor)) {
                throw ValidationException::withMessages(['amount' => 'Payment must be positive and cannot exceed the unreserved outstanding balance.']);
            }
            $amount = number_format($amountMinor / 10000, 2, '.', '');
            $id = DB::table('governance_meeting_penalty_payments')->insertGetId([
                'tenant_id' => $this->tenantId(), 'penalty_id' => $penaltyId, 'amount' => $amount,
                'currency' => 'ETB', 'payment_method' => $data['payment_method'],
                'receipt_reference' => $data['receipt_reference'], 'paid_at' => $data['paid_at'] ?? now(),
                'recorded_by' => $this->actorId(), 'finance_reconciliation_status' => 'pending',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $newPaidMinor = $paidMinor + $amountMinor;
            $newStatus = $newPaidMinor >= $penaltyMinor ? 'paid' : 'partially_paid';
            DB::table('governance_meeting_penalties')->where('id', $penaltyId)->update(['status' => $newStatus, 'updated_at' => now()]);
            $payment = DB::table('governance_meeting_penalty_payments')->where('id', $id)->first();
            $this->audit($meetingId, 'penalty.payment_recorded', 'payment', $id, null, (array) $payment);
            return $payment;
        });
    }


    public function financeReconciliationQueue(): array
    {
        $tenantId = $this->tenantId();
        return [
            'payments' => DB::table('governance_meeting_penalty_payments as payments')
                ->join('governance_meeting_penalties as penalties', 'penalties.id', '=', 'payments.penalty_id')
                ->join('governance_meetings as meetings', 'meetings.id', '=', 'penalties.meeting_id')
                ->where('payments.tenant_id', $tenantId)
                ->where('payments.finance_reconciliation_status', 'pending')
                ->select('payments.*', 'meetings.id as meeting_id', 'meetings.finance_legal_entity_id')
                ->orderBy('payments.id')->limit(500)->get(),
            'dividend_settlements' => DB::table('governance_meeting_dividend_settlement_requests as settlements')
                ->join('governance_meeting_penalties as penalties', 'penalties.id', '=', 'settlements.penalty_id')
                ->where('settlements.tenant_id', $tenantId)
                ->whereIn('settlements.status', ['pending_finance_legal_review', 'approved'])
                ->select('settlements.*', 'penalties.meeting_id')
                ->orderBy('settlements.id')->limit(500)->get(),
        ];
    }

    private function assertPostedJournal(int $journalId, object $meeting): object
    {
        if (!$meeting->finance_legal_entity_id) {
            throw ValidationException::withMessages(['finance_legal_entity_id' => 'Select an active Finance legal entity on the meeting before reconciliation.']);
        }
        $journal = JournalEntry::query()->where('tenant_id', $this->tenantId())
            ->where('legal_entity_id', $meeting->finance_legal_entity_id)
            ->whereKey($journalId)->where('status', JournalEntry::POSTED)->first();
        if (!$journal) {
            throw ValidationException::withMessages(['finance_reference' => 'A posted journal in the meeting legal entity is required for reconciliation.']);
        }
        return $journal;
    }

    public function reconcilePayment(int $meetingId, int $paymentId, int $journalId): object
    {
        return DB::transaction(function () use ($meetingId, $paymentId, $journalId): object {
            $meeting = $this->meeting($meetingId, true);
            $payment = DB::table('governance_meeting_penalty_payments as payments')
                ->join('governance_meeting_penalties as penalties', 'penalties.id', '=', 'payments.penalty_id')
                ->where('payments.tenant_id', $this->tenantId())->where('payments.id', $paymentId)
                ->where('penalties.meeting_id', $meetingId)->select('payments.*')->lockForUpdate()->first();
            if (!$payment) throw new NotFoundHttpException('Payment record not found.');
            if ($payment->finance_reconciliation_status === 'reconciled') {
                if ((string) $payment->finance_reference === (string) $journalId) return $payment;
                throw ValidationException::withMessages(['finance_reference' => 'This payment is already reconciled to another journal.']);
            }
            $journal = $this->assertPostedJournal($journalId, $meeting);
            $journalDebit = $this->minorUnits((string) DB::table('journal_lines')->where('tenant_id', $this->tenantId())
                ->where('legal_entity_id', $meeting->finance_legal_entity_id)->where('journal_entry_id', $journal->id)->sum('debit_amount'));
            $journalCredit = $this->minorUnits((string) DB::table('journal_lines')->where('tenant_id', $this->tenantId())
                ->where('legal_entity_id', $meeting->finance_legal_entity_id)->where('journal_entry_id', $journal->id)->sum('credit_amount'));
            $paymentAmount = $this->minorUnits((string) $payment->amount);
            if ($journalDebit !== $paymentAmount || $journalCredit !== $paymentAmount) {
                throw ValidationException::withMessages(['finance_reference' => 'The posted journal debit and credit totals must each equal the payment amount.']);
            }
            $duplicate = DB::table('governance_meeting_penalty_payments')->where('tenant_id', $this->tenantId())
                ->where('finance_reference', (string) $journal->id)->exists()
                || DB::table('governance_meeting_dividend_settlement_requests')->where('tenant_id', $this->tenantId())
                    ->where('finance_reference', (string) $journal->id)->exists();
            if ($duplicate) throw ValidationException::withMessages(['finance_reference' => 'This journal is already linked to another meeting settlement.']);
            DB::table('governance_meeting_penalty_payments')->where('id', $paymentId)->update([
                'finance_reconciliation_status' => 'reconciled',
                'finance_reference' => (string) $journal->id, 'updated_at' => now(),
            ]);
            $updated = DB::table('governance_meeting_penalty_payments')->where('id', $paymentId)->first();
            $this->audit($meetingId, 'penalty.payment_reconciled', 'payment', $paymentId, (array) $payment, (array) $updated);
            return $updated;
        });
    }

    public function reviewDividendSettlement(int $meetingId, int $settlementId, array $data): object
    {
        return DB::transaction(function () use ($meetingId, $settlementId, $data): object {
            $this->meeting($meetingId, true);
            $settlement = DB::table('governance_meeting_dividend_settlement_requests as settlements')
                ->join('governance_meeting_penalties as penalties', 'penalties.id', '=', 'settlements.penalty_id')
                ->where('settlements.tenant_id', $this->tenantId())->where('settlements.id', $settlementId)
                ->where('penalties.meeting_id', $meetingId)->select('settlements.*')->lockForUpdate()->first();
            if (!$settlement || $settlement->status !== 'pending_finance_legal_review') {
                throw ValidationException::withMessages(['settlement_id' => 'Only a pending Finance/Legal settlement request can be decided.']);
            }
            if ((int) $settlement->requested_by === $this->actorId()) {
                throw ValidationException::withMessages(['decision' => 'The requester cannot approve or reject their own dividend settlement request.']);
            }
            if ($data['decision'] === 'approve' && trim((string) ($data['authority_reference'] ?? '')) === '') {
                throw ValidationException::withMessages(['authority_reference' => 'A valid corporate/legal authority reference is required before approval.']);
            }
            $status = $data['decision'] === 'approve' ? 'approved' : 'rejected';
            DB::table('governance_meeting_dividend_settlement_requests')->where('id', $settlementId)->update([
                'status' => $status, 'authority_reference' => $data['authority_reference'] ?? null,
                'decided_by' => $this->actorId(), 'decided_at' => now(),
                'decision_reason' => $data['reason'], 'updated_at' => now(),
            ]);
            $updated = DB::table('governance_meeting_dividend_settlement_requests')->where('id', $settlementId)->first();
            $this->audit($meetingId, 'dividend_settlement.'.$status, 'dividend_settlement', $settlementId, (array) $settlement, (array) $updated);
            return $updated;
        });
    }

    public function reconcileDividendSettlement(int $meetingId, int $settlementId, int $journalId): object
    {
        return DB::transaction(function () use ($meetingId, $settlementId, $journalId): object {
            $meeting = $this->meeting($meetingId, true);
            $settlement = DB::table('governance_meeting_dividend_settlement_requests as settlements')
                ->join('governance_meeting_penalties as penalties', 'penalties.id', '=', 'settlements.penalty_id')
                ->where('settlements.tenant_id', $this->tenantId())->where('settlements.id', $settlementId)
                ->where('penalties.meeting_id', $meetingId)->select('settlements.*', 'penalties.id as linked_penalty_id')->lockForUpdate()->first();
            if (!$settlement || $settlement->status !== 'approved') {
                throw ValidationException::withMessages(['settlement_id' => 'Only an approved dividend settlement can be reconciled.']);
            }
            $linkedPenalty = DB::table('governance_meeting_penalties')->where('tenant_id', $this->tenantId())
                ->where('id', $settlement->linked_penalty_id)->lockForUpdate()->first();
            if (!$linkedPenalty || !in_array($linkedPenalty->status, ['assessed', 'partially_paid'], true)) {
                throw ValidationException::withMessages(['settlement_id' => 'The linked penalty must remain assessed and outstanding at reconciliation time.']);
            }
            $journal = $this->assertPostedJournal($journalId, $meeting);
            $journalDebit = $this->minorUnits((string) DB::table('journal_lines')->where('tenant_id', $this->tenantId())
                ->where('legal_entity_id', $meeting->finance_legal_entity_id)->where('journal_entry_id', $journal->id)->sum('debit_amount'));
            $journalCredit = $this->minorUnits((string) DB::table('journal_lines')->where('tenant_id', $this->tenantId())
                ->where('legal_entity_id', $meeting->finance_legal_entity_id)->where('journal_entry_id', $journal->id)->sum('credit_amount'));
            $settlementAmount = $this->minorUnits((string) $settlement->requested_amount);
            if ($journalDebit !== $settlementAmount || $journalCredit !== $settlementAmount) {
                throw ValidationException::withMessages(['finance_reference' => 'The posted journal debit and credit totals must each equal the approved dividend settlement amount.']);
            }
            $used = DB::table('governance_meeting_dividend_settlement_requests')->where('tenant_id', $this->tenantId())
                ->where('finance_reference', (string) $journal->id)->exists()
                || DB::table('governance_meeting_penalty_payments')->where('tenant_id', $this->tenantId())
                    ->where('finance_reference', (string) $journal->id)->exists();
            if ($used) throw ValidationException::withMessages(['finance_reference' => 'This journal is already linked to another meeting settlement.']);
            DB::table('governance_meeting_dividend_settlement_requests')->where('id', $settlementId)->update([
                'status' => 'reconciled', 'finance_reference' => (string) $journal->id, 'updated_at' => now(),
            ]);
            $paidMinor = $this->minorUnits((string) DB::table('governance_meeting_penalty_payments')->where('penalty_id', $settlement->linked_penalty_id)->sum('amount'));
            $offsetMinor = $this->minorUnits((string) DB::table('governance_meeting_dividend_settlement_requests')
                ->where('penalty_id', $settlement->linked_penalty_id)->where('status', 'reconciled')->sum('requested_amount'));
            $penalty = DB::table('governance_meeting_penalties')->where('id', $settlement->linked_penalty_id)->first();
            $newStatus = ($paidMinor + $offsetMinor) >= $this->minorUnits((string) $penalty->amount) ? 'paid' : 'partially_paid';
            DB::table('governance_meeting_penalties')->where('id', $settlement->linked_penalty_id)->update(['status' => $newStatus, 'updated_at' => now()]);
            $updated = DB::table('governance_meeting_dividend_settlement_requests')->where('id', $settlementId)->first();
            $this->audit($meetingId, 'dividend_settlement.reconciled', 'dividend_settlement', $settlementId, (array) $settlement, (array) $updated);
            return $updated;
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
            $paidMinor = $this->minorUnits((string) DB::table('governance_meeting_penalty_payments')->where('penalty_id', $penaltyId)->sum('amount'));
            $reservedMinor = $this->minorUnits((string) DB::table('governance_meeting_dividend_settlement_requests')
                ->where('penalty_id', $penaltyId)
                ->whereIn('status', ['pending_finance_legal_review', 'approved', 'reconciled'])
                ->sum('requested_amount'));
            $requestedMinor = $this->minorUnits((string) $data['requested_amount']);
            $penaltyMinor = $this->minorUnits((string) $penalty->amount);
            if ($requestedMinor <= 0 || $requestedMinor > ($penaltyMinor - $paidMinor - $reservedMinor)) {
                throw ValidationException::withMessages(['requested_amount' => 'Requested set-off cannot exceed the remaining unreserved penalty balance.']);
            }
            $requested = number_format($requestedMinor / 10000, 2, '.', '');
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
