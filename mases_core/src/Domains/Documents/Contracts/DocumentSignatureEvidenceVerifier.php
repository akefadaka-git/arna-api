<?php

declare(strict_types=1);

namespace MaaleArna\\MASES\\Core\\Domains\\Documents\\Contracts;

use MaaleArna\\MASES\\Core\\Auth\\Models\\User;

interface DocumentSignatureEvidenceVerifier
{
    /**
     * Verify a detached Ed25519 signature over the canonical issuance challenge.
     *
     * @param array{key_id:string,signature:string,signed_at:string} $evidence
     * @return array{key_id:int,payload_sha256:string,signature:string,signed_at:string}
     */
    public function verify(
        User $signer,
        int $tenantId,
        string $workflowUuid,
        string $fileSha256,
        array $evidence,
    ): array;
}
