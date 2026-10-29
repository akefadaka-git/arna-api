<?php
declare(strict_types=1);
namespace Tests\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Authorization\Models\Role;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
final class PersonContactPointAcceptanceTest extends TestCase {
 use RefreshDatabase;
 #[Test]
 public function duplicate_active_contact_is_rejected_and_another_tenant_cannot_read_it(): void {
  [$tenant,$actor]=$this->tenantPeopleManager();
  Sanctum::actingAs($actor);
  $headers=['X-Tenant-ID'=>(string)$tenant->getKey()];
  $person=$this->withHeaders($headers)->postJson('/api/people',['first_name_en'=>'Contact Privacy Test'])->assertCreated();
  $publicId=$person->json('data.public_id');
  $payload=['contact_type'=>'email','label'=>'Work','contact_value'=>'private@example.test','is_primary'=>true];
  $this->withHeaders($headers)->postJson('/api/people/'.$publicId.'/contacts',$payload)->assertCreated();
  $this->withHeaders($headers)->postJson('/api/people/'.$publicId.'/contacts',$payload)->assertUnprocessable();

  [$otherTenant,$otherActor]=$this->tenantPeopleManager();
  Sanctum::actingAs($otherActor);
  $this->withHeader('X-Tenant-ID',(string)$otherTenant->getKey())
   ->getJson('/api/people/'.$publicId.'/contacts')->assertNotFound();
 }
 /** @return array{0: Tenant, 1: User} */
 private function tenantPeopleManager(): array {
  $tenant=Tenant::factory()->create(['is_active'=>true]);
  $actor=User::factory()->create(['is_active'=>true]);
  $actor->tenants()->attach($tenant->getKey(),['joined_at'=>now(),'status'=>'active']);
  $permission=Permission::query()->create(['name_en'=>'Manage People Profiles','slug'=>'people.manage','category'=>'user','scope'=>'tenant','tenant_id'=>null,'is_active'=>true,'is_system'=>false]);
  $role=Role::query()->create(['name_en'=>'Tenant People Manager','slug'=>'tenant-people-manager-'.$tenant->getKey(),'scope'=>'tenant','tenant_id'=>$tenant->getKey(),'is_active'=>true]);
  $role->permissions()->attach($permission->getKey(),['assigned_at'=>now()]);
  $actor->roles()->attach($role->getKey(),['assigned_at'=>now()]);
  return [$tenant,$actor];
 }
}
