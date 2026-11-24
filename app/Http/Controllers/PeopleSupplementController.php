<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\PeopleSupplementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PeopleSupplementController extends Controller
{
    private function tenantId(): int
    {
        $id = mases_tenant_id();
        abort_if($id === null, 400, 'A validated tenant context is required.');
        return (int) $id;
    }

    public function contacts(string $publicId, PeopleSupplementService $service): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $service->contacts($this->tenantId(), $publicId)]);
    }

    public function addContact(Request $request, string $publicId, PeopleSupplementService $service): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:email,phone,address,website,other'],
            'value' => ['required', 'string', 'max:4000'],
            'label' => ['nullable', 'string', 'max:80'],
            'is_primary' => ['sometimes', 'boolean'],
        ]);
        return response()->json(['success' => true, 'data' => $service->addContact($this->tenantId(), $publicId, (int) $request->user()->getKey(), $data)], 201);
    }

    public function deactivateContact(string $publicId, int $contactId, PeopleSupplementService $service): JsonResponse
    {
        $service->deactivateContact($this->tenantId(), $publicId, $contactId);
        return response()->json(['success' => true, 'data' => ['deactivated' => true]]);
    }

    public function relationships(string $publicId, PeopleSupplementService $service): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $service->relationships($this->tenantId(), $publicId)]);
    }

    public function addRelationship(Request $request, string $publicId, PeopleSupplementService $service): JsonResponse
    {
        $data = $request->validate([
            'related_person_public_id' => ['required', 'uuid'],
            'relationship_type' => ['required', 'string', 'max:48'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);
        return response()->json(['success' => true, 'data' => $service->addRelationship($this->tenantId(), $publicId, (int) $request->user()->getKey(), $data)], 201);
    }

    public function deactivateRelationship(string $publicId, int $relationshipId, PeopleSupplementService $service): JsonResponse
    {
        $service->deactivateRelationship($this->tenantId(), $publicId, $relationshipId);
        return response()->json(['success' => true, 'data' => ['deactivated' => true]]);
    }

    public function participations(string $publicId, PeopleSupplementService $service): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $service->participations($this->tenantId(), $publicId)]);
    }

    public function addParticipation(Request $request, string $publicId, PeopleSupplementService $service): JsonResponse
    {
        $data = $request->validate([
            'participation_type' => ['required', 'string', 'max:48'],
            'title' => ['nullable', 'string', 'max:160'],
            'organization_label' => ['nullable', 'string', 'max:160'],
            'started_on' => ['nullable', 'date'],
            'ended_on' => ['nullable', 'date', 'after_or_equal:started_on'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);
        return response()->json(['success' => true, 'data' => $service->addParticipation($this->tenantId(), $publicId, (int) $request->user()->getKey(), $data)], 201);
    }

    public function deactivateParticipation(string $publicId, int $participationId, PeopleSupplementService $service): JsonResponse
    {
        $service->deactivateParticipation($this->tenantId(), $publicId, $participationId);
        return response()->json(['success' => true, 'data' => ['deactivated' => true]]);
    }
}
