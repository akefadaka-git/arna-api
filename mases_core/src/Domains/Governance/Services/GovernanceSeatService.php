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
use MaaleArna\MASES\Core\Domains\People\Models\PersonTenantAccess;

final class GovernanceSeatService
{
    public function __construct(private readonly AuthorizationService $authorization) {}

    /** List only seats in the validated tenant after checking governance management permission. */
    public function list(User $actor, int $tenantId)
    {
        $this->authorize($actor, $tenantId);

        return GovernanceSeat::query()
            ->where('tenant_id', $tenantId)
            ->with([
                'body:id,tenant_id,code,name_am,name_en',
                'position:id,tenant_id,governance_body_id,code,name_am,name_en',
                'occupancies' => fn ($query) => $query->whereHas(
                    'person.tenantAccesses',
                    fn ($access) => $access->where('tenant_id', $tenantId)->where('status', 'active'),
                ),
                'occupancies.person:id,public_id,first_name_am,father_name_am,grandfather_name_am,first_name_en,father_name_en,grandfather_name_en',
                'occupancies.membership:id,tenant_id,governance_body_id,governance_position_id,user_id,status',
                'actingAssignments' => fn ($query) => $query
                    ->whereHas(
                        'actingPerson.tenantAccesses',
                        fn ($access) => $access->where('tenant_id', $tenantId)->where('status', 'active'),
                    )
                    ->whereHas(
                        'substantiveOccupancy.person.tenantAccesses',
                        fn ($access) => $access->where('tenant_id', $tenantId)->where('status', 'active'),
                    ),
                'actingAssignments.actingPerson:id,public_id,first_name_am,father_name_am,grandfather_name_am,first_name_en,father_name_en,grandfather_name_en',
            ])
            ->orderBy('id')
            ->paginate(25);
    }

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

            $hasTenantAccess = PersonTenantAccess::query()
                ->where('person_id', $person->id)
                ->where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->exists();
            if (!$hasTenantAccess) {
                throw ValidationException::withMessages(['person_id' => ['The canonical Person must have an active explicit access grant for this tenant.']]);
            }

            if ($membershipId !== null) {
                $membership = GovernanceMembership::query()
                    ->whereKey($membershipId)->where('tenant_id', $tenantId)
                    ->where('governance_body_id', $seat->governance_body_id)
                    ->where('governance_position_id', $seat->governance_position_id)
                    ->where('status', 'active')->first();
                if (!$membership || $person->user_id === null || (int) $membership->user_id !== (int) $person->user_id) {
                    throw ValidationException::withMessages(['governance_membership_id' => ['Membership must be active and match the canonical Person account linked to this seat.']]);
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

    /** End or shorten a tenant-scoped occupancy interval without deleting its history. */
    public function endOccupancy(User $actor, int $tenantId, int $occupancyId, string $endDate, string $reason): GovernanceSeatOccupancy
    {
        $this->authorize($actor, $tenantId);
        $end = $this->date($endDate, 'end_date');
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['ended_reason' => ['An end reason of 1–500 characters is required.']]);
        }

        return DB::transaction(function () use ($tenantId, $occupancyId, $end, $reason): GovernanceSeatOccupancy {
            $existing = GovernanceSeatOccupancy::query()
                ->whereHas('seat', fn ($query) => $query->where('tenant_id', $tenantId))
                ->whereKey($occupancyId)
                ->first();
            if (!$existing) {
                throw (new ModelNotFoundException())->setModel(GovernanceSeatOccupancy::class, [$occupancyId]);
            }

            $seat = GovernanceSeat::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($existing->governance_seat_id)
                ->lockForUpdate()
                ->first();
            if (!$seat) {
                throw (new ModelNotFoundException())->setModel(GovernanceSeat::class, [$existing->governance_seat_id]);
            }

            $occupancy = GovernanceSeatOccupancy::query()
                ->where('governance_seat_id', $seat->id)
                ->whereKey($occupancyId)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();
            if (!$occupancy) {
                throw ValidationException::withMessages(['occupancy' => ['Only an active seat occupancy can be ended.']]);
            }
            if ($end->lt($occupancy->start_date)) {
                throw ValidationException::withMessages(['end_date' => ['The end date cannot precede the occupancy start date.']]);
            }
            if ($occupancy->end_date !== null && $end->gt($occupancy->end_date)) {
                throw ValidationException::withMessages(['end_date' => ['Ending an occupancy cannot extend its already recorded end date.']]);
            }

            $occupancy->forceFill([
                'end_date' => $end->toDateString(),
                'ended_reason' => $reason,
            ])->save();

            return $occupancy->refresh();
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
