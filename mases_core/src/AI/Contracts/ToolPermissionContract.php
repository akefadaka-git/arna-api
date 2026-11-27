<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\AI\Contracts;

interface ToolPermissionContract
{
    /**
     * Return true only when the current actor is authorized by the owning
     * domain for this tool and resource scope.
     *
     * @param array<string,mixed> $scope
     */
    public function allows(string $tool, array $scope): bool;
}
