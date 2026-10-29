<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\People\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\People\Models\Person;
use MaaleArna\MASES\Core\Domains\People\Models\PersonContactPoint;

final class PersonContactPointService
{
    public function create(int $tenantId, Person $person, array $attributes, ?int $actorId): PersonContactPoint
    {
        $value = trim((string) $attributes['contact_value']);
        $type = (string) $attributes['contact_type'];
        $normalized = match ($type) {
            'email' => mb_strtolower($value),
            'phone' => preg_replace('/\D+/', '', $value) ?? $value,
            default => $value,
        };

        if ($type === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['contact_value' => 'A valid email address is required.']);
        }
        if ($type === 'phone' && preg_match('/^[+0-9().\-\s]{7,32}$/', $value) !== 1) {
            throw ValidationException::withMessages(['contact_value' => 'A valid phone number format is required.']);
        }
        if (! in_array($type, ['email', 'phone', 'other'], true)) {
            throw ValidationException::withMessages(['contact_type' => 'Unsupported contact type.']);
        }

        return DB::transaction(function () use ($tenantId, $person, $attributes, $actorId, $value, $type, $normalized): PersonContactPoint {
            $lockedPerson = Person::query()->whereKey($person->getKey())
                ->where('status', 'active')->lockForUpdate()->firstOrFail();
            $activeGrant = DB::table('person_tenant_accesses')
                ->where('tenant_id', $tenantId)->where('person_id', $lockedPerson->getKey())
                ->where('status', 'active')->lockForUpdate()->first();
            if ($activeGrant === null) {
                abort(404, 'Active Person tenant access was not found.');
            }

            $duplicate = PersonContactPoint::query()
                ->where('tenant_id', $tenantId)
                ->where('person_id', $person->getKey())
                ->where('contact_type', $type)
                ->where('normalized_hash', hash_hmac('sha256', $normalized, (string) config('app.key')))
                ->where('status', 'active')
                ->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['contact_value' => 'This active contact point is already recorded for this person.']);
            }

            $count = PersonContactPoint::query()
                ->where('tenant_id', $tenantId)
                ->where('person_id', $person->getKey())
                ->where('status', 'active')
                ->count();
            if ($count >= (int) config('people.contacts.max_per_person', 20)) {
                throw ValidationException::withMessages(['contact_value' => 'The person has reached the active contact-point limit.']);
            }

            if (($attributes['is_primary'] ?? false) === true) {
                PersonContactPoint::query()
                    ->where('tenant_id', $tenantId)
                    ->where('person_id', $person->getKey())
                    ->where('contact_type', $type)
                    ->where('status', 'active')
                    ->update(['is_primary' => false]);
            }

            $contact = new PersonContactPoint();
            $contact->fill([
                'tenant_id' => $tenantId,
                'person_id' => $person->getKey(),
                'contact_type' => $type,
                'label' => $attributes['label'] ?? null,
                'contact_value' => $value,
                'normalized_hash' => hash_hmac('sha256', $normalized, (string) config('app.key')),
                'is_primary' => (bool) ($attributes['is_primary'] ?? false),
                'is_verified' => false,
                'verified_at' => null,
                'source' => 'manual',
                'status' => 'active',
                'created_by' => $actorId,
            ]);
            $contact->save();

            return $contact;
        });
    }

    public function deactivate(int $tenantId, Person $person, int $contactId, string $reason): PersonContactPoint
    {
        return DB::transaction(function () use ($tenantId, $person, $contactId, $reason): PersonContactPoint {
            Person::query()->whereKey($person->getKey())->lockForUpdate()->firstOrFail();
            $contact = PersonContactPoint::query()
                ->where('tenant_id', $tenantId)
                ->where('person_id', $person->getKey())
                ->whereKey($contactId)
                ->where('status', 'active')
                ->lockForUpdate()
                ->firstOrFail();
            $contact->status = 'inactive';
            $contact->is_primary = false;
            $contact->deactivation_reason = $reason;
            $contact->save();

            return $contact;
        });
    }
}
