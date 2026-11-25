<?php

declare(strict_types=1);

namespace App\Contracts\Admin\Providers;

use App\Data\Admin\Providers\ProviderExecutionResult;
use App\Data\Admin\Providers\ProviderOperationPlan;

interface ProviderAdapter
{
    public function providerKey(): string;

    public function adapterVersion(): string;

    /**
     * @return array<string, array{risk_tier: string, resource_types: list<string>, idempotent?: bool}>
     */
    public function supportedOperations(): array;

    public function plan(
        string $operation,
        string $resourceType,
        string $resourceIdentifier,
        array $payload
    ): ProviderOperationPlan;

    /**
     * Read-only observation. Returned evidence must already be sanitized.
     *
     * @return array<string, mixed>
     */
    public function observe(string $resourceType, string $resourceIdentifier): array;

    public function execute(
        ProviderOperationPlan $approvedPlan,
        string $idempotencyKey
    ): ProviderExecutionResult;

    /** Read-only reconciliation after an uncertain provider response. */
    public function reconcile(
        ProviderOperationPlan $approvedPlan,
        string $idempotencyKey
    ): ProviderExecutionResult;
}
