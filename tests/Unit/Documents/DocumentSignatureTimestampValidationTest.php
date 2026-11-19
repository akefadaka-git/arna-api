<?php

declare(strict_types=1);

namespace Tests\Unit\Documents;

use Illuminate\Foundation\Testing\RefreshDatabase;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Documents\Services\SodiumDocumentSignatureEvidenceVerifier;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Tests\TestCase;

final class DocumentSignatureTimestampValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_nonexistent_calendar_date_is_rejected_instead_of_normalized(): void
    {
        $signer = User::factory()->create(['is_active' => true]);

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('The signature timestamp is invalid.');

        (new SodiumDocumentSignatureEvidenceVerifier())->verify(
            $signer,
            1,
            'workflow-test',
            str_repeat('a', 64),
            [
                'key_id' => 'not-reached',
                'signature' => base64_encode(str_repeat('x', SODIUM_CRYPTO_SIGN_BYTES)),
                'signed_at' => '2026-02-30T12:00:00Z',
            ],
        );
    }

    public function test_future_signature_timestamp_is_rejected(): void
    {
        $signer = User::factory()->create(['is_active' => true]);
        $future = gmdate('Y-m-d\\TH:i:s\\Z', time() + 3600);

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('A document signature cannot be dated in the future.');

        (new SodiumDocumentSignatureEvidenceVerifier())->verify(
            $signer,
            1,
            'workflow-test',
            str_repeat('a', 64),
            [
                'key_id' => 'not-reached',
                'signature' => base64_encode(str_repeat('x', SODIUM_CRYPTO_SIGN_BYTES)),
                'signed_at' => $future,
            ],
        );
    }
}
