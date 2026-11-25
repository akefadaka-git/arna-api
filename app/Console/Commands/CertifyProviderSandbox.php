<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Admin\Providers\ProviderSandboxCertificationService;
use Illuminate\Console\Command;
use RuntimeException;

final class CertifyProviderSandbox extends Command
{
    protected $signature = 'arna:providers:certify-sandbox {provider_key : Explicitly registered provider key}';

    protected $description = 'Run an adapter-defined isolated sandbox certification; does not enable mutations';

    public function handle(ProviderSandboxCertificationService $service): int
    {
        try {
            $result = $service->certify((string) $this->argument('provider_key'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        $this->info('Sandbox certification passed: ' . json_encode($result, JSON_THROW_ON_ERROR));
        $this->warn('Certification does not enable sandbox or production mutations.');
        return self::SUCCESS;
    }
}
