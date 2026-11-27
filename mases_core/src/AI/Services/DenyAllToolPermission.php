<?php

declare(strict_types=1);

namespace MaaleArna\\MASES\\Core\\AI\\Services;

use MaaleArna\\MASES\\Core\\AI\\Contracts\\ToolPermissionContract;

/** Safe default until each tool is bound to an owning-domain authorization adapter. */
final class DenyAllToolPermission implements ToolPermissionContract
{
    public function allows(string $tool, array $scope): bool
    {
        return false;
    }
}
