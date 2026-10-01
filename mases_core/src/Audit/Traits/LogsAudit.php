<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Audit\Traits;

use MaaleArna\MASES\Core\Audit\Models\AuditLog;
use Illuminate\Support\Facades\Log;

/**
 * LogsAudit
 *
 * Provides a convenient method for controllers to log audit events
 * to the `audit_logs` table.
 *
 * =============================================================================
 * Revision history
 * =============================================================================
 * 1.0.0  — initial delivery. Class docblock described the tenant resolution
 *          order of `mases_tenant_id()` as: request input → X-Tenant-ID
 *          header → user default.
 *
 * 1.1.0  — C-09 correction. Comment-only update. The trait's executable code
 *          is unchanged. The `mases_tenant_id()` call site still resolves the
 *          tenant context; the helper itself now behaves differently in HTTP
 *          requests after the C-09 correction (see `Helpers.php` v1.1.0).
 *
 *          Specifically:
 *
 *            · In HTTP context, `mases_tenant_id()` reads the tenant resolved
 *              by `TenantContextMiddleware` (via `TenantContextService`). It
 *              does NOT read request body, query string, or the X-Tenant-ID
 *              header independently. If the middleware resolved no tenant,
 *              the helper returns `null`.
 *
 *            · In non-HTTP contexts (console, queue, scheduler), the helper
 *              falls back to the authenticated user's `default_tenant_id`,
 *              then to `config('mases.tenant.default_tenant_id')`, then to
 *              `null`.
 *
 *          The audit row produced by this trait therefore records the
 *          middleware-verified tenant in HTTP context, not an attacker-
 *          influenced request value. This is the intended C-09 behavior.
 *
 *          No code change. No method signature change. No behavioral change
 *          in this file beyond what `mases_tenant_id()` now returns.
 * =============================================================================
 */
trait LogsAudit
{
    /**
     * Log an audit event.
     *
     * @param string      $action        The action slug (e.g., 'file.uploaded').
     * @param string|null $resourceType  The type of resource affected.
     * @param string|null $resourceId    The ID of the affected resource.
     * @param array       $newValues     Key-value pairs of new values (sanitised).
     * @param array       $oldValues     Key-value pairs of old values (sanitised).
     * @return void
     */
    protected function logAudit(
        string $action,
        ?string $resourceType = null,
        ?string $resourceId = null,
        array $newValues = [],
        array $oldValues = [],
    ): void {
        // Respect the feature flag – skip logging if disabled.
        if (! config('mases.features.audit_enabled', true)) {
            return;
        }

        try {
            $request = request();

            // Capture request metadata.
            $ipAddress  = $request->ip();
            $userAgent  = $request->userAgent();
            $requestId  = $request->header('X-Request-ID');

            /*
             * Resolve tenant ID using the platform helper.
             *
             * Under C-09, the helper's behavior is context-dependent:
             *
             *   HTTP context:
             *     returns the tenant resolved by `TenantContextMiddleware`
             *     (via `TenantContextService::getTenantId()`). If the
             *     middleware resolved no tenant, returns null. The helper
             *     does NOT read request body, query string, or the
             *     X-Tenant-ID header independently.
             *
             *   Non-HTTP context (console, queue, scheduler):
             *     falls back to the authenticated user's `default_tenant_id`,
             *     then to `config('mases.tenant.default_tenant_id')`, then
             *     to null.
             *
             * The audit row therefore records the middleware-verified tenant
             * in HTTP context. A body-supplied `tenant_id` has no effect on
             * this value.
             */
            $tenantId = mases_tenant_id();

            AuditLog::create([
                'action'        => $action,
                'resource_type' => $resourceType,
                'resource_id'   => $resourceId,
                'user_id'       => auth()->id(),
                'tenant_id'     => $tenantId,
                'old_values'    => $oldValues,
                'new_values'    => $this->sanitizeAudit($newValues),
                'ip_address'    => $ipAddress,
                'user_agent'    => $userAgent,
                'request_id'    => $requestId,
            ]);
        } catch (\Throwable $e) {
            // Never let audit logging failures crash the application.
            Log::error('Audit log creation failed', [
                'action' => $action,
                'error'  => $e->getMessage(),
                'trace'  => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Remove sensitive keys from audit data.
     *
     * @param array $data
     * @return array
     */
    private function sanitizeAudit(array $data): array
    {
        $sensitive = [
            'password',
            'password_confirmation',
            'token',
            'access_token',
            'refresh_token',
            'api_key',
            'secret',
            'client_secret',
            'authorization',
            'file',
            'content',
        ];
        return array_diff_key($data, array_flip($sensitive));
    }
}