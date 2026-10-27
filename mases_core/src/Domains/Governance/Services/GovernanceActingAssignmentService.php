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
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceSeat;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceSeatOccupancy;
use MaaleArna\MASES\Core\Domains\People\Models\Person;
use MaaleArna\MASES\Core\Domains\People\Models\PersonTenantAccess;

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
            $occupantHasTenantAccess = PersonTenantAccess::query()
                ->where('person_id', $occupancy->person_id)
                ->where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->exists();
            if (!$occupantHasTenantAccess) {
                throw ValidationException::withMessages(['substantive_occupancy_id' => ['The substantive incumbent must have an active explicit access grant for this tenant.']]);
            }
            if ($occupancy->person_id === $actingPersonId) {
                throw ValidationException::withMessages(['acting_person_id' => ['The substantive incumbent cannot be assigned as their own acting replacement.']]);
            }
            $actingPerson = Person::query()->whereKey($actingPersonId)->where('status', 'active')->whereNull('deleted_at')->first();
            $grantor = Person::query()->where('user_id', $actor->getKey())->where('status', 'active')->whereNull('deleted_at')->first();
            if (!$actingPerson || !$grantor) {
                throw ValidationException::withMessages(['acting_person_id' => ['Acting person and authorizing grantor must be active canonical Person identities.']]);
            }

            foreach ([$actingPerson, $grantor] as $participant) {
                $hasTenantAccess = PersonTenantAccess::query()
                    ->where('person_id', $participant->id)
                    ->where('tenant_id', $tenantId)
                    ->where('status', 'active')
                    ->exists();
                if (!$hasTenantAccess) {
                    throw ValidationException::withMessages(['acting_person_id' => ['Acting person and authorizing grantor must each have an active explicit access grant for this tenant.']]);
                }
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

