<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Services;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceActingAssignment;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceMembership;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernancePosition;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceSeat;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceSeatOccupancy;
use MaaleArna\MASES\Core\Domains\People\Models\Person;

final class GovernanceSeatService
{
    public function __construct(private readonly AuthorizationService $authorization) {}

    public function createSeat(User $actor, int $tenantId, int $bodyId, int $positionId, string $seatCode, ?string $labelAm = null, ?string $labelEn = null): GovernanceSeat
    {
        $this->authorize($actor, $tenantId);
        $seatCode = strtoupper(trim($seatCode));
        if ($seatCode === '' || mb_strlen($seatCode) > 64) {
            throw ValidationException::withMessages(['seat_code' => ['A seat code of 1–64 characters is required.']]);
        }

        return DB::transaction(function () use ($tenantId, $bodyId, $positionId, $seatCode, $labelAm, $labelEn): GovernanceSeat {
            $body = GovernanceBody::query()->where('tenant_id', $tenantId)->whereKey($bodyId)->where('is_active', true)->first();
            $position = GovernancePosition::query()->where('tenant_id', $tenantId)->where('governance_body_id', $bodyId)->whereKey($positionId)->where('is_active', true)->first();
            if (!$body || !$position) {
                throw ValidationException::withMessages(['governance_position_id' => ['Body and position must be active and belong to the validated tenant and each other.']]);
            }

            return GovernanceSeat::query()->create([
                'tenant_id' => $tenantId,
                'governance_body_id' => $bodyId,
                'governance_position_id' => $positionId,
                'seat_code' => $seatCode,
                'label_am' => $labelAm,
                'label_en' => $labelEn,
                'status' => 'active',
            ]);
        }, 3);
    }

    /** Assign a canonical Person to one explicit seat for an inclusive calendar-date interval. */
    public function occupy(User $actor, int $tenantId, int $seatId, int $personId, string $startDate, ?string $endDate = null, ?int $membershipId = null): GovernanceSeatOccupancy
    {
        $this->authorize($actor, $tenantId);
        $start = $this->date($startDate, 'start_date');
        $end = $endDate === null || trim($endDate) === '' ? null : $this->date($endDate, 'end_date');
        if ($end && $end->lt($start)) {
            throw ValidationException::withMessages(['end_date' => ['End date must be on or after start date.']]);
        }

        return DB::transaction(function () use ($actor, $tenantId, $seatId, $personId, $start, $end, $membershipId): GovernanceSeatOccupancy {
            $seat = GovernanceSeat::query()->where('tenant_id', $tenantId)->where('status', 'active')->whereKey($seatId)->lockForUpdate()->first();
            if (!$seat) {
                throw (new ModelNotFoundException())->setModel(GovernanceSeat::class, [$seatId]);
            }
            $person = Person::query()->whereKey($personId)->where('status', 'active')->whereNull('deleted_at')->first();
            if (!$person) {
                throw ValidationException::withMessages(['person_id' => ['A current canonical Person identity is required.']]);
            }

            if ($membershipId !== null) {
                $membership = GovernanceMembership::query()
                    ->whereKey($membershipId)->where('tenant_id', $tenantId)
                    ->where('governance_body_id', $seat->governance_body_id)
                    ->where('governance_position_id', $seat->governance_position_id)
                    ->where('status', 'active')->first();
                if (!$membership || ($person->user_id !== null && (int) $membership->user_id !== (int) $person->user_id)) {
                    throw ValidationException::withMessages(['governance_membership_id' => ['Membership must be active and match this seat and canonical person.']]);
                }
            }

            $overlap = GovernanceSeatOccupancy::query()
                ->where('governance_seat_id', $seat->id)
                ->where('status', 'active')
                ->whereDate('start_date', '<=', ($end ?? CarbonImmutable::parse('9999-12-31'))->toDateString())
                ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $start->toDateString()))
                ->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['governance_seat_id' => ['This explicit seat already has an overlapping primary occupancy. Adjacent inclusive periods must not share an end/start date.']]);
            }

            return GovernanceSeatOccupancy::query()->create([
                'governance_seat_id' => $seat->id,
                'person_id' => $person->id,
                'governance_membership_id' => $membershipId,
                'start_date' => $start->toDateString(),
                'end_date' => $end?->toDateString(),
                'status' => 'active',
            ]);
        }, 3);
    }

    private function authorize(User $actor, int $tenantId): void
    {
        if ($tenantId < 1 || !$actor->is_active || !$this->authorization->hasPermission('governance.body.manage', $actor, $tenantId)) {
            throw new AuthorizationException('A validated tenant and governance.body.manage permission are required.');
        }
    }

    private function date(string $value, string $field): CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
            if (!$date || $date->format('Y-m-d') !== $value) {
                throw new \InvalidArgumentException();
            }
            return $date;
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => ['A valid YYYY-MM-DD calendar date is required.']]);
        }
    }
}

final class GovernanceActingAssignmentService
{
    /** Acting authority is explicit and bounded; no implicit permission inheritance. */
    public const ACTIONS = [
        'governance.appointment.manage',
        'governance.membership.manage',
    ];

    public function __construct(private readonly AuthorizationService $authorization) {}

