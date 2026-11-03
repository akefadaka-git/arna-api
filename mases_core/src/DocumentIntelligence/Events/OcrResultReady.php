<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\DocumentIntelligence\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class OcrResultReady
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $fileId,
        public readonly int $resultId,
    ) {}
}
