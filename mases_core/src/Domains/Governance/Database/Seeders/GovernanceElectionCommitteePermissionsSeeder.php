<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Database\Seeders;

use Illuminate\Database\Seeder;
use MaaleArna\MASES\Core\Authorization\Models\Permission;

/**
 * Registers least-privilege election committee permissions.
 *
 * This seeder deliberately does not grant these permissions to roles or users.
 * Assignment is an explicit tenant-governed administrative action.
 */
final class GovernanceElectionCommitteePermissionsSeeder extends Seeder
{
    private const PERMISSIONS = [
        ['slug' => 'governance.election.committee.administer', 'name_am' => 'የምርጫ ኮሚቴ አስተዳዳሪዎችን መመደብ', 'name_en' => 'Administer election committee authorities'],
        ['slug' => 'governance.election.committee.view', 'name_am' => 'የምርጫ ኮሚቴን ማየት', 'name_en' => 'View election committee'],
        ['slug' => 'governance.election.committee.appoint', 'name_am' => 'የምርጫ ኮሚቴ አባላትን መመደብ', 'name_en' => 'Appoint election committee members'],
        ['slug' => 'governance.election.committee.revoke', 'name_am' => 'የምርጫ ኮሚቴ ሹመትን መሻር', 'name_en' => 'Revoke election committee appointments'],
        ['slug' => 'governance.election.committee.conflict.declare', 'name_am' => 'የጥቅም ግጭትን ማሳወቅ', 'name_en' => 'Declare committee conflicts of interest'],
        ['slug' => 'governance.election.committee.decide', 'name_am' => 'የኮሚቴ ጉዳዮችን መወሰን', 'name_en' => 'Record committee decisions'],
        ['slug' => 'governance.election.committee.audit.view', 'name_am' => 'የኮሚቴ የደህንነት ኦዲትን ማየት', 'name_en' => 'View committee security audit'],
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
                ]
            );
        }

        $this->command?->info('Election committee permissions seeded: ' . count(self::PERMISSIONS) . ' entries; no role grants changed.');
    }
}
