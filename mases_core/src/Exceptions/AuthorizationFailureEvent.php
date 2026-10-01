<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * AuthorizationFailureEvent
 *
 * Emitted whenever an authorization check fails anywhere in the platform:
 *
 *   - Permission middleware denies a request.
 *   - Role middleware denies a request.
 *   - Application-access middleware denies a request.
 *   - AuthorizationService::requirePermission() or ::requireRole() throws.
 *   - Any other service that deliberately denies a user action.
 *
 * The event is intentionally framework-agnostic: it carries only the
 * structured context needed by a listener to persist a security event,
 * and no sensitive data (no credentials, no tokens, no raw request body).
 *
 * -----------------------------------------------------------------------------
 * Why an event (rather than direct logging in the middleware)?
 * -----------------------------------------------------------------------------
 *   - DECOUPLING: the authorization layer should not depend on the security
 *     logging layer. It only needs to announce "an authorization decision
 *     failed", and any number of listeners can react (audit, alerting,
 *     rate-limiting, metrics, ...).
 *   - TESTABILITY: the middleware tests can assert that the event was
 *     dispatched, without caring how it is persisted.
 *   - CONSISTENCY: all failure paths funnel through the same event, ensuring
 *     the security_events table has a uniform shape for every denial.
 *   - EXTENSIBILITY: real-time alerting (Slack, e-mail) can be added later
 *     as an additional listener without touching the middleware.
 *
 * -----------------------------------------------------------------------------
 * Guaranteed non-sensitive fields
 * -----------------------------------------------------------------------------
 * The event carries:
 *   - userId     : nullable int (null for unauthenticated requests)
 *   - tenantId   : nullable int|string (null for platform-level actions)
 *   - application: nullable string (application slug, if the request is
 *                  scoped to one; e.g. 'finance')
 *   - checkType  : one of 'permission', 'role', 'application', or a
 *                  domain-specific value such as 'shareholder.edit'
 *   - checkValue : the required permission/role/application/resource that
 *                  the user did not satisfy
 *   - resourceType / resourceId : nullable, for resource-level denials
 *   - action     : short verb describing the attempted action (e.g. 'read',
 *                  'update', 'delete', 'approve')
 *   - requestId  : nullable string, from the incoming request
 *   - ip         : nullable string
 *   - userAgent  : nullable string
 *   - occurredAt : DateTimeImmutable, the moment of the denial
 *
 * None of these fields can carry credentials or secrets; they are all
 * identifiers and metadata. Sensitive request bodies are deliberately
 * never captured.
 *
 * -----------------------------------------------------------------------------
 * Example
 * -----------------------------------------------------------------------------
 *     event(new AuthorizationFailureEvent(
 *         userId:       auth()->id(),
 *         tenantId:     mases_tenant_id(),
 *         application:  'finance',
 *         checkType:    'permission',
 *         checkValue:   'finance.transaction.create',
 *         resourceType: 'transaction',
 *         resourceId:   null,
 *         action:       'create',
 *     ));
 *
 * @package MaaleArna\MASES\Core\Events
 */
final class AuthorizationFailureEvent
{
    use Dispatchable;
    use SerializesModels;

    /**
     * The authenticated user's ID (null for unauthenticated requests).
     */
    public readonly ?int $userId;

    /**
     * The active tenant's ID (null for platform-level actions).
     */
    public readonly int|string|null $tenantId;

    /**
     * The application slug, if the request is scoped to one (e.g. 'finance').
     */
    public readonly ?string $application;

    /**
     * The kind of check that failed.
     *
     * Canonical values:
     *   - 'permission'  — a specific permission slug was required
     *   - 'role'        — a specific role slug was required
     *   - 'application' — access to an application was required
     *   - '<domain>.<action>' — any domain-specific denial
     */
    public readonly string $checkType;

    /**
     * The required value the user did not satisfy.
     *
     * For 'permission' this is the permission slug (e.g. 'finance.transaction.create').
     * For 'role' it is the role slug (e.g. 'finance_manager').
     * For 'application' it is the application slug (e.g. 'finance').
     */
    public readonly string $checkValue;

    /**
     * Optional resource type involved in the denial (e.g. 'transaction').
     */
    public readonly ?string $resourceType;

    /**
     * Optional resource identifier involved in the denial.
     */
    public readonly ?string $resourceId;

    /**
     * Optional short verb describing the attempted action
     * (e.g. 'read', 'create', 'update', 'delete', 'approve').
     */
    public readonly ?string $action;

    /**
     * Optional request identifier, propagated from the incoming request.
     */
    public readonly ?string $requestId;

    /**
     * Optional client IP address.
     */
    public readonly ?string $ip;

    /**
     * Optional user agent string.
     */
    public readonly ?string $userAgent;

    /**
     * The moment the denial occurred.
     */
    public readonly \DateTimeImmutable $occurredAt;

