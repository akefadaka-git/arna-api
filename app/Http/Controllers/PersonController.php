<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MaaleArna\MASES\Core\Domains\People\Models\Person;
use MaaleArna\MASES\Core\Domains\People\Services\PersonIdentityService;

final class PersonController extends Controller
{
    /**
     * List only profiles explicitly visible in the middleware-validated tenant.
     * Never accept tenant_id from the body or query as an authority source.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = mases_tenant_id();
        abort_if($tenantId === null, 400, 'A validated tenant context is required.');

        $people = Person::query()
            ->where('people.status', 'active')
            ->whereHas('tenantAccesses', static fn ($query) => $query
                ->where('tenant_id', $tenantId)
                ->where('status', 'active'))
            ->orderBy('people.id')
            ->paginate(min(max((int) $request->integer('per_page', 25), 1), 100));

        return response()->json([
            'success' => true,
            'data' => $people->getCollection()->map(self::present(...))->values(),
            'meta' => [
                'current_page' => $people->currentPage(),
                'per_page' => $people->perPage(),
                'total' => $people->total(),
                'last_page' => $people->lastPage(),
            ],
        ]);
    }

    public function store(Request $request, PersonIdentityService $identities): JsonResponse
    {
        $tenantId = mases_tenant_id();
        abort_if($tenantId === null, 400, 'A validated tenant context is required.');

        $attributes = $request->validate([
            'first_name_am' => ['nullable', 'string', 'max:120'],
            'father_name_am' => ['nullable', 'string', 'max:120'],
            'grandfather_name_am' => ['nullable', 'string', 'max:120'],
            'first_name_en' => ['nullable', 'string', 'max:120'],
            'father_name_en' => ['nullable', 'string', 'max:120'],
            'grandfather_name_en' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $person = $identities->create((int) $tenantId, $request->user(), $attributes);
        } catch (DomainException $exception) {
            abort(403, 'The caller is not authorized to create a Person in this tenant.');
        }

        return response()->json([
            'success' => true,
            'data' => self::present($person),
        ], 201);
    }

    public function show(string $publicId): JsonResponse
    {
        $tenantId = mases_tenant_id();
        abort_if($tenantId === null, 400, 'A validated tenant context is required.');

        $person = Person::query()
            ->where('public_id', $publicId)
            ->where('status', 'active')
            ->whereHas('tenantAccesses', static fn ($query) => $query
                ->where('tenant_id', $tenantId)
                ->where('status', 'active'))
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => self::present($person),
        ]);
    }

    /**
     * Explicit allow-list: never serialize credentials, internal user IDs,
     * tenant grants, or unreviewed model attributes to API consumers.
     */
    private static function present(Person $person): array
    {
        return [
            'public_id' => $person->public_id,
            'first_name_am' => $person->first_name_am,
            'father_name_am' => $person->father_name_am,
            'grandfather_name_am' => $person->grandfather_name_am,
            'first_name_en' => $person->first_name_en,
            'father_name_en' => $person->father_name_en,
            'grandfather_name_en' => $person->grandfather_name_en,
            'status' => $person->status,
            'created_at' => $person->created_at?->toIso8601String(),
            'updated_at' => $person->updated_at?->toIso8601String(),
        ];
    }
}
