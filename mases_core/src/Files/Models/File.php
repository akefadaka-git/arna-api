<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Files\Models;

/**
 * Backward-compatible class alias.
 *
 * The canonical File model is owned by the FileManagement domain. This alias
 * preserves integrations that still use the legacy namespace.
 */
class_alias(\MaaleArna\MASES\Core\Domains\FileManagement\Models\File::class, __NAMESPACE__ . '\\File');
