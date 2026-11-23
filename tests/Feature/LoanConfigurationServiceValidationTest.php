<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Domains\Loan\Services\LoanConfigurationService;
use MaaleArna\MASES\Core\Policy\Services\VersionedPolicyRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionMethod;
use Tests\TestCase;

#[CoversClass(LoanConfigurationService::class)]
final class LoanConfigurationServiceValidationTest extends TestCase
{
    public function test_empty_configuration_is_rejected_before_a_draft_is_persisted(): void
    {
        $service = $this->service();
        $validator = new ReflectionMethod(LoanConfigurationService::class, 'validateConfiguration');

        $this->expectException(ValidationException::class);
        $validator->invoke($service, []);
    }

    public function test_policy_configuration_requires_all_five_financial_sections(): void
    {
        $service = $this->service();
        $validator = new ReflectionMethod(LoanConfigurationService::class, 'validateConfiguration');

        $this->expectException(ValidationException::class);
        $validator->invoke($service, [
            'effective_from' => '2027-01-01T00:00:00Z',
            'product' => [
                'code' => 'TEST_PRODUCT',
                'name_en' => 'Test product',
                'currency' => 'ETB',
                'min_principal' => '100.0000',
                'max_principal' => '1000.0000',
                'min_term_months' => 1,
                'max_term_months' => 12,
            ],
            'financial_policy' => ['interest' => []],
        ]);
    }

    private function service(): LoanConfigurationService
    {
        return new LoanConfigurationService(
            app(AuthorizationService::class),
            new VersionedPolicyRegistry(),
        );
    }
}
