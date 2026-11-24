<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Authorization\Models\Role;
use MaaleArna\MASES\Core\Authorization\Services\TenantRoleOwnerApprovalService;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Tenancy\Services\TenantContextService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

final class TenantRoleOwnerApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_signature_allows_one_exact_role_assignment_then_replay_is_rejected(): void
    {
        [$tenant, $actor, $owner, $target, $role, $secretKey] = $this->fixture();
        $service = app(TenantRoleOwnerApprovalService::class);

        [$challenge, $evidence] = $this->signedChallenge($service, $actor, $tenant, $secretKey, [
            'owner_user_id' => $owner->id,
            'action' => TenantRoleOwnerApprovalService::ACTION_ASSIGN,
            'role_id' => $role->id,
            'target_user_id' => $target->id,
            'permission_slugs' => [],
            'reason' => 'Owner-approved tenant role assignment test',
        ]);

        $result = $service->execute($actor, (int) $tenant->id, $challenge['challenge_id'], $evidence);

        $this->assertSame(TenantRoleOwnerApprovalService::ACTION_ASSIGN, $result['action']);
        $this->assertDatabaseHas('user_has_roles', ['user_id' => $target->id, 'role_id' => $role->id]);
        $this->assertDatabaseHas('admin_owner_approval_consumptions', [
            'tenant_id' => $tenant->id,
            'action' => TenantRoleOwnerApprovalService::ACTION_ASSIGN,
            'payload_sha256' => $challenge['payload_sha256'],
        ]);

        $this->expectException(ConflictHttpException::class);
        $service->execute($actor, (int) $tenant->id, $challenge['challenge_id'], $evidence);
    }

    public function test_owner_signature_gates_exact_permission_set_and_role_revocation(): void
    {
        [$tenant, $actor, $owner, $target, $role, $secretKey] = $this->fixture();
        $service = app(TenantRoleOwnerApprovalService::class);

        Permission::query()->create([
            'name_en' => 'Tenant Records Read',
            'slug' => 'tenant.records.read',
            'category' => 'records',
            'scope' => 'tenant',
            'tenant_id' => $tenant->id,
            'application_id' => null,
            'is_active' => true,
            'is_system' => false,
        ]);

        [$assignChallenge, $assignEvidence] = $this->signedChallenge($service, $actor, $tenant, $secretKey, [
            'owner_user_id' => $owner->id,
            'action' => TenantRoleOwnerApprovalService::ACTION_ASSIGN,
            'role_id' => $role->id,
            'target_user_id' => $target->id,
            'permission_slugs' => [],
            'reason' => 'Owner-approved role assignment',
        ]);
        $service->execute($actor, (int) $tenant->id, $assignChallenge['challenge_id'], $assignEvidence);

        [$syncChallenge, $syncEvidence] = $this->signedChallenge($service, $actor, $tenant, $secretKey, [
            'owner_user_id' => $owner->id,
            'action' => TenantRoleOwnerApprovalService::ACTION_SYNC_PERMISSIONS,
            'role_id' => $role->id,
            'target_user_id' => null,
            'permission_slugs' => ['tenant.records.read'],
            'reason' => 'Owner-approved exact role permission set',
        ]);
        $service->execute($actor, (int) $tenant->id, $syncChallenge['challenge_id'], $syncEvidence);

        $permissionId = Permission::query()->where('slug', 'tenant.records.read')->value('id');
        $this->assertDatabaseHas('role_has_permissions', [
            'role_id' => $role->id,
            'permission_id' => $permissionId,
        ]);

        [$revokeChallenge, $revokeEvidence] = $this->signedChallenge($service, $actor, $tenant, $secretKey, [
            'owner_user_id' => $owner->id,
            'action' => TenantRoleOwnerApprovalService::ACTION_REVOKE,
            'role_id' => $role->id,
            'target_user_id' => $target->id,
            'permission_slugs' => [],
            'reason' => 'Owner-approved tenant role revocation',
        ]);
        $service->execute($actor, (int) $tenant->id, $revokeChallenge['challenge_id'], $revokeEvidence);

        $this->assertDatabaseMissing('user_has_roles', ['user_id' => $target->id, 'role_id' => $role->id]);
    }

    public function test_invalid_owner_signature_does_not_mutate_role_assignment_or_consume_challenge(): void
    {
        [$tenant, $actor, $owner, $target, $role, $secretKey] = $this->fixture();
        $service = app(TenantRoleOwnerApprovalService::class);

        [$challenge] = $this->signedChallenge($service, $actor, $tenant, $secretKey, [
            'owner_user_id' => $owner->id,
            'action' => TenantRoleOwnerApprovalService::ACTION_ASSIGN,
            'role_id' => $role->id,
            'target_user_id' => $target->id,
            'permission_slugs' => [],
            'reason' => 'Invalid signature must fail closed',
        ]);

        try {
            $service->execute($actor, (int) $tenant->id, $challenge['challenge_id'], [
                'signature' => base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_BYTES)),
                'signed_at' => json_decode($challenge['canonical_payload'], true, 512, JSON_THROW_ON_ERROR)['issued_at'],
            ]);
            $this->fail('Invalid Owner signature must be rejected.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->assertDatabaseMissing('user_has_roles', ['user_id' => $target->id, 'role_id' => $role->id]);
            $this->assertDatabaseMissing('admin_owner_approval_consumptions', [
                'tenant_id' => $tenant->id,
                'payload_sha256' => $challenge['payload_sha256'],
            ]);
        }
    }

    private function fixture(): array
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $actor = User::factory()->create(['is_active' => true, 'default_tenant_id' => $tenant->id]);
        $owner = User::factory()->create(['is_active' => true, 'default_tenant_id' => $tenant->id]);
        $target = User::factory()->create(['is_active' => true, 'default_tenant_id' => $tenant->id]);

        foreach ([$actor, $target] as $member) {
            DB::table('tenant_memberships')->insert([
                'tenant_id' => $tenant->id,
                'user_id' => $member->id,
                'joined_at' => now(),
                'status' => 'active',
                'settings' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        app(TenantContextService::class)->setTenantById((int) $tenant->id);

        Permission::query()->create([
            'name_en' => 'Tenant Membership Manage',
            'slug' => 'tenant.membership.manage',
            'category' => 'tenancy',
            'scope' => 'tenant',
            'tenant_id' => $tenant->id,
            'application_id' => null,
            'is_active' => true,
            'is_system' => true,
        ]);

        $role = Role::query()->create([
            'name_en' => 'Tenant RBAC Requester',
            'slug' => 'tenant-rbac-requester-' . $tenant->id,
            'scope' => 'tenant',
            'tenant_id' => $tenant->id,
            'application_id' => null,
            'is_active' => true,
        ]);
        $role->syncPermissions(['tenant.membership.manage']);
        DB::table('user_has_roles')->insert([
            'user_id' => $actor->id,
            'role_id' => $role->id,
            'assigned_at' => now(),
            'assigned_by' => $actor->id,
        ]);

        $pair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($pair);
        $secretKey = sodium_crypto_sign_secretkey($pair);
        $keyId = 'owner-approval-' . $tenant->id;
        $trustedAt = now()->subMinute();

        $signingKeyId = DB::table('document_signing_keys')->insertGetId([
            'tenant_id' => $tenant->id,
            'key_id' => $keyId,
            'signer_user_id' => $owner->id,
            'algorithm' => 'Ed25519',
            'public_key_base64' => base64_encode($publicKey),
            'trusted_by_user_id' => $owner->id,
            'trust_reference' => 'Owner-approved test trust dossier',
            'trusted_at' => $trustedAt,
            'valid_until' => null,
        ]);

        DB::table('admin_owner_approval_trust_anchors')->insert([
            'tenant_id' => $tenant->id,
            'owner_user_id' => $owner->id,
            'signing_key_id' => $signingKeyId,
            'authority_reference' => 'TEST-OWNER-AUTHORITY',
            'evidence_sha256' => hash('sha256', 'test Owner authority evidence'),
            'trusted_by_user_id' => $owner->id,
            'trusted_at' => $trustedAt,
            'valid_until' => null,
        ]);

        return [$tenant, $actor, $owner, $target, $role, $secretKey];
    }

    private function signedChallenge(
        TenantRoleOwnerApprovalService $service,
        User $actor,
        Tenant $tenant,
        string $secretKey,
        array $input,
    ): array {
        $challenge = $service->createChallenge($actor, (int) $tenant->id, $input);
        $payload = json_decode($challenge['canonical_payload'], true, 512, JSON_THROW_ON_ERROR);
        $signature = sodium_crypto_sign_detached($challenge['canonical_payload'], $secretKey);

        return [
            $challenge,
            [
                'signature' => base64_encode($signature),
                'signed_at' => $payload['issued_at'],
            ],
        ];
    }
}
