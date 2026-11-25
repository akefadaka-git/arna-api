<?php

declare(strict_types=1);

namespace App\Services\Admin\Providers;

use App\Data\Admin\Providers\ProviderOperationPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class ProviderActionLifecycleService
{
    public function __construct(
        private readonly ProviderAdapterRegistry $adapters,
        private readonly ProviderApprovalPolicy $policy,
        private readonly ProviderOwnerSignatureVerifier $ownerVerifier,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function request(int $requesterId, array $input): array
    {
        $adapter = $this->adapters->resolve((string) $input['provider_key']);
        $operation = (string) $input['operation'];
        $resourceType = (string) $input['resource_type'];
        $declared = $adapter->supportedOperations()[$operation] ?? null;

        if (! is_array($declared) || ! in_array($resourceType, $declared['resource_types'] ?? [], true)) {
            throw new RuntimeException('The provider adapter does not declare this operation/resource combination.');
        }

        $plan = $adapter->plan(
            $operation,
            $resourceType,
            (string) $input['resource_identifier'],
            (array) ($input['payload'] ?? []),
        );

        $riskTier = (string) ($declared['risk_tier'] ?? '');
        if ($plan->providerKey !== $adapter->providerKey()
            || $plan->adapterVersion !== $adapter->adapterVersion()
            || $plan->operation !== $operation
            || $plan->resourceType !== $resourceType
            || $plan->riskTier !== $riskTier) {
            throw new RuntimeException('The adapter plan does not match its registered capability declaration.');
        }

        $this->assertNoCredentialMaterial($plan->payload);
        $approval = $this->policy->forRiskTier($riskTier);
        $environment = (string) $input['environment'];
        $publicId = (string) Str::uuid();
        $expiresAt = now()->addMinutes(30);

        DB::transaction(function () use ($requesterId, $input, $adapter, $plan, $approval, $environment, $publicId, $expiresAt): void {
            $id = DB::table('platform_provider_action_requests')->insertGetId([
                'public_id' => $publicId,
                'provider_key' => $adapter->providerKey(),
                'adapter_version' => $adapter->adapterVersion(),
                'environment' => $environment,
                'operation_key' => $plan->operation,
                'resource_type' => $plan->resourceType,
                'resource_identifier' => $plan->resourceIdentifier,
                'plan_payload' => json_encode($plan->payload, JSON_THROW_ON_ERROR),
                'payload_sha256' => $plan->payloadSha256,
                'risk_tier' => $plan->riskTier,
                'policy_version' => ProviderApprovalPolicy::VERSION,
                'requester_id' => $requesterId,
                'reason' => (string) $input['reason'],
                'status' => 'pending_approval',
                'required_approvals' => $approval['required_approvals'],
                'owner_signature_required' => $approval['owner_signature_required'],
                'expires_at' => $expiresAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit((int) $id, $requesterId, 'request.created', $plan->payloadSha256, [
                'provider_key' => $adapter->providerKey(),
                'environment' => $environment,
                'risk_tier' => $plan->riskTier,
                'policy_version' => ProviderApprovalPolicy::VERSION,
            ]);
        });

        return ['public_id' => $publicId, 'status' => 'pending_approval', 'payload_sha256' => $plan->payloadSha256, 'expires_at' => $expiresAt->toIso8601String()];
    }

    /** @return array<string, mixed> */
    public function decide(string $publicId, int $actorId, string $decision, string $note): array
    {
        if (! in_array($decision, ['approved', 'rejected'], true)) {
            throw new RuntimeException('Provider action decision is invalid.');
        }

        return DB::transaction(function () use ($publicId, $actorId, $decision, $note): array {
            $request = DB::table('platform_provider_action_requests')->where('public_id', $publicId)->lockForUpdate()->first();
            if ($request === null) {
                throw new RuntimeException('Provider action request was not found.');
            }
            $this->assertPendingAndCurrent($request);
            if ((int) $request->requester_id === $actorId) {
                throw new RuntimeException('The requester cannot decide their own provider action.');
            }

            DB::table('platform_provider_action_approvals')->insert([
                'request_id' => $request->id,
                'approver_id' => $actorId,
                'decision' => $decision,
                'payload_sha256' => $request->payload_sha256,
                'policy_version' => $request->policy_version,
                'note' => $note,
                'created_at' => now(),
            ]);

            if ($decision === 'rejected') {
                DB::table('platform_provider_action_requests')->where('id', $request->id)->update([
                    'status' => 'rejected', 'rejected_at' => now(), 'updated_at' => now(),
                ]);
                $this->audit((int) $request->id, $actorId, 'request.rejected', $request->payload_sha256, ['note' => $note]);
                return ['public_id' => $publicId, 'status' => 'rejected'];
            }

            $this->audit((int) $request->id, $actorId, 'approval.recorded', $request->payload_sha256, [
                'approver_id' => $actorId, 'policy_version' => $request->policy_version,
            ]);
            $this->queueIfReady($request);

            $fresh = DB::table('platform_provider_action_requests')->where('id', $request->id)->first();
            return ['public_id' => $publicId, 'status' => $fresh->status, 'approval_count' => $this->approvalCount((int) $request->id)];
        });
    }

    /** @return array<string, mixed> */
    public function authorizeOwner(string $publicId, int $actorId, string $keyId, string $signature): array
    {
        return DB::transaction(function () use ($publicId, $actorId, $keyId, $signature): array {
            $request = DB::table('platform_provider_action_requests')->where('public_id', $publicId)->lockForUpdate()->first();
            if ($request === null) {
                throw new RuntimeException('Provider action request was not found.');
            }
            $this->assertPendingAndCurrent($request);
            if (! (bool) $request->owner_signature_required) {
                throw new RuntimeException('This provider action does not require Owner authorization.');
            }
            if ($request->owner_signature_verified_at !== null) {
                throw new RuntimeException('Owner authorization has already been recorded.');
            }

            $signatureHash = $this->ownerVerifier->verify((array) $request, $keyId, $signature);
            DB::table('platform_provider_action_requests')->where('id', $request->id)->update([
                'owner_signature_key_id' => $keyId,
                'owner_signature_sha256' => $signatureHash,
                'owner_signature_verified_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit((int) $request->id, $actorId, 'owner.signature_verified', $request->payload_sha256, [
                'key_id' => $keyId, 'signature_sha256' => $signatureHash,
            ]);

            $this->queueIfReady(DB::table('platform_provider_action_requests')->where('id', $request->id)->first());
            $fresh = DB::table('platform_provider_action_requests')->where('id', $request->id)->first();

            return ['public_id' => $publicId, 'status' => $fresh->status, 'owner_signature_verified' => true];
        });
    }

    private function queueIfReady(object $request): void
    {
        if ($request->status !== 'pending_approval' || $this->approvalCount((int) $request->id) < (int) $request->required_approvals) {
            return;
        }
        if ((bool) $request->owner_signature_required && $request->owner_signature_verified_at === null) {
            return;
        }

        $idempotencyKey = 'arna-provider:' . hash('sha256', $request->public_id . ':' . $request->payload_sha256);
        DB::table('platform_provider_action_outbox')->insertOrIgnore([
            'request_id' => $request->id,
            'idempotency_key' => $idempotencyKey,
            'payload_sha256' => $request->payload_sha256,
            'status' => 'held',
            'attempt_count' => 0,
            'available_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('platform_provider_action_requests')->where('id', $request->id)->update([
            'status' => 'approved', 'approved_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit((int) $request->id, null, 'outbox.held', $request->payload_sha256, [
            'idempotency_key' => $idempotencyKey, 'status' => 'held',
        ]);
    }

    private function approvalCount(int $requestId): int
    {
        return DB::table('platform_provider_action_approvals')
            ->where('request_id', $requestId)->where('decision', 'approved')->distinct('approver_id')->count('approver_id');
    }

    private function assertPendingAndCurrent(object $request): void
    {
        if ($request->status !== 'pending_approval' || now()->greaterThanOrEqualTo($request->expires_at)) {
            throw new RuntimeException('Provider action request is not pending or has expired.');
        }
        if ($request->policy_version !== ProviderApprovalPolicy::VERSION) {
            throw new RuntimeException('Provider action approval policy changed; create a new request.');
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
            throw new RuntimeException('Provider action plan digest no longer matches its immutable request.');
        }
    }

    /** @param array<string, mixed> $payload */
    private function assertNoCredentialMaterial(array $payload): void
    {
        $scan = function (array $items) use (&$scan): void {
            foreach ($items as $key => $value) {
                if (is_string($key) && preg_match('/(?:secret|password|token|credential|private[_-]?key|api[_-]?key)/i', $key)) {
                    throw new RuntimeException('Provider action payloads must reference vault credentials and cannot contain credential material.');
                }
                if (is_array($value)) {
                    $scan($value);
                }
            }
        };
        $scan($payload);
    }

    /** @param array<string, mixed> $metadata */
    private function audit(?int $requestId, ?int $actorId, string $event, ?string $digest, array $metadata): void
    {
        DB::table('platform_provider_action_audit_events')->insert([
            'request_id' => $requestId,
            'actor_id' => $actorId,
            'event_type' => $event,
            'payload_sha256' => $digest,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
