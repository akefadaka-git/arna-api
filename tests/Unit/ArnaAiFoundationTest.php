<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use MaaleArna\MASES\Core\AI\Contracts\ModelGatewayContract;
use MaaleArna\MASES\Core\AI\Registry\AgentDefinition;
use MaaleArna\MASES\Core\AI\Registry\AgentRegistry;
use MaaleArna\MASES\Core\AI\Services\DenyAllToolPermission;
use MaaleArna\MASES\Core\AI\Services\DisabledModelGateway;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class ArnaAiFoundationTest extends TestCase
{
    public function test_model_gateway_is_disabled_and_fails_closed(): void
    {
        $gateway = new DisabledModelGateway();

        self::assertFalse($gateway->isEnabled());
        $this->expectException(ServiceUnavailableHttpException::class);
        $gateway->generate(['prompt' => 'test']);
    }

    public function test_tool_permissions_deny_by_default(): void
    {
        self::assertFalse((new DenyAllToolPermission())->allows('finance.trial_balance.read', [
            'tenant_id' => 1,
            'actor_id' => 1,
        ]));
    }

    public function test_registry_rejects_duplicate_agent_keys(): void
    {
        $definition = new AgentDefinition('test_agent', 'Test', 'Test definition', [], ['finance']);

        $this->expectException(InvalidArgumentException::class);
        new AgentRegistry([$definition, $definition]);
    }

    public function test_agent_definitions_reject_autonomy_above_l2(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AgentDefinition('unsafe_agent', 'Unsafe', 'Must fail closed', ['payment.execute'], ['finance'], 'L3');
    }

    public function test_gateway_contract_is_bound_to_disabled_implementation(): void
    {
        $gateway = new DisabledModelGateway();
        self::assertInstanceOf(ModelGatewayContract::class, $gateway);
        self::assertFalse($gateway->isEnabled());
    }
}
