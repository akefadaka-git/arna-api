<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\People\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\People\Models\Person;
use MaaleArna\MASES\Core\Domains\People\Models\PersonRelationship;

final class PersonRelationshipService
{
    public function create(int $tenantId, Person $person, Person $related, array $attributes, ?int $actorId): PersonRelationship
    {
        if ((int) $person->getKey() === (int) $related->getKey()) {
            throw ValidationException::withMessages(['related_public_id' => 'A person cannot be related to themselves.']);
        }
        if (! empty($attributes['effective_from']) && ! empty($attributes['effective_to']) && $attributes['effective_from'] > $attributes['effective_to']) {
            throw ValidationException::withMessages(['effective_to' => 'The effective end date must not precede the start date.']);
        }

        return DB::transaction(function () use ($tenantId, $person, $related, $attributes, $actorId): PersonRelationship {
            Person::query()->whereKey($person->getKey())->lockForUpdate()->firstOrFail();
            Person::query()->whereKey($related->getKey())->lockForUpdate()->firstOrFail();

            $duplicate = PersonRelationship::query()
                ->where('tenant_id', $tenantId)->where('person_id', $person->getKey())
                ->where('related_person_id', $related->getKey())
                ->where('relationship_type', $attributes['relationship_type'])
                ->where('status', 'active')->lockForUpdate()->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['related_public_id' => 'An active relationship of this type already exists.']);
            }

            $record = new PersonRelationship();
            $record->fill([
                'tenant_id' => $tenantId,
                'person_id' => $person->getKey(),
                'related_person_id' => $related->getKey(),
                'relationship_type' => $attributes['relationship_type'],
                'effective_from' => $attributes['effective_from'] ?? null,
                'effective_to' => $attributes['effective_to'] ?? null,
                'status' => 'active',
                'reason' => $attributes['reason'],
                'created_by' => $actorId,
            ]);
            $record->save();

            return $record->load('relatedPerson');
        });
    }

    public function deactivate(int $tenantId, Person $person, int $relationshipId, string $reason): PersonRelationship
    {
        return DB::transaction(function () use ($tenantId, $person, $relationshipId, $reason): PersonRelationship {
            Person::query()->whereKey($person->getKey())->lockForUpdate()->firstOrFail();
            $record = PersonRelationship::query()
                ->where('tenant_id', $tenantId)->where('person_id', $person->getKey())
                ->whereKey($relationshipId)->where('status', 'active')->lockForUpdate()->firstOrFail();
            $record->status = 'inactive';
            $record->deactivation_reason = $reason;
            $record->save();

            return $record->load('relatedPerson');
        });
    }
}
