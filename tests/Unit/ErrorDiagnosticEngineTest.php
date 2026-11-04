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
    public function existing_authentication_tenant_and_file_exception_codes_are_registered(): void
    {
        foreach ([
            'AUTH_EMAIL_TAKEN',
            'AUTH_ACCOUNT_INACTIVE',
            'AUTH_TENANT_ACCESS_DENIED',
            'AUTH_TOKEN_INVALID',
            'AUTH_TOKEN_EXPIRED',
            'AUTH_UNAUTHENTICATED',
            'AUTH_PASSWORD_RESET_FAILED',
            'AUTH_EMAIL_NOT_VERIFIED',
            'AUTH_USER_NOT_FOUND',
            'AUTH_INVALID_VERIFICATION_TOKEN',
            'AUTH_EMAIL_ALREADY_VERIFIED',
            'AUTH_MFA_INVALID',
            'TENANT_REQUIRED',
            'TENANT_MISMATCH',
            'TENANT_INACTIVE',
            'TENANT_ISOLATION_VIOLATION',
            'TENANT_ACCESS_DENIED',
            'TENANT_RESOLUTION_FAILED',
            'MEMBERSHIP_CHECK_FAILED',
            'FILE_NOT_FOUND',
            'FILE_UPLOAD_FAILED',
            'FILE_STORAGE_FAILED',
            'FILE_TOO_LARGE',
            'FILE_EXTENSION_NOT_ALLOWED',
            'FILE_AUTH_REQUIRED',
            'FILE_NOT_DELETED',
            'FILE_DELETE_FAILED',
            'FILE_RESTORE_FAILED',
            'FILE_INVALID_OPERATION',
        ] as $code) {
            $this->assertTrue(ErrorCodeRegistry::isRegistered($code), "Missing registered error code: {$code}");
        }

        $this->assertSame('critical', ErrorCodeRegistry::metadata('TENANT_ISOLATION_VIOLATION')['severity']);
    }

    #[Test]
    public function diagnostics_are_read_only_and_classify_financial_integrity_errors(): void
    {
        $engine = new ErrorDiagnosticEngine;
        $result = $engine->diagnose(new RuntimeException('Ledger rejected write'), 'FINANCE_UNBALANCED_JOURNAL');

        $this->assertSame('financial_integrity', $result['category']);
        $this->assertSame('none', $result['auto_remediation']);
        $this->assertNotEmpty($result['checks']);
        $this->assertNotEmpty($result['safe_actions']);
    }

    #[Test]
    public function database_diagnostics_do_not_expose_exception_messages(): void
    {
        $engine = new ErrorDiagnosticEngine;
        $result = $engine->diagnose(new RuntimeException('SQLSTATE[secret] password=do-not-leak'), 'DATABASE_ERROR');

        $this->assertSame('database', $result['category']);
        $this->assertStringNotContainsString('do-not-leak', json_encode($result));
        $this->assertSame('none', $result['auto_remediation']);
    }
}
