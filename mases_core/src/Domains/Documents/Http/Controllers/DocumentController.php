<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Documents\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Documents\Models\Document;
use MaaleArna\MASES\Core\Domains\Documents\Registries\DocumentTypeRegistry;
use Illuminate\Validation\Rule;
use MaaleArna\MASES\Core\Domains\Documents\Services\DocumentIssuanceService;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Illuminate\Routing\Controller;

final class DocumentController extends Controller
{
    public function __construct(private readonly DocumentIssuanceService $documents)
    {
    }

    public function issue(Request $request): JsonResponse
    {
        $actor = $this->actor();
        $validated = $request->validate([
            'legal_entity_id' => ['required', 'integer', 'min:1'],
            'organization_code' => ['required', 'string', 'regex:/^[A-Z0-9]{2,12}$/'],
            'document_type_code' => ['required', 'string', Rule::in(DocumentTypeRegistry::CODES)],
            'department_code' => ['required', 'string', 'regex:/^[A-Z0-9]{2,12}$/'],
            'issuing_role_code' => ['required', 'string', 'regex:/^[A-Z0-9]{2,12}$/'],
            'title' => ['required', 'string', 'min:1', 'max:255'],
            'file_id' => ['required', 'integer', 'min:1'],
            'metadata' => ['sometimes', 'array'],
        ]);

        $key = (string) $request->header('Idempotency-Key', '');
        $document = $this->documents->issue($validated, $actor, $key);

        return response()->json(['success' => true, 'data' => $this->present($document)], 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->present($this->documents->findForCurrentTenant($id, $this->actor())),
        ]);
    }

    public function void(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:1', 'max:1000']]);
        $document = $this->documents->void($id, $validated['reason'], $this->actor());

        return response()->json(['success' => true, 'data' => $this->present($document)]);
    }

    public function revoke(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:1', 'max:1000']]);
        $document = $this->documents->revoke($id, $validated['reason'], $this->actor());

        return response()->json(['success' => true, 'data' => $this->present($document)]);
    }

    private function actor(): User
    {
        $actor = auth()->user();
        if (!$actor instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'A platform user session is required.');
        }

        return $actor;
    }

    /**
     * Expose a stable safe projection, never internal file storage metadata.
     *
     * @return array<string, mixed>
     */
    private function present(Document $document): array
    {
        return [
            'id' => $document->getKey(),
            'uuid' => $document->uuid,
            'canonical_number' => $document->canonical_number,
            'document_type_code' => $document->document_type_code,
            'title' => $document->title,
            'status' => $document->status,
            'ec_year' => $document->ec_year,
            'sequence_number' => $document->sequence_number,
            'file_id' => $document->file_id,
            'file_version' => $document->file_version,
            'issued_at' => $document->issued_at?->toIso8601String(),
            'voided_at' => $document->voided_at?->toIso8601String(),
            'void_reason' => $document->void_reason,
            'revoked_at' => $document->revoked_at?->toIso8601String(),
            'revoke_reason' => $document->revoke_reason,
        ];
    }
}
