<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Files\Services;

/**
 * Backward-compatible class alias.
 *
 * The canonical FileService is owned by the FileManagement domain. This alias
 * preserves integrations that still use the legacy namespace.
 */
class_alias(\MaaleArna\MASES\Core\Domains\FileManagement\Services\FileService::class, __NAMESPACE__ . '\\FileService');
