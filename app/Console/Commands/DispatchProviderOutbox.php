<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Admin\Providers\ProviderOutboxWorker;
use Illuminate\Console\Command;
use RuntimeException;

final class DispatchProviderOutbox extends Command
{
    protected $signature = 'arna:providers:dispatch-held-outbox {--limit=10 : Maximum sandbox actions to claim}';

    protected $description = 'Dispatch approved provider actions only to explicitly enabled isolated sandboxes';

    public function handle(ProviderOutboxWorker $worker): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 100) {
            $this->error('Limit must be between 1 and 100. No provider action was claimed.');
            return self::FAILURE;
        }

        try {
            $result = $worker->dispatch($limit);
        } catch (RuntimeException $exception) {
            $this->warn($exception->getMessage());
            return self::FAILURE;
        }

        $this->info('Provider outbox: ' . json_encode($result, JSON_THROW_ON_ERROR));
        return $result['reconciliation_required'] > 0 || $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
