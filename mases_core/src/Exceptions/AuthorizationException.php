<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Exceptions;

use Throwable;

/**
 * AuthorizationException
 *
 * Thrown when a user lacks the required permissions or roles to perform
 * an action. This exception is used by the AuthorizationService and
 * authorization middleware to enforce access control.
 *
 * The default HTTP status code is 403 (Forbidden).
 *
 * @package MaaleArna\MASES\Core\Exceptions
 */
final class AuthorizationException extends CoreException
{
    /**
     * Create a new AuthorizationException instance.
     *
     * @param string $message The exception message (default language).
     * @param string $errorCode Unique error code (default: 'AUTH_PERMISSION_DENIED').
     * @param int $httpStatusCode HTTP status code (default: 403).
     * @param int $code Internal error code (default: 0). **Ignored** – we use httpStatusCode as exception code.
     * @param Throwable|null $previous Previous exception for chaining.
     */
    public function __construct(
        string $message = 'Permission denied.',
        string $errorCode = 'AUTH_PERMISSION_DENIED',
        int $httpStatusCode = 403,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        // Pass httpStatusCode as the exception code so getCode() returns 403.
        parent::__construct($message, $errorCode, $httpStatusCode, $previous);
        $this->setHttpStatusCode($httpStatusCode);
    }

    /**
     * Get the HTTP status code for this exception.
     *
     * @return int
     */
    public function getStatusCode(): int
    {
        return $this->httpStatusCode;
    }
}