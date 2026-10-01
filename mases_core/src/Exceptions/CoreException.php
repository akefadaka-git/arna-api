<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Exceptions;

use Exception;
use Throwable;

/**
 * CoreException
 *
 * This is the base exception class for all MASES Core platform errors.
 * All domain-specific and infrastructure exceptions should extend this class
 * to ensure consistent error handling, logging, and response formatting.
 *
 * The class supports:
 * - An error code (for client-side error identification)
 * - A context array (for additional debugging data without leaking secrets)
 * - A localized message key (for internationalisation)
 *
 * In production, these exceptions are caught by the API exception handler
 * and converted to a structured JSON response with a suitable HTTP status code.
 *
 * @package MaaleArna\MASES\Core\Exceptions
 */
abstract class CoreException extends Exception
{
    /**
     * The error code used for client-side identification (e.g., 'AUTH_INVALID_TOKEN').
     *
     * @var string
     */
    protected string $errorCode;

    /**
     * Additional contextual data for debugging (will be redacted if sensitive).
     *
     * @var array<string, mixed>
     */
    protected array $context = [];

    /**
     * The locale key for the error message (e.g., 'errors.auth.invalid_token').
     * If null, the exception message is used directly.
     *
     * @var string|null
     */
    protected ?string $messageKey = null;

    /**
     * The HTTP status code that should be returned in the response.
     *
     * @var int
     */
    protected int $httpStatusCode = 500;

    /**
     * Create a new CoreException instance.
     *
     * @param string          $message     The exception message (default language).
     * @param string          $errorCode   Unique error code (e.g., 'AUTH_UNAUTHORIZED').
     * @param int             $code        Internal error code (usually 0).
     * @param Throwable|null  $previous    Previous exception for chaining.
     */
    public function __construct(
        string $message = '',
        string $errorCode = 'INTERNAL_ERROR',
        int $code = 0,
        ?Throwable $previous = null
    ) {
        $this->errorCode = $errorCode;
        parent::__construct($message, $code, $previous);
    }

    /**
     * Get the error code.
     *
     * @return string
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * Set the error code.
     *
     * @param string $errorCode
     *
     * @return self
     */
    public function setErrorCode(string $errorCode): self
    {
        $this->errorCode = $errorCode;
        return $this;
    }

    /**
     * Get the context array.
     *
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Set the context array. Overwrites any existing context.
     *
     * @param array<string, mixed> $context
     *
     * @return self
     */
    public function setContext(array $context): self
    {
        $this->context = $context;
        return $this;
    }

    /**
     * Add a single context key-value pair.
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return self
     */
    public function addContext(string $key, mixed $value): self
    {
        $this->context[$key] = $value;
        return $this;
    }

    /**
     * Get the localization message key.
     *
     * @return string|null
     */
    public function getMessageKey(): ?string
    {
        return $this->messageKey;
    }

    /**
     * Set the localization message key.
     *
     * @param string $messageKey
     *
     * @return self
     */
    public function setMessageKey(string $messageKey): self
    {
        $this->messageKey = $messageKey;
        return $this;
    }

    /**
     * Get the HTTP status code recommended for this exception.
     *
     * @return int
     */
    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    /**
     * Set the HTTP status code.
     *
     * @param int $httpStatusCode
     *
     * @return self
     */
    public function setHttpStatusCode(int $httpStatusCode): self
    {
        $this->httpStatusCode = $httpStatusCode;
        return $this;
    }
}