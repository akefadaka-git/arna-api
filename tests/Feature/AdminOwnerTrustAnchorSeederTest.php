<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\AdminOwnerApprovalTrustAnchorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

final class AdminOwnerTrustAnchorSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_owner_trust_bootstrap_is_idempotent_and_does_not_assign_access(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $owner = User::factory()->create(['is_active' => true]);
        $reviewer = User::factory()->create(['is_active' => true]);

        $pair = sodium_crypto_sign_keypair();
        $trustedAt = now()->subMinute();
        DB::table('document_signing_keys')->insert([
            'tenant_id' => $tenant->id,
            'key_id' => 'explicit-owner-bootstrap-test',
            'signer_user_id' => $owner->id,
            'algorithm' => 'Ed25519',
            'public_key_base64' => base64_encode(sodium_crypto_sign_publickey($pair)),
            'trusted_by_user_id' => $reviewer->id,
            'trust_reference' => 'Independently reviewed test key dossier',
            'trusted_at' => $trustedAt,
            'valid_until' => null,
        ]);

        $values = [
            'ARNA_OWNER_BOOTSTRAP_TENANT_ID' => (string) $tenant->id,
            'ARNA_OWNER_BOOTSTRAP_USER_ID' => (string) $owner->id,
            'ARNA_OWNER_BOOTSTRAP_TRUSTED_BY_USER_ID' => (string) $reviewer->id,
            'ARNA_OWNER_BOOTSTRAP_KEY_ID' => 'explicit-owner-bootstrap-test',
            'ARNA_OWNER_BOOTSTRAP_AUTHORITY_REFERENCE' => 'TEST-OWNER-RESOLUTION-001',
            'ARNA_OWNER_BOOTSTRAP_EVIDENCE_SHA256' => hash('sha256', 'Owner identity and authority evidence'),
        ];
        $previous = [];
        foreach ($values as $name => $value) {
            $previous[$name] = getenv($name);
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        try {
            $beforeRolePivots = DB::table('user_has_roles')->count();
            $beforeDirectGrants = DB::table('user_has_permissions')->count();

            (new AdminOwnerApprovalTrustAnchorSeeder())->run();
            (new AdminOwnerApprovalTrustAnchorSeeder())->run();

            $this->assertDatabaseHas('admin_owner_approval_trust_anchors', [
                'tenant_id' => $tenant->id,
                'owner_user_id' => $owner->id,
                'authority_reference' => 'TEST-OWNER-RESOLUTION-001',
                'trusted_by_user_id' => $reviewer->id,
            ]);
            $this->assertSame(1, DB::table('admin_owner_approval_trust_anchors')->count());
            $this->assertDatabaseHas('security_events', [
                'event_type' => 'admin.owner_approval.trust_anchor.created',
                'tenant_id' => $tenant->id,
                'user_id' => $reviewer->id,
            ]);
            $this->assertSame($beforeRolePivots, DB::table('user_has_roles')->count());
            $this->assertSame($beforeDirectGrants, DB::table('user_has_permissions')->count());
        } finally {
            foreach ($previous as $name => $value) {
                if ($value === false) {
                    putenv($name);
                    unset($_ENV[$name], $_SERVER[$name]);
                } else {
                    putenv($name . '=' . $value);
                    $_ENV[$name] = $value;
                    $_SERVER[$name] = $value;
                }
            }
        }
    }

    public function test_owner_cannot_be_its_own_trust_reviewer(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $owner = User::factory()->create(['is_active' => true]);
        $pair = sodium_crypto_sign_keypair();
        DB::table('document_signing_keys')->insert([
            'tenant_id' => $tenant->id,
            'key_id' => 'self-reviewed-owner-key',
            'signer_user_id' => $owner->id,
            'algorithm' => 'Ed25519',
            'public_key_base64' => base64_encode(sodium_crypto_sign_publickey($pair)),
            'trusted_by_user_id' => $owner->id,
            'trust_reference' => 'Invalid self-reviewed test key',
            'trusted_at' => now()->subMinute(),
            'valid_until' => null,
        ]);

        $values = [
            'ARNA_OWNER_BOOTSTRAP_TENANT_ID' => (string) $tenant->id,
            'ARNA_OWNER_BOOTSTRAP_USER_ID' => (string) $owner->id,
            'ARNA_OWNER_BOOTSTRAP_TRUSTED_BY_USER_ID' => (string) $owner->id,
            'ARNA_OWNER_BOOTSTRAP_KEY_ID' => 'self-reviewed-owner-key',
            'ARNA_OWNER_BOOTSTRAP_AUTHORITY_REFERENCE' => 'TEST-SELF-REVIEW',
            'ARNA_OWNER_BOOTSTRAP_EVIDENCE_SHA256' => hash('sha256', 'invalid'),
        ];
        $previous = [];
        foreach ($values as $name => $value) {
            $previous[$name] = getenv($name);
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        try {
            $this->expectException(\RuntimeException::class);
            (new AdminOwnerApprovalTrustAnchorSeeder())->run();
        } finally {
            foreach ($previous as $name => $value) {
                if ($value === false) {
                    putenv($name);
                    unset($_ENV[$name], $_SERVER[$name]);
                } else {
                    putenv($name . '=' . $value);
                    $_ENV[$name] = $value;
                    $_SERVER[$name] = $value;
                }
            }
        }
    }
}
