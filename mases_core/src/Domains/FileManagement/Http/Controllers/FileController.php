<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\FileManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Audit\Traits\LogsAudit;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Contracts\Documents\DocumentIdentityLookup;
use MaaleArna\MASES\Core\Exceptions\FileException;
use MaaleArna\MASES\Core\Domains\FileManagement\Models\File;
use MaaleArna\MASES\Core\Domains\FileManagement\Services\FileService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * FileController
 *
 * Handles file management API endpoints with full audit logging.
 * All successful upload and delete operations are recorded in the
 * `audit_logs` table via the `LogsAudit` trait.
 *
 * The audit trail includes request metadata (IP, user agent, request ID)
 * and automatically filters out sensitive data.
 */
class FileController extends Controller
{
    use LogsAudit;

    private FileService $fileService;
    private AuthorizationService $authorizationService;
    private DocumentIdentityLookup $documentIdentityLookup;

    public function __construct(
        FileService $fileService,
        AuthorizationService $authorizationService,
        DocumentIdentityLookup $documentIdentityLookup
    ) {
        $this->fileService = $fileService;
        $this->authorizationService = $authorizationService;
        $this->documentIdentityLookup = $documentIdentityLookup;
    }

    /**
     * Upload a new file.
     *
     * @param Request $request
     * @return JsonResponse
     * @throws ValidationException
     * @throws FileException
     */
    public function upload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file'          => ['required', 'file', 'max:' . (config('mases.files.max_upload_size', 100) * 1024)],
            'file_name'     => ['sometimes', 'string', 'max:255'],
            'directory'     => ['sometimes', 'string', 'max:255'],
            'is_public'     => ['sometimes', 'boolean'],
            'metadata'      => ['sometimes', 'array'],
            'parent_file_id'=> ['sometimes', 'integer', 'exists:files,id'],
        ]);

        /** @var UploadedFile $uploadedFile */
        $uploadedFile = $request->file('file');

        $user = $request->user();
        if (!$user) {
            throw FileException::authRequired();
        }

        // Upload and publication are distinct permissions. A public-visibility
        // request cannot be used to bypass the publication authority boundary.
        $this->authorizationService->requirePermission(
            user: $user,
            permissionSlug: 'file.upload',
            tenantId: mases_tenant_id()
        );
        if (($validated['is_public'] ?? false) === true || ($validated['is_public'] ?? null) === 1 || ($validated['is_public'] ?? null) === '1') {
            $this->authorizationService->requirePermission(
                user: $user,
                permissionSlug: 'file.publish',
                tenantId: mases_tenant_id()
            );
        }

        $file = $this->fileService->upload(
            uploadedFile: $uploadedFile,
            fileName: $validated['file_name'] ?? '',
            directory: $validated['directory'] ?? null,
            isPublic: $validated['is_public'] ?? false,
            metadata: $validated['metadata'] ?? [],
            user: $user,
            tenantId: mases_tenant_id(),
            parentFileId: $validated['parent_file_id'] ?? null
        );

        // --------------------------------------------------------------------
        // AUDIT LOG: File Upload
        // --------------------------------------------------------------------
        // The `logAudit()` method automatically:
        //   - Checks the `mases.features.audit_enabled` flag.
        //   - Captures IP, user agent, request ID.
        //   - Sanitises sensitive keys from `newValues`.
        // --------------------------------------------------------------------
        $this->logAudit(
            action: 'file.uploaded',
            resourceType: 'file',
            resourceId: (string) $file->id,
            newValues: [
                'original_name' => $file->original_name,
                'mime_type'     => $file->mime_type,
                'size'          => $file->size,
                'is_public'     => $file->is_public,
                'version'       => $file->version,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'File uploaded successfully.',
            'data'    => $this->formatFileResponse($file),
        ], 201);
    }

    /**
     * Delete (soft delete) a file.
     *
     * @param Request $request
     * @param int|string $id
     * @return JsonResponse
     * @throws FileException
     */
    public function destroy(Request $request, int|string $id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            throw FileException::authRequired();
        }

        $file = $this->fileService->find($id);
        if (!$file) {
            throw FileException::notFound((string) $id);
        }

        // Authorise: require 'file.delete' permission.
        $this->authorizationService->requirePermission(
            user: $user,
            permissionSlug: 'file.delete',
            tenantId: $file->tenant_id
        );

        $this->fileService->delete($file, $user);

        // --------------------------------------------------------------------
        // AUDIT LOG: File Deletion
        // --------------------------------------------------------------------
        // Record the deletion with old values for traceability.
        // --------------------------------------------------------------------
        $this->logAudit(
            action: 'file.deleted',
            resourceType: 'file',
            resourceId: (string) $file->id,
            oldValues: [
                'original_name' => $file->original_name,
                'mime_type'     => $file->mime_type,
                'size'          => $file->size,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'File deleted successfully.',
        ]);
    }

    // =========================================================================
    // Other endpoints (download, show, update, forceDestroy, restore, index…)
    // =========================================================================

    /**
     * Download a file (streamed response).
     */
    public function download(Request $request, int|string $id): StreamedResponse|JsonResponse
    {
        $user = $request->user();
        $file = $this->fileService->find($id);

        if (!$file) {
            throw FileException::notFound((string) $id);
        }

        if (!$file->is_public || $file->status !== 'published') {
            if (!$user) {
                throw FileException::authRequired();
            }
            $this->authorizationService->requirePermission(
                user: $user,
                permissionSlug: 'file.download',
                tenantId: $file->tenant_id
            );
        }

        return $this->fileService->download($file, $user);
    }

    /**
     * Get file metadata.
     */
    public function show(Request $request, int|string $id): JsonResponse
    {
        $user = $request->user();
        $file = $this->fileService->find($id);

        if (!$file) {
            throw FileException::notFound((string) $id);
        }

        if (!$file->is_public || $file->status !== 'published') {
            if (!$user) {
                throw FileException::authRequired();
            }
            $this->authorizationService->requirePermission(
                user: $user,
                permissionSlug: 'file.view',
                tenantId: $file->tenant_id
            );
        }

        return response()->json([
            'success' => true,
            'data'    => $this->formatFileResponse($file, $this->documentIdentityFor($file)),
        ]);
    }

    /**
     * Update file metadata.
     */
    public function update(Request $request, int|string $id): JsonResponse
    {
        $validated = $request->validate([
            'status'    => ['sometimes', 'string', 'in:draft,published,archived'],
            'is_public' => ['sometimes', 'boolean'],
            'metadata'  => ['sometimes', 'array'],
            'file'      => ['sometimes', 'file', 'max:' . (config('mases.files.max_upload_size', 100) * 1024)],
        ]);

        $user = $request->user();
        if (!$user) {
            throw FileException::authRequired();
        }

        $file = $this->fileService->find($id);
        if (!$file) {
            throw FileException::notFound((string) $id);
        }

        $this->authorizationService->requirePermission(
            user: $user,
            permissionSlug: 'file.update',
            tenantId: $file->tenant_id
        );
        $publicationRequested =
            (($validated['status'] ?? null) === 'published')
            || in_array($validated['is_public'] ?? false, [true, 1, '1'], true);
        if ($publicationRequested) {
            $this->authorizationService->requirePermission(
                user: $user,
                permissionSlug: 'file.publish',
                tenantId: $file->tenant_id
            );
        }

        $newFile = $request->file('file') ?? null;
        $updatedFile = $this->fileService->update($file, $validated, $newFile, $user);

        return response()->json([
            'success' => true,
            'message' => 'File updated successfully.',
            'data'    => $this->formatFileResponse($updatedFile, $this->documentIdentityFor($updatedFile)),
        ]);
    }

    /**
     * Force delete a file (permanent).
     */
    public function forceDestroy(Request $request, int|string $id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            throw FileException::authRequired();
        }

        $file = $this->fileService->find($id, withTrashed: true);
        if (!$file) {
            throw FileException::notFound((string) $id);
        }

        $this->authorizationService->requirePermission(
            user: $user,
            permissionSlug: 'file.force_delete',
            tenantId: $file->tenant_id
        );

        $this->fileService->forceDelete($file, $user);

        // Optional audit log for permanent deletion.
        $this->logAudit(
            action: 'file.force_deleted',
            resourceType: 'file',
            resourceId: (string) $file->id,
            oldValues: ['original_name' => $file->original_name]
        );

        return response()->json([
            'success' => true,
            'message' => 'File permanently deleted.',
        ]);
    }

    /**
     * Restore a soft-deleted file.
     */
    public function restore(Request $request, int|string $id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            throw FileException::authRequired();
        }

        $file = $this->fileService->find($id, withTrashed: true);
        if (!$file) {
            throw FileException::notFound((string) $id);
        }

        $this->authorizationService->requirePermission(
            user: $user,
            permissionSlug: 'file.restore',
            tenantId: $file->tenant_id
        );

        $restoredFile = $this->fileService->restore($file, $user);

        return response()->json([
            'success' => true,
            'message' => 'File restored successfully.',
            'data'    => $this->formatFileResponse($restoredFile),
        ]);
    }

    /**
     * List files for the current tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            throw FileException::authRequired();
        }

        $this->authorizationService->requirePermission(
            user: $user,
            permissionSlug: 'file.list',
            tenantId: mases_tenant_id()
        );

        $filters = $request->validate([
            'status'          => ['sometimes', 'string', 'in:draft,published,archived'],
            'is_public'       => ['sometimes', 'boolean'],
            'user_id'         => ['sometimes', 'integer', 'min:1'],
            'mime_type'       => ['sometimes', 'string', 'max:255'],
            'order_by'        => ['sometimes', 'string', 'in:created_at,updated_at,original_name,size,mime_type,status,version'],
            'order_direction' => ['sometimes', 'string', 'in:asc,desc'],
            'limit'           => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $files = $this->fileService->getFilesForTenant(
            tenantId: mases_tenant_id(),
            filters: $filters
        );

        return response()->json([
            'success' => true,
            'data'    => $this->formatFileCollection($files),
        ]);
    }

    /**
     * Get version history of a file.
     */
    public function versions(Request $request, int|string $id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            throw FileException::authRequired();
        }

        $file = $this->fileService->find($id);
        if (!$file) {
            throw FileException::notFound((string) $id);
        }

        $this->authorizationService->requirePermission(
            user: $user,
            permissionSlug: 'file.view_versions',
            tenantId: $file->tenant_id
        );

        $versions = $this->fileService->getVersionHistory($id);

        return response()->json([
            'success' => true,
            'data'    => $this->formatFileCollection($versions),
        ]);
    }

    /**
     * Get the latest version of a file.
     */
    public function latestVersion(Request $request, int|string $id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            throw FileException::authRequired();
        }

        $file = $this->fileService->find($id);
        if (!$file) {
            throw FileException::notFound((string) $id);
        }

        $this->authorizationService->requirePermission(
            user: $user,
            permissionSlug: 'file.view_versions',
            tenantId: $file->tenant_id
        );

        $latest = $this->fileService->getLatestVersion($id);

        if (!$latest) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'FILE_LATEST_NOT_FOUND',
                    'message' => 'No versions found for this file.',
                ],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => $this->formatFileResponse($latest, $this->documentIdentityFor($latest)),
        ]);
    }

    /**
     * Download a deliberately public, published file without requiring a login.
     *
     * This endpoint is outside tenant.membership middleware by design. Public
     * visibility is checked against the persisted record on every request;
     * private, draft, archived, and soft-deleted records are never served here.
     */
    public function publicDownload(int|string $id): StreamedResponse
    {
        $file = File::query()
            ->where('is_public', true)
            ->where('status', 'published')
            ->find($id);

        if (!$file) {
            throw FileException::notFound((string) $id);
        }

        return $this->fileService->download($file, null);
    }

    /**
     * Verify a file after normal authenticated tenant and file-view authorization.
     * Private file details are never returned to unauthenticated callers.
     */
    public function verifyAuthenticated(int|string $id): JsonResponse
    {
        $file = $this->fileService->find($id);
        if (!$file) {
            throw FileException::notFound((string) $id);
        }

        $identity = $this->documentIdentityFor($file);

        return response()->json([
            'success' => true,
            'data' => [
                'file_id' => (string) $file->id,
                'canonical_number' => $identity['canonical_number'] ?? null,
                'document_number' => $identity['canonical_number'] ?? null,
                'document_type' => $identity['document_type_code'] ?? null,
                'document_type_number' => $identity['document_type_number'] ?? null,
                'document_status' => $identity['status'] ?? null,
                'file_name' => $file->original_name,
                'version' => $file->version,
                'published' => $file->status === 'published',
                'public' => (bool) $file->is_public,
                'updated_at' => $file->updated_at?->toIso8601String(),
                'verification_url' => route('api.files.verify', ['id' => $file->id]),
            ],
        ]);
    }

    /**
     * Verify a public document through its file-specific signed QR URL.
     *
     * This verifies the current publication record and its identity. It is
     * not a cryptographic signature over the document bytes.
     */
    public function publicVerify(int|string $id): JsonResponse
    {
        $file = File::query()
            ->where('is_public', true)
            ->where('status', 'published')
            ->find($id);

        if (!$file) {
            throw FileException::notFound((string) $id);
        }

        $identity = $this->documentIdentityFor($file);

        return response()->json([
            'success' => true,
            'data' => [
                'file_id' => (string) $file->id,
                'canonical_number' => $identity['canonical_number'] ?? null,
                'document_number' => $identity['canonical_number'] ?? null,
                'document_type' => $identity['document_type_code'] ?? null,
                'document_type_number' => $identity['document_type_number'] ?? null,
                'document_status' => $identity['status'] ?? null,
                'file_name' => $file->original_name,
                'version' => $file->version,
                'published' => true,
                'published_at' => $file->updated_at?->toIso8601String(),
                'verification_url' => URL::signedRoute('api.files.public.verify', ['id' => $file->id]),
            ],
        ]);
    }

    /**
     * Resolve the Documents-owned identity without reconstructing it from file
     * metadata, current Organization assignments, or issuance dates.
     *
     * @return array<string, mixed>|null
     */
    private function documentIdentityFor(File $file): ?array
    {
        if ($file->tenant_id === null) {
            return null;
        }

        return $this->documentIdentityLookup->findForFile($file->id, $file->tenant_id);
    }

    /**
     * Format a collection with one tenant-scoped identity lookup, avoiding
     * per-file queries for list and version-history responses.
     *
     * @param \Illuminate\Support\Collection<int, File> $files
     * @return array<int, array<string, mixed>>
     */
    private function formatFileCollection($files): array
    {
        $tenantId = $files->first()?->tenant_id ?? mases_tenant_id();
        $identities = $tenantId !== null
            ? $this->documentIdentityLookup->findForFiles($files->pluck('id')->all(), $tenantId)
            : [];

        return $files->map(fn (File $file) => $this->formatFileResponse(
            $file,
            $identities[(string) $file->id] ?? null
        ))->values()->all();
    }

    /**
     * Format a file instance for API response.
     *
     * @param File $file
     * @return array<string, mixed>
     */
    private function formatFileResponse(File $file, ?array $documentIdentity = null): array
    {
        return [
            'id'            => $file->id,
            'original_name' => $file->original_name,
            'file_name'     => $file->name,
            'mime_type'     => $file->mime_type,
            'extension'     => $file->extension,
            'size'          => $file->size,
            'version'       => $file->version,
            'parent_file_id'=> $file->parent_file_id,
            'status'        => $file->status,
            'is_public'     => $file->is_public,
            'metadata'      => $file->metadata,
            'canonical_number' => $documentIdentity['canonical_number'] ?? null,
            'document_number' => $documentIdentity['canonical_number'] ?? null,
            'document_type' => $documentIdentity['document_type_code'] ?? null,
            'document_type_number' => $documentIdentity['document_type_number'] ?? null,
            'document_status' => $documentIdentity['status'] ?? null,
            'document_uuid' => $documentIdentity['uuid'] ?? null,
            'user_id'       => $file->user_id,
            'tenant_id'     => $file->tenant_id,
            'created_at'    => $file->created_at?->toIso8601String(),
            'updated_at'    => $file->updated_at?->toIso8601String(),
            'deleted_at'    => $file->deleted_at?->toIso8601String(),
            // Public QR verification is available only after explicit publication.
            // Private records must use the authenticated application route.
            'verification_url' => ($file->is_public && $file->status === 'published')
                ? URL::signedRoute('api.files.public.verify', ['id' => $file->id])
                : route('api.files.verify', ['id' => $file->id]),
            'verification_mode' => ($file->is_public && $file->status === 'published')
                ? 'public_signed'
                : 'authenticated',
        ];
    }
}