<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PeopleSupplementService
{
    private function person(int $tenantId, string $publicId): object
    {
        $person = DB::table('people')
            ->join('person_tenant_accesses as pta', 'pta.person_id', '=', 'people.id')
            ->where('people.public_id', $publicId)
            ->where('people.status', 'active')
            ->whereNull('people.deleted_at')
            ->where('pta.tenant_id', $tenantId)
            ->where('pta.status', 'active')
            ->select('people.id', 'people.public_id')
            ->first();

        if ($person === null) {
            throw new NotFoundHttpException('Person not found.');
        }

        return $person;
    }

    public function contacts(int $tenantId, string $publicId): array
    {
        $person = $this->person($tenantId, $publicId);

        return DB::table('people_contact_points')->where('tenant_id', $tenantId)
            ->where('person_id', $person->id)->where('status', 'active')->whereNull('deleted_at')
            ->orderByDesc('is_primary')->orderBy('id')
            ->get(['id', 'type', 'label', 'value_masked', 'is_primary', 'is_verified', 'verified_at', 'created_at'])
            ->map(fn ($row) => (array) $row)->all();
    }

    public function addContact(int $tenantId, string $publicId, int $actorId, array $data): array
    {
        $person = $this->person($tenantId, $publicId);
        $value = trim($data['value']);
        $masked = $this->mask($data['type'], $value);
        $id = DB::table('people_contact_points')->insertGetId([
            'tenant_id' => $tenantId, 'person_id' => $person->id,
            'type' => $data['type'], 'label' => $data['label'] ?? null,
            'value_encrypted' => Crypt::encryptString($value), 'value_masked' => $masked,
            'is_primary' => (bool) ($data['is_primary'] ?? false),
            // No verification workflow exists yet. Never accept client-supplied verified state.
            'is_verified' => false, 'verified_at' => null, 'status' => 'active',
            'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return (array) DB::table('people_contact_points')->where('id', $id)
            ->first(['id', 'type', 'label', 'value_masked', 'is_primary', 'is_verified', 'verified_at', 'created_at']);
    }

    public function deactivateContact(int $tenantId, string $publicId, int $contactId): void
    {
        $person = $this->person($tenantId, $publicId);
        $updated = DB::table('people_contact_points')->where('id', $contactId)
            ->where('tenant_id', $tenantId)->where('person_id', $person->id)
            ->where('status', 'active')->whereNull('deleted_at')
            ->update(['status' => 'inactive', 'deleted_at' => now(), 'updated_at' => now()]);
        if ($updated !== 1) {
            throw new NotFoundHttpException('Contact point not found.');
        }
    }

    public function relationships(int $tenantId, string $publicId): array
    {
        $person = $this->person($tenantId, $publicId);
        return DB::table('people_relationships as r')
            ->join('people as p', 'p.id', '=', 'r.related_person_id')
            ->where('r.tenant_id', $tenantId)->where('r.person_id', $person->id)
            ->where('r.status', 'active')->whereNull('r.deleted_at')
            ->where('p.status', 'active')->whereNull('p.deleted_at')
            ->orderBy('r.id')->get([
                'r.id', 'r.relationship_type', 'r.description',
                'p.public_id as related_person_public_id',
                'p.first_name_am', 'p.father_name_am', 'p.grandfather_name_am',
                'p.first_name_en', 'p.father_name_en', 'p.grandfather_name_en',
            ])->map(fn ($row) => (array) $row)->all();
    }

    public function addRelationship(int $tenantId, string $publicId, int $actorId, array $data): array
    {
        $person = $this->person($tenantId, $publicId);
        $related = $this->person($tenantId, $data['related_person_public_id']);
        if ((int) $person->id === (int) $related->id) {
            abort(422, 'A person cannot be related to themselves.');
        }
        $id = DB::table('people_relationships')->insertGetId([
            'tenant_id' => $tenantId, 'person_id' => $person->id, 'related_person_id' => $related->id,
            'relationship_type' => $data['relationship_type'], 'description' => $data['description'] ?? null,
            'status' => 'active', 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return (array) DB::table('people_relationships')->where('id', $id)
            ->first(['id', 'relationship_type', 'description', 'status', 'created_at']);
    }

    public function deactivateRelationship(int $tenantId, string $publicId, int $relationshipId): void
    {
        $person = $this->person($tenantId, $publicId);
        $updated = DB::table('people_relationships')->where('id', $relationshipId)
            ->where('tenant_id', $tenantId)->where('person_id', $person->id)
            ->where('status', 'active')->whereNull('deleted_at')
            ->update(['status' => 'inactive', 'deleted_at' => now(), 'updated_at' => now()]);
        if ($updated !== 1) {
            throw new NotFoundHttpException('Relationship not found.');
        }
    }

    public function participations(int $tenantId, string $publicId): array
    {
        $person = $this->person($tenantId, $publicId);
        return DB::table('people_participations')->where('tenant_id', $tenantId)
            ->where('person_id', $person->id)->where('status', 'active')->whereNull('deleted_at')
            ->orderByDesc('started_on')->orderBy('id')
            ->get(['id', 'participation_type', 'title', 'organization_label', 'started_on', 'ended_on', 'description', 'status'])
            ->map(fn ($row) => (array) $row)->all();
    }

    public function addParticipation(int $tenantId, string $publicId, int $actorId, array $data): array
    {
        $person = $this->person($tenantId, $publicId);
        $id = DB::table('people_participations')->insertGetId([
            'tenant_id' => $tenantId, 'person_id' => $person->id,
            'participation_type' => $data['participation_type'], 'title' => $data['title'] ?? null,
            'organization_label' => $data['organization_label'] ?? null,
            'started_on' => $data['started_on'] ?? null, 'ended_on' => $data['ended_on'] ?? null,
            'description' => $data['description'] ?? null, 'status' => 'active',
            'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return (array) DB::table('people_participations')->where('id', $id)
            ->first(['id', 'participation_type', 'title', 'organization_label', 'started_on', 'ended_on', 'description', 'status']);
    }

    public function deactivateParticipation(int $tenantId, string $publicId, int $participationId): void
    {
        $person = $this->person($tenantId, $publicId);
        $updated = DB::table('people_participations')->where('id', $participationId)
            ->where('tenant_id', $tenantId)->where('person_id', $person->id)
            ->where('status', 'active')->whereNull('deleted_at')
            ->update(['status' => 'inactive', 'deleted_at' => now(), 'updated_at' => now()]);
        if ($updated !== 1) {
            throw new NotFoundHttpException('Participation not found.');
        }
    }

    private function mask(string $type, string $value): string
    {
        if ($type === 'email') {
            $parts = explode('@', $value, 2);
            return count($parts) === 2 ? substr($parts[0], 0, 1).'***@'.$parts[1] : '***';
        }
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        return $digits === '' ? '***' : '***'.substr($digits, -4);
    }
}
