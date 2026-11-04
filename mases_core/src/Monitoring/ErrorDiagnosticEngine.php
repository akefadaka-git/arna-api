<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Monitoring;

use Throwable;

/**
 * Deterministic, read-only diagnostics for common platform failures.
 * Never executes SQL, changes files, modifies permissions, or deploys code.
 */
final class ErrorDiagnosticEngine
{
    /**
     * @return array{diagnostic_id:string,category:string,likely_causes:list<string>,checks:list<string>,safe_actions:list<string>,auto_remediation:'none'}
     */
    public function diagnose(Throwable $exception, string $errorCode): array
    {
        $class = get_class($exception);
        $message = strtolower($exception->getMessage());
        $category = 'application';
        $causes = ['Unhandled application exception'];
        $checks = ['Inspect the correlated server log using the request ID'];
        $actions = ['Capture a reproducible case and add a regression test'];

        if (str_contains($class, 'QueryException') || str_contains($message, 'sqlstate')) {
            $category = 'database';
            $causes = ['Database constraint, connectivity, schema drift, or query failure'];
            $checks = ['Check database health and connection pool', 'Compare deployed migrations with the actual schema', 'Inspect constraint names and query timing in protected server logs'];
            $actions = ['Alert the database operator', 'Do not replay a non-idempotent write until transaction outcome is known'];
        } elseif (str_contains($message, 'timeout') || str_contains($message, 'connection refused')) {
            $category = 'dependency';
            $causes = ['Unavailable dependency, network interruption, or exhausted connection pool'];
            $checks = ['Check dependency health and latency', 'Inspect retry and circuit-breaker metrics'];
            $actions = ['Retry only idempotent operations with bounded backoff'];
        } elseif (str_contains($errorCode, 'AUTH_') || str_contains($errorCode, 'TENANT_')) {
            $category = 'identity_or_tenancy';
            $causes = ['Authentication state, tenant context, membership, or authorization policy mismatch'];
            $checks = ['Verify the request identity and tenant membership', 'Inspect policy decision and cache invalidation events'];
            $actions = ['Require an authorized administrator to correct identity or access configuration'];
        } elseif (str_contains($errorCode, 'FINANCE_') || str_contains($errorCode, 'DIVIDEND_')) {
            $category = 'financial_integrity';
            $causes = ['Financial business rule rejection or ledger integrity condition'];
            $checks = ['Verify journal balance, period state, idempotency key, and approval chain'];
            $actions = ['Block automatic replay or mutation; route to Finance authority'];
        } elseif (str_contains($errorCode, 'ELECTION_') || str_contains($errorCode, 'GOVERNANCE_')) {
            $category = 'governance_integrity';
            $causes = ['Election state, eligibility, quorum, or governance authority condition'];
            $checks = ['Verify election lifecycle state, voter eligibility, quorum and audit trail'];
            $actions = ['Preserve ballots and audit evidence; require governance authority for changes'];
        }

        return [
            'diagnostic_id' => bin2hex(random_bytes(8)),
            'category' => $category,
            'likely_causes' => $causes,
            'checks' => $checks,
            'safe_actions' => $actions,
            'auto_remediation' => 'none',
        ];
    }
}
