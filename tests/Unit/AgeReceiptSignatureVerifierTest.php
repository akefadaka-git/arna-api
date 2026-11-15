<?php

declare(strict_types=1);

namespace Tests\Unit;

use MaaleArna\MASES\Core\Domains\Governance\Security\AgeReceiptCanonicalizer;
use MaaleArna\MASES\Core\Domains\Governance\Security\AgeReceiptSignatureVerifier;
use Tests\TestCase;

final class AgeReceiptSignatureVerifierTest extends TestCase
{
    /** @var array<string,string> */
    private array $receiptKeys = [];

    /** @var array<string,string> */
    private array $witnessKeys = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->receiptKeys = sodium_crypto_sign_keypair() ? [] : [];
    }

    public function test_receipt_and_witness_signatures_require_separate_keys_and_domains(): void
    {
        $receiptPair = sodium_crypto_sign_keypair();
        $witnessPair = sodium_crypto_sign_keypair();
        $receiptSecret = sodium_crypto_sign_secretkey($receiptPair);
        $witnessSecret = sodium_crypto_sign_secretkey($witnessPair);
        $receiptPublic = sodium_crypto_sign_publickey($receiptPair);
        $witnessPublic = sodium_crypto_sign_publickey($witnessPair);

        config(['age.receipts.trust_bundle' => [
            'version' => '1.0.0',
            'keys' => [
                'receipt-key-v1' => $this->key($receiptPublic, 'receipt'),
                'witness-key-v1' => $this->key($witnessPublic, 'witness'),
            ],
        ]]);

        $receipt = [
            'key_id' => 'receipt-key-v1',
            'stream_id' => '8f7f7b15-1f4c-4a67-8f95-8d5a5d2e0001',
            'sequence' => 1,
            'event_hash' => str_repeat('a', 64),
            'signed_at' => '2026-11-15T10:00:00Z',
        ];
        $receipt['signature'] = $this->sign($receipt, "arna:age:external-receipt:receipt:v1\0", $receiptSecret);

        $witness = [
            'key_id' => 'witness-key-v1',
            'stream_id' => $receipt['stream_id'],
            'sequence' => 1,
            'receipt_hash' => str_repeat('b', 64),
            'witnessed_at' => '2026-11-15T10:00:01Z',
        ];
        $witness['signature'] = $this->sign($witness, "arna:age:external-receipt:witness:v1\0", $witnessSecret);

        $verifier = new AgeReceiptSignatureVerifier();
        self::assertTrue($verifier->verifyReceipt($receipt));
        self::assertTrue($verifier->verifyWitnessCheckpoint($witness));
        self::assertFalse($verifier->verifyReceipt($witness));
        self::assertFalse($verifier->verifyWitnessCheckpoint($receipt));
    }

    public function test_revoked_or_wrong_purpose_key_fails_closed(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($pair);
        $public = sodium_crypto_sign_publickey($pair);
        config(['age.receipts.trust_bundle' => [
            'version' => '1.0.0',
            'keys' => [
                'revoked-receipt' => [...$this->key($public, 'receipt'), 'status' => 'revoked', 'revoked_at' => '2026-11-14T00:00:00Z'],
                'witness-only' => $this->key($public, 'witness'),
            ],
        ]]);

        $receipt = [
            'key_id' => 'revoked-receipt',
            'stream_id' => '8f7f7b15-1f4c-4a67-8f95-8d5a5d2e0001',
            'sequence' => 1,
            'event_hash' => str_repeat('a', 64),
            'signed_at' => '2026-11-15T10:00:00Z',
        ];
        $receipt['signature'] = $this->sign($receipt, "arna:age:external-receipt:receipt:v1\0", $secret);

        $verifier = new AgeReceiptSignatureVerifier();
        self::assertFalse($verifier->verifyReceipt($receipt));
        $receipt['key_id'] = 'witness-only';
        self::assertFalse($verifier->verifyReceipt($receipt));
    }

    public function test_tampered_payload_and_unknown_key_are_rejected(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($pair);
        $public = sodium_crypto_sign_publickey($pair);
        config(['age.receipts.trust_bundle' => [
            'version' => '1.0.0',
            'keys' => ['receipt-key-v1' => $this->key($public, 'receipt')],
        ]]);
        $receipt = [
            'key_id' => 'receipt-key-v1',
            'stream_id' => '8f7f7b15-1f4c-4a67-8f95-8d5a5d2e0001',
            'sequence' => 1,
            'event_hash' => str_repeat('a', 64),
            'signed_at' => '2026-11-15T10:00:00Z',
        ];
        $receipt['signature'] = $this->sign($receipt, "arna:age:external-receipt:receipt:v1\0", $secret);
        $verifier = new AgeReceiptSignatureVerifier();
        self::assertTrue($verifier->verifyReceipt($receipt));
        $receipt['event_hash'] = str_repeat('c', 64);
        self::assertFalse($verifier->verifyReceipt($receipt));
        $receipt['key_id'] = 'unknown';
        self::assertFalse($verifier->verifyReceipt($receipt));
    }

    /** @return array<string,mixed> */
    private function key(string $public, string $purpose): array
    {
        return [
            'purpose' => $purpose,
            'status' => 'active',
            'public_key_base64' => base64_encode($public),
            'valid_from' => '2026-01-01T00:00:00Z',
            'valid_until' => null,
            'revoked_at' => null,
        ];
    }

    /** @param array<string,mixed> $payload */
    private function sign(array $payload, string $domain, string $secret): string
    {
        unset($payload['signature']);
        return base64_encode(sodium_crypto_sign_detached($domain . AgeReceiptCanonicalizer::encode($payload), $secret));
    }
}
