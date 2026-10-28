<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));

        $query = Person::query()
            ->where('people.status', 'active')
            ->whereHas('tenantAccesses', static fn ($query) => $query
                ->where('tenant_id', $tenantId)
                ->where('status', 'active'));

        if ($search !== '') {
            $pattern = '%'.$search.'%';
            $query->where(static function ($names) use ($pattern): void {
                foreach ([
                    'first_name_am', 'father_name_am', 'grandfather_name_am',
                    'first_name_en', 'father_name_en', 'grandfather_name_en',
                ] as $column) {
                    $names->orWhere('people.'.$column, 'like', $pattern);
                }
            });
        }

        $people = $query->orderBy('people.id')
            ->paginate((int) ($filters['per_page'] ?? 25));

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

    public function update(Request $request, string $publicId, PersonIdentityService $identities): JsonResponse
    {
        $tenantId = mases_tenant_id();
        abort_if($tenantId === null, 400, 'A validated tenant context is required.');

        $attributes = $request->validate([
            'first_name_am' => ['sometimes', 'nullable', 'string', 'max:120'],
            'father_name_am' => ['sometimes', 'nullable', 'string', 'max:120'],
            'grandfather_name_am' => ['sometimes', 'nullable', 'string', 'max:120'],
            'first_name_en' => ['sometimes', 'nullable', 'string', 'max:120'],
            'father_name_en' => ['sometimes', 'nullable', 'string', 'max:120'],
            'grandfather_name_en' => ['sometimes', 'nullable', 'string', 'max:120'],
            'reason' => ['required', 'string', 'min:8', 'max:1000'],
        ]);

        $reason = $attributes['reason'];
        unset($attributes['reason']);

        try {
            $person = $identities->updateProfile((int) $tenantId, $publicId, $request->user(), $attributes, $reason);
        } catch (DomainException $exception) {
            abort(403, 'The caller is not authorized to update this Person in the tenant.');
        }

        return response()->json(['success' => true, 'data' => self::present($person)]);
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
     * Link an existing domain record to the canonical Person. The domain
     * record remains owned by its domain; this operation only changes the
     * explicit identity reference and records the lifecycle audit event.
     */
    public function linkDomain(Request $request, string $publicId, string $domain, int $recordId, PersonIdentityService $identities): JsonResponse
    {
        $tenantId = mases_tenant_id();
        abort_if($tenantId === null, 400, 'A validated tenant context is required.');
        $attributes = $request->validate([
            'reason' => ['required', 'string', 'min:8', 'max:1000'],
        ]);
        $person = $this->visiblePerson($publicId, (int) $tenantId);

        try {
            switch ($domain) {
                case 'employees':
                    $identities->linkEmployee((int) $tenantId, $recordId, (int) $person->getKey(), $request->user(), $attributes['reason']);
                    break;
                case 'shareholders':
                    $identities->linkShareholder((int) $tenantId, $recordId, (int) $person->getKey(), $request->user(), $attributes['reason']);
                    break;
                case 'governance-memberships':
                    $identities->linkGovernanceMembership((int) $tenantId, $recordId, (int) $person->getKey(), $request->user(), $attributes['reason']);
                    break;
                default:
                    abort(404);
            }
        } catch (DomainException $exception) {
            abort(403, 'The requested identity relationship is not authorized or is inconsistent.');
        }

        return response()->json(['success' => true, 'data' => ['linked' => true]]);
    }

    public function unlinkDomain(Request $request, string $publicId, string $domain, int $recordId, PersonIdentityService $identities): JsonResponse
    {
        $tenantId = mases_tenant_id();
        abort_if($tenantId === null, 400, 'A validated tenant context is required.');
        $attributes = $request->validate([
            'reason' => ['required', 'string', 'min:8', 'max:1000'],
        ]);
        $person = $this->visiblePerson($publicId, (int) $tenantId);

        try {
            switch ($domain) {
                case 'employees':
                    $identities->unlinkEmployee((int) $tenantId, $recordId, $request->user(), $attributes['reason']);
                    break;
                case 'shareholders':
                    $identities->unlinkShareholder((int) $tenantId, $recordId, $request->user(), $attributes['reason']);
                    break;
                case 'governance-memberships':
                    $identities->unlinkGovernanceMembership((int) $tenantId, $recordId, $request->user(), $attributes['reason']);
                    break;
                default:
                    abort(404);
            }
        } catch (DomainException $exception) {
            abort(403, 'The requested identity relationship is not authorized or is inconsistent.');
        }

        return response()->json(['success' => true, 'data' => ['linked' => false]]);
    }

    public function linkAccount(Request $request, string $publicId, PersonIdentityService $identities): JsonResponse
    {
        $tenantId = mases_tenant_id();
        abort_if($tenantId === null, 400, 'A validated tenant context is required.');
        $attributes = $request->validate([
            'user_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:8', 'max:1000'],
        ]);
        $person = $this->visiblePerson($publicId, (int) $tenantId);

        try {
            $identities->linkAccount((int) $tenantId, (int) $person->getKey(), (int) $attributes['user_id'], $request->user(), $attributes['reason']);
        } catch (DomainException $exception) {
            abort(403, 'The requested account relationship is not authorized or is inconsistent.');
        }

        return response()->json(['success' => true, 'data' => ['linked' => true]]);
    }

    public function unlinkAccount(Request $request, string $publicId, PersonIdentityService $identities): JsonResponse
    {
        $tenantId = mases_tenant_id();
        abort_if($tenantId === null, 400, 'A validated tenant context is required.');
        $attributes = $request->validate([
            'reason' => ['required', 'string', 'min:8', 'max:1000'],
        ]);
        $person = $this->visiblePerson($publicId, (int) $tenantId);

        try {
            $identities->unlinkAccount((int) $tenantId, (int) $person->getKey(), $request->user(), $attributes['reason']);
        } catch (DomainException $exception) {
            abort(403, 'The requested account relationship is not authorized.');
        }

        return response()->json(['success' => true, 'data' => ['linked' => false]]);
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

    public function documents(string $publicId, PersonIdentityService $identities): JsonResponse
    {
        $tenantId = mases_tenant_id();
        abort_if($tenantId === null, 400, 'A validated tenant context is required.');
        $person = $this->visiblePerson($publicId, (int) $tenantId);

        return response()->json([
            'success' => true,
            'data' => $identities->listDocuments((int) $tenantId, (int) $person->getKey(), request()->user()),
        ]);
    }

    public function linkDocument(Request $request, string $publicId, PersonIdentityService $identities): JsonResponse
    {
        $tenantId = mases_tenant_id();
        abort_if($tenantId === null, 400, 'A validated tenant context is required.');
        $attributes = $request->validate([
            'file_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:8', 'max:1000'],
        ]);
        $person = $this->visiblePerson($publicId, (int) $tenantId);

        try {
            $identities->linkDocument(
                (int) $tenantId,
                (int) $person->getKey(),
                (int) $attributes['file_id'],
                $request->user(),
                $attributes['reason'],
            );
        } catch (DomainException $exception) {
            abort(403, 'The requested document relationship is not authorized or is inconsistent.');
        }

        return response()->json(['success' => true, 'data' => ['linked' => true]]);
    }

    public function unlinkDocument(Request $request, string $publicId, int $fileId, PersonIdentityService $identities): JsonResponse
    {
        $tenantId = mases_tenant_id();
        abort_if($tenantId === null, 400, 'A validated tenant context is required.');
        $attributes = $request->validate([
            'reason' => ['required', 'string', 'min:8', 'max:1000'],
        ]);
        $person = $this->visiblePerson($publicId, (int) $tenantId);

        try {
            $identities->unlinkDocument(
                (int) $tenantId,
                (int) $person->getKey(),
                $fileId,
                $request->user(),
                $attributes['reason'],
            );
        } catch (DomainException $exception) {
            abort(403, 'The requested document relationship is not authorized or is inconsistent.');
        }

        return response()->json(['success' => true, 'data' => ['linked' => false]]);
    }

    /**
     * Return tenant-scoped relationship indicators without exposing linked
     * account IDs or unrelated domain attributes. This endpoint is restricted
     * to People managers because relationship existence can itself be sensitive.
     */
    /**
     * Return tenant-scoped, privacy-minimized records available for explicit
     * People identity association. Internal user IDs and unrelated attributes
     * are deliberately excluded from the response.
     */
    public function relationshipOptions(Request $request, string $publicId, string $domain): JsonResponse
    {
        $tenantId = mases_tenant_id();
        abort_if($tenantId === null, 400, 'A validated tenant context is required.');
        abort_unless(in_array($domain, ['employees', 'shareholders', 'governance-memberships'], true), 404);

        $attributes = $request->validate(['search' => ['sometimes', 'string', 'max:100']]);
        $search = trim((string) ($attributes['search'] ?? ''));
        $person = $this->visiblePerson($publicId, (int) $tenantId);
        $userMatches = static function ($query) use ($person): void {
            $query->whereNull('user_id');
            if ($person->user_id !== null) {
                $query->orWhere('user_id', (int) $person->user_id);
            }
        };

        if ($domain === 'employees') {
            $linkedBase = DB::table('employees')->where('tenant_id', $tenantId)->whereNull('deleted_at');
            $candidateQuery = (clone $linkedBase)->where('is_active', true)->whereNull('person_id')->where($userMatches);
            $linkedQuery = (clone $linkedBase)->where('person_id', $person->getKey());
            if ($search !== '') {
                foreach ([$candidateQuery, $linkedQuery] as $query) {
                    $query->where('employee_number', 'like', '%'.$search.'%');
                }
            }
            $map = static fn ($row): array => [
                'id' => (int) $row->id,
                'label' => (string) $row->employee_number,
                'detail' => 'Employee',
                'status' => (bool) $row->is_active ? 'active' : 'inactive',
            ];
        } elseif ($domain === 'shareholders') {
            $linkedBase = DB::table('shareholders')->where('tenant_id', $tenantId)
                ->whereNull('deleted_at')->where('shareholder_type', 'individual');
            $candidateQuery = (clone $linkedBase)->where('is_active', true)->whereNotNull('user_id')->whereNull('person_id')->where($userMatches);
            $linkedQuery = (clone $linkedBase)->where('person_id', $person->getKey());
            if ($search !== '') {
                foreach ([$candidateQuery, $linkedQuery] as $query) {
                    $query->where(function ($nested) use ($search): void {
                        $nested->where('shareholder_number', 'like', '%'.$search.'%')
                            ->orWhere('first_name_am', 'like', '%'.$search.'%')
                            ->orWhere('father_name_am', 'like', '%'.$search.'%')
                            ->orWhere('first_name_en', 'like', '%'.$search.'%')
                            ->orWhere('father_name_en', 'like', '%'.$search.'%');
                    });
                }
            }
            $map = static fn ($row): array => [
                'id' => (int) $row->id,
                'label' => trim(implode(' ', array_filter([
                    $row->first_name_am ?? null, $row->father_name_am ?? null, $row->grandfather_name_am ?? null,
                ])) ?: implode(' ', array_filter([
                    $row->first_name_en ?? null, $row->father_name_en ?? null, $row->grandfather_name_en ?? null,
                ]))) ?: (string) ($row->shareholder_number ?? 'Shareholder'),
                'detail' => (string) ($row->shareholder_number ?? 'Individual shareholder'),
                'status' => (bool) $row->is_active ? 'active' : 'inactive',
            ];
        } else {
            $linkedBase = DB::table('governance_memberships')->where('tenant_id', $tenantId)->whereNull('deleted_at');
            $candidateQuery = (clone $linkedBase)->where('status', 'active')->whereNull('person_id')->where($userMatches);
            $linkedQuery = (clone $linkedBase)->where('person_id', $person->getKey());
            if ($search !== '') {
                foreach ([$candidateQuery, $linkedQuery] as $query) {
                    $query->where(function ($nested) use ($search): void {
                        $nested->where('member_name_am', 'like', '%'.$search.'%')
                            ->orWhere('member_name_en', 'like', '%'.$search.'%')
                            ->orWhere('external_reference', 'like', '%'.$search.'%');
                    });
                }
            }
            $map = static fn ($row): array => [
                'id' => (int) $row->id,
                'label' => trim((string) ($row->member_name_am ?? '')) ?: (trim((string) ($row->member_name_en ?? '')) ?: 'Governance membership'),
                'detail' => trim((string) ($row->external_reference ?? '')) ?: (string) $row->status,
                'status' => (string) $row->status,
            ];
        }

        $linked = $linkedQuery->orderBy('id')->limit(100)->get()->map($map)->values();
        $candidates = $candidateQuery->orderBy('id')->limit(50)->get()->map($map)->values();

        return response()->json([
            'success' => true,
            'data' => [
                'domain' => $domain,
                'linked' => $linked,
                'candidates' => $candidates,
                'candidate_limit' => 50,
            ],
        ]);
    }

    public function relationships(string $publicId): JsonResponse
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

        $employees = DB::table('employees')
            ->where('tenant_id', $tenantId)
            ->where('person_id', $person->getKey())
            ->whereNull('deleted_at')
            ->count();
        $shareholders = DB::table('shareholders')
            ->where('tenant_id', $tenantId)
            ->where('person_id', $person->getKey())
            ->count();
        $governanceMemberships = DB::table('governance_memberships')
            ->where('tenant_id', $tenantId)
            ->where('person_id', $person->getKey())
            ->count();
        $documentCount = Schema::hasTable('person_document_links')
            ? DB::table('person_document_links')->where('tenant_id', $tenantId)->where('person_id', $person->getKey())->count()
            : null;

        return response()->json([
            'success' => true,
            'data' => [
                'account_linked' => $person->user_id !== null,
                'employment_count' => $employees,
                'shareholding_count' => $shareholders,
                'governance_membership_count' => $governanceMemberships,
                'document_linking_supported' => $documentCount !== null,
                'document_count' => $documentCount,
            ],
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
