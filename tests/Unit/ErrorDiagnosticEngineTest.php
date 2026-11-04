<?php

declare(strict_types=1);

namespace Tests\Unit;

use MaaleArna\MASES\Core\Exceptions\ErrorCodeRegistry;
use MaaleArna\MASES\Core\Monitoring\ErrorDiagnosticEngine;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

final class ErrorDiagnosticEngineTest extends TestCase
{
    #[Test]
    public function registered_codes_have_stable_metadata(): void
    {
        $metadata = ErrorCodeRegistry::metadata('FINANCE_INSUFFICIENT_BALANCE');

        $this->assertSame('warning', $metadata['severity']);
        $this->assertFalse($metadata['retryable']);
        $this->assertSame('errors.finance.insufficient_balance', $metadata['message_key']);
        $this->assertTrue(ErrorCodeRegistry::isRegistered('FINANCE_INSUFFICIENT_BALANCE'));
    }

    #[Test]
    public function unknown_legacy_codes_remain_supported_with_safe_defaults(): void
    {
        $metadata = ErrorCodeRegistry::metadata('LEGACY_DOMAIN_FAILURE');

        $this->assertFalse(ErrorCodeRegistry::isRegistered('LEGACY_DOMAIN_FAILURE'));
        $this->assertSame('error', $metadata['severity']);
        $this->assertFalse($metadata['retryable']);
    }

    #[Test]
    public function diagnostics_are_read_only_and_classify_financial_integrity_errors(): void
    {
        $engine = new ErrorDiagnosticEngine();
        $result = $engine->diagnose(new RuntimeException('Ledger rejected write'), 'FINANCE_UNBALANCED_JOURNAL');

        $this->assertSame('financial_integrity', $result['category']);
        $this->assertSame('none', $result['auto_remediation']);
        $this->assertNotEmpty($result['checks']);
        $this->assertNotEmpty($result['safe_actions']);
    }

    #[Test]
    public function database_diagnostics_do_not_expose_exception_messages(): void
    {
        $engine = new ErrorDiagnosticEngine();
        $result = $engine->diagnose(new RuntimeException('SQLSTATE[secret] password=do-not-leak'), 'DATABASE_ERROR');

        $this->assertSame('database', $result['category']);
        $this->assertStringNotContainsString('do-not-leak', json_encode($result));
        $this->assertSame('none', $result['auto_remediation']);
    }
}
