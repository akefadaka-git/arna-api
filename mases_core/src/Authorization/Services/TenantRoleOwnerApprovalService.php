<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Authorization\Services;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Authorization\Models\Role;
use MaaleArna\MASES\Core\Security\Services\SecurityEventService;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class TenantRoleOwnerApprovalService
{
    public const ACTION_ASSIGN = 'tenant.role.assign';
    public const ACTION_REVOKE = 'tenant.role.revoke';
    public const ACTION_SYNC_PERMISSIONS = 'tenant.role.permissions.sync';

    private const CHALLENGE_TTL_MINUTES = 10;
    private const CONTRACT = 'ARNA-ADMIN-OWNER-APPROVAL-V1';

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly SecurityEventService $securityEvents,
    ) {
    }

    /**
     * Create an immutable, one-use challenge. This does not change access.
     *
     * @param array{owner_user_id:int,action:string,role_id:int,target_user_id?:int|null,permission_slugs?:array,reason:string} $input
     * @return array{challenge_id:string,key_id:string,canonical_payload:string,payload_sha256:string,expires_at:string}
     */
    public function createChallenge(User $actor, int $tenantId, array $input): array
    {
        $this->assertTenantAndActor($actor, $tenantId);

        $ownerId = (int) ($input['owner_user_id'] ?? 0);
        $roleId = (int) ($input['role_id'] ?? 0);
        $action = (string) ($input['action'] ?? '');
        $targetId = isset($input['target_user_id']) ? (int) $input['target_user_id'] : null;
        $reason = trim((string) ($input['reason'] ?? ''));
        $slugs = $this->normalizeSlugs($input['permission_slugs'] ?? []);

        if (!in_array($action, [self::ACTION_ASSIGN, self::ACTION_REVOKE, self::ACTION_SYNC_PERMISSIONS], true)) {
            throw ValidationException::withMessages(['action' => ['The requested tenant RBAC action is not supported.']]);
        }
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => ['A reason of 1–500 characters is required.']]);
        }
        if ($ownerId === (int) $actor->getKey()) {
            throw new AuthorizationException('The requester cannot approve their own consequential tenant RBAC request.');
        }
        if (($action === self::ACTION_SYNC_PERMISSIONS && $targetId !== null)
            || ($action !== self::ACTION_SYNC_PERMISSIONS && $targetId === null)
            || ($action !== self::ACTION_SYNC_PERMISSIONS && $slugs !== [])) {
            throw ValidationException::withMessages(['action' => ['The target user and permission set do not match the requested action.']]);
        }

        return DB::transaction(function () use ($actor, $tenantId, $ownerId, $roleId, $action, $targetId, $reason, $slugs): array {
            $role = Role::query()
                ->whereKey($roleId)
                ->where('scope', 'tenant')
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->first();

            if (!$role || !$role->is_active) {
                throw new NotFoundHttpException('An active tenant-scoped role was not found in the validated tenant.');
            }

            if ($action === self::ACTION_ASSIGN || $action === self::ACTION_REVOKE) {
                $target = User::query()->whereKey($targetId)->where('is_active', true)->lockForUpdate()->first();
                if (!$target || !DB::table('tenant_memberships')
                    ->where('tenant_id', $tenantId)
                    ->where('user_id', $targetId)
                    ->where('status', 'active')
                    ->exists()) {
                    throw ValidationException::withMessages(['target_user_id' => ['The target must be an active user with active membership in this tenant.']]);
                }

                $assigned = DB::table('user_has_roles')
                    ->where('user_id', $targetId)
                    ->where('role_id', $roleId)
                    ->exists();

                if (($action === self::ACTION_ASSIGN && $assigned)
                    || ($action === self::ACTION_REVOKE && !$assigned)) {
                    throw new ConflictHttpException('The requested role assignment state already exists.');
                }
            } else {
                $this->assertPermissionSetCompatible($role, $tenantId, $slugs);
            }

            $owner = User::query()->whereKey($ownerId)->where('is_active', true)->exists();
            if (!$owner) {
                throw ValidationException::withMessages(['owner_user_id' => ['The selected Owner approver must be an active account.']]);
            }

            $now = now()->utc();
            $anchor = $this->activeOwnerAnchor($tenantId, $ownerId, $now->toDateTimeImmutable(), true);
            if ($anchor === null) {
                throw new AuthorizationException('No active, independently provisioned Owner approval trust anchor exists for this tenant and Owner account.');
            }

            $challengeId = (string) Str::uuid();
            $nonce = bin2hex(random_bytes(32));
            $issuedAt = $now->format('Y-m-d\TH:i:s\Z');
            $expiresAt = $now->copy()->addMinutes(self::CHALLENGE_TTL_MINUTES)->format('Y-m-d\TH:i:s\Z');
            $permissionHash = hash('sha256', json_encode($slugs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            $payload = self::canonicalPayload([
                'challenge_id' => $challengeId,
                'tenant_id' => $tenantId,
                'action' => $action,
                'requested_by_user_id' => (int) $actor->getKey(),
                'owner_user_id' => $ownerId,
                'key_id' => (string) $anchor->key_id,
                'target_user_id' => $targetId,
                'role_id' => $roleId,
                'role_slug' => (string) $role->slug,
                'permission_slugs' => $slugs,
                'permission_set_sha256' => $permissionHash,
                'reason' => $reason,
                'nonce' => $nonce,
                'issued_at' => $issuedAt,
                'expires_at' => $expiresAt,
            ]);
            $payloadHash = hash('sha256', $payload);

            DB::table('admin_owner_approval_challenges')->insert([
                'challenge_uuid' => $challengeId,
                'tenant_id' => $tenantId,
                'requested_by_user_id' => $actor->getKey(),
                'owner_user_id' => $ownerId,
                'target_user_id' => $targetId,
                'role_id' => $roleId,
                'signing_key_id' => $anchor->signing_key_id,
                'key_id' => $anchor->key_id,
                'action' => $action,
                'role_slug' => $role->slug,
                'permission_slugs' => json_encode($slugs, JSON_THROW_ON_ERROR),
                'permission_set_sha256' => $permissionHash,
                'reason' => $reason,
                'nonce' => $nonce,
                'canonical_payload' => $payload,
                'payload_sha256' => $payloadHash,
                'issued_at' => $now->toDateTimeString(),
                'expires_at' => $now->copy()->addMinutes(self::CHALLENGE_TTL_MINUTES)->toDateTimeString(),
            ]);

            $this->requireSecurityEvent($this->securityEvents->log(
                'tenant.rbac.approval.challenge_created',
                [
                    'tenant_id' => $tenantId,
                    'user_id' => $actor->getKey(),
                    'actor_user_id' => $actor->getKey(),
                    'owner_user_id' => $ownerId,
                    'role_id' => $roleId,
                    'action' => $action,
                    'challenge_id' => $challengeId,
                    'payload_sha256' => $payloadHash,
                    'reason' => $reason,
                ],
                'warning',
                'Owner approval challenge created; no access mutation has occurred.',
            ));

            return [
                'challenge_id' => $challengeId,
                'key_id' => (string) $anchor->key_id,
                'canonical_payload' => $payload,
                'payload_sha256' => $payloadHash,
                'expires_at' => $expiresAt,
            ];
        }, 3);
    }

    /**
     * Verify Owner Ed25519 evidence and consume the challenge exactly once.
     *
     * @return array{action:string,role_id:int,target_user_id:int|null,challenge_id:string,owner_user_id:int}
     */
    public function execute(User $actor, int $tenantId, string $challengeId, array $evidence): array
    {
        $this->assertTenantAndActor($actor, $tenantId);
        if (!extension_loaded('sodium')) {
            throw new RuntimeException('The sodium extension is required for Owner approval verification.');
        }

        $signatureEncoded = (string) ($evidence['signature'] ?? '');

        return DB::transaction(function () use ($actor, $tenantId, $challengeId, $signatureEncoded): array {
            $challenge = DB::table('admin_owner_approval_challenges')
                ->where('tenant_id', $tenantId)
                ->where('challenge_uuid', $challengeId)
                ->where('requested_by_user_id', $actor->getKey())
                ->lockForUpdate()
                ->first();

            if ($challenge === null) {
                throw new NotFoundHttpException('The approval challenge was not found for this actor and tenant.');
            }
            if (DB::table('admin_owner_approval_consumptions')
                ->where('tenant_id', $tenantId)
                ->where('challenge_id', $challenge->id)
                ->exists()) {
                throw new ConflictHttpException('The Owner approval challenge has already been consumed.');
            }

            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $issuedAt = new DateTimeImmutable((string) $challenge->issued_at, new DateTimeZone('UTC'));
            $expiresAt = new DateTimeImmutable((string) $challenge->expires_at, new DateTimeZone('UTC'));
            if ($now < $issuedAt || $now > $expiresAt) {
                throw new AuthorizationException('The Owner approval challenge is not currently within its validity interval.');
            }

            if (!User::query()->whereKey($challenge->owner_user_id)->where('is_active', true)->exists()) {
                throw new AuthorizationException('The Owner approver account is no longer active.');
            }

            $anchor = $this->activeOwnerAnchor(
                $tenantId,
                (int) $challenge->owner_user_id,
                $now,
                true,
                (int) $challenge->signing_key_id,
            );
            if ($anchor === null || (string) $anchor->key_id !== (string) $challenge->key_id) {
                throw new AuthorizationException('The Owner approval trust anchor is missing, revoked, expired, or mismatched.');
            }

            $storedPayload = (string) $challenge->canonical_payload;
            if (!hash_equals((string) $challenge->payload_sha256, hash('sha256', $storedPayload))) {
                throw new AuthorizationException('The stored approval challenge payload failed its integrity check.');
            }

            $slugs = json_decode((string) $challenge->permission_slugs, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($slugs) || !hash_equals(
                (string) $challenge->permission_set_sha256,
                hash('sha256', json_encode($slugs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            )) {
                throw new AuthorizationException('The approved permission set failed its integrity check.');
            }

            $signature = base64_decode($signatureEncoded, true);
            $publicKey = base64_decode((string) $anchor->public_key_base64, true);
            if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
                || $publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                throw ValidationException::withMessages(['signature' => ['The Ed25519 signature or public key encoding is invalid.']]);
            }
            if (!sodium_crypto_sign_verify_detached($signature, $storedPayload, $publicKey)) {
                throw new AuthorizationException('The Owner Ed25519 signature does not verify against this exact challenge.');
            }

            $result = $this->applyApprovedMutation($actor, $tenantId, $challenge, $slugs, (int) $anchor->signing_key_id);

            DB::table('admin_owner_approval_consumptions')->insert([
                'tenant_id' => $tenantId,
                'challenge_id' => $challenge->id,
                'requested_by_user_id' => $actor->getKey(),
                'owner_user_id' => $challenge->owner_user_id,
                'signing_key_id' => $anchor->signing_key_id,
                'action' => $challenge->action,
                'signature_base64' => $signatureEncoded,
                'verified_at' => $now->format('Y-m-d H:i:s'),
                'payload_sha256' => $challenge->payload_sha256,
            ]);

            $this->requireSecurityEvent($this->securityEvents->log(
                'tenant.rbac.owner_approval.consumed',
                [
                    'tenant_id' => $tenantId,
                    'user_id' => $actor->getKey(),
                    'actor_user_id' => $actor->getKey(),
                    'owner_user_id' => $challenge->owner_user_id,
                    'target_user_id' => $challenge->target_user_id,
                    'role_id' => $challenge->role_id,
                    'action' => $challenge->action,
                    'challenge_id' => $challenge->challenge_uuid,
                    'payload_sha256' => $challenge->payload_sha256,
                    'reason' => $challenge->reason,
                ],
                'warning',
                'Owner-signed tenant RBAC mutation was verified and committed.',
            ));

            return $result;
        }, 3);
    }

    public static function canonicalPayload(array $fields): string
    {
        return json_encode([
            'contract' => self::CONTRACT,
            'challenge_id' => $fields['challenge_id'],
            'tenant_id' => (int) $fields['tenant_id'],
            'action' => $fields['action'],
            'requested_by_user_id' => (int) $fields['requested_by_user_id'],
            'owner_user_id' => (int) $fields['owner_user_id'],
            'key_id' => $fields['key_id'],
            'target_user_id' => $fields['target_user_id'] === null ? null : (int) $fields['target_user_id'],
            'role_id' => (int) $fields['role_id'],
            'role_slug' => $fields['role_slug'],
            'permission_slugs' => array_values($fields['permission_slugs']),
            'permission_set_sha256' => $fields['permission_set_sha256'],
            'reason' => $fields['reason'],
            'nonce' => $fields['nonce'],
            'issued_at' => $fields['issued_at'],
            'expires_at' => $fields['expires_at'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function applyApprovedMutation(User $actor, int $tenantId, object $challenge, array $slugs, int $signingKeyId): array
    {
        $role = Role::query()
            ->whereKey($challenge->role_id)
            ->where('scope', 'tenant')
            ->where('tenant_id', $tenantId)
            ->lockForUpdate()
            ->first();

        if (!$role || !$role->is_active || (string) $role->slug !== (string) $challenge->role_slug) {
            throw new AuthorizationException('The role no longer matches the Owner-approved tenant role identity.');
        }

        $targetId = $challenge->target_user_id === null ? null : (int) $challenge->target_user_id;
        if ($challenge->action === self::ACTION_ASSIGN || $challenge->action === self::ACTION_REVOKE) {
            $target = User::query()->whereKey($targetId)->where('is_active', true)->lockForUpdate()->first();
            if (!$target || !DB::table('tenant_memberships')->where('tenant_id', $tenantId)
                ->where('user_id', $targetId)->where('status', 'active')->exists()) {
                throw new AuthorizationException('The target user is no longer an active member of the approved tenant.');
            }

            $pivot = DB::table('user_has_roles')->where('user_id', $targetId)->where('role_id', $role->id);
            if ($challenge->action === self::ACTION_ASSIGN) {
                if ($pivot->exists()) {
                    throw new ConflictHttpException('The role is already assigned; the approved assignment cannot be replayed.');
                }
                DB::table('user_has_roles')->insert([
                    'user_id' => $targetId,
                    'role_id' => $role->id,
                    'assigned_at' => now(),
                    'assigned_by' => $actor->getKey(),
                ]);
            } else {
                if (!$pivot->exists()) {
                    throw new ConflictHttpException('The role is no longer assigned; the approved revocation cannot be applied.');
                }
                $pivot->delete();
            }

            $this->authorization->clearUserTenantCache($target, $tenantId);
        } elseif ($challenge->action === self::ACTION_SYNC_PERMISSIONS) {
            $role->syncPermissions($slugs);
        } else {
            throw new AuthorizationException('The Owner-approved action is not supported.');
        }

        $this->requireSecurityEvent($this->securityEvents->log(
            'tenant.rbac.' . str_replace('.', '_', (string) $challenge->action),
            [
                'tenant_id' => $tenantId,
                'user_id' => $actor->getKey(),
                'actor_user_id' => $actor->getKey(),
                'owner_user_id' => $challenge->owner_user_id,
                'target_user_id' => $targetId,
                'role_id' => $role->id,
                'role_slug' => $role->slug,
                'action' => $challenge->action,
                'permission_slugs' => $slugs,
                'challenge_id' => $challenge->challenge_uuid,
                'owner_signing_key_id' => $signingKeyId,
                'reason' => $challenge->reason,
            ],
            'warning',
            'Owner-approved tenant RBAC mutation committed.',
        ));

        return [
            'action' => (string) $challenge->action,
            'role_id' => (int) $role->id,
            'target_user_id' => $targetId,
            'challenge_id' => (string) $challenge->challenge_uuid,
            'owner_user_id' => (int) $challenge->owner_user_id,
        ];
    }

    private function activeOwnerAnchor(
        int $tenantId,
        int $ownerId,
        DateTimeImmutable $at,
        bool $lock,
        ?int $signingKeyId = null,
    ): ?object {
        $query = DB::table('admin_owner_approval_trust_anchors as anchor')
            ->join('document_signing_keys as signing_key', function ($join): void {
                $join->on('signing_key.id', '=', 'anchor.signing_key_id')
                    ->on('signing_key.tenant_id', '=', 'anchor.tenant_id');
            })
            ->where('anchor.tenant_id', $tenantId)
            ->where('anchor.owner_user_id', $ownerId)
            ->where('signing_key.signer_user_id', $ownerId)
            ->whereColumn('anchor.trusted_by_user_id', '<>', 'anchor.owner_user_id')
            ->whereColumn('signing_key.trusted_by_user_id', '=', 'anchor.trusted_by_user_id')
            ->whereNotNull('anchor.authority_reference')
            ->where('anchor.authority_reference', '<>', '')
            ->whereNotNull('anchor.evidence_sha256')
            ->where('signing_key.algorithm', 'Ed25519')
            ->where('anchor.trusted_at', '<=', $at->format('Y-m-d H:i:s'))
            ->where(function ($query) use ($at): void {
                $query->whereNull('anchor.valid_until')->orWhere('anchor.valid_until', '>=', $at->format('Y-m-d H:i:s'));
            })
            ->where('signing_key.trusted_at', '<=', $at->format('Y-m-d H:i:s'))
            ->where(function ($query) use ($at): void {
                $query->whereNull('signing_key.valid_until')->orWhere('signing_key.valid_until', '>=', $at->format('Y-m-d H:i:s'));
            })
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('admin_owner_approval_trust_anchor_revocations')
                    ->whereColumn('admin_owner_approval_trust_anchor_revocations.trust_anchor_id', 'anchor.id')
                    ->whereColumn('admin_owner_approval_trust_anchor_revocations.tenant_id', 'anchor.tenant_id');
            })
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('document_signing_key_revocations')
                    ->whereColumn('document_signing_key_revocations.signing_key_id', 'signing_key.id')
                    ->whereColumn('document_signing_key_revocations.tenant_id', 'signing_key.tenant_id');
            });

        if ($signingKeyId !== null) {
            $query->where('anchor.signing_key_id', $signingKeyId);
        }
        if ($lock) {
            $query->lockForUpdate();
        }

        $anchor = $query->orderBy('anchor.id')->first([
            'anchor.id as trust_anchor_id',
            'anchor.signing_key_id',
            'signing_key.key_id',
            'signing_key.public_key_base64',
            'anchor.authority_reference',
            'anchor.evidence_sha256',
        ]);

        if ($anchor === null || !preg_match('/^[a-f0-9]{64}$/', (string) $anchor->evidence_sha256)) {
            return null;
        }

        return $anchor;
    }

    private function assertTenantAndActor(User $actor, int $tenantId): void
    {
        if ($tenantId < 1 || !function_exists('mases_tenant_id') || (int) mases_tenant_id() !== $tenantId) {
            throw new AuthorizationException('A matching server-validated tenant context is required.');
        }
        if (!$actor->is_active || !$this->authorization->hasPermission('tenant.membership.manage', $actor, $tenantId)) {
            throw new AuthorizationException('The actor lacks the tenant Admin approval-request capability.');
        }
        if (!DB::table('tenant_memberships')->where('tenant_id', $tenantId)
            ->where('user_id', $actor->getKey())->where('status', 'active')->exists()) {
            throw new AuthorizationException('The actor must hold active membership in the validated tenant.');
        }
    }

    private function normalizeSlugs(mixed $slugs): array
    {
        if (!is_array($slugs) || count($slugs) > 250) {
            throw ValidationException::withMessages(['permission_slugs' => ['The permission set must be an array containing at most 250 slugs.']]);
        }
        foreach ($slugs as $slug) {
            if (!is_string($slug) || trim($slug) === '' || mb_strlen($slug) > 150) {
                throw ValidationException::withMessages(['permission_slugs' => ['Each permission must be a non-empty canonical slug.']]);
            }
        }
        $slugs = array_values(array_unique(array_map('trim', $slugs)));
        sort($slugs, SORT_STRING);
        return $slugs;
    }

    private function assertPermissionSetCompatible(Role $role, int $tenantId, array $slugs): void
    {
        $permissions = Permission::query()->whereIn('slug', $slugs)->get()->keyBy('slug');
        if ($permissions->count() !== count($slugs)) {
            throw ValidationException::withMessages(['permission_slugs' => ['Every requested permission must exist in the canonical permission catalogue.']]);
        }
        foreach ($permissions as $permission) {
            if ($permission->scope === 'tenant' && (string) $permission->tenant_id !== (string) $tenantId) {
                throw ValidationException::withMessages(['permission_slugs' => ['A tenant role cannot receive a permission bound to another tenant.']]);
            }
            if ($permission->scope === 'application' && $permission->tenant_id !== null
                && (string) $permission->tenant_id !== (string) $tenantId) {
                throw ValidationException::withMessages(['permission_slugs' => ['An application permission is bound to another tenant.']]);
            }
        }
    }

    private function requireSecurityEvent(mixed $event): void
    {
        if ($event === null) {
            throw new RuntimeException('The tenant RBAC mutation was rolled back because mandatory security evidence could not be persisted.');
        }
    }
}
