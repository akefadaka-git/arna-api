<?php

declare(strict_types=1);

namespace App\Services\Admin\Providers;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ProviderSandboxCertificationService
{
    public function __construct(private readonly ProviderAdapterRegistry $adapters)
    {
    }

    /** @return array<string, mixed> */
    public function certify(string $providerKey): array
    {
        if (config('services.arna_provider_mutations.certification_enabled', false) !== true) {
            throw new RuntimeException('ARNA provider sandbox certification is disabled by the safety gate.');
        }

        $adapter = $this->adapters->resolve($providerKey);
        $registration = DB::table('platform_integrations')->where('provider_key', $providerKey)->first();
        if ($registration === null || $registration->environment !== 'sandbox'
            || $registration->adapter_version !== $adapter->adapterVersion()) {
            throw new RuntimeException('A matching sandbox integration registration and adapter version are required.');
        }

        // Re-certification first withdraws prior sandbox certification and mutation permission.
        DB::table('platform_integrations')->where('id', $registration->id)->update([
            'sandbox_mutations_enabled' => false,
            'sandbox_certified_at' => null,
            'sandbox_certification_reference' => null,
            'updated_at' => now(),
        ]);

        try {
            $result = $adapter->certifySandbox();
        } catch (\\Throwable) {
            DB::table('platform_integrations')->where('id', $registration->id)->update([
                'connection_state' => 'degraded',
                'updated_at' => now(),
            ]);
            $this->audit($providerKey, $adapter->adapterVersion(), false, '', []);
            throw new RuntimeException('Provider sandbox certification could not complete; sandbox mutations remain disabled.');
        }
        $checksPassed = $result->checks !== [];
        foreach ($result->checks as $check) {
            if (! is_array($check) || ! is_string($check['name'] ?? null)
                || ! is_string($check['status'] ?? null) || $check['status'] !== 'passed'
                || ! is_string($check['summary'] ?? null)) {
                $checksPassed = false;
                break;
            }
        }

        $passed = $result->passed
            && $result->adapterVersion === $adapter->adapterVersion()
            && $result->reference !== ''
            && strlen($result->reference) <= 160
            && $checksPassed;

        if (! $passed) {
            DB::table('platform_integrations')->where('id', $registration->id)->update([
                'connection_state' => 'degraded',
                'updated_at' => now(),
            ]);
            $this->audit($providerKey, $adapter->adapterVersion(), false, $result->reference, $result->checks);
            throw new RuntimeException('Provider sandbox certification failed; sandbox mutations remain disabled.');
        }

        DB::table('platform_integrations')->where('id', $registration->id)->update([
            'connection_state' => 'connected',
            'adapter_version' => $adapter->adapterVersion(),
            'sandbox_certified_at' => now(),
            'sandbox_certification_reference' => $result->reference,
            // Certification never activates mutations; a separate control must do that.
            'sandbox_mutations_enabled' => false,
            'updated_at' => now(),
        ]);

        $this->audit($providerKey, $adapter->adapterVersion(), true, $result->reference, $result->checks);

        return [
            'provider_key' => $providerKey,
            'adapter_version' => $adapter->adapterVersion(),
            'sandbox_certified' => true,
            'sandbox_certification_reference' => $result->reference,
            'sandbox_mutations_enabled' => false,
            'checks' => array_map(static fn (array $check): array => ['name' => $check['name'], 'status' => $check['status']], $result->checks),
        ];
    }

    /** @param list<array{name:string,status:string,summary:string}> $checks */
    private function audit(string $providerKey, string $adapterVersion, bool $passed, string $reference, array $checks): void
    {
        DB::table('platform_provider_action_audit_events')->insert([
            'request_id' => null,
            'actor_id' => null,
            'event_type' => $passed ? 'adapter.sandbox_certification_passed' : 'adapter.sandbox_certification_failed',
            'payload_sha256' => null,
            'metadata' => json_encode([
                'provider_key' => $providerKey,
                'adapter_version' => $adapterVersion,
                'reference' => $reference,
                'checks' => array_map(static function ($check): array {
                    return is_array($check) ? [
                        'name' => is_string($check['name'] ?? null) ? $check['name'] : 'unknown',
                        'status' => is_string($check['status'] ?? null) ? $check['status'] : 'invalid',
                    ] : ['name' => 'invalid', 'status' => 'invalid'];
                }, $checks),
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
