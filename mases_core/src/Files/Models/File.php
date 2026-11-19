<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Files\Models;

/**
 * Backward-compatibility alias for the canonical File Management model.
 *
 * The implementation and the single Eloquent mapping for the `files` table
 * live in Domains\\FileManagement. This alias preserves existing integrations
 * while consumers migrate to the domain namespace.
 */
class_alias(
    \MaaleArna\MASES\Core\Domains\FileManagement\Models\File::class,
    __NAMESPACE__ . '\\File'
);
