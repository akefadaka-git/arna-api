<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Exceptions;

use Throwable;

/**
 * AuthenticationException
 *
 * Thrown when authentication-related errors occur, such as:
 * - Invalid credentials (email/password mismatch)
 * - Email already taken during registration
 * - User account inactive
 * - Tenant access denied during login
 * - Token invalid or expired
 * - Authentication required but not present
 *
 * The default HTTP status code is 401 (Unauthorized) for authentication
 * failures, and 403 (Forbidden) for access/tenant-related denials.
 *
 * @package MaaleArna\MASES\Core\Exceptions
 */
final class AuthenticationException extends CoreException
{
    /**
     * Error code constants for common authentication errors.
     */
    public const ERROR_INVALID_CREDENTIALS = 'AUTH_INVALID_CREDENTIALS';
    public const ERROR_EMAIL_TAKEN = 'AUTH_EMAIL_TAKEN';
    public const ERROR_ACCOUNT_INACTIVE = 'AUTH_ACCOUNT_INACTIVE';
    public const ERROR_TENANT_ACCESS_DENIED = 'AUTH_TENANT_ACCESS_DENIED';
    public const ERROR_TOKEN_INVALID = 'AUTH_TOKEN_INVALID';
    public const ERROR_TOKEN_EXPIRED = 'AUTH_TOKEN_EXPIRED';
    public const ERROR_UNAUTHENTICATED = 'AUTH_UNAUTHENTICATED';
    public const ERROR_PASSWORD_RESET_FAILED = 'AUTH_PASSWORD_RESET_FAILED';
    public const ERROR_EMAIL_NOT_VERIFIED = 'AUTH_EMAIL_NOT_VERIFIED';

    /**
     * Create a new AuthenticationException instance.
     *
     * @param string $message The exception message.
     * @param string $errorCode Unique error code (default: 'AUTH_ERROR').
     * @param int $httpStatusCode HTTP status code (default: 401).
     * @param int $code Internal error code (default: 0).
     * @param Throwable|null $previous Previous exception for chaining.
     */
    public function __construct(
        string $message = 'Authentication failed.',
        string $errorCode = 'AUTH_ERROR',
        int $httpStatusCode = 401,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $errorCode, $code, $previous);
        $this->setHttpStatusCode($httpStatusCode);
    }

    /**
     * Create an instance for invalid credentials.
     *
     * @param string $email The email that was attempted (for logging context).
     * @return self
     */
    public static function invalidCredentials(string $email = ''): self
    {
        $exception = new self(
            'Invalid email or password.',
            self::ERROR_INVALID_CREDENTIALS,
            401
        );
        if ($email !== '') {
            $exception->addContext('email', $email);
        }
        return $exception;
    }

    /**
     * Create an instance for email already taken.
     *
     * @param string $email The email that is already taken.
     * @return self
     */
    public static function emailTaken(string $email): self
    {
        return new self(
            sprintf('A user with email "%s" already exists.', $email),
            self::ERROR_EMAIL_TAKEN,
            409
        );
    }

    /**
     * Create an instance for inactive account.
     *
     * @param string $email The email of the inactive account.
     * @return self
     */
    public static function accountInactive(string $email = ''): self
    {
        $exception = new self(
            'This account has been deactivated.',
            self::ERROR_ACCOUNT_INACTIVE,
            403
        );
        if ($email !== '') {
            $exception->addContext('email', $email);
        }
        return $exception;
    }

    /**
     * Create an instance for tenant access denied.
     *
     * @param int|string $tenantId The tenant ID that access was denied for.
     * @param string $email The user's email (for logging context).
     * @return self
     */
    public static function tenantAccessDenied(int|string $tenantId, string $email = ''): self
    {
        $exception = new self(
            'User does not have access to the specified tenant.',
            self::ERROR_TENANT_ACCESS_DENIED,
            403
        );
        $exception->addContext('tenant_id', $tenantId);
        if ($email !== '') {
            $exception->addContext('email', $email);
        }
        return $exception;
    }

    /**
     * Create an instance for invalid token.
     *
     * @param string $tokenType The type of token (e.g., 'access', 'refresh').
     * @return self
     */
    public static function invalidToken(string $tokenType = 'access'): self
    {
        $exception = new self(
            sprintf('Invalid %s token provided.', $tokenType),
            self::ERROR_TOKEN_INVALID,
            401
        );
        $exception->addContext('token_type', $tokenType);
        return $exception;
    }

    /**
     * Create an instance for expired token.
     *
     * @param string $tokenType The type of token (e.g., 'access', 'refresh').
     * @return self
     */
    public static function expiredToken(string $tokenType = 'access'): self
    {
        $exception = new self(
            sprintf('The %s token has expired.', $tokenType),
            self::ERROR_TOKEN_EXPIRED,
            401
        );
        $exception->addContext('token_type', $tokenType);
        return $exception;
    }

    /**
     * Create an instance for unauthenticated requests.
     *
     * @return self
     */
    public static function unauthenticated(): self
    {
        return new self(
            'Authentication is required to access this resource.',
            self::ERROR_UNAUTHENTICATED,
            401
        );
    }

    /**
     * Create an instance for password reset failure.
     *
     * @param string $reason The reason for the failure.
     * @return self
     */
    public static function passwordResetFailed(string $reason = ''): self
    {
        $message = 'Password reset failed.';
        if ($reason !== '') {
            $message .= ' ' . $reason;
        }
        return new self(
            $message,
            self::ERROR_PASSWORD_RESET_FAILED,
            400
        );
    }

    /**
     * Create an instance for unverified email.
     *
     * @param string $email The email that is not verified.
     * @return self
     */
    public static function emailNotVerified(string $email = ''): self
    {
        $exception = new self(
            'Please verify your email address before continuing.',
            self::ERROR_EMAIL_NOT_VERIFIED,
            403
        );
        if ($email !== '') {
            $exception->addContext('email', $email);
        }
        return $exception;
    }
}