    /**
     * Create a new AuthorizationFailureEvent instance.
     *
     * @param int|null                $userId
     * @param int|string|null         $tenantId
     * @param string|null             $application
     * @param string                  $checkType
     * @param string                  $checkValue
     * @param string|null             $resourceType
     * @param string|null             $resourceId
     * @param string|null             $action
     * @param string|null             $requestId
     * @param string|null             $ip
     * @param string|null             $userAgent
     * @param \DateTimeImmutable|null $occurredAt
     */
    public function __construct(
        ?int $userId,
        int|string|null $tenantId,
        ?string $application,
        string $checkType,
        string $checkValue,
        ?string $resourceType = null,
        ?string $resourceId = null,
        ?string $action = null,
        ?string $requestId = null,
        ?string $ip = null,
        ?string $userAgent = null,
        ?\DateTimeImmutable $occurredAt = null
    ) {
        $this->userId = $userId;
        $this->tenantId = $tenantId;
        $this->application = $application;
        $this->checkType = $checkType;
        $this->checkValue = $checkValue;
        $this->resourceType = $resourceType;
        $this->resourceId = $resourceId;
        $this->action = $action;
        $this->requestId = $requestId;
        $this->ip = $ip;
        $this->userAgent = $userAgent;
        $this->occurredAt = $occurredAt ?? new \DateTimeImmutable('now');
    }

    /**
     * Build an event from the current HTTP request context.
     *
     * This factory is the recommended way to construct the event inside
     * middleware and services, because it captures request metadata
     * consistently and safely (never reading request bodies).
     *
     * @param string      $checkType
     * @param string      $checkValue
     * @param string|null $application
     * @param string|null $resourceType
     * @param string|null $resourceId
     * @param string|null $action
     *
     * @return self
     */
    public static function fromRequest(
        string $checkType,
        string $checkValue,
        ?string $application = null,
        ?string $resourceType = null,
        ?string $resourceId = null,
        ?string $action = null
    ): self {
        $request = function_exists('request') ? request() : null;

        // Resolve the current user ID safely.
        $userId = null;
        if (function_exists('auth') && auth()->check()) {
            $uid = auth()->id();
            $userId = is_int($uid) ? $uid : (is_numeric($uid) ? (int) $uid : null);
        }

        // Resolve the tenant ID safely via the helper if available.
        $tenantId = null;
        if (function_exists('mases_tenant_id')) {
            $tenantId = mases_tenant_id();
        }

        return new self(
            userId: $userId,
            tenantId: $tenantId,
            application: $application,
            checkType: $checkType,
            checkValue: $checkValue,
            resourceType: $resourceType,
            resourceId: $resourceId,
            action: $action,
            requestId: self::resolveRequestId($request),
            ip: $request?->ip(),
            userAgent: $request?->userAgent(),
            occurredAt: new \DateTimeImmutable('now')
        );
    }

    /**
     * Convert the event to a structured array suitable for the
     * SecurityEventService or for plain logging.
     *
     * The resulting array is guaranteed not to contain credentials or
     * secrets. It is intended to be stored in the `event_data` JSON column
     * of the `security_events` table.
     *
     * @return array<string, mixed>
     */
    public function toSecurityEventData(): array
    {
        return [
            'check_type'    => $this->checkType,
            'check_value'   => $this->checkValue,
            'application'   => $this->application,
            'resource_type' => $this->resourceType,
            'resource_id'   => $this->resourceId,
            'action'        => $this->action,
            'occurred_at'   => $this->occurredAt->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Get a short, human-readable description of the failure.
     *
     * @return string
     */
    public function description(): string
    {
        $suffix = $this->action !== null
            ? sprintf(' (action: %s)', $this->action)
            : '';

        return sprintf(
            'Authorization denied: %s "%s"%s',
            $this->checkType,
            $this->checkValue,
            $suffix
        );
    }

    /**
     * Attempt to resolve the request ID from the given request object.
     *
     * Laravel requests may expose a `id()` macro (from a
     * RequestIdMiddleware) or an `X-Request-ID` header. We check both.
     *
     * @param mixed $request
     *
     * @return string|null
     */
    private static function resolveRequestId(mixed $request): ?string
    {
        if ($request === null) {
            return null;
        }

        if (is_object($request) && method_exists($request, 'id')) {
            try {
                /** @var mixed $id */
                $id = $request->id();
                if (is_string($id) && $id !== '') {
                    return $id;
                }
            } catch (\Throwable) {
                // Some request implementations may throw if the macro is
                // not defined; ignore and fall back to the header below.
            }
        }

        if (is_object($request) && method_exists($request, 'header')) {
            $header = $request->header('X-Request-ID');
            if (is_string($header) && $header !== '') {
                return $header;
            }
        }

        return null;
    }
}