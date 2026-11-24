<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Security\Services\SecurityEventService;
use RuntimeException;

/**
 * Explicit, opt-in Owner approval trust-anchor bootstrap.
 *
 * This seeder never creates an Owner identity, signing key, password,
 * user-role pivot, or direct permission grant. It only binds an already
 * verified active Owner account to an already provisioned Ed25519 key.
 *
 * Invoke explicitly after out-of-band identity, authority, and key-custody
 * verification. It is intentionally not called by DatabaseSeeder.
 */
final class AdminOwnerApprovalTrustAnchorSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = $this->requiredPositiveInt('ARNA_OWNER_BOOTSTRAP_TENANT_ID');
        $ownerUserId = $this->requiredPositiveInt('ARNA_OWNER_BOOTSTRAP_USER_ID');
        $trustedByUserId = $this->requiredPositiveInt('ARNA_OWNER_BOOTSTRAP_TRUSTED_BY_USER_ID');
        $keyId = trim((string) env('ARNA_OWNER_BOOTSTRAP_KEY_ID', ''));
        $authorityReference = trim((string) env('ARNA_OWNER_BOOTSTRAP_AUTHORITY_REFERENCE', ''));
        $evidenceSha256 = strtolower(trim((string) env('ARNA_OWNER_BOOTSTRAP_EVIDENCE_SHA256', '')));

        if ($keyId === '' || mb_strlen($keyId) > 100
            || $authorityReference === '' || mb_strlen($authorityReference) > 255
            || !preg_match('/^[a-f0-9]{64}$/', $evidenceSha256)) {
            throw new RuntimeException(
                'Owner bootstrap requires a key ID, authority reference, and 64-character lowercase SHA-256 evidence digest.'
            );
        }
        if ($trustedByUserId === $ownerUserId) {
            throw new RuntimeException('Owner signing-key trust must be independently reviewed; the Owner cannot be their own trust reviewer.');
        }

        DB::transaction(function () use (
            $tenantId,
            $ownerUserId,
            $trustedByUserId,
            $keyId,
            $authorityReference,
            $evidenceSha256,
        ): void {
            $tenant = DB::table('tenants')
                ->where('id', $tenantId)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();
            if ($tenant === null) {
                throw new RuntimeException('The explicitly selected active tenant does not exist.');
            }

            $owner = User::query()->whereKey($ownerUserId)->where('is_active', true)->exists();
            $reviewer = User::query()->whereKey($trustedByUserId)->where('is_active', true)->exists();
            if (!$owner || !$reviewer) {
                throw new RuntimeException('The explicitly selected Owner and independent trust reviewer must both be active accounts.');
            }

            $key = DB::table('document_signing_keys')
                ->where('tenant_id', $tenantId)
                ->where('key_id', $keyId)
                ->where('signer_user_id', $ownerUserId)
                ->where('trusted_by_user_id', $trustedByUserId)
                ->where('algorithm', 'Ed25519')
                ->where('trusted_at', '<=', now())
                ->where(function ($query): void {
                    $query->whereNull('valid_until')->orWhere('valid_until', '>=', now());
                })
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')->from('document_signing_key_revocations')
                        ->whereColumn('document_signing_key_revocations.signing_key_id', 'document_signing_keys.id')
                        ->whereColumn('document_signing_key_revocations.tenant_id', 'document_signing_keys.tenant_id');
                })
                ->lockForUpdate()
                ->first(['id', 'valid_until']);

            if ($key === null) {
                throw new RuntimeException('The Owner key must already exist, be Ed25519, be trusted by the independent reviewer, be currently valid, and not be revoked.');
            }

            $existing = DB::table('admin_owner_approval_trust_anchors')
                ->where('tenant_id', $tenantId)
                ->where('owner_user_id', $ownerUserId)
                ->where('signing_key_id', $key->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $revoked = DB::table('admin_owner_approval_trust_anchor_revocations')
                    ->where('tenant_id', $tenantId)
                    ->where('trust_anchor_id', $existing->id)
                    ->exists();

                if ($revoked
                    || $existing->authority_reference !== $authorityReference
                    || $existing->evidence_sha256 !== $evidenceSha256
                    || (int) $existing->trusted_by_user_id !== $trustedByUserId) {
                    throw new RuntimeException('A conflicting or revoked Owner trust anchor already exists; append-only trust evidence cannot be overwritten.');
                }

                return;
            }

            DB::table('admin_owner_approval_trust_anchors')->insert([
                'tenant_id' => $tenantId,
                'owner_user_id' => $ownerUserId,
                'signing_key_id' => $key->id,
                'authority_reference' => $authorityReference,
                'evidence_sha256' => $evidenceSha256,
                'trusted_by_user_id' => $trustedByUserId,
                'trusted_at' => now(),
                'valid_until' => $key->valid_until,
            ]);

            $event = app(SecurityEventService::class)->log(
                'admin.owner_approval.trust_anchor.created',
                [
                    'tenant_id' => $tenantId,
                    'user_id' => $trustedByUserId,
                    'actor_user_id' => $trustedByUserId,
                    'owner_user_id' => $ownerUserId,
                    'signing_key_id' => $key->id,
                    'key_id' => $keyId,
                    'authority_reference' => $authorityReference,
                    'evidence_sha256' => $evidenceSha256,
                ],
                'warning',
                'An independently reviewed Owner approval trust anchor was provisioned.',
            );

            if ($event === null) {
                throw new RuntimeException('Owner trust-anchor provisioning was rolled back because mandatory security evidence could not be persisted.');
            }
        }, 3);
    }

    private function requiredPositiveInt(string $name): int
    {
        $value = filter_var(env($name), FILTER_VALIDATE_INT);
        if ($value === false || $value < 1) {
            throw new RuntimeException("Missing or invalid required Owner bootstrap setting: {$name}.");
        }

        return $value;
    }
}
