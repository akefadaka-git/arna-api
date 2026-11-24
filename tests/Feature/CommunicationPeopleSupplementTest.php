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
        $readPermission = Permission::where('slug', 'communication.message.read')->firstOrFail();
        $readerRole = Role::create([
            'name_en' => 'Conversation Reader',
            'slug' => 'conversation-reader-'.$recipient->id,
            'scope' => 'tenant',
            'tenant_id' => $tenant->id,
            'application_id' => null,
            'is_active' => true,
        ]);
        $readerRole->permissions()->attach($readPermission->id, ['assigned_at' => now()]);
        $recipient->roles()->attach($readerRole->id, ['assigned_at' => now(), 'assigned_by' => $actor->id]);
        $id = $this->request($actor,$tenant)->postJson('/api/communication/conversations', ['type'=>'direct','participant_ids'=>[$recipient->id]])
            ->assertCreated()->json('data.id');
        $payload = ['body'=>'Hello','idempotency_key'=>'message-1'];
        $message = $this->request($actor,$tenant)->postJson("/api/communication/conversations/$id/messages",$payload)->assertCreated()->json('data.id');
        $this->request($actor,$tenant)->postJson("/api/communication/conversations/$id/messages",$payload)->assertCreated()->assertJsonPath('data.id',$message);
        $this->assertSame(1,DB::table('communication_messages')->where('tenant_id',$tenant->id)->count());
        $this->request($recipient,$tenant)->postJson("/api/communication/conversations/$id/read")->assertOk()->assertJsonPath('data.read_receipts_recorded',1);
        $this->request($recipient,$tenant)->postJson("/api/communication/conversations/$id/read")->assertOk()->assertJsonPath('data.read_receipts_recorded',0);
        $this->assertDatabaseCount('communication_message_reads',1);
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

    #[Test]
    public function descriptive_relationships_and_participations_require_same_tenant_and_do_not_create_authority_records(): void
    {
        [$tenant,$actor] = $this->member([
            'people.manage','people.relationship.view','people.relationship.manage',
            'people.participation.view','people.participation.manage',
        ]);
        $personA=$this->request($actor,$tenant)->postJson('/api/people',['first_name_en'=>'Person A'])->assertCreated()->json('data.public_id');
        $personB=$this->request($actor,$tenant)->postJson('/api/people',['first_name_en'=>'Person B'])->assertCreated()->json('data.public_id');

        $relationship=$this->request($actor,$tenant)->postJson("/api/people/$personA/relationships/descriptive",[
            'related_person_public_id'=>$personB,'relationship_type'=>'colleague','description'=>'Descriptive only',
        ])->assertCreated()->json('data.id');
        $this->request($actor,$tenant)->getJson("/api/people/$personA/relationships/descriptive")
            ->assertOk()->assertJsonPath('data.0.relationship_type','colleague');

        // A relationship must not keep exposing a related Person after this
        // tenant's explicit visibility grant has been revoked.
        $relatedPersonId = (int) DB::table('people')->where('public_id', $personB)->value('id');
        DB::table('person_tenant_accesses')->where('tenant_id', $tenant->getKey())
            ->where('person_id', $relatedPersonId)->update(['status' => 'revoked', 'updated_at' => now()]);
        $this->request($actor,$tenant)->getJson("/api/people/$personA/relationships/descriptive")
            ->assertOk()->assertExactJson(['success' => true, 'data' => []]);

        $participation=$this->request($actor,$tenant)->postJson("/api/people/$personA/participations",[
            'participation_type'=>'volunteer','title'=>'Community cleanup','organization_label'=>'Local initiative',
        ])->assertCreated()->json('data.id');
        $this->request($actor,$tenant)->getJson("/api/people/$personA/participations")
            ->assertOk()->assertJsonPath('data.0.title','Community cleanup');

        $this->request($actor,$tenant)->deleteJson("/api/people/$personA/relationships/descriptive/$relationship")
            ->assertOk()->assertJsonPath('data.deactivated',true);
        $this->request($actor,$tenant)->deleteJson("/api/people/$personA/participations/$participation")
            ->assertOk()->assertJsonPath('data.deactivated',true);

        $this->assertDatabaseHas('people_relationships',['id'=>$relationship,'tenant_id'=>$tenant->id,'status'=>'inactive']);
        $this->assertDatabaseHas('people_participations',['id'=>$participation,'tenant_id'=>$tenant->id,'status'=>'inactive']);
        [$foreignTenant,$foreignActor]=$this->member(['people.relationship.manage']);
        $this->request($foreignActor,$foreignTenant)->postJson("/api/people/$personA/relationships/descriptive",[
            'related_person_public_id'=>$personB,'relationship_type'=>'colleague',
        ])->assertNotFound();
    }

    #[Test]
    public function revoked_tenant_membership_removes_all_existing_conversation_access(): void
    {
        [$tenant, $actor] = $this->member([
            'communication.conversation.view','communication.conversation.create',
            'communication.message.send','communication.message.read',
        ]);
        $recipient = User::factory()->create();
        $recipient->tenants()->attach($tenant->id, ['status' => TenantMembership::STATUS_ACTIVE]);
        $readPermission = Permission::where('slug', 'communication.message.read')->firstOrFail();
        $readerRole = Role::create([
            'name_en' => 'Revocation Test Reader',
            'slug' => 'revocation-test-reader-'.$recipient->id,
            'scope' => 'tenant',
            'tenant_id' => $tenant->id,
            'application_id' => null,
            'is_active' => true,
        ]);
        $readerRole->permissions()->attach($readPermission->id, ['assigned_at' => now()]);
        $recipient->roles()->attach($readerRole->id, ['assigned_at' => now(), 'assigned_by' => $actor->id]);

        $id = $this->request($actor, $tenant)
            ->postJson('/api/communication/conversations', ['type' => 'direct', 'participant_ids' => [$recipient->id]])
            ->assertCreated()->json('data.id');
        $messageId = $this->request($actor, $tenant)
            ->postJson("/api/communication/conversations/$id/messages", [
                'body' => 'Before revocation',
                'idempotency_key' => 'before-revocation',
            ])->assertCreated()->json('data.id');

        $this->assertCount(1, $this->request($recipient, $tenant)
            ->getJson('/api/communication/conversations')->assertOk()->json('data'));

        DB::table('tenant_memberships')->where('tenant_id', $tenant->id)
            ->where('user_id', $recipient->id)
            ->update(['status' => 'inactive', 'updated_at' => now()]);

        $this->request($recipient, $tenant)->getJson('/api/communication/conversations')
            ->assertForbidden();
        $this->request($recipient, $tenant)->getJson("/api/communication/conversations/$id/messages")
            ->assertForbidden();
        $this->request($recipient, $tenant)->postJson("/api/communication/conversations/$id/messages", [
            'body' => 'After revocation',
            'idempotency_key' => 'after-revocation',
        ])->assertForbidden();
        $this->request($recipient, $tenant)->postJson("/api/communication/conversations/$id/read")
            ->assertForbidden();

        $this->assertDatabaseCount('communication_messages', 1);
        $this->assertDatabaseCount('communication_message_reads', 0);
        $this->assertDatabaseHas('communication_messages', ['id' => $messageId, 'body' => 'Before revocation']);
        $this->assertDatabaseHas('communication_conversation_participants', [
            'conversation_id' => $id,
            'tenant_id' => $tenant->id,
            'user_id' => $recipient->id,
            'status' => 'active',
        ]);
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
