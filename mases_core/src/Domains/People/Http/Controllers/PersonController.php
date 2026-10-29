<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\People\Http\Controllers;

use App\Http\Controllers\Controller;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MaaleArna\MASES\Core\Domains\People\Models\Person;
use MaaleArna\MASES\Core\Domains\People\Contracts\PersonProfileRepositoryContract;
use MaaleArna\MASES\Core\Domains\People\Services\PersonIdentityService;
use MaaleArna\MASES\Core\Domains\People\Http\Requests\StorePersonRequest;
use MaaleArna\MASES\Core\Domains\People\Http\Requests\UpdatePersonRequest;
use MaaleArna\MASES\Core\Domains\People\Http\Requests\LinkPersonAccountRequest;
use MaaleArna\MASES\Core\Domains\People\Http\Requests\LinkPersonDomainRecordRequest;
use MaaleArna\MASES\Core\Domains\People\Http\Requests\LinkPersonDocumentRequest;
use MaaleArna\MASES\Core\Domains\People\Http\Requests\UnlinkPersonDocumentRequest;

final class PersonController extends Controller
{
    /**
     * List only profiles explicitly visible in the middleware-validated tenant.
     * Never accept tenant_id from the body or query as an authority source.
     */
    public function index(Request $request, PersonProfileRepositoryContract $peopleRepository): JsonResponse
    {
        $tenantId = mases_tenant_id();
        abort_if($tenantId === null, 400, 'A validated tenant context is required.');

        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $people = $peopleRepository->paginateVisible(
            (int) $tenantId,
            $search,
            (int) ($filters['per_page'] ?? config('people.directory.default_per_page', 25)),
        );

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

    public function store(StorePersonRequest $request, PersonIdentityService $identities): JsonResponse
    {
        $tenantId = mases_tenant_id();
        abort_if($tenantId === null, 400, 'A validated tenant context is required.');

        $attributes = $request->validated();

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

    public function update(UpdatePersonRequest $request, string $publicId, PersonIdentityService $identities): JsonResponse
    {
        $tenantId = mases_tenant_id();
        abort_if($tenantId === null, 400, 'A validated tenant context is required.');

        $attributes = $request->validated();

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
    public function linkDomain(LinkPersonDomainRecordRequest $request, string $publicId, string $domain, int $recordId, PersonIdentityService $identities): JsonResponse
    {
        $tenantId = mases_tenant_id();
        abort_if($tenantId === null, 400, 'A validated tenant context is required.');
        $attributes = $request->validated();
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

    public function unlinkDomain(LinkPersonDomainRecordRequest $request, string $publicId, string $domain, int $recordId, PersonIdentityService $identities): JsonResponse
    {
        $tenantId = mases_tenant_id();
        abort_if($tenantId === null, 400, 'A validated tenant context is required.');
        $attributes = $request->validated();
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

    public function linkAccount(LinkPersonAccountRequest $request, string $publicId, PersonIdentityService $identities): JsonResponse
    {
        $tenantId = mases_tenant_id();
        abort_if($tenantId === null, 400, 'A validated tenant context is required.');
        $attributes = $request->validated();
        $person = $this->visiblePerson($publicId, (int) $tenantId);

        try {
            $identities->linkAccount((int) $tenantId, (int) $person->getKey(), (int) $attributes['user_id'], $request->user(), $attributes['reason']);
        } catch (DomainException $exception) {
            abort(403, 'The requested account relationship is not authorized or is inconsistent.');
        }

        return response()->json(['success' => true, 'data' => ['linked' => true]]);
    }

    public function unlinkAccount(LinkPersonDomainRecordRequest $request, string $publicId, PersonIdentityService $identities): JsonResponse
    {
        $tenantId = mases_tenant_id();
        abort_if($tenantId === null, 400, 'A validated tenant context is required.');
        $attributes = $request->validated();
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

    public function linkDocument(LinkPersonDocumentRequest $request, string $publicId, PersonIdentityService $identities): JsonResponse
    {
        $tenantId = mases_tenant_id();
        abort_if($tenantId === null, 400, 'A validated tenant context is required.');
        $attributes = $request->validated();
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

    public function unlinkDocument(UnlinkPersonDocumentRequest $request, string $publicId, int $fileId, PersonIdentityService $identities): JsonResponse
    {
        $tenantId = mases_tenant_id();
        abort_if($tenantId === null, 400, 'A validated tenant context is required.');
        $attributes = $request->validated();
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


    public function contacts(Request $request, string $publicId): JsonResponse
    {
        [$tenantId, $person] = $this->visiblePersonContext($publicId);
        $rows = DB::table('person_contact_points')->where('tenant_id', $tenantId)
            ->where('person_id', $person->getKey())->orderByDesc('is_primary')->orderBy('id')->limit(100)->get();
        return response()->json(['success' => true, 'data' => $rows->map(fn ($row) => $this->presentContact($row))->values()]);
    }

    public function createContact(Request $request, string $publicId): JsonResponse
    {
        [$tenantId, $person] = $this->visiblePersonContext($publicId);
        $a = $request->validate([
            'contact_type' => ['required', 'string', 'in:email,phone,other'],
            'label' => ['sometimes', 'nullable', 'string', 'max:120'],
            'contact_value' => ['required', 'string', 'min:3', 'max:320'],
            'is_primary' => ['sometimes', 'boolean'],
        ]);
        $value = trim($a['contact_value']);
        if ($a['contact_type'] === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw \Illuminate\Validation\ValidationException::withMessages(['contact_value' => 'Enter a valid email address.']);
        }
        if ($a['contact_type'] === 'phone' && !preg_match('/^[+0-9().\-\s]{6,32}$/', $value)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['contact_value' => 'Enter a valid phone number.']);
        }
        $primary = (bool) ($a['is_primary'] ?? false);
        $id = DB::transaction(function () use ($tenantId, $person, $a, $value, $primary, $request): int {
            if ($primary) {
                DB::table('person_contact_points')->where('tenant_id', $tenantId)->where('person_id', $person->getKey())
                    ->where('contact_type', $a['contact_type'])->where('status', 'active')->update(['is_primary' => false, 'updated_at' => now()]);
            }
            return (int) DB::table('person_contact_points')->insertGetId([
                'tenant_id' => $tenantId, 'person_id' => $person->getKey(), 'contact_type' => $a['contact_type'],
                'label' => $a['label'] ?? null, 'contact_value' => \Illuminate\Support\Facades\Crypt::encryptString($value),
                'normalized_hash' => hash_hmac('sha256', $a['contact_type'] === 'email' ? mb_strtolower($value) : preg_replace('/\s+/', '', $value), (string) config('app.key')),
                'is_primary' => $primary, 'is_verified' => false, 'verified_at' => null, 'source' => 'manual',
                'status' => 'active', 'created_by' => $request->user()?->getAuthIdentifier(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        $row = DB::table('person_contact_points')->where('tenant_id', $tenantId)->where('id', $id)->first();
        return response()->json(['success' => true, 'data' => $this->presentContact($row)], 201);
    }

    public function deactivateContact(Request $request, string $publicId, int $contactId): JsonResponse
    {
        [$tenantId, $person] = $this->visiblePersonContext($publicId);
        $a = $request->validate(['reason' => ['required', 'string', 'min:8', 'max:1000']]);
        $count = DB::table('person_contact_points')->where('tenant_id', $tenantId)->where('person_id', $person->getKey())
            ->where('id', $contactId)->where('status', 'active')
            ->update(['status' => 'inactive', 'is_primary' => false, 'deactivation_reason' => trim($a['reason']), 'updated_at' => now()]);
        abort_if($count === 0, 404, 'Active contact point not found.');
        return response()->json(['success' => true, 'data' => $this->presentContact(DB::table('person_contact_points')->where('tenant_id', $tenantId)->where('id', $contactId)->first())]);
    }

    public function personRelationships(Request $request, string $publicId): JsonResponse
    {
        [$tenantId, $person] = $this->visiblePersonContext($publicId);
        $rows = DB::table('person_relationships as rel')->join('people as related', 'related.id', '=', 'rel.related_person_id')
            ->join('person_tenant_accesses as related_access', function ($join) use ($tenantId): void {
                $join->on('related_access.person_id', '=', 'related.id')->where('related_access.tenant_id', $tenantId)->where('related_access.status', 'active');
            })->where('related.status', 'active')
            ->where('rel.tenant_id', $tenantId)->where('rel.person_id', $person->getKey())->orderBy('rel.id')->limit(100)
            ->get(['rel.id','related.public_id as related_public_id','related.first_name_am as related_first_name_am','related.father_name_am as related_father_name_am','related.grandfather_name_am as related_grandfather_name_am','related.first_name_en as related_first_name_en','related.father_name_en as related_father_name_en','related.grandfather_name_en as related_grandfather_name_en','rel.relationship_type','rel.effective_from','rel.effective_to','rel.status']);
        return response()->json(['success' => true, 'data' => $rows->map(fn ($row) => $this->presentPersonRelationship($row))->values()]);
    }

    public function createPersonRelationship(Request $request, string $publicId): JsonResponse
    {
        [$tenantId, $person] = $this->visiblePersonContext($publicId);
        $types = ['parent','child','guardian','dependent','spouse','sibling','emergency_contact','household_member','other'];
        $a = $request->validate([
            'related_public_id' => ['required','uuid'],
            'relationship_type' => ['required','string',\Illuminate\Validation\Rule::in($types)],
            'effective_from' => ['sometimes','nullable','date'],
            'effective_to' => ['sometimes','nullable','date','after_or_equal:effective_from'],
            'reason' => ['required','string','min:8','max:1000'],
        ]);
        abort_if($a['related_public_id'] === $publicId, 422, 'A person cannot be related to themselves.');
        $related = Person::query()->where('public_id', $a['related_public_id'])->where('status', 'active')
            ->whereHas('tenantAccesses', static fn ($q) => $q->where('tenant_id', $tenantId)->where('status', 'active'))->firstOrFail();
        $id = DB::transaction(function () use ($tenantId, $person, $related, $a, $request): int {
            $duplicate = DB::table('person_relationships')->where('tenant_id', $tenantId)->where('person_id', $person->getKey())
                ->where('related_person_id', $related->getKey())->where('relationship_type', $a['relationship_type'])->where('status', 'active')->lockForUpdate()->exists();
            abort_if($duplicate, 409, 'An active relationship of this type already exists.');
            return (int) DB::table('person_relationships')->insertGetId([
                'tenant_id'=>$tenantId,'person_id'=>$person->getKey(),'related_person_id'=>$related->getKey(),
                'relationship_type'=>$a['relationship_type'],'effective_from'=>$a['effective_from'] ?? null,'effective_to'=>$a['effective_to'] ?? null,
                'status'=>'active','reason'=>trim($a['reason']),'created_by'=>$request->user()?->getAuthIdentifier(),'created_at'=>now(),'updated_at'=>now(),
            ]);
        });
        $row = DB::table('person_relationships as rel')->join('people as related','related.id','=','rel.related_person_id')
            ->join('person_tenant_accesses as related_access', function ($join) use ($tenantId): void { $join->on('related_access.person_id','=','related.id')->where('related_access.tenant_id',$tenantId)->where('related_access.status','active'); })->where('related.status','active')
            ->where('rel.tenant_id',$tenantId)->where('rel.id',$id)
            ->first(['rel.id','related.public_id as related_public_id','related.first_name_am as related_first_name_am','related.father_name_am as related_father_name_am','related.grandfather_name_am as related_grandfather_name_am','related.first_name_en as related_first_name_en','related.father_name_en as related_father_name_en','related.grandfather_name_en as related_grandfather_name_en','rel.relationship_type','rel.effective_from','rel.effective_to','rel.status']);
        return response()->json(['success'=>true,'data'=>$this->presentPersonRelationship($row)],201);
    }

    public function deactivatePersonRelationship(Request $request, string $publicId, int $relationshipId): JsonResponse
    {
        [$tenantId, $person] = $this->visiblePersonContext($publicId);
        $a = $request->validate(['reason'=>['required','string','min:8','max:1000']]);
        $count = DB::table('person_relationships')->where('tenant_id',$tenantId)->where('person_id',$person->getKey())
            ->where('id',$relationshipId)->where('status','active')->update(['status'=>'inactive','deactivation_reason'=>trim($a['reason']),'updated_at'=>now()]);
        abort_if($count === 0,404,'Active person relationship not found.');
        $row = DB::table('person_relationships as rel')->join('people as related','related.id','=','rel.related_person_id')
            ->join('person_tenant_accesses as related_access', function ($join) use ($tenantId): void { $join->on('related_access.person_id','=','related.id')->where('related_access.tenant_id',$tenantId)->where('related_access.status','active'); })->where('related.status','active')
            ->where('rel.tenant_id',$tenantId)->where('rel.id',$relationshipId)
            ->first(['rel.id','related.public_id as related_public_id','related.first_name_am as related_first_name_am','related.father_name_am as related_father_name_am','related.grandfather_name_am as related_grandfather_name_am','related.first_name_en as related_first_name_en','related.father_name_en as related_father_name_en','related.grandfather_name_en as related_grandfather_name_en','rel.relationship_type','rel.effective_from','rel.effective_to','rel.status']);
        return response()->json(['success'=>true,'data'=>$this->presentPersonRelationship($row)]);
    }

    public function participations(Request $request, string $publicId): JsonResponse
    {
        [$tenantId, $person] = $this->visiblePersonContext($publicId);
        $rows = DB::table('person_participations')->where('tenant_id',$tenantId)->where('person_id',$person->getKey())
            ->orderByDesc('effective_from')->orderBy('id')->limit(200)->get()->map(fn ($row) => $this->presentParticipation($row))->values();
        return response()->json(['success'=>true,'data'=>$rows]);
    }

    public function createParticipation(Request $request, string $publicId): JsonResponse
    {
        [$tenantId, $person] = $this->visiblePersonContext($publicId);
        $types = ['membership','ministry','volunteer','committee','event_attendance','training','qualification','other'];
        $a = $request->validate([
            'participation_type'=>['required','string',\Illuminate\Validation\Rule::in($types)],
            'title'=>['required','string','min:2','max:180'],'reference_code'=>['sometimes','nullable','string','max:100'],
            'effective_from'=>['sometimes','nullable','date'],'effective_to'=>['sometimes','nullable','date','after_or_equal:effective_from'],
            'reason'=>['required','string','min:8','max:1000'],
        ]);
        $id = DB::table('person_participations')->insertGetId([
            'tenant_id'=>$tenantId,'person_id'=>$person->getKey(),'participation_type'=>$a['participation_type'],'title'=>trim($a['title']),
            'reference_code'=>isset($a['reference_code']) ? trim($a['reference_code']) : null,'effective_from'=>$a['effective_from'] ?? null,
            'effective_to'=>$a['effective_to'] ?? null,'status'=>'active','reason'=>trim($a['reason']),
            'created_by'=>$request->user()?->getAuthIdentifier(),'created_at'=>now(),'updated_at'=>now(),
        ]);
        return response()->json(['success'=>true,'data'=>$this->presentParticipation(DB::table('person_participations')->where('tenant_id',$tenantId)->where('id',$id)->first())],201);
    }

    public function deactivateParticipation(Request $request, string $publicId, int $participationId): JsonResponse
    {
        [$tenantId,$person] = $this->visiblePersonContext($publicId);
        $a = $request->validate(['reason'=>['required','string','min:8','max:1000']]);
        $count = DB::table('person_participations')->where('tenant_id',$tenantId)->where('person_id',$person->getKey())
            ->where('id',$participationId)->where('status','active')->update(['status'=>'inactive','deactivation_reason'=>trim($a['reason']),'updated_at'=>now()]);
        abort_if($count===0,404,'Active participation record not found.');
        return response()->json(['success'=>true,'data'=>$this->presentParticipation(DB::table('person_participations')->where('tenant_id',$tenantId)->where('id',$participationId)->first())]);
    }

    private function visiblePersonContext(string $publicId): array
    {
        $tenantId = mases_tenant_id();
        abort_if($tenantId === null, 400, 'A validated tenant context is required.');
        $person = Person::query()->where('public_id',$publicId)->where('status','active')
            ->whereHas('tenantAccesses', static fn($q)=>$q->where('tenant_id',$tenantId)->where('status','active'))->firstOrFail();
        return [(int)$tenantId,$person];
    }

    private function presentContact(object $row): array
    {
        try { $value = \Illuminate\Support\Facades\Crypt::decryptString((string)$row->contact_value); } catch (\Throwable) { $value = ''; }
        $masked = match ($row->contact_type) {
            'email' => str_contains($value,'@') ? mb_substr(explode('@',$value,2)[0],0,1).'•••@'.explode('@',$value,2)[1] : '••••',
            'phone' => '••••'.substr(preg_replace('/\D+/', '', $value), -3),
            default => mb_substr($value,0,1).'••••',
        };
        return ['id'=>(int)$row->id,'contact_type'=>$row->contact_type,'label'=>$row->label,'masked_value'=>$masked,'is_primary'=>(bool)$row->is_primary,'is_verified'=>(bool)$row->is_verified,'verified_at'=>$row->verified_at,'status'=>$row->status,'created_at'=>$row->created_at];
    }

    private function presentPersonRelationship(object $row): array
    {
        $am = trim(implode(' ',array_filter([$row->related_first_name_am,$row->related_father_name_am,$row->related_grandfather_name_am])));
        $en = trim(implode(' ',array_filter([$row->related_first_name_en,$row->related_father_name_en,$row->related_grandfather_name_en])));
        return ['id'=>(int)$row->id,'related_public_id'=>$row->related_public_id,'related_name_am'=>$am !== '' ? $am : null,'related_name_en'=>$en !== '' ? $en : null,'relationship_type'=>$row->relationship_type,'effective_from'=>$row->effective_from,'effective_to'=>$row->effective_to,'status'=>$row->status];
    }

    private function presentParticipation(object $row): array
    {
        return ['id'=>(int)$row->id,'participation_type'=>$row->participation_type,'title'=>$row->title,'reference_code'=>$row->reference_code,'effective_from'=>$row->effective_from,'effective_to'=>$row->effective_to,'status'=>$row->status];
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
