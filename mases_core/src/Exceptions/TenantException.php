<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Exceptions;

use Throwable;

/**
 * TenantException
 *
 * Thrown when tenant-related errors occur, such as:
 * - Tenant not found or inactive
 * - Tenant context required but not present
 * - Tenant mismatch (expected slug vs actual slug)
 * - Tenant isolation violations
 * - Tenant access denied (user not a member)
 *
 * The default HTTP status code is 400 (Bad Request) for tenant resolution
 * errors, and 403 (Forbidden) for tenant mismatch/access violations.
 *
 * @package MaaleArna\MASES\Core\Exceptions
 */
final class TenantException extends CoreException
{
    /**
     * Error code constants for common tenant errors.
     */
    public const ERROR_TENANT_NOT_FOUND = 'TENANT_NOT_FOUND';
    public const ERROR_TENANT_REQUIRED = 'TENANT_REQUIRED';
    public const ERROR_TENANT_MISMATCH = 'TENANT_MISMATCH';
    public const ERROR_TENANT_INACTIVE = 'TENANT_INACTIVE';
    public const ERROR_TENANT_ISOLATION = 'TENANT_ISOLATION_VIOLATION';
    public const ERROR_TENANT_ACCESS_DENIED = 'TENANT_ACCESS_DENIED';

    /**
     * Create a new TenantException instance.
     *
     * @param string $message The exception message.
     * @param string $errorCode Unique error code (default: 'TENANT_ERROR').
     * @param int $httpStatusCode HTTP status code (default: 400).
     * @param int $code Internal error code (default: 0).
     * @param Throwable|null $previous Previous exception for chaining.
     */
    public function __construct(
        string $message = 'Tenant error occurred.',
        string $errorCode = 'TENANT_ERROR',
        int $httpStatusCode = 400,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $errorCode, $code, $previous);
        $this->setHttpStatusCode($httpStatusCode);
    }

    /**
     * Create a TenantNotFoundException instance.
     *
     * @param string $tenantId The tenant ID that was not found.
     * @return self
     */
    public static function notFound(string $tenantId): self
    {
        return new self(
            sprintf('Tenant "%s" not found or inactive.', $tenantId),
            self::ERROR_TENANT_NOT_FOUND,
            404
        );
    }

    /**
     * Create a TenantRequiredException instance.
     *
     * @return self
     */
    public static function required(): self
    {
        return new self(
            'Tenant context is required for this request.',
            self::ERROR_TENANT_REQUIRED,
            400
        );
    }

    /**
     * Create a TenantMismatchException instance.
     *
     * @param string $expected The expected tenant slug.
     * @param string $actual The actual tenant slug.
     * @return self
     */
    public static function mismatch(string $expected, string $actual): self
    {
        return new self(
            sprintf('Tenant mismatch: expected "%s" but got "%s".', $expected, $actual),
            self::ERROR_TENANT_MISMATCH,
            403
        );
    }

    /**
     * Create a TenantIsolationException instance.
     *
     * @param string $context Additional context about the isolation violation.
     * @return self
     */
    public static function isolationViolation(string $context = ''): self
    {
        $message = 'Tenant isolation violation detected.';
        if ($context !== '') {
            $message .= ' ' . $context;
        }
        return new self(
            $message,
            self::ERROR_TENANT_ISOLATION,
            403
        );
    }

    /**
     * Create a TenantAccessDeniedException instance.
     *
     * @param string $reason Additional reason for access denial.
     * @return self
     */
    public static function accessDenied(string $reason = ''): self
    {
        $message = 'Access denied for this tenant.';
        if ($reason !== '') {
            $message .= ' ' . $reason;
        }
        $exception = new self(
            $message,
            self::ERROR_TENANT_ACCESS_DENIED,
            403
        );
        if ($reason !== '') {
            $exception->addContext('reason', $reason);
        }
        return $exception;
    }
}