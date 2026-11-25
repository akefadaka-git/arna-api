<?php

declare(strict_types=1);

namespace App\Services\Admin\Providers;

use App\Data\Admin\Providers\ProviderExecutionResult;
use App\Data\Admin\Providers\ProviderOperationPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class ProviderOutboxWorker
{
    public function __construct(
        private readonly ProviderAdapterRegistry $adapters,
        private readonly ProviderMutationGate $gate,
    ) {
    }

    /** @return array{claimed:int,succeeded:int,requeued:int,reconciliation_required:int,failed:int} */
    public function dispatch(int $limit = 10): array
    {
        $this->gate->assertSandboxEnabled('sandbox');
        $summary = ['claimed' => 0, 'succeeded' => 0, 'requeued' => 0, 'reconciliation_required' => 0, 'failed' => 0];

        for ($i = 0; $i < max(1, min($limit, 100)); $i++) {
            $claim = $this->claim();
            if ($claim === null) {
                break;
            }
            $summary['claimed']++;

            try {
                $outcome = $this->process($claim);
                $summary[$outcome]++;
            } catch (Throwable) {
                $this->markReconciliationRequired($claim, 'provider_worker_unexpected');
                $summary['reconciliation_required']++;
            }
        }

        return $summary;
    }

    /** @return array<string, mixed>|null */
    private function claim(): ?array
    {
        return DB::transaction(function (): ?array {
            $row = DB::table('platform_provider_action_outbox')
                ->whereIn('status', ['held', 'reconciliation_required', 'processing'])
                ->where(function ($query): void {
                    $query->whereNull('available_at')->orWhere('available_at', '<=', now());
                })
                ->where(function ($query): void {
                    $query->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<', now());
                })
                ->orderBy('id')->lockForUpdate()->first();

            if ($row === null) {
                return null;
            }

            $lease = (string) Str::uuid();
            $updated = DB::table('platform_provider_action_outbox')
                ->where('id', $row->id)
                ->whereIn('status', ['held', 'reconciliation_required', 'processing'])
                ->update([
                    'status' => 'processing',
                    'lease_token' => $lease,
                    'claimed_at' => now(),
                    'lease_expires_at' => now()->addMinutes(2),
                    'attempt_count' => DB::raw('attempt_count + 1'),
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                return null;
            }

            return ['outbox' => (array) $row, 'lease_token' => $lease, 'lease_recovery' => $row->status === 'processing'];
        });
    }

    /** @param array<string, mixed> $claim */
    private function process(array $claim): string
    {
        $outbox = (object) $claim['outbox'];
        $request = DB::table('platform_provider_action_requests')->where('id', $outbox->request_id)->first();
        if ($request === null || ! in_array($request->status, ['approved', 'reconciliation_required'], true)
            || $request->environment !== 'sandbox'
            || (!(($claim['lease_recovery'] ?? false) || $outbox->status === 'reconciliation_required') && now()->greaterThanOrEqualTo($request->expires_at))
            || ! hash_equals((string) $outbox->payload_sha256, (string) $request->payload_sha256)) {
            $this->finish($claim, 'failed', 'request_not_executable', null);
            return 'failed';
        }

        $approvals = DB::table('platform_provider_action_approvals')
            ->where('request_id', $request->id)->where('decision', 'approved')->get();
        foreach ($approvals as $approval) {
            if ((int) $approval->approver_id === (int) $request->requester_id
                || ! hash_equals((string) $request->payload_sha256, (string) $approval->payload_sha256)
                || $approval->policy_version !== $request->policy_version) {
                $this->finish($claim, 'failed', 'approval_binding_mismatch', null);
                return 'failed';
            }
        }
        $approvalCount = $approvals->pluck('approver_id')->unique()->count();
        if ($approvalCount < (int) $request->required_approvals
            || ((bool) $request->owner_signature_required && $request->owner_signature_verified_at === null)) {
            $this->finish($claim, 'failed', 'approval_invariant_failed', null);
            return 'failed';
        }

        $payload = json_decode((string) $request->plan_payload, true, 512, JSON_THROW_ON_ERROR);
        $plan = new ProviderOperationPlan(
            (string) $request->provider_key,
            (string) $request->adapter_version,
            (string) $request->operation_key,
            (string) $request->resource_type,
            (string) $request->resource_identifier,
            $payload,
            (string) $request->risk_tier,
        );
        if (! hash_equals((string) $request->payload_sha256, $plan->payloadSha256)) {
            $this->finish($claim, 'failed', 'payload_digest_mismatch', null);
            return 'failed';
        }

        $adapter = $this->adapters->resolve((string) $request->provider_key);
        if ($adapter->adapterVersion() !== $request->adapter_version) {
            $this->finish($claim, 'failed', 'adapter_version_changed', null);
            return 'failed';
        }

        $sandboxRegistration = DB::table('platform_integrations')
            ->where('provider_key', $request->provider_key)
            ->where('environment', 'sandbox')
            ->where('adapter_version', $request->adapter_version)
            ->where('connection_state', 'connected')
            ->where('sandbox_mutations_enabled', true)
            ->whereNotNull('sandbox_certified_at')
            ->whereNotNull('sandbox_certification_reference')
            ->first();
        if ($sandboxRegistration === null) {
            $this->finish($claim, 'failed', 'provider_sandbox_not_certified', null);
            return 'failed';
        }
        $capability = $adapter->supportedOperations()[$request->operation_key] ?? null;
        if (! is_array($capability) || ! in_array($request->resource_type, $capability['resource_types'] ?? [], true)
            || ($capability['risk_tier'] ?? null) !== $request->risk_tier) {
            $this->finish($claim, 'failed', 'adapter_capability_changed', null);
            return 'failed';
        }

        try {
            $result = ($claim['lease_recovery'] ?? false) || $outbox->status === 'reconciliation_required'
                ? $adapter->reconcile($plan, (string) $outbox->idempotency_key)
                : $adapter->execute($plan, (string) $outbox->idempotency_key);
        } catch (Throwable) {
            try {
                $result = $adapter->reconcile($plan, (string) $outbox->idempotency_key);
            } catch (Throwable) {
                $this->markReconciliationRequired($claim, 'provider_response_indeterminate');
                return 'reconciliation_required';
            }
        }

        return match ($result->status) {
            'succeeded', 'applied' => $this->completeSuccess($claim, $result),
            'not_applied' => $this->handleNotApplied($claim, $result, (bool) ($capability['idempotent'] ?? false)),
            default => $this->handleIndeterminate($claim, $result),
        };
    }

    /** @param array<string, mixed> $claim */
    private function completeSuccess(array $claim, ProviderExecutionResult $result): string
    {
        $this->finish($claim, 'succeeded', null, $result);
        return 'succeeded';
    }

    /** @param array<string, mixed> $claim */
    private function handleNotApplied(array $claim, ProviderExecutionResult $result, bool $idempotent): string
    {
        if (! $idempotent) {
            $this->finish($claim, 'reconciliation_required', 'non_idempotent_not_applied', $result);
            return 'reconciliation_required';
        }
        if (((int) ($claim['outbox']['attempt_count'] ?? 0)) + 1 >= 5) {
            $this->finish($claim, 'failed', 'safe_retry_limit_reached', $result);
            return 'failed';
        }
        $this->finish($claim, 'held', 'safe_retry_not_applied', $result, true);
        return 'requeued';
    }

    /** @param array<string, mixed> $claim */
    private function handleIndeterminate(array $claim, ProviderExecutionResult $result): string
    {
        $this->finish($claim, 'reconciliation_required', 'provider_state_indeterminate', $result);
        return 'reconciliation_required';
    }

    /** @param array<string, mixed> $claim */
    private function markReconciliationRequired(array $claim, string $errorCode): void
    {
        $this->finish($claim, 'reconciliation_required', $errorCode, null);
    }

    /** @param array<string, mixed> $claim */
    private function finish(array $claim, string $status, ?string $errorCode, ?ProviderExecutionResult $result, bool $makeAvailable = false): void
    {
        $outbox = (object) $claim['outbox'];
        $lease = (string) $claim['lease_token'];
        $sanitized = $result === null ? null : json_encode([
            'status' => $result->status,
            'provider_reference' => $result->providerReference,
            'summary' => $result->summary,
            'metadata' => $result->sanitizedMetadata,
        ], JSON_THROW_ON_ERROR);

        DB::transaction(function () use ($outbox, $lease, $status, $errorCode, $sanitized, $makeAvailable, $result): void {
            $updated = DB::table('platform_provider_action_outbox')->where('id', $outbox->id)
                ->where('status', 'processing')->where('lease_token', $lease)
                ->update([
                    'status' => $status,
                    'last_error_code' => $errorCode,
                    'sanitized_result' => $sanitized,
                    'available_at' => $makeAvailable ? now()->addSeconds(5) : ($status === 'reconciliation_required' ? now()->addSeconds(60) : null),
                    'lease_token' => null,
                    'lease_expires_at' => null,
                    'reconciled_at' => $result !== null || $status === 'reconciliation_required' ? now() : null,
                    'reconciliation_status' => $result !== null ? $result->status : ($status === 'reconciliation_required' ? 'indeterminate' : null),
                    'completed_at' => $status === 'succeeded' ? now() : null,
                    'updated_at' => now(),
                ]);

            if ($updated === 1) {
                if ($status !== 'held') {
                    DB::table('platform_provider_action_requests')->where('id', $outbox->request_id)->update([
                        'status' => $status,
                        'updated_at' => now(),
                    ]);
                }
                DB::table('platform_provider_action_audit_events')->insert([
                    'request_id' => $outbox->request_id,
                    'actor_id' => null,
                    'event_type' => 'outbox.' . $status,
                    'payload_sha256' => $outbox->payload_sha256,
                    'metadata' => json_encode(['error_code' => $errorCode, 'idempotency_key' => $outbox->idempotency_key], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);
            }
        });
    }
}
