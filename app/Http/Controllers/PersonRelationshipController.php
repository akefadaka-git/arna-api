<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use MaaleArna\MASES\Core\Domains\People\Models\Person;
use MaaleArna\MASES\Core\Domains\People\Models\PersonRelationship;

final class PersonRelationshipController extends Controller
{
    private const TYPES = [
        'parent', 'child', 'guardian', 'dependent', 'spouse', 'sibling',
        'emergency_contact', 'household_member', 'other',
    ];

    public function index(string $publicId): JsonResponse
    {
        $tenantId = mases_tenant_id();
        abort_if($tenantId === null, 400, 'A validated tenant context is required.');
        $person = $this->visiblePerson($publicId, (int) $tenantId);

        $relationships = PersonRelationship::query()
            ->with(['relatedPerson:id,public_id,first_name_am,father_name_am,grandfather_name_am,first_name_en,father_name_en,grandfather_name_en'])
            ->where('tenant_id', $tenantId)
            ->where('person_id', $person->getKey())
            ->where('status', 'active')
            ->where(static fn ($query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', today()))
            ->where(static fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', today()))
            ->orderBy('relationship_type')
            ->orderBy('id')
            ->limit(100)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $relationships->map(fn (PersonRelationship $relationship): array => $this->present($relationship))->values(),
        ]);
    }

    public function store(Request $request, string $publicId): JsonResponse
    {
        $tenantId = mases_tenant_id();
        abort_if($tenantId === null, 400, 'A validated tenant context is required.');
        $person = $this->visiblePerson($publicId, (int) $tenantId);
        $attributes = $request->validate([
            'related_public_id' => ['required', 'uuid'],
            'relationship_type' => ['required', Rule::in(self::TYPES)],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'min:8', 'max:1000'],
        ]);

        if (! empty($attributes['effective_from']) && ! empty($attributes['effective_to'])
            && $attributes['effective_from'] > $attributes['effective_to']) {
            abort(422, 'The effective end date must not precede the start date.');
        }

        if ($attributes['related_public_id'] === $publicId) {
            abort(422, 'A person cannot be related to themselves.');
        }

        $related = $this->visiblePerson($attributes['related_public_id'], (int) $tenantId);
        $relationship = DB::transaction(function () use ($tenantId, $person, $related, $request, $attributes): PersonRelationship {
            $duplicate = PersonRelationship::query()
                ->where('tenant_id', $tenantId)
                ->where('person_id', $person->getKey())
                ->where('related_person_id', $related->getKey())
                ->where('relationship_type', $attributes['relationship_type'])
                ->where('status', 'active')
                ->lockForUpdate()
                ->exists();
            if ($duplicate) {
                abort(409, 'An active relationship of this type already exists.');
            }

            $relationship = new PersonRelationship();
            $relationship->fill([
                'tenant_id' => $tenantId,
                'person_id' => $person->getKey(),
                'related_person_id' => $related->getKey(),
                'relationship_type' => $attributes['relationship_type'],
                'effective_from' => $attributes['effective_from'] ?? null,
                'effective_to' => $attributes['effective_to'] ?? null,
                'status' => 'active',
                'reason' => $attributes['reason'],
                'created_by' => $request->user()?->getKey(),
            ]);
            $relationship->save();

            return $relationship->load('relatedPerson');
        });

        return response()->json(['success' => true, 'data' => $this->present($relationship)], 201);
    }

    public function deactivate(Request $request, string $publicId, int $relationshipId): JsonResponse
    {
        $tenantId = mases_tenant_id();
        abort_if($tenantId === null, 400, 'A validated tenant context is required.');
        $person = $this->visiblePerson($publicId, (int) $tenantId);
        $attributes = $request->validate([
            'reason' => ['required', 'string', 'min:8', 'max:1000'],
        ]);

        $relationship = DB::transaction(function () use ($tenantId, $person, $relationshipId, $attributes): PersonRelationship {
            $relationship = PersonRelationship::query()
                ->where('tenant_id', $tenantId)
                ->where('person_id', $person->getKey())
                ->whereKey($relationshipId)
                ->where('status', 'active')
                ->lockForUpdate()
                ->firstOrFail();
            $relationship->status = 'inactive';
            $relationship->deactivation_reason = $attributes['reason'];
            $relationship->save();

            return $relationship->load('relatedPerson');
        });

        return response()->json(['success' => true, 'data' => $this->present($relationship)]);
    }

    private function visiblePerson(string $publicId, int $tenantId): Person
    {
        return Person::query()
            ->where('public_id', $publicId)
            ->where('status', 'active')
            ->whereHas('tenantAccesses', static fn ($query) => $query
                ->where('tenant_id', $tenantId)
                ->where('status', 'active'))
            ->firstOrFail();
    }

    private function present(PersonRelationship $relationship): array
    {
        $related = $relationship->relatedPerson;
        return [
            'id' => (int) $relationship->getKey(),
            'related_public_id' => $related?->public_id,
            'related_name_am' => $related ? trim(implode(' ', array_filter([
                $related->first_name_am, $related->father_name_am, $related->grandfather_name_am,
            ]))) : null,
            'related_name_en' => $related ? trim(implode(' ', array_filter([
                $related->first_name_en, $related->father_name_en, $related->grandfather_name_en,
            ]))) : null,
            'relationship_type' => $relationship->relationship_type,
            'effective_from' => $relationship->effective_from?->toDateString(),
            'effective_to' => $relationship->effective_to?->toDateString(),
            'status' => $relationship->status,
        ];
    }
}
