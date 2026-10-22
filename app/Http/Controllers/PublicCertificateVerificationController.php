<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use MaaleArna\MASES\Core\Domains\Shareholder\Enums\CertificateStatus;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareCertificate;

/**
 * Public, read-only verification of issued share certificates.
 *
 * Only the certificate reference, lifecycle-derived verification status,
 * document type, and issue timestamp are returned. Shareholder identity,
 * holdings, tenant data, and internal record IDs are never disclosed.
 */
final class PublicCertificateVerificationController
{
    public function verify(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reference' => ['required', 'string', 'max:64'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_FAILED',
                    'message' => 'A certificate reference of at most 64 characters is required.',
                ],
            ], 422);
        }

        $reference = trim((string) $validator->validated()['reference']);

        if ($reference === '') {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_FAILED',
                    'message' => 'A certificate reference is required.',
                ],
            ], 422);
        }

        // Include soft-deleted records so a deleted issued certificate can
        // never be misrepresented as unknown or currently valid.
        $certificate = ShareCertificate::withTrashed()
            ->where('certificate_number', $reference)
            ->first();

        $status = 'invalid';
        $issuedAt = null;

        if ($certificate !== null) {
            $issuedAt = $certificate->issued_at?->toIso8601String();

            if (
                $certificate->trashed()
                || $certificate->status === CertificateStatus::Cancelled
                || $certificate->status === CertificateStatus::Replaced
            ) {
                $status = 'revoked';
            } elseif (
                $certificate->status === CertificateStatus::Issued
                && $issuedAt !== null
            ) {
                // Fail closed for inconsistent legacy rows: "issued" without
                // an issuance timestamp is insufficient public evidence.
                $status = 'verified';
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'reference' => $reference,
                'status' => $status,
                'documentType' => 'Share certificate',
                'issuedAt' => $status === 'verified' ? $issuedAt : null,
            ],
        ]);
    }
}
