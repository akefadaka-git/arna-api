<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class GovernanceElectionDisputeEvidenceIntegrityService
{
    /**
     * Inspect one evidence object without changing its metadata, verification
     * state, dispute ledger, or storage contents.
     *
     * The result is an operational integrity observation only. It is not a
     * scanner verdict, admissibility decision, or legal verification.
     *
     * @return array{
     *     evidence_id:int,
     *     tenant_id:int,
     *     integrity_state:string,
     *     private_visibility:bool,
     *     expected_sha256:string,
     *     actual_sha256:?string,
     *     expected_byte_size:int,
     *     actual_byte_size:?int
     * }
     */
    public function inspect(int $tenantId, int $evidenceId): array
    {
        if ($tenantId < 1 || $evidenceId < 1) {
            throw new InvalidArgumentException('A positive tenant and evidence identifier are required.');
        }

        $evidence = DB::table('governance_election_dispute_evidence')
            ->where('tenant_id', $tenantId)
            ->where('id', $evidenceId)
            ->first([
                'id',
                'tenant_id',
                'storage_disk',
                'storage_key',
                'byte_size',
                'content_sha256',
            ]);

        if ($evidence === null) {
            throw (new ModelNotFoundException())->setModel('GovernanceElectionDisputeEvidence');
        }

        $base = [
            'evidence_id' => (int) $evidence->id,
            'tenant_id' => (int) $evidence->tenant_id,
            'expected_sha256' => (string) $evidence->content_sha256,
            'actual_sha256' => null,
            'expected_byte_size' => (int) $evidence->byte_size,
            'actual_byte_size' => null,
        ];

        $diskName = (string) $evidence->storage_disk;
        if ($diskName === '' || in_array($diskName, ['public', 's3-public'], true)) {
            return $base + [
                'integrity_state' => 'not_private',
                'private_visibility' => false,
            ];
        }

        $disk = Storage::disk($diskName);

        try {
            if (!$disk->exists((string) $evidence->storage_key)) {
                return $base + [
                    'integrity_state' => 'missing',
                    'private_visibility' => false,
                ];
            }

            $visibility = $disk->getVisibility((string) $evidence->storage_key);
        } catch (Throwable) {
            return $base + [
                'integrity_state' => 'unreadable',
                'private_visibility' => false,
            ];
        }

        if ($visibility !== 'private') {
            return $base + [
                'integrity_state' => 'not_private',
                'private_visibility' => false,
            ];
        }

        try {
            $stream = $disk->readStream((string) $evidence->storage_key);
            if (!is_resource($stream)) {
                throw new RuntimeException('Evidence storage returned no readable stream.');
            }

            $context = hash_init('sha256');
            $actualSize = 0;

            try {
                while (!feof($stream)) {
                    $chunk = fread($stream, 8192);
                    if ($chunk === false) {
                        throw new RuntimeException('Evidence storage stream read failed.');
                    }

                    if ($chunk !== '') {
                        $actualSize += strlen($chunk);
                        hash_update($context, $chunk);
                    }
                }
            } finally {
                fclose($stream);
            }

            $actualHash = hash_final($context);
        } catch (Throwable) {
            return $base + [
                'integrity_state' => 'unreadable',
                'private_visibility' => true,
            ];
        }

        $isIntact = $actualSize === (int) $evidence->byte_size
            && hash_equals((string) $evidence->content_sha256, $actualHash);

        return [
            ...$base,
            'integrity_state' => $isIntact ? 'intact' : 'mismatch',
            'private_visibility' => true,
            'actual_sha256' => $actualHash,
            'actual_byte_size' => $actualSize,
        ];
    }
}
