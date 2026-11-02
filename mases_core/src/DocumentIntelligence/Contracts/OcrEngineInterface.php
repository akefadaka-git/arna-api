<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\DocumentIntelligence\Contracts;

interface OcrEngineInterface
{
    /**
     * @return array{engine:string,version:string,pages:list<array{page_number:int,text:string,confidence:?float,blocks:list<array<string,mixed>>}>}
     */
    public function recognize(string $sourcePath, string $mimeType, string $languages): array;
}
