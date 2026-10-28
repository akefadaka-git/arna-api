<?php

declare(strict_types=1);

namespace MaaleArna\\MASES\\Core\\Domains\\Organization\\Policies;

use Illuminate\\Database\\Eloquent\\ModelNotFoundException;
use MaaleArna\\MASES\\Core\\Domains\\Organization\\Models\\OrganizationUnit;
use Symfony\\Component\\HttpKernel\\Exception\\ConflictHttpException;

/**
 * Canonical Organization unit taxonomy and parent/child compatibility policy.
 *
 * Changes to this taxonomy require a reviewed policy change; API input cannot
 * introduce arbitrary structural types or parent edges.
 */
final class OrganizationHierarchyPolicy
{
    /** @var array<string, list<string>> Child unit type => permitted parent types. */
    private const PARENT_TYPES = [
        'company' => [],
        'division' => ['company'],
        'branch' => ['company', 'division'],
        'department' => ['company', 'division', 'branch'],
        'team' => ['department'],
    ];

    /** @return list<string> */
    public static function unitTypes(): array
    {
        return array_keys(self::PARENT_TYPES);
    }

    /** @return list<string> */
    public static function permittedParentTypes(string $childType): array
    {
        return self::PARENT_TYPES[$childType] ?? [];
    }

    public function assertParentType(string $childType, ?OrganizationUnit $parent): void
    {
        if (!array_key_exists($childType, self::PARENT_TYPES)) {
            throw new ConflictHttpException('The organization unit type is not supported.');
        }

        if ($childType === 'company') {
            if ($parent !== null) {
                throw new ConflictHttpException('A company must be a root organization unit.');
            }

            return;
        }

        if ($parent === null || !in_array($parent->unit_type, self::PARENT_TYPES[$childType], true)) {
            throw new ConflictHttpException('The parent unit type is not valid for this organization unit type.');
        }
    }
}
