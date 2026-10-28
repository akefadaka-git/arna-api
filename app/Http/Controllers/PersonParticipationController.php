<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use MaaleArna\MASES\Core\Domains\People\Models\Person;
use MaaleArna\MASES\Core\Domains\People\Models\PersonParticipation;
use MaaleArna\MASES\Core\Domains\People\Events\PersonProfileLifecycleChanged;
use MaaleArna\MASES\Core\Domains\People\Http\Requests\StorePersonParticipationRequest;
use MaaleArna\MASES\Core\Domains\People\Http\Requests\DeactivatePersonRecordRequest;

final class PersonParticipationController extends Controller
{
    private const TYPES = [
        'membership', 'ministry', 'volunteer', 'committee',
        'event_attendance', 'training', 'qualification', 'other',
    ];

    public function index(string $publicId): JsonResponse
    {
        $tenantId = mases_tenant_id();
        abort_if($tenantId === null, 400, 'A validated tenant context is required.');
        $person = $this->visiblePerson($publicId, (int) $tenantId);

        $records = PersonParticipation::query()
            ->where('tenant_id', $tenantId)
            ->where('person_id', $person->getKey())
            ->where('status', 'active')
            ->where(static fn ($query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', today()))
            ->where(static fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', today()))
            ->orderBy('participation_type')
            ->orderBy('title')
            ->limit(200)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $records->map(fn (PersonParticipation $record): array => $this->present($record))->values(),
        ]);
    }

    public function store(StorePersonParticipationRequest $request, string $publicId): JsonResponse
    {
        $tenantId = mases_tenant_id();
        abort_if($tenantId === null, 400, 'A validated tenant context is required.');
        $person = $this->visiblePerson($publicId, (int) $tenantId);
        $attributes = $request->validated();

        if (! empty($attributes['effective_from']) && ! empty($attributes['effective_to'])
            && $attributes['effective_from'] > $attributes['effective_to']) {
            abort(422, 'The effective end date must not precede the start date.');
        }

        $record = DB::transaction(function () use ($tenantId, $person, $request, $attributes): PersonParticipation {
            Person::query()->whereKey($person->getKey())->lockForUpdate()->firstOrFail();
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
                'created_by' => $request->user()?->getKey(),
            ]);
            $record->save();

            return $record;
        });

        event(new PersonProfileLifecycleChanged('people.participation.created', (int) $tenantId, (int) $person->getKey(), $request->user()?->getKey(), 'participation', (int) $record->getKey()));

        return response()->json(['success' => true, 'data' => $this->present($record)], 201);
    }

    public function deactivate(DeactivatePersonRecordRequest $request, string $publicId, int $participationId): JsonResponse
    {
        $tenantId = mases_tenant_id();
        abort_if($tenantId === null, 400, 'A validated tenant context is required.');
        $person = $this->visiblePerson($publicId, (int) $tenantId);
        $attributes = $request->validated();

        $record = DB::transaction(function () use ($tenantId, $person, $participationId, $attributes): PersonParticipation {
            $record = PersonParticipation::query()
                ->where('tenant_id', $tenantId)
                ->where('person_id', $person->getKey())
                ->whereKey($participationId)
                ->where('status', 'active')
                ->lockForUpdate()
                ->firstOrFail();
            $record->status = 'inactive';
            $record->deactivation_reason = $attributes['reason'];
            $record->save();

            return $record;
        });

        event(new PersonProfileLifecycleChanged('people.participation.deactivated', (int) $tenantId, (int) $person->getKey(), $request->user()?->getKey(), 'participation', (int) $record->getKey()));

        return response()->json(['success' => true, 'data' => $this->present($record)]);
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

    private function present(PersonParticipation $record): array
    {
        return [
            'id' => (int) $record->getKey(),
            'participation_type' => $record->participation_type,
            'title' => $record->title,
            'reference_code' => $record->reference_code,
            'effective_from' => $record->effective_from?->toDateString(),
            'effective_to' => $record->effective_to?->toDateString(),
            'status' => $record->status,
        ];
    }
}
