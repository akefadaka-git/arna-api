<?php

declare(strict_types=1);

namespace Tests\Support\Fakes;

use App\Contracts\Admin\Providers\ProviderAdapter;
use App\Data\Admin\Providers\ProviderExecutionResult;
use App\Data\Admin\Providers\ProviderOperationPlan;

final class TestSandboxProviderAdapter implements ProviderAdapter
{
    public int $executionCount = 0;

    public function providerKey(): string { return 'sandbox_dns'; }
    public function adapterVersion(): string { return 'test-1.0.0'; }
    public function supportedOperations(): array
    {
        return ['replace_zone' => ['risk_tier' => 'critical', 'resource_types' => ['dns_zone'], 'idempotent' => true]];
    }
    public function plan(string $operation, string $resourceType, string $resourceIdentifier, array $payload): ProviderOperationPlan
    {
        return new ProviderOperationPlan($this->providerKey(), $this->adapterVersion(), $operation, $resourceType, $resourceIdentifier, $payload, 'critical');
    }
    public function observe(string $resourceType, string $resourceIdentifier): array { return ['status' => 'synthetic']; }
    public function execute(ProviderOperationPlan $approvedPlan, string $idempotencyKey): ProviderExecutionResult
    {
        $this->executionCount++;
        return new ProviderExecutionResult('succeeded', 'synthetic-reference', 'Fake sandbox provider applied the operation.', ['sandbox' => true]);
    }
    public function reconcile(ProviderOperationPlan $approvedPlan, string $idempotencyKey): ProviderExecutionResult
    {
        return new ProviderExecutionResult('not_applied', null, 'Synthetic provider confirms no state change.', ['sandbox' => true]);
    }
}
