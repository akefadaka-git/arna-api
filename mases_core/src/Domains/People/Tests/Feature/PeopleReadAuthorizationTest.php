<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\People\Models\Person;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PeopleReadAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function tenant_member_without_people_read_or_manage_permission_cannot_list_or_read_profiles(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $actor = User::factory()->create(['is_active' => true]);
        $actor->tenants()->attach($tenant->getKey(), [
            'joined_at' => now(),
            'status' => 'active',
        ]);

        $person = Person::query()->create(['first_name_en' => 'Permission Boundary']);
        DB::table('person_tenant_accesses')->insert([
            'person_id' => $person->getKey(),
            'tenant_id' => $tenant->getKey(),
            'created_by' => $actor->getKey(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($actor);
        $headers = ['X-Tenant-ID' => (string) $tenant->getKey()];

        $this->withHeaders($headers)
            ->getJson('/api/people')
            ->assertForbidden();

        $this->withHeaders($headers)
            ->getJson('/api/people/'.$person->public_id)
            ->assertForbidden();
    }
}
