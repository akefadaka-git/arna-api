<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\DocumentIntelligence\Providers;

use Illuminate\Support\ServiceProvider;
use MaaleArna\MASES\Core\DocumentIntelligence\Contracts\OcrEngineInterface;
use MaaleArna\MASES\Core\DocumentIntelligence\Engines\Tesseract\TesseractEngine;

final class DocumentIntelligenceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(config_path('ocr.php'), 'ocr');
        $this->app->bind(OcrEngineInterface::class, TesseractEngine::class);
    }
}