    public function assign(User $actor, int $tenantId, int $seatId, int $occupancyId, int $actingPersonId, string $startDate, string $endDate, string $sourceDecisionReference, array $actions, string $reason): GovernanceActingAssignment
    {
        if ($tenantId < 1 || !$actor->is_active || !$this->authorization->hasPermission('governance.body.manage', $actor, $tenantId)) {
            throw new AuthorizationException('A validated tenant and governance.body.manage permission are required.');
        }

        $start = $this->date($startDate, 'start_date');
        $end = $this->date($endDate, 'end_date');
        $sourceDecisionReference = trim($sourceDecisionReference);
        $reason = trim($reason);
        $actions = array_values(array_unique(array_map('strval', $actions)));
        if ($end->lt($start)) {
            throw ValidationException::withMessages(['end_date' => ['End date must be on or after start date.']]);
        }
        if ($sourceDecisionReference === '' || mb_strlen($sourceDecisionReference) > 191) {
            throw ValidationException::withMessages(['source_decision_reference' => ['An authorized source decision reference is required.']]);
        }
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => ['A reason of 1–500 characters is required.']]);
        }
        if ($actions === [] || array_diff($actions, self::ACTIONS) !== []) {
            throw ValidationException::withMessages(['actions' => ['At least one action from the approved acting-authority catalogue is required.']]);
        }

        return DB::transaction(function () use ($actor, $tenantId, $seatId, $occupancyId, $actingPersonId, $start, $end, $sourceDecisionReference, $actions, $reason): GovernanceActingAssignment {
            $seat = GovernanceSeat::query()->where('tenant_id', $tenantId)->where('status', 'active')->whereKey($seatId)->lockForUpdate()->first();
            if (!$seat) {
                throw (new ModelNotFoundException())->setModel(GovernanceSeat::class, [$seatId]);
            }
            $occupancy = GovernanceSeatOccupancy::query()->where('governance_seat_id', $seat->id)->whereKey($occupancyId)->where('status', 'active')->first();
            if (!$occupancy || $occupancy->start_date->gt($start) || ($occupancy->end_date && $occupancy->end_date->lt($end))) {
                throw ValidationException::withMessages(['substantive_occupancy_id' => ['The referenced primary occupancy must cover the full acting-assignment interval.']]);
            }
            if ($occupancy->person_id === $actingPersonId) {
                throw ValidationException::withMessages(['acting_person_id' => ['The substantive incumbent cannot be assigned as their own acting replacement.']]);
            }
            $actingPerson = Person::query()->whereKey($actingPersonId)->where('status', 'active')->whereNull('deleted_at')->first();
            $grantor = Person::query()->where('user_id', $actor->getKey())->where('status', 'active')->whereNull('deleted_at')->first();
            if (!$actingPerson || !$grantor) {
                throw ValidationException::withMessages(['acting_person_id' => ['Acting person and authorizing grantor must be active canonical Person identities.']]);
            }

            $overlap = GovernanceActingAssignment::query()
                ->where('governance_seat_id', $seat->id)->whereNull('revoked_at')
                ->whereDate('start_date', '<=', $end->toDateString())
                ->whereDate('end_date', '>=', $start->toDateString())->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['governance_seat_id' => ['Another acting assignment overlaps this seat and date interval.']]);
            }

            return GovernanceActingAssignment::query()->create([
                'governance_seat_id' => $seat->id,
                'substantive_occupancy_id' => $occupancy->id,
                'acting_person_id' => $actingPerson->id,
                'grantor_person_id' => $grantor->id,
                'created_by_user_id' => $actor->getKey(),
                'source_decision_reference' => $sourceDecisionReference,
                'actions' => $actions,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'reason' => $reason,
            ]);
        }, 3);
    }

    public function revoke(User $actor, int $tenantId, int $assignmentId, string $reason): GovernanceActingAssignment
    {
        if ($tenantId < 1 || !$actor->is_active || !$this->authorization->hasPermission('governance.body.manage', $actor, $tenantId)) {
            throw new AuthorizationException('A validated tenant and governance.body.manage permission are required.');
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => ['A revocation reason of 1–500 characters is required.']]);
        }

        return DB::transaction(function () use ($actor, $tenantId, $assignmentId, $reason): GovernanceActingAssignment {
            $assignment = GovernanceActingAssignment::query()->whereHas('seat', fn ($q) => $q->where('tenant_id', $tenantId))->whereKey($assignmentId)->lockForUpdate()->first();
            if (!$assignment) {
                throw (new ModelNotFoundException())->setModel(GovernanceActingAssignment::class, [$assignmentId]);
            }
            if ($assignment->revoked_at !== null) {
                throw ValidationException::withMessages(['assignment' => ['This acting assignment is already revoked.']]);
            }
            $assignment->forceFill(['revoked_at' => now(), 'revoked_by_user_id' => $actor->getKey(), 'revocation_reason' => $reason])->save();
            return $assignment->refresh();
        }, 3);
    }

    private function date(string $value, string $field): CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
            if (!$date || $date->format('Y-m-d') !== $value) {
                throw new \InvalidArgumentException();
            }
            return $date;
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => ['A valid YYYY-MM-DD calendar date is required.']]);
        }
    }
}
