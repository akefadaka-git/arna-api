<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceMeeting;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceMeetingService;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class GovernanceMeetingController extends Controller
{
    public function __construct(private readonly GovernanceMeetingService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', GovernanceMeeting::class);
        $tenantId = $this->tenantId();
        $perPage = min(max($request->integer('per_page', 25), 1), 100);

        $meetings = GovernanceMeeting::query()->where('tenant_id', $tenantId)
            ->whereHas('body', fn ($query) => $query->where('tenant_id', $tenantId))
            ->with(['body:id,tenant_id,code,body_type,name_am,name_en'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('governance_body_id'), fn ($query) => $query->where('governance_body_id', $request->integer('governance_body_id')))
            ->orderByRaw('scheduled_at IS NULL')->orderBy('scheduled_at')->orderByDesc('id')
            ->paginate($perPage);

        return response()->json(['success' => true, 'data' => $meetings]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', GovernanceMeeting::class);
        $data = $request->validate([
            'governance_body_id' => ['required', 'integer', 'min:1'],
            'title_am' => ['nullable', 'string', 'max:200'],
            'title_en' => ['nullable', 'string', 'max:200'],
            'meeting_type' => ['sometimes', 'in:ordinary,extraordinary,annual,special'],
            'scheduled_at' => ['nullable', 'date'],
            'location' => ['nullable', 'string', 'max:255'],
            'meeting_mode' => ['sometimes', 'in:in_person,remote,hybrid'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ]);
        if (empty(trim((string) ($data['title_am'] ?? ''))) && empty(trim((string) ($data['title_en'] ?? '')))) {
            throw ValidationException::withMessages([
                'title_am' => ['Provide a title in Amharic or English.'],
            ]);
        }

        $meeting = $this->service->create($this->tenantId(), (int) $request->user()->getAuthIdentifier(), $data);
        return response()->json(['success' => true, 'data' => $meeting->load('body')], 201);
    }

    public function show(Request $request, GovernanceMeeting $governanceMeeting): JsonResponse
    {
        $this->assertTenant($governanceMeeting);
        Gate::authorize('view', $governanceMeeting);
        return response()->json(['success' => true, 'data' => $governanceMeeting->load('body')]);
    }

    public function update(Request $request, GovernanceMeeting $governanceMeeting): JsonResponse
    {
        $this->assertTenant($governanceMeeting);
        Gate::authorize('update', $governanceMeeting);
        $data = $request->validate([
            'governance_body_id' => ['sometimes', 'integer', 'min:1'],
            'title_am' => ['sometimes', 'nullable', 'string', 'max:200'],
            'title_en' => ['sometimes', 'nullable', 'string', 'max:200'],
            'meeting_type' => ['sometimes', 'in:ordinary,extraordinary,annual,special'],
            'scheduled_at' => ['sometimes', 'nullable', 'date'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'meeting_mode' => ['sometimes', 'in:in_person,remote,hybrid'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ]);
        if (array_key_exists('title_am', $data) || array_key_exists('title_en', $data)) {
            $am = $data['title_am'] ?? $governanceMeeting->title_am;
            $en = $data['title_en'] ?? $governanceMeeting->title_en;
            if (empty(trim((string) $am)) && empty(trim((string) $en))) {
                throw ValidationException::withMessages(['title_am' => ['A title in Amharic or English is required.']]);
            }
        }

        $updated = $this->service->update($governanceMeeting, $this->tenantId(), (int) $request->user()->getAuthIdentifier(), $data);
        return response()->json(['success' => true, 'data' => $updated->load('body')]);
    }

    public function approve(Request $request, GovernanceMeeting $governanceMeeting): JsonResponse
    {
        $this->assertTenant($governanceMeeting);
        Gate::authorize('approve', $governanceMeeting);
        $meeting = $this->service->approve($governanceMeeting, $this->tenantId(), (int) $request->user()->getAuthIdentifier());
        return response()->json(['success' => true, 'data' => $meeting->load('body')]);
    }

    private function tenantId(): int
    {
        $tenantId = function_exists('mases_tenant_id') ? mases_tenant_id() : null;
        if ($tenantId === null) {
            throw ValidationException::withMessages([
                'tenant' => ['No tenant context is available for this request.'],
            ]);
        }
        return (int) $tenantId;
    }

    private function assertTenant(GovernanceMeeting $meeting): void
    {
        $tenantId = $this->tenantId();
        if ((int) $meeting->tenant_id === $tenantId
            && $meeting->body()->where('tenant_id', $tenantId)->exists()) {
            return;
        }
        throw (new ModelNotFoundException())->setModel(GovernanceMeeting::class, [$meeting->getKey()]);
    }
}
