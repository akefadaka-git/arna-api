<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\Admin\Providers\ProviderAdapter;
use App\Data\Admin\Providers\ProviderExecutionResult;
use App\Data\Admin\Providers\ProviderOperationPlan;
use App\Services\Admin\Providers\ProviderAdapterRegistry;
use App\Services\Admin\Providers\ProviderApprovalPolicy;
use App\Services\Admin\Providers\ProviderMutationGate;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Tests\TestCase;

final class PlatformProviderArchitectureTest extends TestCase
{
    public function test_mutation_gate_is_disabled_by_default(): void
    {
        config(['services.arna_provider_mutations.enabled' => false]);

        $gate = new ProviderMutationGate();

        $this->assertFalse($gate->enabled());
        $this->expectException(RuntimeException::class);
        $gate->assertEnabled();
    }

    public function test_registry_fails_closed_for_unregistered_provider(): void
    {
        $registry = new ProviderAdapterRegistry();

        $this->assertSame([], $registry->registeredKeys());
        $this->expectException(RuntimeException::class);
        $registry->resolve('dns');
    }

    public function test_registry_rejects_duplicate_provider_keys(): void
    {
        $registry = new ProviderAdapterRegistry();
        $adapter = $this->adapter('dns');
        $registry->register($adapter);

        $this->expectException(LogicException::class);
        $registry->register($adapter);
    }

    public function test_registry_rejects_invalid_provider_keys(): void
    {
        $registry = new ProviderAdapterRegistry();

        $this->expectException(InvalidArgumentException::class);
        $registry->register($this->adapter('DNS PROVIDER'));
    }

    public function test_operation_digest_is_stable_for_associative_key_order(): void
    {
        $first = new ProviderOperationPlan('dns', '1.0.0', 'create_txt', 'dns_record', 'zone-1', [
            'ttl' => 300,
            'record' => ['value' => 'challenge', 'name' => '_verify'],
        ], 'low_reversible');
        $second = new ProviderOperationPlan('dns', '1.0.0', 'create_txt', 'dns_record', 'zone-1', [
            'record' => ['name' => '_verify', 'value' => 'challenge'],
            'ttl' => 300,
        ], 'low_reversible');

        $this->assertSame($first->payloadSha256, $second->payloadSha256);
    }

    public function test_critical_provider_operations_require_owner_signature_in_addition_to_two_approvals(): void
    {
        $policy = new ProviderApprovalPolicy();

        $this->assertSame([
            'required_approvals' => 2,
            'owner_signature_required' => true,
        ], $policy->forRiskTier('critical'));
        $this->assertSame([
            'required_approvals' => 2,
            'owner_signature_required' => false,
        ], $policy->forRiskTier('elevated'));
    }

    private function adapter(string $key): ProviderAdapter
    {
        return new class($key) implements ProviderAdapter {
            public function __construct(private readonly string $key) {}
            public function providerKey(): string { return $this->key; }
            public function adapterVersion(): string { return '1.0.0'; }
            public function supportedOperations(): array { return []; }
            public function plan(string $operation, string $resourceType, string $resourceIdentifier, array $payload): ProviderOperationPlan
            {
                return new ProviderOperationPlan($this->key, '1.0.0', $operation, $resourceType, $resourceIdentifier, $payload, 'low_reversible');
            }
            public function observe(string $resourceType, string $resourceIdentifier): array { return []; }
            public function execute(ProviderOperationPlan $approvedPlan, string $idempotencyKey): ProviderExecutionResult
            {
                throw new RuntimeException('Test adapter execution is intentionally unavailable.');
            }
        };
    }
}
