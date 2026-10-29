<?php

declare(strict_types=1);

namespace App\Http\Controllers;

/**
 * Backward-compatible controller adapter.
 *
 * The canonical controller and route ownership now belong to FileManagement.
 * Keep this class for integrations referencing the former controller FQCN.
 */
final class FileController extends \MaaleArna\MASES\Core\Domains\FileManagement\Http\Controllers\FileController
{
}
