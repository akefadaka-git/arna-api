<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Exceptions;

use Throwable;

/**
 * FileException
 *
 * Thrown when file-related errors occur, such as:
 * - File not found on disk or in database
 * - Upload failed (storage error, validation failure)
 * - File too large or extension not allowed
 * - Access denied (authentication required, tenant mismatch)
 * - File operation failed (delete, restore, etc.)
 *
 * The default HTTP status code is 400 (Bad Request) for validation errors,
 * 404 (Not Found) for missing files, and 403 (Forbidden) for access denials.
 *
 * @package MaaleArna\MASES\Core\Exceptions
 */
final class FileException extends CoreException
{
    /**
     * Error code constants for common file errors.
     */
    public const ERROR_FILE_NOT_FOUND = 'FILE_NOT_FOUND';
    public const ERROR_FILE_UPLOAD_FAILED = 'FILE_UPLOAD_FAILED';
    public const ERROR_FILE_STORAGE_FAILED = 'FILE_STORAGE_FAILED';
    public const ERROR_FILE_TOO_LARGE = 'FILE_TOO_LARGE';
    public const ERROR_FILE_EXTENSION_NOT_ALLOWED = 'FILE_EXTENSION_NOT_ALLOWED';
    public const ERROR_FILE_ACCESS_DENIED = 'FILE_ACCESS_DENIED';
    public const ERROR_FILE_AUTH_REQUIRED = 'FILE_AUTH_REQUIRED';
    public const ERROR_FILE_NOT_DELETED = 'FILE_NOT_DELETED';
    public const ERROR_FILE_DELETE_FAILED = 'FILE_DELETE_FAILED';
    public const ERROR_FILE_RESTORE_FAILED = 'FILE_RESTORE_FAILED';
    public const ERROR_FILE_INVALID_OPERATION = 'FILE_INVALID_OPERATION';

    /**
     * Create a new FileException instance.
     *
     * @param string $message The exception message.
     * @param string $errorCode Unique error code (default: 'FILE_ERROR').
     * @param int $httpStatusCode HTTP status code (default: 400).
     * @param int $code Internal error code (default: 0).
     * @param Throwable|null $previous Previous exception for chaining.
     */
    public function __construct(
        string $message = 'File operation failed.',
        string $errorCode = 'FILE_ERROR',
        int $httpStatusCode = 400,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $errorCode, $code, $previous);
        $this->setHttpStatusCode($httpStatusCode);
    }

    /**
     * Create a FileNotFoundException instance.
     *
     * @param string $identifier The file ID or path that was not found.
     * @return self
     */
    public static function notFound(string $identifier = ''): self
    {
        $message = 'File not found.';
        if ($identifier !== '') {
            $message .= ' (' . $identifier . ')';
        }
        $exception = new self($message, self::ERROR_FILE_NOT_FOUND, 404);
        if ($identifier !== '') {
            $exception->addContext('identifier', $identifier);
        }
        return $exception;
    }

    /**
     * Create an instance for upload failure.
     *
     * @param string $reason The reason for the failure.
     * @return self
     */
    public static function uploadFailed(string $reason = ''): self
    {
        $message = 'Failed to upload file.';
        if ($reason !== '') {
            $message .= ' ' . $reason;
        }
        return new self($message, self::ERROR_FILE_UPLOAD_FAILED, 500);
    }

    /**
     * Create an instance for storage failure.
     *
     * @param string $path The storage path that failed.
     * @return self
     */
    public static function storageFailed(string $path = ''): self
    {
        $message = 'Failed to store file on disk.';
        if ($path !== '') {
            $message .= ' Path: ' . $path;
        }
        $exception = new self($message, self::ERROR_FILE_STORAGE_FAILED, 500);
        if ($path !== '') {
            $exception->addContext('path', $path);
        }
        return $exception;
    }

    /**
     * Create an instance for file too large.
     *
     * @param int $maxSize The maximum allowed size in bytes.
     * @param int $actualSize The actual file size in bytes.
     * @return self
     */
    public static function tooLarge(int $maxSize, int $actualSize): self
    {
        $maxMB = $maxSize / 1024 / 1024;
        $actualMB = $actualSize / 1024 / 1024;
        $exception = new self(
            sprintf('File size exceeds the maximum allowed size of %d MB (actual: %.2f MB).', $maxMB, $actualMB),
            self::ERROR_FILE_TOO_LARGE,
            400
        );
        $exception->addContext('max_size_mb', $maxMB);
        $exception->addContext('actual_size_mb', $actualMB);
        return $exception;
    }

    /**
     * Create an instance for extension not allowed.
     *
     * @param string $extension The disallowed extension.
     * @param array<string> $allowedExtensions The list of allowed extensions.
     * @return self
     */
    public static function extensionNotAllowed(string $extension, array $allowedExtensions): self
    {
        $exception = new self(
            sprintf('File extension "%s" is not allowed. Allowed: %s.', $extension, implode(', ', $allowedExtensions)),
            self::ERROR_FILE_EXTENSION_NOT_ALLOWED,
            400
        );
        $exception->addContext('extension', $extension);
        $exception->addContext('allowed_extensions', $allowedExtensions);
        return $exception;
    }

    /**
     * Create an instance for access denied.
     *
     * @param string $reason The reason access was denied.
     * @return self
     */
    public static function accessDenied(string $reason = ''): self
    {
        $message = 'You do not have access to this file.';
        if ($reason !== '') {
            $message .= ' ' . $reason;
        }
        $exception = new self($message, self::ERROR_FILE_ACCESS_DENIED, 403);
        if ($reason !== '') {
            $exception->addContext('reason', $reason);
        }
        return $exception;
    }

    /**
     * Create an instance for authentication required.
     *
     * @return self
     */
    public static function authRequired(): self
    {
        return new self(
            'Authentication required to access this file.',
            self::ERROR_FILE_AUTH_REQUIRED,
            401
        );
    }

    /**
     * Create an instance for file not deleted (when trying to restore).
     *
     * @return self
     */
    public static function notDeleted(): self
    {
        return new self(
            'File is not deleted and cannot be restored.',
            self::ERROR_FILE_NOT_DELETED,
            400
        );
    }

    /**
     * Create an instance for delete failure.
     *
     * @param string $reason The reason for the failure.
     * @return self
     */
    public static function deleteFailed(string $reason = ''): self
    {
        $message = 'Failed to delete file.';
        if ($reason !== '') {
            $message .= ' ' . $reason;
        }
        return new self($message, self::ERROR_FILE_DELETE_FAILED, 500);
    }

    /**
     * Create an instance for restore failure.
     *
     * @param string $reason The reason for the failure.
     * @return self
     */
    public static function restoreFailed(string $reason = ''): self
    {
        $message = 'Failed to restore file.';
        if ($reason !== '') {
            $message .= ' ' . $reason;
        }
        return new self($message, self::ERROR_FILE_RESTORE_FAILED, 500);
    }

    /**
     * Create an instance for invalid operation.
     *
     * @param string $operation The operation that is invalid.
     * @return self
     */
    public static function invalidOperation(string $operation = ''): self
    {
        $message = 'Invalid file operation.';
        if ($operation !== '') {
            $message .= ' ' . $operation;
        }
        $exception = new self($message, self::ERROR_FILE_INVALID_OPERATION, 400);
        if ($operation !== '') {
            $exception->addContext('operation', $operation);
        }
        return $exception;
    }
}