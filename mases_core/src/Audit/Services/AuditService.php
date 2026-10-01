<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Audit\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use MaaleArna\MASES\Core\Audit\Models\AuditLog;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;

/**
 * AuditService
 *
 * Central service for recording and retrieving audit logs in the MASES platform.
 * Provides methods to log actions, track changes to models, and query audit trails.
 *
 * This service is used to maintain a complete audit trail of all significant
 * actions performed by users across the platform.
 *
 * @package MaaleArna\MASES\Core\Audit\Services
 */
final class AuditService
{
    /**
     * Log an audit event.
     *
     * @param string $action The action performed (e.g., 'user.created', 'finance.transaction.approved').
     * @param array<string, mixed> $data Additional data for the audit log.
     * @param User|int|null $user The user performing the action (or null for system actions).
     * @param int|string|null $tenantId The tenant context (optional).
     * @param string|null $resourceType The type of resource affected (e.g., 'user', 'transaction').
     * @param string|null $resourceId The identifier of the resource.
     * @param array|null $oldValues The old values before the change.
     * @param array|null $newValues The new values after the change.
     * @param string|null $ipAddress The IP address of the request (optional).
     * @param string|null $userAgent The user agent of the request (optional).
     * @param string|null $requestId The request ID (optional).
     * @return AuditLog|null
     */
    public function log(
        string $action,
        array $data = [],
        User|int|null $user = null,
        int|string|null $tenantId = null,
        ?string $resourceType = null,
        ?string $resourceId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $requestId = null
    ): ?AuditLog {
        // Check if audit logging is enabled.
        if (!config('mases.features.audit_enabled', true)) {
            return null;
        }

        // Resolve user ID if not provided.
        $userId = null;
        if ($user instanceof User) {
            $userId = $user->id;
        } elseif (is_int($user)) {
            $userId = $user;
        }

        // If user not provided, try to get the current authenticated user.
        if ($userId === null) {
            $currentUser = auth()->user();
            if ($currentUser instanceof User) {
                $userId = $currentUser->id;
            }
        }

        // Resolve tenant ID if not provided.
        if ($tenantId === null && function_exists('mases_tenant_id')) {
            $tenantId = mases_tenant_id();
        }

        // Resolve IP address if not provided.
        if ($ipAddress === null) {
            $ipAddress = request()->ip();
        }

        // Resolve user agent if not provided.
        if ($userAgent === null) {
            $userAgent = request()->userAgent();
        }

        // Resolve request ID if not provided.
        if ($requestId === null) {
            $requestId = request()->id() ?? null;
        }

        // Build the audit log entry.
        try {
            $logEntry = AuditLog::create([
                'user_id' => $userId,
                'tenant_id' => $tenantId,
                'action' => $action,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'request_id' => $requestId,
            ]);

            // Also log to the application log for real-time monitoring.
            Log::info('Audit log created', [
                'action' => $action,
                'user_id' => $userId,
                'tenant_id' => $tenantId,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'request_id' => $requestId,
            ]);

            return $logEntry;
        } catch (\Throwable $e) {
            // If the database is unavailable, fall back to logging only.
            Log::error('Failed to log audit event to database', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Log a model creation event.
     *
     * @param string $action The action (e.g., 'user.created').
     * @param mixed $model The model that was created.
     * @param array<string, mixed> $attributes The attributes of the model (if not using getAttributes).
     * @param User|int|null $user The user performing the action.
     * @param int|string|null $tenantId The tenant context.
     * @return AuditLog|null
     */
    public function logCreation(
        string $action,
        $model,
        array $attributes = [],
        User|int|null $user = null,
        int|string|null $tenantId = null
    ): ?AuditLog {
        $modelData = $this->extractModelData($model, $attributes);
        return $this->log(
            action: $action,
            user: $user,
            tenantId: $tenantId,
            resourceType: $modelData['resource_type'],
            resourceId: $modelData['resource_id'],
            newValues: $modelData['attributes'],
            data: ['model' => get_class($model)]
        );
    }

    /**
     * Log a model update event.
     *
     * @param string $action The action (e.g., 'user.updated').
     * @param mixed $model The model that was updated.
     * @param array $oldValues The old values.
     * @param array $newValues The new values (or use the model's attributes).
     * @param User|int|null $user The user performing the action.
     * @param int|string|null $tenantId The tenant context.
     * @return AuditLog|null
     */
    public function logUpdate(
        string $action,
        $model,
        array $oldValues,
        array $newValues = [],
        User|int|null $user = null,
        int|string|null $tenantId = null
    ): ?AuditLog {
        $modelData = $this->extractModelData($model, $newValues);
        return $this->log(
            action: $action,
            user: $user,
            tenantId: $tenantId,
            resourceType: $modelData['resource_type'],
            resourceId: $modelData['resource_id'],
            oldValues: $oldValues,
            newValues: $modelData['attributes'],
            data: ['model' => get_class($model)]
        );
    }

    /**
     * Log a model deletion event.
     *
     * @param string $action The action (e.g., 'user.deleted').
     * @param mixed $model The model that was deleted.
     * @param array $attributes The attributes of the model before deletion.
     * @param User|int|null $user The user performing the action.
     * @param int|string|null $tenantId The tenant context.
     * @return AuditLog|null
     */
    public function logDeletion(
        string $action,
        $model,
        array $attributes = [],
        User|int|null $user = null,
        int|string|null $tenantId = null
    ): ?AuditLog {
        $modelData = $this->extractModelData($model, $attributes);
        return $this->log(
            action: $action,
            user: $user,
            tenantId: $tenantId,
            resourceType: $modelData['resource_type'],
            resourceId: $modelData['resource_id'],
            oldValues: $modelData['attributes'],
            data: ['model' => get_class($model)]
        );
    }

    /**
     * Extract resource type, resource ID, and attributes from a model.
     *
     * @param mixed $model
     * @param array $attributes
     * @return array{resource_type: string|null, resource_id: string|null, attributes: array}
     */
    private function extractModelData($model, array $attributes): array
    {
        $resourceType = null;
        $resourceId = null;
        $modelAttributes = [];

        if (is_object($model)) {
            $class = get_class($model);
            $resourceType = class_basename($model);
            if (method_exists($model, 'getKey')) {
                $resourceId = (string) $model->getKey();
            }
            if (method_exists($model, 'getAttributes')) {
                $modelAttributes = $model->getAttributes();
            } else {
                // Attempt to cast to array.
                $modelAttributes = (array) $model;
            }
        }

        // Merge any provided attributes (override model attributes).
        if (!empty($attributes)) {
            $modelAttributes = array_merge($modelAttributes, $attributes);
        }

        return [
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'attributes' => $modelAttributes,
        ];
    }

    /**
     * Get the latest audit logs for a given resource.
     *
     * @param string $resourceType
     * @param string $resourceId
     * @param int $limit
     * @return \Illuminate\Support\Collection<int, AuditLog>
     */
    public function getLogsForResource(string $resourceType, string $resourceId, int $limit = 50)
    {
        return AuditLog::ofResourceType($resourceType)
            ->forResource($resourceId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get audit logs for a specific user.
     *
     * @param int $userId
     * @param int $limit
     * @return \Illuminate\Support\Collection<int, AuditLog>
     */
    public function getLogsForUser(int $userId, int $limit = 50)
    {
        return AuditLog::forUser($userId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get audit logs for a specific action type.
     *
     * @param string $action
     * @param int $limit
     * @return \Illuminate\Support\Collection<int, AuditLog>
     */
    public function getLogsForAction(string $action, int $limit = 50)
    {
        return AuditLog::ofAction($action)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }
}