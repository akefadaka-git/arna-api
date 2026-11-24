<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Authorization\Models\Role;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Tenancy\Models\TenantMembership;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CommunicationPeopleSupplementTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function conversation_message_is_idempotent_and_receipts_are_participant_scoped(): void
    {
        [$tenant, $actor] = $this->member(['communication.conversation.view','communication.conversation.create','communication.message.send','communication.message.read']);
        $recipient = User::factory()->create();
        $recipient->tenants()->attach($tenant->id, ['status' => TenantMembership::STATUS_ACTIVE]);
        $id = $this->request($actor,$tenant)->postJson('/api/communication/conversations', ['type'=>'direct','participant_ids'=>[$recipient->id]])
            ->assertCreated()->json('data.id');
        $payload = ['body'=>'Hello','idempotency_key'=>'message-1'];
        $message = $this->request($actor,$tenant)->postJson("/api/communication/conversations/$id/messages",$payload)->assertCreated()->json('data.id');
        $this->request($actor,$tenant)->postJson("/api/communication/conversations/$id/messages",$payload)->assertCreated()->assertJsonPath('data.id',$message);
        $this->assertSame(1,DB::table('communication_messages')->where('tenant_id',$tenant->id)->count());
        $this->request($recipient,$tenant)->postJson("/api/communication/conversations/$id/read")->assertOk()->assertJsonPath('data.read_receipts_recorded',1);
        $this->assertDatabaseHas('communication_message_reads',['message_id'=>$message,'user_id'=>$recipient->id,'tenant_id'=>$tenant->id]);
        [$foreignTenant,$foreign]= $this->member(['communication.conversation.view']);
        $this->request($foreign,$foreignTenant)->getJson("/api/communication/conversations/$id/messages")->assertNotFound();
    }

    #[Test]
    public function contact_value_is_encrypted_masked_and_never_marked_verified_by_client_input(): void
    {
        [$tenant,$actor] = $this->member(['people.manage','people.contact.view','people.contact.manage']);
        $publicId = $this->request($actor,$tenant)->postJson('/api/people',['first_name_en'=>'Contact Test'])->assertCreated()->json('data.public_id');
        $created = $this->request($actor,$tenant)->postJson("/api/people/$publicId/contacts",[
            'type'=>'email','value'=>'private.person@example.org','is_verified'=>true,
        ])->assertCreated()->assertJsonPath('data.value_masked','p***@example.org')->assertJsonPath('data.is_verified',false);
        $contactId=$created->json('data.id');
        $stored=DB::table('people_contact_points')->where('id',$contactId)->first();
        $this->assertNotSame('private.person@example.org',$stored->value_encrypted);
        $this->assertSame('private.person@example.org',\Illuminate\Support\Facades\Crypt::decryptString($stored->value_encrypted));
        $this->assertArrayNotHasKey('value',$created->json('data'));
        [$otherTenant,$otherActor]=$this->member(['people.contact.view']);
        $this->request($otherActor,$otherTenant)->getJson("/api/people/$publicId/contacts")->assertNotFound();
    }

    private function member(array $slugs): array
    {
        $tenant=Tenant::factory()->create(['is_active'=>true]);
        $user=User::factory()->create();
        $user->tenants()->attach($tenant->id,['status'=>TenantMembership::STATUS_ACTIVE]);
        foreach($slugs as $slug){
            $permission=Permission::updateOrCreate(['slug'=>$slug],[
                'name_am'=>$slug,'name_en'=>$slug,'description_am'=>$slug,'description_en'=>$slug,
                'category'=>str_starts_with($slug,'communication.')?'communication':'people',
                'scope'=>'tenant','tenant_id'=>null,'application_id'=>null,'is_active'=>true,'is_system'=>false,
            ]);
            $role=Role::create(['name_en'=>'Contract '.$slug,'slug'=>'contract-'.$user->id.'-'.str_replace('.','-',$slug),
                'scope'=>'tenant','tenant_id'=>$tenant->id,'application_id'=>null,'is_active'=>true]);
            $role->permissions()->attach($permission->id,['assigned_at'=>now()]);
            $user->roles()->attach($role->id,['assigned_at'=>now(),'assigned_by'=>$user->id]);
        }
        return [$tenant,$user];
    }

    private function request(User $user,Tenant $tenant): static
    {
        return $this->actingAs($user,'sanctum')->withHeader('X-Tenant-ID',(string)$tenant->id);
    }
}
