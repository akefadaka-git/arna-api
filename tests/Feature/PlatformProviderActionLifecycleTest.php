<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Admin\Providers\ProviderActionLifecycleService;
use App\Services\Admin\Providers\ProviderAdapterRegistry;
use App\Services\Admin\Providers\ProviderOwnerSignatureVerifier;
use App\Services\Admin\Providers\ProviderOutboxWorker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\Fakes\TestSandboxProviderAdapter;
use Tests\TestCase;

final class PlatformProviderActionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_critical_action_requires_two_distinct_approvals_and_dedicated_owner_signature_before_held_outbox_creation(): void
    {
        [$service, $registry, $secretKey] = $this->fixture();
        $request = $service->request(10, [
            'provider_key' => 'sandbox_dns',
            'environment' => 'sandbox',
            'operation' => 'replace_zone',
            'resource_type' => 'dns_zone',
            'resource_identifier' => 'synthetic-zone-01',
            'payload' => ['records' => [['name' => 'example.test', 'type' => 'TXT', 'value' => 'synthetic']]],
            'reason' => 'Sandbox lifecycle verification only.',
        ]);

        $this->expectException(RuntimeException::class);
        $service->decide($request['public_id'], 10, 'approved', 'Requester cannot approve.');
    }

    public function test_critical_request_is_held_until_two_approvals_and_owner_signature_are_verified_then_worker_executes_once(): void
    {
        [$service, $registry, $secretKey, $adapter] = $this->fixture();
        $request = $service->request(10, [
            'provider_key' => 'sandbox_dns',
            'environment' => 'sandbox',
            'operation' => 'replace_zone',
            'resource_type' => 'dns_zone',
            'resource_identifier' => 'synthetic-zone-01',
            'payload' => ['records' => [['name' => 'example.test', 'type' => 'TXT', 'value' => 'synthetic']]],
            'reason' => 'Sandbox lifecycle verification only.',
        ]);

        $service->decide($request['public_id'], 11, 'approved', 'First independent approval.');
        $this->assertDatabaseCount('platform_provider_action_outbox', 0);
        $service->decide($request['public_id'], 12, 'approved', 'Second independent approval.');
        $this->assertDatabaseCount('platform_provider_action_outbox', 0);

        $row = DB::table('platform_provider_action_requests')->where('public_id', $request['public_id'])->first();
        $signature = sodium_crypto_sign_detached(
            ProviderOwnerSignatureVerifier::canonicalMessage((array) $row),
            $secretKey,
        );
        $service->authorizeOwner($request['public_id'], 1, 'provider-owner-test-key-v1', base64_encode($signature));

        $this->assertDatabaseHas('platform_provider_action_requests', [
            'public_id' => $request['public_id'],
            'status' => 'approved',
        ]);
        $this->assertDatabaseHas('platform_provider_action_outbox', [
            'payload_sha256' => $request['payload_sha256'],
            'status' => 'held',
        ]);

        config([
            'services.arna_provider_mutations.enabled' => true,
            'services.arna_provider_mutations.sandbox_enabled' => true,
        ]);
        DB::table('platform_integrations')->where('provider_key', 'sandbox_dns')->update([
            'sandbox_mutations_enabled' => true,
            'sandbox_certified_at' => now(),
            'sandbox_certification_reference' => 'synthetic-test-fixture',
            'connection_state' => 'connected',
        ]);
        $worker = app(ProviderOutboxWorker::class);
        $result = $worker->dispatch(10);

        $this->assertSame(1, $result['succeeded']);
        $this->assertSame(1, $adapter->executionCount);
        $this->assertDatabaseHas('platform_provider_action_requests', [
            'public_id' => $request['public_id'],
            'status' => 'succeeded',
        ]);
        $this->assertDatabaseHas('platform_provider_action_outbox', [
            'status' => 'succeeded',
            'idempotency_key' => 'arna-provider:' . hash('sha256', $request['public_id'] . ':' . $request['payload_sha256']),
        ]);
    }

    public function test_invalid_owner_signature_does_not_release_critical_action(): void
    {
        [$service] = $this->fixture();
        $request = $service->request(10, [
            'provider_key' => 'sandbox_dns',
            'environment' => 'sandbox',
            'operation' => 'replace_zone',
            'resource_type' => 'dns_zone',
            'resource_identifier' => 'synthetic-zone-02',
            'payload' => ['records' => [['name' => 'invalid.example.test', 'type' => 'TXT', 'value' => 'synthetic']]],
            'reason' => 'Negative signature verification test.',
        ]);
        $service->decide($request['public_id'], 11, 'approved', 'First approval.');
        $service->decide($request['public_id'], 12, 'approved', 'Second approval.');

        try {
            $service->authorizeOwner($request['public_id'], 1, 'provider-owner-test-key-v1', base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_BYTES)));
            $this->fail('Invalid Owner signature must be rejected.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('platform_provider_action_outbox', 0);
        }
    }

    public function test_worker_requires_provider_specific_sandbox_certification_record(): void
    {
        [$service, $registry, $secretKey, $adapter] = $this->fixture();
        $request = $service->request(10, [
            'provider_key' => 'sandbox_dns',
            'environment' => 'sandbox',
            'operation' => 'replace_zone',
            'resource_type' => 'dns_zone',
            'resource_identifier' => 'uncertified-zone',
            'payload' => ['records' => [['name' => 'uncertified.example.test', 'type' => 'TXT', 'value' => 'synthetic']]],
            'reason' => 'Verify provider-specific sandbox gate.',
        ]);
        $service->decide($request['public_id'], 11, 'approved', 'First approval.');
        $service->decide($request['public_id'], 12, 'approved', 'Second approval.');
        $row = DB::table('platform_provider_action_requests')->where('public_id', $request['public_id'])->first();
        $signature = sodium_crypto_sign_detached(
            ProviderOwnerSignatureVerifier::canonicalMessage((array) $row),
            $secretKey,
        );
        $service->authorizeOwner($request['public_id'], 1, 'provider-owner-test-key-v1', base64_encode($signature));
        config([
            'services.arna_provider_mutations.enabled' => true,
            'services.arna_provider_mutations.sandbox_enabled' => true,
        ]);

        $result = app(ProviderOutboxWorker::class)->dispatch(10);

        $this->assertSame(0, $adapter->executionCount);
        $this->assertSame(1, $result['failed']);
        $this->assertDatabaseHas('platform_provider_action_outbox', [
            'payload_sha256' => $request['payload_sha256'],
            'status' => 'failed',
            'last_error_code' => 'provider_sandbox_not_certified',
        ]);
    }

    public function test_production_request_is_never_sent_to_provider_adapter_by_sandbox_worker(): void
    {
        [$service, $registry, $secretKey, $adapter] = $this->fixture();
        $request = $service->request(10, [
            'provider_key' => 'sandbox_dns',
            'environment' => 'production',
            'operation' => 'replace_zone',
            'resource_type' => 'dns_zone',
            'resource_identifier' => 'production-zone-must-not-run',
            'payload' => ['records' => [['name' => 'production.example.test', 'type' => 'TXT', 'value' => 'synthetic']]],
            'reason' => 'Verify production environment remains blocked.',
        ]);
        $service->decide($request['public_id'], 11, 'approved', 'First approval.');
        $service->decide($request['public_id'], 12, 'approved', 'Second approval.');
        $row = DB::table('platform_provider_action_requests')->where('public_id', $request['public_id'])->first();
        $signature = sodium_crypto_sign_detached(
            ProviderOwnerSignatureVerifier::canonicalMessage((array) $row),
            $secretKey,
        );
        $service->authorizeOwner($request['public_id'], 1, 'provider-owner-test-key-v1', base64_encode($signature));

        config([
            'services.arna_provider_mutations.enabled' => true,
            'services.arna_provider_mutations.sandbox_enabled' => true,
        ]);
        $result = app(ProviderOutboxWorker::class)->dispatch(10);

        $this->assertSame(0, $adapter->executionCount);
        $this->assertSame(1, $result['failed']);
        $this->assertDatabaseHas('platform_provider_action_outbox', [
            'payload_sha256' => $request['payload_sha256'],
            'status' => 'failed',
            'last_error_code' => 'request_not_executable',
        ]);
    }

    public function test_provider_action_routes_are_platform_permission_gated_without_tenant_membership(): void
    {
        $expected = [
            'api.admin.platform.provider-actions.index' => 'auth.permission:platform.providers.actions.read',
            'api.admin.platform.provider-actions.history' => 'auth.permission:platform.providers.actions.read',
            'api.admin.platform.provider-actions.store' => 'auth.permission:platform.providers.actions.request',
            'api.admin.platform.provider-actions.approve' => 'auth.permission:platform.providers.actions.approve',
            'api.admin.platform.provider-actions.reject' => 'auth.permission:platform.providers.actions.approve',
            'api.admin.platform.provider-actions.owner-authorize' => 'auth.permission:platform.providers.actions.owner-authorize',
        ];

        foreach ($expected as $name => $permission) {
            $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "Missing route {$name}");
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
            $this->assertContains($permission, $route->gatherMiddleware());
            $this->assertNotContains('tenant.membership', $route->gatherMiddleware());
        }
    }

    public function test_sandbox_gate_requires_both_global_and_sandbox_flags_and_rejects_production(): void
    {
        $gate = app(\\App\\Services\\Admin\\Providers\\ProviderMutationGate::class);
        config([
            'services.arna_provider_mutations.enabled' => false,
            'services.arna_provider_mutations.sandbox_enabled' => true,
        ]);
        $this->assertFalse($gate->sandboxEnabled());

        config([
            'services.arna_provider_mutations.enabled' => true,
            'services.arna_provider_mutations.sandbox_enabled' => true,
        ]);
        $this->assertTrue($gate->sandboxEnabled());
        $this->expectException(RuntimeException::class);
        $gate->assertSandboxEnabled('production');
    }

    public function test_uncertain_idempotent_provider_response_is_reconciled_before_safe_retry(): void
    {
        [$service, $registry, $secretKey, $adapter] = $this->fixture();
        $request = $this->approvedCriticalRequest($service, $secretKey, 'sandbox', 'retry-zone');
        config([
            'services.arna_provider_mutations.enabled' => true,
            'services.arna_provider_mutations.sandbox_enabled' => true,
        ]);
        DB::table('platform_integrations')->where('provider_key', 'sandbox_dns')->update([
            'sandbox_mutations_enabled' => true,
            'sandbox_certified_at' => now(),
            'sandbox_certification_reference' => 'synthetic-test-fixture',
            'connection_state' => 'connected',
        ]);
        $adapter->throwOnExecute = true;

        $result = app(ProviderOutboxWorker::class)->dispatch(1);

        $this->assertSame(1, $result['requeued']);
        $this->assertSame(1, $adapter->executionCount);
        $this->assertSame(1, $adapter->reconciliationCount);
        $this->assertDatabaseHas('platform_provider_action_outbox', [
            'status' => 'held',
            'attempt_count' => 1,
        ]);
        $this->assertDatabaseHas('platform_provider_action_requests', [
            'public_id' => $request['public_id'],
            'status' => 'approved',
        ]);
    }

    public function test_uncertain_non_idempotent_provider_action_is_not_retried(): void
    {
        [$service, $registry, $secretKey, $adapter] = $this->fixture();
        $adapter->idempotent = false;
        $request = $this->approvedCriticalRequest($service, $secretKey, 'sandbox', 'non-idempotent-zone');
        config([
            'services.arna_provider_mutations.enabled' => true,
            'services.arna_provider_mutations.sandbox_enabled' => true,
        ]);
        DB::table('platform_integrations')->where('provider_key', 'sandbox_dns')->update([
            'sandbox_mutations_enabled' => true,
            'sandbox_certified_at' => now(),
            'sandbox_certification_reference' => 'synthetic-test-fixture',
            'connection_state' => 'connected',
        ]);
        $adapter->throwOnExecute = true;

        $result = app(ProviderOutboxWorker::class)->dispatch(1);

        $this->assertSame(1, $result['reconciliation_required']);
        $this->assertSame(1, $adapter->executionCount);
        $this->assertSame(1, $adapter->reconciliationCount);
        $this->assertDatabaseHas('platform_provider_action_outbox', [
            'status' => 'reconciliation_required',
            'last_error_code' => 'non_idempotent_not_applied',
        ]);
        $this->assertDatabaseHas('platform_provider_action_requests', [
            'public_id' => $request['public_id'],
            'status' => 'reconciliation_required',
        ]);
    }

    public function test_expired_processing_lease_reconciles_before_any_reexecution(): void
    {
        [$service, $registry, $secretKey, $adapter] = $this->fixture();
        $request = $this->approvedCriticalRequest($service, $secretKey, 'sandbox', 'lease-recovery-zone');
        config([
            'services.arna_provider_mutations.enabled' => true,
            'services.arna_provider_mutations.sandbox_enabled' => true,
        ]);
        DB::table('platform_integrations')->where('provider_key', 'sandbox_dns')->update([
            'sandbox_mutations_enabled' => true,
            'sandbox_certified_at' => now(),
            'sandbox_certification_reference' => 'synthetic-test-fixture',
            'connection_state' => 'connected',
        ]);
        DB::table('platform_provider_action_outbox')->where('payload_sha256', $request['payload_sha256'])->update([
            'status' => 'processing',
            'lease_token' => (string) \\Illuminate\\Support\\Str::uuid(),
            'lease_expires_at' => now()->subMinute(),
            'claimed_at' => now()->subMinutes(3),
        ]);

        $result = app(ProviderOutboxWorker::class)->dispatch(1);

        $this->assertSame(1, $result['requeued']);
        $this->assertSame(0, $adapter->executionCount);
        $this->assertSame(1, $adapter->reconciliationCount);
    }

    public function test_provider_worker_refuses_to_run_when_sandbox_gate_is_off(): void
    {
        [, $registry] = $this->fixture();
        config([
            'services.arna_provider_mutations.enabled' => true,
            'services.arna_provider_mutations.sandbox_enabled' => false,
        ]);

        $this->expectException(RuntimeException::class);
        app(ProviderOutboxWorker::class)->dispatch(1);
    }

    /** @return array<string, mixed> */
    private function approvedCriticalRequest(ProviderActionLifecycleService $service, string $secretKey, string $environment, string $resourceIdentifier): array
    {
        $request = $service->request(10, [
            'provider_key' => 'sandbox_dns',
            'environment' => $environment,
            'operation' => 'replace_zone',
            'resource_type' => 'dns_zone',
            'resource_identifier' => $resourceIdentifier,
            'payload' => ['records' => [['name' => $resourceIdentifier . '.example.test', 'type' => 'TXT', 'value' => 'synthetic']]],
            'reason' => 'Provider outbox reliability verification.',
        ]);
        $service->decide($request['public_id'], 11, 'approved', 'First approval.');
        $service->decide($request['public_id'], 12, 'approved', 'Second approval.');
        $row = DB::table('platform_provider_action_requests')->where('public_id', $request['public_id'])->first();
        $signature = sodium_crypto_sign_detached(
            ProviderOwnerSignatureVerifier::canonicalMessage((array) $row),
            $secretKey,
        );
        $service->authorizeOwner($request['public_id'], 1, 'provider-owner-test-key-v1', base64_encode($signature));

        return $request;
    }

    /** @return array{ProviderActionLifecycleService,ProviderAdapterRegistry,string,TestSandboxProviderAdapter} */
    private function fixture(): array
    {
        $keyPair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keyPair);
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        config([
            'services.arna_provider_owner.key_id' => 'provider-owner-test-key-v1',
            'services.arna_provider_owner.public_key' => base64_encode($publicKey),
            'services.arna_provider_mutations.enabled' => false,
            'services.arna_provider_mutations.sandbox_enabled' => false,
        ]);

        $registry = new ProviderAdapterRegistry();
        $adapter = new TestSandboxProviderAdapter();
        $registry->register($adapter);
        DB::table('platform_integrations')->insert([
            'provider_key' => 'sandbox_dns',
            'provider_type' => 'dns',
            'display_name' => 'Synthetic DNS Adapter',
            'category' => 'domain',
            'connection_state' => 'not_connected',
            'environment' => 'sandbox',
            'adapter_version' => 'test-1.0.0',
            'sandbox_mutations_enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app()->instance(ProviderAdapterRegistry::class, $registry);

        return [app(ProviderActionLifecycleService::class), $registry, $secretKey, $adapter];
    }
}
