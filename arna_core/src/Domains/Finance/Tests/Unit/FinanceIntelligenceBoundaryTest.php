<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Finance\Tests\Unit;

use MaaleArna\Arna\Core\Domains\Finance\Contracts\FinanceIntelligenceProviderContract;
use MaaleArna\Arna\Core\Domains\Finance\Services\UnavailableFinanceIntelligenceProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Tests\TestCase;

final class FinanceIntelligenceBoundaryTest extends TestCase
{
    #[Test]
    public function unconfigured_provider_fails_closed_instead_of_fabricating_ai_output(): void
    {
        $this->expectException(ServiceUnavailableHttpException::class);

        (new UnavailableFinanceIntelligenceProvider())->generateProposal([], []);
    }

    #[Test]
    public function provider_contract_exposes_only_proposal_generation_and_no_execution_tools(): void
    {
        $methods = array_map(
            static fn ($method): string => $method->getName(),
            (new ReflectionClass(FinanceIntelligenceProviderContract::class))->getMethods(),
        );

        $this->assertSame(['generateProposal'], $methods);
    }
}
