<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Organization\Tests\Feature;

use InvalidArgumentException;
use MaaleArna\MASES\Core\Domains\Organization\Database\Seeders\OrganizationPermissionSeeder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class OrganizationSeederContractTest extends TestCase
{
    #[Test]
    public function permission_seeder_fails_closed_when_canonical_permission_is_missing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new OrganizationPermissionSeeder())->seed([]);
    }
}
