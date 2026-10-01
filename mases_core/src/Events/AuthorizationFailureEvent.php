<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Events;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Throwable;

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
 * -----------------------------------------------------------------------------
 * Why an event (rather than direct logging in the middleware)?
 * -----------------------------------------------------------------------------
 *   - DECOUPLING: the authorization layer should not depend on the security
 *     logging layer. It only announces "an authorization decision failed",
 *     and any number of listeners can react (audit, alerting, rate-limiting,
 *     metrics, …).
 *   - TESTABILITY: middleware tests can assert that the event was dispatched,
 *     without caring how it is persisted.
 *   - CONSISTENCY: all denial paths funnel through the same event, ensuring
 *     the `security_events` table has a uniform shape for every denial.
 *   - EXTENSIBILITY: real-time alerting (Slack, e-mail) can be added later
 *     as an additional listener without touching the middleware.
 *
 * -----------------------------------------------------------------------------
 * Guaranteed non-sensitive fields
 * -----------------------------------------------------------------------------
 * The event carries only identifiers and metadata:
 *   - userId, tenantId, application
 *   - checkType, checkValue
 *   - resourceType, resourceId, action
 *   - requestId, ip, userAgent
 *   - occurredAt
 *
 * None of these fields can carry credentials or secrets; sensitive request
 * bodies are deliberately never captured.
 *
 * @package MaaleArna\MASES\Core\Events
 */
final class AuthorizationFailureEvent
{
    use Dispatchable;
    use SerializesModels;

    /**
     * The authenticated user's ID (null for unauthenticated requests).
     *
     * @var int|null
     */
    public readonly ?int $userId;

    /**
     * The active tenant's ID (null for platform-level actions).
     *
     * @var int|string|null
     */
    public readonly int|string|null $tenantId;

    /**
     * The application slug, if the request is scoped to one (e.g. 'finance').
     *
     * @var string|null
     */
    public readonly ?string $application;

    /**
     * The kind of check that failed.
     *
     * Canonical values:
     *   - 'permission'    — a specific permission slug was required
     *   - 'role'          — a specific role slug was required
     *   - 'application'   — access to an application was required
     *   - '<domain>.<action>' — any domain-specific denial
     *
     * @var string
     */
    public readonly string $checkType;

    /**
     * The required value the user did not satisfy.
     *
     * @var string
     */
    public readonly string $checkValue;

    /**
     * Optional resource type involved in the denial (e.g. 'transaction').
     *
     * @var string|null
     */
    public readonly ?string $resourceType;

    /**
     * Optional resource identifier involved in the denial.
     *
     * @var string|null
     */
    public readonly ?string $resourceId;

    /**
     * Optional short verb describing the attempted action
     * (e.g. 'read', 'create', 'update', 'delete', 'approve').
     *
     * @var string|null
     */
    public readonly ?string $action;

    /**
     * Optional request identifier, propagated from the incoming request.
     *
     * @var string|null
     */
    public readonly ?string $requestId;

    /**
     * Optional client IP address.
     *
     * @var string|null
     */
    public readonly ?string $ip;

    /**
     * Optional user agent string.
     *
     * @var string|null
     */
    public readonly ?string $userAgent;

    /**
     * The moment the denial occurred.
     *
     * @var DateTimeImmutable
     */
    public readonly DateTimeImmutable $occurredAt;

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
     * @param DateTimeImmutable|null  $occurredAt
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
        ?DateTimeImmutable $occurredAt = null
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
        $this->occurredAt = $occurredAt ?? new DateTimeImmutable('now');
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
            try {
                $tenantId = mases_tenant_id();
            } catch (Throwable) {
                $tenantId = null;
            }
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
            occurredAt: new DateTimeImmutable('now')
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
            'occurred_at'   => $this->occurredAt->format(DateTimeInterface::ATOM),
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
     * Resolution order:
     *   1. `X-Request-ID` header.
     *   2. `X-Correlation-ID` header.
     *   3. A request object exposing an `id()` method.
     *
     * @param mixed $request
     *
     * @return string|null
     */
    private static function resolveRequestId(mixed $request): ?string
    {
        if ($request === null || !is_object($request)) {
            return null;
        }

        // 1. Header-based resolution.
        if (method_exists($request, 'header')) {
            foreach (['X-Request-ID', 'X-Correlation-ID'] as $header) {
                try {
                    $value = $request->header($header);
                } catch (Throwable) {
                    $value = null;
                }
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        // 2. Method-based resolution.
        if (method_exists($request, 'id')) {
            try {
                /** @var mixed $id */
                $id = $request->id();
                if (is_string($id) && $id !== '') {
                    return $id;
                }
            } catch (Throwable) {
                // Some request implementations may throw if the macro is
                // not defined; ignore and fall back to null.
            }
        }

        return null;
    }
}