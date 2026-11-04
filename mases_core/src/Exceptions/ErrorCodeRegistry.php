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
