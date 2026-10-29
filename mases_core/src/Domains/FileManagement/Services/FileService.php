<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\FileManagement\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use MaaleArna\MASES\Core\Audit\Services\AuditService;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Exceptions\FileException;
use MaaleArna\MASES\Core\Domains\FileManagement\Models\File;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * FileService
 *
 * Central service for managing files with complete tenant isolation and
 * permission enforcement. Every sensitive operation verifies both tenant
 * membership and the corresponding user permission.
 *
 * This service is designed to be production‑ready, with robust error handling,
 * resource cleanup, and audit logging. All errors are thrown as `FileException`
 * with appropriate HTTP status codes and contextual data.
 *
 * =============================================================================
 * Revision history
 * =============================================================================
 * 1.0.0  — Initial delivery.
 *
 * 1.0.1  — C-10 remediation. The permission check inside `authorizeAction()`
 *          previously queried the direct-grant pivot relation directly:
 *
 *              $hasPermission = $user->permissions()
 *                  ->where('slug', $permissionSlug)
 *                  ->exists();
 *
 *          `$user->permissions()` resolves the `user_has_permissions`
 *          pivot table, which in the MASES deployment is deliberately
 *          empty. Every permission in the platform is granted via roles
 *          (`user_has_roles` → `role_has_permissions`); the direct-grant
 *          table exists only to support future emergency overrides. The
 *          direct-pivot query therefore returned false for every user —
 *          including `super_admin` and `platform_admin` — and every
 *          file operation raised
 *
 *              FileException('Permission "file.*" is required.', 403)
 *
 *          before reaching the storage layer. The subsystem was
 *          entirely unusable in production. The `FileSecurityTest`
 *          suite did not detect this because its fixtures attach
 *          permissions via the same direct-pivot path the defect
 *          relies on; the defect is a production-fixture divergence,
 *          not a code-path divergence, and it is exactly the case
 *          D-08 was raised to correct.
 *
 *          THE FIX
 *          -------
 *          The direct-pivot query is replaced with a call to the
 *          platform's single permission authority:
 *
 *              AuthorizationService::hasPermission(
 *                  permissionSlug:  $permissionSlug,
 *                  user:            $user,
 *                  tenantId:        $tenantId,
 *                  applicationSlug: null,
 *              );
 *
 *          That service already merges direct grants with role-derived
 *          grants (via `Role::permissions`) and applies the correct
 *          tenant-scope fallback — a grant qualifies when its
 *          `tenant_id` equals the requested tenant OR is null
 *          (platform-wide). Using it here makes the file subsystem
 *          consistent with every other authorization check on the
 *          platform, including `AuthorizationMiddleware`, the domain
 *          controllers, and the `/user` endpoint.
 *
 *          DESIGN NOTE — DEPENDENCY RESOLUTION
 *          -----------------------------------
 *          `AuthorizationService` is a hard requirement of this service;
 *          authorization cannot be skipped. The constructor accepts the
 *          service as an optional parameter (added AFTER the existing
 *          `$auditService` parameter, so existing positional callers
 *          such as `new FileService($auditService)` are unchanged) and
 *          falls back to `app(AuthorizationService::class)` when null.
 *          The container-resolve fallback exists so that a construction
 *          path that does not inject the service explicitly still runs
 *          the check against the platform's real implementation,
 *          never against a silent allow-all or deny-all shim.
 *
 *          SCOPE
 *          -----
 *          Only `authorizeAction()` is affected. Every other method,
 *          contract, signature, exception class, audit call, storage
 *          path, and helper in this file is unchanged from v1.0.0.
 *
 * @package MaaleArna\MASES\Core\Domains\FileManagement\Services
 * @version 1.0.1
 */
final class FileService
{
    private string $disk;
    private ?AuditService $auditService = null;
    private AuthorizationService $authorizationService;

