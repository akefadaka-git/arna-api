<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\People\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\People\Models\Person;
use MaaleArna\MASES\Core\Domains\People\Models\PersonParticipation;

final class PersonParticipationService
{
    public function create(int $tenantId, Person $person, array $attributes, ?int $actorId): PersonParticipation
    {
        if (! empty($attributes['effective_from']) && ! empty($attributes['effective_to']) && $attributes['effective_from'] > $attributes['effective_to']) {
            throw ValidationException::withMessages(['effective_to' => 'The effective end date must not precede the start date.']);
        }

        return DB::transaction(function () use ($tenantId, $person, $attributes, $actorId): PersonParticipation {
            $lockedPerson = Person::query()->whereKey($person->getKey())
                ->where('status', 'active')->lockForUpdate()->firstOrFail();
            $activeGrant = DB::table('person_tenant_accesses')
                ->where('tenant_id', $tenantId)->where('person_id', $lockedPerson->getKey())
                ->where('status', 'active')->lockForUpdate()->first();
            if ($activeGrant === null) {
                abort(404, 'Active Person tenant access was not found.');
            }

            $count = PersonParticipation::query()
                ->where('tenant_id', $tenantId)->where('person_id', $person->getKey())->where('status', 'active')->count();
            if ($count >= (int) config('people.participations.max_per_person', 200)) {
                throw ValidationException::withMessages(['title' => 'The person has reached the active participation limit.']);
            }

            $record = new PersonParticipation();
            $record->fill([
                'tenant_id' => $tenantId,
                'person_id' => $person->getKey(),
                'participation_type' => $attributes['participation_type'],
                'title' => trim($attributes['title']),
                'reference_code' => isset($attributes['reference_code']) ? trim($attributes['reference_code']) : null,
                'effective_from' => $attributes['effective_from'] ?? null,
                'effective_to' => $attributes['effective_to'] ?? null,
                'status' => 'active',
                'reason' => $attributes['reason'],
                'created_by' => $actorId,
            ]);
            $record->save();

            return $record;
        });
    }

    public function deactivate(int $tenantId, Person $person, int $participationId, string $reason): PersonParticipation
    {
        return DB::transaction(function () use ($tenantId, $person, $participationId, $reason): PersonParticipation {
            $lockedPerson = Person::query()->whereKey($person->getKey())
                ->where('status', 'active')->lockForUpdate()->firstOrFail();
            $activeGrant = DB::table('person_tenant_accesses')
                ->where('tenant_id', $tenantId)->where('person_id', $lockedPerson->getKey())
                ->where('status', 'active')->lockForUpdate()->first();
            if ($activeGrant === null) {
                abort(404, 'Active Person tenant access was not found.');
            }
            $record = PersonParticipation::query()
                ->where('tenant_id', $tenantId)->where('person_id', $person->getKey())
                ->whereKey($participationId)->where('status', 'active')->lockForUpdate()->firstOrFail();
            $record->status = 'inactive';
            $record->deactivation_reason = $reason;
            $record->save();

            return $record;
        });
    }
}
