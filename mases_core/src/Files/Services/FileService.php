<?php

declare(strict_types=1);

/**
 * Backward-compatibility alias for the canonical File Management service.
 *
 * All storage, authorization, audit and versioning behavior is implemented
 * by Domains\\FileManagement\\Services\\FileService. This alias avoids a
 * second service or storage implementation during consumer migration.
 */
class_alias(
    \MaaleArna\MASES\Core\Domains\FileManagement\Services\FileService::class,
    __NAMESPACE__ . '\\FileService'
);
