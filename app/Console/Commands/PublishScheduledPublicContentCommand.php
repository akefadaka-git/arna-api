<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Audit\Models\AuditLog;
use MaaleArna\MASES\Core\Domains\PublicContent\Models\PublicContent;

final class PublishScheduledPublicContentCommand extends Command
{
    protected $signature = 'public-content:publish-scheduled';
    protected $description = 'Publish approved public content whose scheduled publication time has arrived.';

    public function handle(): int
    {
        $published = 0;

        PublicContent::query()
            ->where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($items) use (&$published): void {
                foreach ($items as $candidate) {
                    DB::transaction(function () use ($candidate, &$published): void {
                        $item = PublicContent::query()->lockForUpdate()->find($candidate->id);
                        if (!$item || $item->status !== 'scheduled' || !$item->scheduled_at || $item->scheduled_at->isFuture()) {
                            return;
                        }

                        $item->status = 'published';
                        $item->published_at = now();
                        $item->scheduled_at = null;
                        $item->save();

                        AuditLog::create([
                            'user_id' => null,
                            'tenant_id' => null,
                            'action' => 'public_content.scheduled_publish',
                            'resource_type' => 'public_content',
                            'resource_id' => (string) $item->id,
                            'old_values' => ['status' => 'scheduled'],
                            'new_values' => ['status' => 'published', 'version' => $item->version],
                            'metadata' => ['public_id' => $item->public_id],
                        ]);

                        $published++;
                    });
                }
            });

        $this->info("Published {$published} scheduled public content item(s).");

        return self::SUCCESS;
    }
}
