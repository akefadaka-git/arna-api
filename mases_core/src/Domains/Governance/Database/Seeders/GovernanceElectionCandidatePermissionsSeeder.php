<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Database\Seeders;

use Illuminate\Database\Seeder;
use MaaleArna\MASES\Core\Authorization\Models\Permission;

/**
 * Registers least-privilege candidate intake permissions.
 * It intentionally creates no role grants.
 */
final class GovernanceElectionCandidatePermissionsSeeder extends Seeder
{
    private const PERMISSIONS = [
        ['slug' => 'governance.election.candidate.view', 'name_am' => 'የምርጫ እጩዎችን ማየት', 'name_en' => 'View election candidates'],
        ['slug' => 'governance.election.nomination.submit', 'name_am' => 'የእጩ ሹመት ማቅረብ', 'name_en' => 'Submit election nomination'],
        ['slug' => 'governance.election.candidate.evidence.submit', 'name_am' => 'የእጩ ማስረጃ ማቅረብ', 'name_en' => 'Submit candidate evidence'],
        ['slug' => 'governance.election.candidate.evidence.review', 'name_am' => 'የእጩ ማስረጃ መገምገም', 'name_en' => 'Review candidate evidence'],
        ['slug' => 'governance.election.candidate.screen', 'name_am' => 'የእጩ ቅድመ ምርመራ ማድረግ', 'name_en' => 'Screen election candidates'],
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::updateOrCreate(
                ['slug' => $permission['slug']],
                [
                    'name_am' => $permission['name_am'],
                    'name_en' => $permission['name_en'],
                    'description_am' => $permission['name_am'],
                    'description_en' => $permission['name_en'],
                    'category' => 'governance',
                    'scope' => 'tenant',
                    'tenant_id' => null,
                    'application_id' => null,
                    'is_active' => true,
                    'is_system' => true,
                ],
            );
        }

        $this->command?->info('Election candidate permissions seeded: ' . count(self::PERMISSIONS) . ' entries; no role grants changed.');
    }
}
