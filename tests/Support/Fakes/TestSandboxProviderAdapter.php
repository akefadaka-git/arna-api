<?php

declare(strict_types=1);

namespace Tests\Support\Fakes;

use App\Contracts\Admin\Providers\ProviderAdapter;
use App\Data\Admin\Providers\ProviderExecutionResult;
use App\Data\Admin\Providers\ProviderSandboxCertificationResult;
use App\Data\Admin\Providers\ProviderOperationPlan;

final class TestSandboxProviderAdapter implements ProviderAdapter
{
    public int $executionCount = 0;
    public int $reconciliationCount = 0;
    public bool $throwOnExecute = false;
    public bool $idempotent = true;

    public function providerKey(): string { return 'sandbox_dns'; }
    public function adapterVersion(): string { return 'test-1.0.0'; }
    public function supportedOperations(): array
    {
        return ['replace_zone' => ['risk_tier' => 'critical', 'resource_types' => ['dns_zone'], 'idempotent' => $this->idempotent]];
    }
    public function plan(string $operation, string $resourceType, string $resourceIdentifier, array $payload): ProviderOperationPlan
    {
        return new ProviderOperationPlan($this->providerKey(), $this->adapterVersion(), $operation, $resourceType, $resourceIdentifier, $payload, 'critical');
    }
    public function observe(string $resourceType, string $resourceIdentifier): array { return ['status' => 'synthetic']; }
    public function certifySandbox(): ProviderSandboxCertificationResult
    {
        return new ProviderSandboxCertificationResult(true, $this->adapterVersion(), 'synthetic-test-fixture', [
            ['name' => 'sandbox_endpoint_isolated', 'status' => 'passed', 'summary' => 'Adapter targets the synthetic sandbox endpoint.'],
            ['name' => 'idempotency_supported', 'status' => 'passed', 'summary' => 'Repeated request key is stable.'],
            ['name' => 'reconciliation_available', 'status' => 'passed', 'summary' => 'Read-only reconciliation returns provider state.'],
            ['name' => 'synthetic_cleanup', 'status' => 'passed', 'summary' => 'Synthetic resources are cleaned up.'],
        ]);
    }
    public function execute(ProviderOperationPlan $approvedPlan, string $idempotencyKey): ProviderExecutionResult
    {
        $this->executionCount++;
        if ($this->throwOnExecute) {
            throw new \RuntimeException('Synthetic network timeout after request dispatch.');
        }
        return new ProviderExecutionResult('succeeded', 'synthetic-reference', 'Fake sandbox provider applied the operation.', ['sandbox' => true]);
    }
    public function reconcile(ProviderOperationPlan $approvedPlan, string $idempotencyKey): ProviderExecutionResult
    {
        $this->reconciliationCount++;
        return new ProviderExecutionResult('not_applied', null, 'Synthetic provider confirms no state change.', ['sandbox' => true]);
    }
}