    /**
     * Create a new FileService instance.
     *
     * Constructor parameters are ordered so that existing positional
     * callers — `new FileService($auditService)` — remain source-
     * compatible. `$authorizationService` is appended as the second
     * parameter, and resolved from the container when omitted.
     *
     * @param AuditService|null         $auditService
     * @param AuthorizationService|null $authorizationService
     */
    public function __construct(
        ?AuditService $auditService = null,
        ?AuthorizationService $authorizationService = null
    ) {
        $this->disk = config('mases.files.disk', 'local');
        $this->auditService = $auditService;
        // Authorization cannot be skipped. Resolve from the container
        // when not injected, so the check always runs against the
        // platform's real AuthorizationService.
        $this->authorizationService = $authorizationService ?? app(AuthorizationService::class);
    }

    /**
     * Upload a new file.
     *
     * Validates tenant membership, the user's upload permission, file size/extension,
     * stores the file on the configured disk, and creates a database record.
     * On failure, any stored file is removed to prevent orphan files.
     *
     * @param UploadedFile        $uploadedFile   The uploaded file instance.
     * @param string              $fileName       Optional base name for stored file (without extension).
     * @param string|null         $directory      Optional subdirectory (sanitized for path traversal).
     * @param bool                $isPublic       Whether the file is publicly accessible.
     * @param array               $metadata       Additional metadata as associative array.
     * @param User|null           $user           The uploading user (defaults to current user).
     * @param int|string|null     $tenantId       Tenant ID (defaults to current tenant context).
     * @param int|string|null     $parentFileId   Parent file ID for versioning.
     *
     * @return File
     * @throws FileException
     */
    public function upload(
        UploadedFile $uploadedFile,
        string $fileName = '',
        ?string $directory = null,
        bool $isPublic = false,
        array $metadata = [],
        ?User $user = null,
        int|string|null $tenantId = null,
        int|string|null $parentFileId = null
    ): File {
        $user = $user ?? mases_user();
        $tenantId = $tenantId ?? mases_tenant_id();

        // 1. Tenant membership validation
        if ($tenantId !== null && $user) {
            $isMember = $user->tenants()->where('tenant_id', $tenantId)->exists();
            if (!$isMember) {
                throw FileException::accessDenied('User does not belong to this tenant.');
            }
        }

        // 2. Permission check (require 'file.upload')
        $this->authorizeAction('file.upload', null, $user, $tenantId);

        // A version must reference an existing file in the same tenant, and
        // creating a version requires update authority on that parent record.
        $parentFile = null;
        if ($parentFileId !== null) {
            $parentFile = File::find($parentFileId);
            if (!$parentFile) {
                throw FileException::notFound((string) $parentFileId);
            }
            if ((string) $parentFile->tenant_id !== (string) $tenantId) {
                throw FileException::accessDenied('Parent file does not belong to the same tenant.');
            }
            $this->authorizeAction('file.update', $parentFile, $user);
        }

        // 3. Validate the uploaded file
        $this->validateFile($uploadedFile);

        // 4. Prepare file information
        $originalName = $uploadedFile->getClientOriginalName();
        $extension = $uploadedFile->getClientOriginalExtension();
        $storedName = $this->generateStoredName(
            $fileName ?: pathinfo($originalName, PATHINFO_FILENAME),
            $extension
        );

        $path = $this->buildPath($directory, $tenantId, $storedName);

        // 5. Store the file using a stream to avoid memory exhaustion
        try {
            $stream = fopen($uploadedFile->getRealPath(), 'rb');
            $success = Storage::disk($this->disk)->put($path, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            if (!$success) {
                throw FileException::storageFailed($path);
            }
        } catch (FileException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw FileException::uploadFailed($e->getMessage());
        }

        // 6. Determine version from the already-authorized parent, if supplied.
        $version = $parentFile !== null ? $parentFile->version + 1 : 1;

        // 7. Create the file record
        try {
            $file = File::create([
                'user_id'        => $user?->id,
                'tenant_id'      => $tenantId,
                'original_name'  => $originalName,
                'name'           => $storedName,
                'mime_type'      => $uploadedFile->getMimeType(),
                'extension'      => $extension,
                'size'           => $uploadedFile->getSize(),
                'path'           => $path,
                'disk'           => $this->disk,
                'version'        => $version,
                'parent_file_id' => $parentFileId,
                'status'         => 'published',
                'is_public'      => $isPublic,
                'metadata'       => $metadata,
            ]);
        } catch (\Throwable $e) {
            // Remove the stored file if database insert fails
            Storage::disk($this->disk)->delete($path);
            throw new FileException(
                'Failed to create file record: ' . $e->getMessage(),
                FileException::ERROR_FILE_UPLOAD_FAILED,
                500,
                0,
                $e
            );
        }

        // 8. Audit & log (audit failure should not break upload)
        try {
            $this->audit('file.uploaded', $file, [
                'user_id'       => $user?->id,
                'tenant_id'     => $tenantId,
                'file_id'       => $file->id,
                'original_name' => $originalName,
                'size'          => $file->size,
                'is_public'     => $isPublic,
            ]);
        } catch (\Throwable $e) {
            Log::error('Audit failed during file upload: ' . $e->getMessage());
        }

        Log::info('File uploaded', [
            'file_id'       => $file->id,
            'user_id'       => $user?->id,
            'tenant_id'     => $tenantId,
            'original_name' => $originalName,
        ]);

        return $file;
    }

    /**
     * Download a file.
     *
     * Public files are accessible without authentication.
     * Private files require both tenant membership and the 'file.download' permission.
     *
     * For local disks, this method uses `file_get_contents` to avoid stream callbacks
     * that can cause segmentation faults in certain PHP environments. For cloud disks,
     * it falls back to `Storage::get()`. The content is returned as a standard
     * `Illuminate\Http\Response` (not a streaming response) to ensure maximum compatibility.
     *
     * @param File|int|string $file The file instance or ID.
     * @param User|null       $user The user attempting to download.
     *
     * @return Response
     * @throws FileException
     */
    public function download($file, ?User $user = null): StreamedResponse
    {
        $file = $this->resolveFile($file);
        $user = $user ?? mases_user();

        // Public files are freely downloadable
        if (!$file->is_public) {
            $this->authorizeAction('file.download', $file, $user);
        }

        // Open a storage stream rather than loading the entire object into PHP memory.
        $disk = Storage::disk($file->disk);
        if (!$disk->exists($file->path)) {
            throw FileException::notFound((string) $file->id);
        }

        $stream = $disk->readStream($file->path);
        if (!is_resource($stream)) {
            throw new FileException(
                'Unable to open file content for reading.',
                FileException::ERROR_FILE_NOT_FOUND,
                500
            );
        }

        // Audit (non‑fatal)
        try {
            $this->audit('file.downloaded', $file, [
                'user_id' => $user?->id,
                'file_id' => $file->id,
                'ip'      => request()->ip(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Audit failed during file download: ' . $e->getMessage());
        }

        $downloadName = basename(str_replace(["\r", "\n", "\0"], '', (string) $file->original_name));
        if ($downloadName === '' || $downloadName === '.' || $downloadName === '..') {
            $downloadName = 'download';
        }
        $fallbackName = preg_replace('/[^A-Za-z0-9._-]/', '_', $downloadName) ?: 'download';
        $disposition = HeaderUtils::makeDisposition('attachment', $downloadName, $fallbackName);

        return response()->streamDownload(
            static function () use ($stream): void {
                try {
                    fpassthru($stream);
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
            },
            $downloadName,
            [
                'Content-Type' => $file->mime_type ?: 'application/octet-stream',
                'Content-Disposition' => $disposition,
                'Content-Length' => (string) $file->size,
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => $file->is_public ? 'public, max-age=300' : 'private, no-store',
            ]
        );
    }

    /**
     * Update file metadata or create a new version (if a new file is provided).
     *
     * @param File|int|string $file   The file instance or ID.
     * @param array           $data   Associative array of fields to update
     *                                (allowed: status, is_public, metadata).
     * @param UploadedFile|null $newFile If provided, creates a new version.
     * @param User|null       $user   The user performing the update.
     *
     * @return File
     * @throws FileException
     */
    public function update($file, array $data = [], ?UploadedFile $newFile = null, ?User $user = null): File
    {
        $file = $this->resolveFile($file);
        $user = $user ?? mases_user();

        // Permission check for update
        $this->authorizeAction('file.update', $file, $user);

        if ($newFile !== null) {
            // Preserve the existing directory without duplicating the tenant segment.
            $directory = dirname($file->path);
            $tenantDirectory = 'tenant_' . $file->tenant_id;
            if (basename($directory) === $tenantDirectory) {
                $directory = dirname($directory);
            }
            if ($directory === '.' || $directory === DIRECTORY_SEPARATOR) {
                $directory = null;
            }

            // Creating a new version
            $newVersion = $this->upload(
                uploadedFile: $newFile,
                fileName: pathinfo($file->original_name, PATHINFO_FILENAME),
                directory: $directory,
                isPublic: $data['is_public'] ?? $file->is_public,
                metadata: $data['metadata'] ?? $file->metadata,
                user: $user,
                tenantId: $file->tenant_id,
                parentFileId: $file->id
            );

            // Archive the old version
            $file->status = 'archived';
            $file->save();

            try {
                $this->audit('file.version_created', $newVersion, [
                    'user_id'        => $user?->id,
                    'parent_file_id' => $file->id,
                    'new_version'    => $newVersion->id,
                ]);
            } catch (\Throwable $e) {
                Log::error('Audit failed during version creation: ' . $e->getMessage());
            }

            return $newVersion;
        }

        // Metadata-only update
        $fillable = ['status', 'is_public', 'metadata'];
        $changes = [];
        foreach ($fillable as $field) {
            if (array_key_exists($field, $data)) {
                $changes[$field] = $data[$field];
                $file->$field = $data[$field];
            }
        }

        $file->save();

        try {
            $this->audit('file.updated', $file, [
                'user_id' => $user?->id,
                'changes' => $changes,
            ]);
        } catch (\Throwable $e) {
            Log::error('Audit failed during file update: ' . $e->getMessage());
        }

        return $file;
    }

    /**
     * Soft delete a file.
     *
     * @param File|int|string $file The file instance or ID.
     * @param User|null       $user The user performing the deletion.
     *
     * @return bool
     * @throws FileException
     */
    public function delete($file, ?User $user = null): bool
    {
        $file = $this->resolveFile($file);
        $user = $user ?? mases_user();

        $this->authorizeAction('file.delete', $file, $user);

        $file->delete();

        try {
            $this->audit('file.deleted', $file, [
                'user_id' => $user?->id,
                'file_id' => $file->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('Audit failed during file deletion: ' . $e->getMessage());
        }

        Log::info('File soft deleted', [
            'file_id' => $file->id,
            'user_id' => $user?->id,
        ]);

        return true;
    }

    /**
     * Permanently delete a file.
     *
     * @param File|int|string $file The file instance or ID.
     * @param User|null       $user The user performing the deletion.
     *
     * @return bool
     * @throws FileException
     */
    public function forceDelete($file, ?User $user = null): bool
    {
        $file = $this->resolveFile($file);
        $user = $user ?? mases_user();

        $this->authorizeAction('file.delete', $file, $user);

        if (Storage::disk($file->disk)->exists($file->path)) {
            Storage::disk($file->disk)->delete($file->path);
        }

        $file->forceDelete();

        try {
            $this->audit('file.force_deleted', $file, [
                'user_id' => $user?->id,
                'file_id' => $file->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('Audit failed during force delete: ' . $e->getMessage());
        }

        Log::info('File permanently deleted', [
            'file_id' => $file->id,
            'user_id' => $user?->id,
        ]);

        return true;
    }

    /**
     * Restore a soft-deleted file.
     *
     * @param File|int|string $file The file instance or ID.
     * @param User|null       $user The user performing the restore.
     *
     * @return File
     * @throws FileException
     */
    public function restore($file, ?User $user = null): File
    {
        $file = $this->resolveFile($file, true);
        $user = $user ?? mases_user();

        $this->authorizeAction('file.delete', $file, $user); // or 'file.restore'

        if (!$file->trashed()) {
            throw FileException::notDeleted();
        }

        $file->restore();

        try {
            $this->audit('file.restored', $file, [
                'user_id' => $user?->id,
                'file_id' => $file->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('Audit failed during file restore: ' . $e->getMessage());
        }

        Log::info('File restored', [
            'file_id' => $file->id,
            'user_id' => $user?->id,
        ]);

        return $file;
    }

    /**
     * Find a file by ID, enforcing tenant view permission.
     *
     * @param int|string $id         The file ID.
     * @param bool       $withTrashed Whether to include soft-deleted files.
     *
     * @return File|null
     * @throws FileException
     */
    public function find(int|string $id, bool $withTrashed = false): ?File
    {
        $file = File::query()
            ->when($withTrashed, fn($q) => $q->withTrashed())
            ->find($id);

        if (!$file) {
            return null;
        }

        $this->authorizeAction('file.view', $file, mases_user());

        return $file;
    }

    /**
     * Get files for a tenant, enforcing view permission.
     *
     * @param int|string|null $tenantId The tenant ID (defaults to current context).
     * @param array           $filters  Optional filters (status, is_public, user_id, mime_type, order, limit).
     *
     * @return \Illuminate\Database\Eloquent\Collection
     * @throws FileException
     */
    public function getFilesForTenant(int|string|null $tenantId = null, array $filters = [])
    {
        $tenantId = $tenantId ?? mases_tenant_id();
        $user = mases_user();

        $this->authorizeAction('file.view', null, $user, $tenantId);

        $query = File::where('tenant_id', $tenantId);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['is_public'])) {
            $query->where('is_public', (bool) $filters['is_public']);
        }
        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }
        if (isset($filters['mime_type'])) {
            $query->where('mime_type', $filters['mime_type']);
        }

        $orderBy = $filters['order_by'] ?? 'created_at';
        $orderDirection = $filters['order_direction'] ?? 'desc';
        $query->orderBy($orderBy, $orderDirection);

        if (isset($filters['limit'])) {
            $query->limit($filters['limit']);
        }

        return $query->get();
    }

    /**
     * Get the latest version of a file.
     *
     * @param int|string $fileId The original file ID.
     *
     * @return File|null
     * @throws FileException
     */
    public function getLatestVersion(int|string $fileId): ?File
    {
        $file = $this->find($fileId); // Enforces view permission
        if (!$file) {
            return null;
        }

        $history = $this->getVersionHistory($fileId);
        return $history->sortByDesc('version')->first();
    }

    /**
     * Get full version history of a file.
     *
     * @param int|string $fileId The original file ID.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     * @throws FileException
     */
    public function getVersionHistory(int|string $fileId)
    {
        $file = $this->find($fileId); // Enforces view permission
        if (!$file) {
            return collect();
        }

        // Walk to the root, then collect every descendant version in this tenant.
        $root = $file;
        $visited = [(string) $root->id => true];
        while ($root->parent_file_id !== null) {
            $parent = File::where('tenant_id', $file->tenant_id)->find($root->parent_file_id);
            if (!$parent || isset($visited[(string) $parent->id])) {
                break;
            }
            $visited[(string) $parent->id] = true;
            $root = $parent;
        }

        $versions = collect([$root]);
        $frontier = [(string) $root->id];
        while ($frontier !== []) {
            $children = File::where('tenant_id', $file->tenant_id)
                ->whereIn('parent_file_id', $frontier)
                ->whereNotIn('id', array_keys($visited))
                ->orderBy('version')
                ->get();

            if ($children->isEmpty()) {
                break;
            }

            $frontier = [];
            foreach ($children as $child) {
                $visited[(string) $child->id] = true;
                $frontier[] = (string) $child->id;
                $versions->push($child);
            }
        }

        return $versions->sortBy('version')->values();
    }

    /**
     * Validate an uploaded file's size and extension against configuration.
     *
     * @param UploadedFile $uploadedFile
     *
     * @throws FileException
     */
    private function validateFile(UploadedFile $uploadedFile): void
    {
        if (!$uploadedFile->isValid()) {
            throw FileException::uploadFailed('The uploaded file did not pass PHP upload validation.');
        }

        $size = (int) $uploadedFile->getSize();
        $maxSize = max(1, (int) config('mases.files.max_upload_size', 100)) * 1024 * 1024;
        if ($size <= 0) {
            throw FileException::uploadFailed('Empty files are not accepted.');
        }
        if ($size > $maxSize) {
            throw FileException::tooLarge($maxSize, $size);
        }

        $extension = strtolower($uploadedFile->getClientOriginalExtension());
        $allowedExtensions = array_map(
            static fn ($value): string => strtolower(ltrim((string) $value, '.')),
            (array) config('mases.files.allowed_extensions', [])
        );
        if ($extension === '' || ($allowedExtensions !== [] && !in_array($extension, $allowedExtensions, true))) {
            throw FileException::extensionNotAllowed($extension, $allowedExtensions);
        }

        // Optional defense-in-depth MIME allowlist based on server-detected MIME.
        $allowedMimeTypes = array_map('strtolower', (array) config('mases.files.allowed_mime_types', []));
        $detectedMime = strtolower((string) $uploadedFile->getMimeType());
        if ($allowedMimeTypes !== [] && !in_array($detectedMime, $allowedMimeTypes, true)) {
            throw FileException::extensionNotAllowed($extension, $allowedExtensions);
        }
    }

    /**
     * Generate a safe, unique stored file name.
     *
     * @param string $baseName The base name (without extension).
     * @param string $extension The file extension.
     *
     * @return string
     */
    private function generateStoredName(string $baseName, string $extension): string
    {
        $baseName = Str::slug($baseName);
        $baseName = $baseName !== '' ? $baseName : 'file';
        $extension = strtolower(ltrim($extension, '.'));
        $uniqueId = Str::random(32);
        return $baseName . '_' . $uniqueId . ($extension !== '' ? '.' . $extension : '');
    }

    /**
     * Build a sanitized storage path.
     * Prevents path traversal by stripping dangerous characters and normalizing.
     *
     * @param string|null     $directory  User-supplied directory.
     * @param int|string|null $tenantId   Tenant ID for tenant folder.
     * @param string          $storedName Stored file name.
     *
     * @return string
     */
    private function buildPath(?string $directory, int|string|null $tenantId, string $storedName): string
    {
        $parts = [];

        if ($directory) {
            // Normalize path segments; never preserve absolute or dot-segment paths.
            $directory = str_replace(["\0", "\\"], ['', '/'], $directory);
            $segments = explode('/', trim($directory, '/'));
            $safeSegments = [];
            foreach ($segments as $segment) {
                $segment = trim($segment);
                if ($segment === '' || $segment === '.' || $segment === '..') {
                    continue;
                }
                $safeSegments[] = preg_replace('/[^A-Za-z0-9._-]/', '_', $segment);
            }
            if ($safeSegments !== []) {
                $parts[] = implode('/', $safeSegments);
            }
        }

        if ($tenantId !== null) {
            $parts[] = 'tenant_' . $tenantId;
        }

        $parts[] = $storedName;

        return implode('/', $parts);
    }

    /**
     * Resolve a file instance from either a model instance or an ID.
     *
     * @param File|int|string $file
     * @param bool            $withTrashed
     *
     * @return File
     * @throws FileException
     */
    private function resolveFile($file, bool $withTrashed = false): File
    {
        if ($file instanceof File) {
            return $file;
        }

        $resolved = File::query()
            ->when($withTrashed, fn($q) => $q->withTrashed())
            ->find($file);

        if (!$resolved) {
            throw FileException::notFound((string) $file);
        }
        return $resolved;
    }

    /**
     * Authorize a file action.
     *
     * Three checks, in order:
     *
     *   1. AUTHENTICATION — the caller must supply an authenticated user.
     *      Public file downloads do not reach this method: the public
     *      branch in `download()` short-circuits before the call.
     *
     *   2. TENANT MEMBERSHIP — when a tenant id is known (from the file
     *      or from the explicit argument), the user must be a member of
     *      it. This is a separate concern from permission resolution:
     *      a user can hold a permission in a tenant they are not a
     *      member of, and file operations must reject that case.
     *
     *   3. PERMISSION — the platform's single permission authority,
     *      `AuthorizationService::hasPermission()`, resolves the
     *      requested slug. That service merges direct grants (from
     *      `user_has_permissions`) with role-derived grants (from
     *      `user_has_roles` → `role_has_permissions`) and applies the
     *      tenant-scope fallback — a grant qualifies when its
     *      `tenant_id` equals the requested tenant OR is null
     *      (platform-wide). See the class docblock §"1.0.1 — C-10
     *      remediation" for why the earlier direct-pivot query was
     *      wrong.
     *
     * The `applicationSlug` argument is `null` because file operations
     * are not scoped to a specific application in the current platform.
     * When application-scoped files are introduced in a future phase,
     * the slug should be plumbed through this method.
     *
     * @param string          $permissionSlug The permission slug required.
     * @param File|null       $file           Optional file to derive tenant ID.
     * @param User|null       $user           The user to authorize.
     * @param int|string|null $tenantId       Explicit tenant ID (overrides file tenant).
     *
     * @throws FileException
     */
    private function authorizeAction(
        string $permissionSlug,
        ?File $file = null,
        ?User $user = null,
        int|string|null $tenantId = null
    ): void {
        // Determine tenant ID from file if not provided
        if ($file !== null) {
            $tenantId = $file->tenant_id;
        }

        // Authentication is required for all non‑public actions
        if (!$user) {
            throw FileException::authRequired();
        }

        // Check tenant membership if a tenant is involved
        if ($tenantId !== null) {
            $isMember = $user->tenants()->where('tenant_id', $tenantId)->exists();
            if (!$isMember) {
                throw FileException::accessDenied('User does not belong to this tenant.');
            }
        }

        // Check permission via the platform's single permission
        // authority. This replaces the direct-pivot query that used to
        // live here; see the class docblock §"1.0.1 — C-10
        // remediation" for the rationale.
        $hasPermission = $this->authorizationService->hasPermission(
            permissionSlug:  $permissionSlug,
            user:            $user,
            tenantId:        $tenantId,
            applicationSlug: null,
        );

        if (!$hasPermission) {
            throw new FileException(
                "Permission '$permissionSlug' is required.",
                FileException::ERROR_FILE_ACCESS_DENIED,
                403
            );
        }
    }

    /**
     * Record an audit entry if an AuditService is available.
     *
     * @param string $action The audit action name.
     * @param File   $file   The file associated with the event.
     * @param array  $data   Additional data for the audit log.
     */
    private function audit(string $action, File $file, array $data = []): void
    {
        if ($this->auditService) {
            $this->auditService->log(
                action: $action,
                data: $data,
                user: $data['user_id'] ?? null,
                tenantId: $file->tenant_id,
                resourceType: 'file',
                resourceId: (string) $file->id,
                oldValues: null,
                newValues: null
            );
        }
    }
}