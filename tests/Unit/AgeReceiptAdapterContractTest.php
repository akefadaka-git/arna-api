<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use MaaleArna\MASES\Core\Domains\Governance\Security\AgeReceiptCanonicalizer;
use MaaleArna\MASES\Core\Domains\Governance\Services\Receipts\AgeReceiptUnavailable;
use MaaleArna\MASES\Core\Domains\Governance\Services\Receipts\UnconfiguredAgeReceiptGateway;
use Tests\TestCase;

final class AgeReceiptAdapterContractTest extends TestCase
{
    public function test_event_canonicalization_is_independent_of_object_key_order(): void
    {
        $first = [
            'schema_version' => '1.0.0',
            'event_type' => 'register.prepared',
            'scope' => ['tenant_ref' => 'tenant-opaque-0001', 'election_ref' => 'election-opaque-0001'],
        ];
        $second = [
            'scope' => ['election_ref' => 'election-opaque-0001', 'tenant_ref' => 'tenant-opaque-0001'],
            'event_type' => 'register.prepared',
            'schema_version' => '1.0.0',
        ];

        self::assertSame(AgeReceiptCanonicalizer::encode($first), AgeReceiptCanonicalizer::encode($second));
        self::assertSame(AgeReceiptCanonicalizer::eventHash($first), AgeReceiptCanonicalizer::eventHash($second));
    }

    public function test_event_canonicalization_preserves_list_order_and_unicode_values(): void
    {
        $event = ['event_type' => 'register.prepared', 'labels' => ['Maale', 'አርና']];

        self::assertSame(
            '{"event_type":"register.prepared","labels":["Maale","አርና"]}',
            AgeReceiptCanonicalizer::encode($event),
        );
        self::assertSame(64, strlen(AgeReceiptCanonicalizer::eventHash($event)));
    }

    public function test_event_canonicalization_rejects_floating_point_values(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AgeReceiptCanonicalizer::encode(['voting_weight' => 1.25]);
    }

    public function test_event_canonicalization_rejects_non_ascii_object_property_names(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AgeReceiptCanonicalizer::encode(['ክስተት' => 'register.prepared']);
    }

    public function test_event_canonicalization_rejects_integers_outside_json_safe_range(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AgeReceiptCanonicalizer::encode(['sequence' => PHP_INT_MAX]);
    }

    public function test_unconfigured_gateway_fails_closed_for_append_and_witness_challenge(): void
    {
        $gateway = new UnconfiguredAgeReceiptGateway();

        try {
            $gateway->append([]);
            self::fail('An unconfigured receipt authority must never return an anchored acknowledgement.');
        } catch (AgeReceiptUnavailable $exception) {
            self::assertStringContainsString('not configured', $exception->getMessage());
        }

        try {
            $gateway->challengeWitnessHead([]);
            self::fail('An unconfigured witness must never return a verified current head.');
        } catch (AgeReceiptUnavailable $exception) {
            self::assertStringContainsString('not configured', $exception->getMessage());
        }
    }
}
