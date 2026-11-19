<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Documents\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Documents\Contracts\DocumentWorkflowAuthorizer;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Appends a tenant-scoped signing-key revocation under the same row lock used
 * by signature verification, preventing verification/revocation races.
 */
final class DocumentSigningKeyRevocationService
{
    public function __construct(
        private readonly DocumentWorkflowAuthorizer $authorization,
    ) {}

    public function revoke(
        User $actor,
        int $tenantId,
        int $governanceBodyId,
        int $governancePositionId,
        string $keyId,
        string $reason,
    ): int {
        $reason = trim($reason);
        if ($tenantId < 1 || $governanceBodyId < 1 || $governancePositionId < 1
            || trim($keyId) === '' || mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw new \InvalidArgumentException('A tenant, Governance authority, key identifier and auditable revocation reason are required.');
        }

        $this->authorization->requireGovernedAction(
            $actor,
            $tenantId,
            $governanceBodyId,
            $governancePositionId,
            'documents.sign',
        );

        return DB::transaction(function () use ($actor, $tenantId, $keyId, $reason): int {
            $key = DB::table('document_signing_keys')
                ->where('tenant_id', $tenantId)
                ->where('key_id', $keyId)
                ->lockForUpdate()
                ->first(['id']);

            if ($key === null) {
                throw new NotFoundHttpException('The tenant signing key was not found.');
            }

            $alreadyRevoked = DB::table('document_signing_key_revocations')
                ->where('tenant_id', $tenantId)
                ->where('signing_key_id', $key->id)
                ->exists();
            if ($alreadyRevoked) {
                throw new ConflictHttpException('The signing key has already been revoked.');
            }

            return (int) DB::table('document_signing_key_revocations')->insertGetId([
                'tenant_id' => $tenantId,
                'signing_key_id' => $key->id,
                'revoked_by_user_id' => $actor->getKey(),
                'reason' => $reason,
                'revoked_at' => now(),
            ]);
        });
    }
}
