<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Exceptions;

/**
 * Canonical, additive error metadata registry for ARNA.
 * Unknown legacy codes remain renderable for backward compatibility.
 */
final class ErrorCodeRegistry
{
    /** @var array<string, array{severity:string,retryable:bool,message_key:string}> */
    private const DEFINITIONS = [
        'VALIDATION_ERROR' => ['severity' => 'warning', 'retryable' => false, 'message_key' => 'errors.validation'],
        'UNAUTHENTICATED' => ['severity' => 'warning', 'retryable' => false, 'message_key' => 'errors.auth.unauthenticated'],
        'AUTH_PERMISSION_DENIED' => ['severity' => 'warning', 'retryable' => false, 'message_key' => 'errors.auth.permission_denied'],
        'RESOURCE_NOT_FOUND' => ['severity' => 'warning', 'retryable' => false, 'message_key' => 'errors.resource.not_found'],
        'ROUTE_NOT_FOUND' => ['severity' => 'warning', 'retryable' => false, 'message_key' => 'errors.route.not_found'],
        'METHOD_NOT_ALLOWED' => ['severity' => 'warning', 'retryable' => false, 'message_key' => 'errors.method_not_allowed'],
        'TOO_MANY_REQUESTS' => ['severity' => 'warning', 'retryable' => true, 'message_key' => 'errors.rate_limited'],
        'PAYLOAD_TOO_LARGE' => ['severity' => 'warning', 'retryable' => false, 'message_key' => 'errors.payload_too_large'],
        'TOKEN_MISMATCH' => ['severity' => 'warning', 'retryable' => true, 'message_key' => 'errors.auth.token_mismatch'],
        'DATABASE_ERROR' => ['severity' => 'critical', 'retryable' => false, 'message_key' => 'errors.database'],
        'INTERNAL_SERVER_ERROR' => ['severity' => 'error', 'retryable' => false, 'message_key' => 'errors.internal'],
        'SERVICE_UNAVAILABLE' => ['severity' => 'error', 'retryable' => true, 'message_key' => 'errors.service_unavailable'],
        'NETWORK_ERROR' => ['severity' => 'error', 'retryable' => true, 'message_key' => 'errors.network'],
        'REQUEST_CANCELLED' => ['severity' => 'info', 'retryable' => false, 'message_key' => 'errors.request_cancelled'],
        'AUTH_INVALID_CREDENTIALS' => ['severity' => 'warning', 'retryable' => false, 'message_key' => 'errors.auth.invalid_credentials'],
        'AUTH_SESSION_EXPIRED' => ['severity' => 'warning', 'retryable' => false, 'message_key' => 'errors.auth.session_expired'],
        'TENANT_NOT_FOUND' => ['severity' => 'warning', 'retryable' => false, 'message_key' => 'errors.tenant.not_found'],
        'TENANT_CONTEXT_MISSING' => ['severity' => 'warning', 'retryable' => false, 'message_key' => 'errors.tenant.context_missing'],
        'TENANT_MEMBERSHIP_REQUIRED' => ['severity' => 'warning', 'retryable' => false, 'message_key' => 'errors.tenant.membership_required'],
        'INTERNAL_ERROR' => ['severity' => 'error', 'retryable' => false, 'message_key' => 'errors.internal'],
        'AUTH_EMAIL_TAKEN' => ['severity' => 'warning', 'retryable' => false, 'message_key' => 'errors.auth.email_taken'],
        'AUTH_ACCOUNT_INACTIVE' => ['severity' => 'warning', 'retryable' => false, 'message_key' => 'errors.auth.account_inactive'],
        'AUTH_TENANT_ACCESS_DENIED' => ['severity' => 'warning', 'retryable' => false, 'message_key' => 'errors.auth.tenant_access_denied'],
        'AUTH_TOKEN_INVALID' => ['severity' => 'warning', 'retryable' => false, 'message_key' => 'errors.auth.token_invalid'],
        'AUTH_TOKEN_EXPIRED' => ['severity' => 'warning', 'retryable' => false, 'message_key' => 'errors.auth.token_expired'],
        'AUTH_UNAUTHENTICATED' => ['severity' => 'warning', 'retryable' => false, 'message_key' => 'errors.auth.unauthenticated'],
        'AUTH_PASSWORD_RESET_FAILED' => ['severity' => 'warning', 'retryable' => false, 'message_key' => 'errors.auth.password_reset_failed'],
        'AUTH_EMAIL_NOT_VERIFIED' => ['severity' => 'warning', 'retryable' => false, 'message_key' => 'errors.auth.email_not_verified'],
        'AUTH_USER_NOT_FOUND' => ['severity' => 'warning', 'retryable' => false, 'message_key' => 'errors.auth.user_not_found'],
        'AUTH_INVALID_VERIFICATION_TOKEN' => ['severity' => 'warning', 'retryable' => false, 'message_key' => 'errors.auth.invalid_verification_token'],
        'AUTH_EMAIL_ALREADY_VERIFIED' => ['severity' => 'warning', 'retryable' => false, 'message_key' => 'errors.auth.email_already_verified'],
        'AUTH_MFA_INVALID' => ['severity' => 'warning', 'retryable' => false, 'message_key' => 'errors.auth.mfa_invalid'],
        'AUTH_SMS_INTEGRATION_COMING_SOON' => ['severity' => 'warning', 'retryable' => true, 'message_key' => 'errors.auth.sms_unavailable'],
        'AUTH_ERROR' => ['severity' => 'error', 'retryable' => false, 'message_key' => 'errors.auth.general'],
        'TENANT_REQUIRED' => ['severity' => 'warning', 'retryable' => false, 'message_key' => 'errors.tenant.required'],
        'TENANT_MISMATCH' => ['severity' => 'critical', 'retryable' => false, 'message_key' => 'errors.tenant.mismatch'],
        'TENANT_INACTIVE' => ['severity' => 'warning', 'retryable' => false, 'message_key' => 'errors.tenant.inactive'],
        'TENANT_ISOLATION_VIOLATION' => ['severity' => 'critical', 'retryable' => false, 'message_key' => 'errors.tenant.isolation_violation'],
        'TENANT_ACCESS_DENIED' => ['severity' => 'warning', 'retryable' => false, 'message_key' => 'errors.tenant.access_denied'],
        'TENANT_ERROR' => ['severity' => 'error', 'retryable' => false, 'message_key' => 'errors.tenant.general'],
        'TENANT_RESOLUTION_FAILED' => ['severity' => 'error', 'retryable' => false, 'message_key' => 'errors.tenant.resolution_failed'],
        'MEMBERSHIP_CHECK_FAILED' => ['severity' => 'error', 'retryable' => false, 'message_key' => 'errors.tenant.membership_check_failed'],
        'FILE_NOT_FOUND' => ['severity' => 'warning', 'retryable' => false, 'message_key' => 'errors.file.not_found'],
        'FILE_UPLOAD_FAILED' => ['severity' => 'error', 'retryable' => true, 'message_key' => 'errors.file.upload_failed'],
        'FILE_STORAGE_FAILED' => ['severity' => 'error', 'retryable' => true, 'message_key' => 'errors.file.storage_failed'],
        'FILE_TOO_LARGE' => ['severity' => 'warning', 'retryable' => false, 'message_key' => 'errors.file.too_large'],
        'FILE_EXTENSION_NOT_ALLOWED' => ['severity' => 'warning', 'retryable' => false, 'message_key' => 'errors.file.extension_not_allowed'],
        'FILE_AUTH_REQUIRED' => ['severity' => 'warning', 'retryable' => false, 'message_key' => 'errors.file.auth_required'],
        'FILE_NOT_DELETED' => ['severity' => 'warning', 'retryable' => false, 'message_key' => 'errors.file.not_deleted'],
        'FILE_DELETE_FAILED' => ['severity' => 'error', 'retryable' => false, 'message_key' => 'errors.file.delete_failed'],
        'FILE_RESTORE_FAILED' => ['severity' => 'error', 'retryable' => false, 'message_key' => 'errors.file.restore_failed'],
        'FILE_INVALID_OPERATION' => ['severity' => 'warning', 'retryable' => false, 'message_key' => 'errors.file.invalid_operation'],
        'FILE_ERROR' => ['severity' => 'error', 'retryable' => false, 'message_key' => 'errors.file.general'],
        'FINANCE_PERIOD_CLOSED' => ['severity' => 'warning', 'retryable' => false, 'message_key' => 'errors.finance.period_closed'],
        'FINANCE_INSUFFICIENT_BALANCE' => ['severity' => 'warning', 'retryable' => false, 'message_key' => 'errors.finance.insufficient_balance'],
        'FINANCE_UNBALANCED_JOURNAL' => ['severity' => 'critical', 'retryable' => false, 'message_key' => 'errors.finance.unbalanced_journal'],
        'FINANCE_DUPLICATE_TRANSACTION' => ['severity' => 'warning', 'retryable' => false, 'message_key' => 'errors.finance.duplicate_transaction'],
        'DIVIDEND_NOT_APPROVED' => ['severity' => 'warning', 'retryable' => false, 'message_key' => 'errors.dividend.not_approved'],
        'DIVIDEND_ALREADY_DISTRIBUTED' => ['severity' => 'warning', 'retryable' => false, 'message_key' => 'errors.dividend.already_distributed'],
        'ELECTION_VOTING_CLOSED' => ['severity' => 'warning', 'retryable' => false, 'message_key' => 'errors.election.voting_closed'],
        'ELECTION_DUPLICATE_VOTE' => ['severity' => 'warning', 'retryable' => false, 'message_key' => 'errors.election.duplicate_vote'],
        'DOCUMENT_WORKFLOW_BLOCKED' => ['severity' => 'warning', 'retryable' => false, 'message_key' => 'errors.document.workflow_blocked'],
        'FILE_ACCESS_DENIED' => ['severity' => 'warning', 'retryable' => false, 'message_key' => 'errors.file.access_denied'],
    ];

    /** @return array{severity:string,retryable:bool,message_key:string} */
    public static function metadata(string $code): array
    {
        return self::DEFINITIONS[$code] ?? [
            'severity' => 'error',
            'retryable' => false,
            'message_key' => 'errors.unknown',
        ];
    }

    public static function isRegistered(string $code): bool
    {
        return isset(self::DEFINITIONS[$code]);
    }

    /** @return list<string> */
    public static function allCodes(): array
    {
        return array_keys(self::DEFINITIONS);
    }
}
