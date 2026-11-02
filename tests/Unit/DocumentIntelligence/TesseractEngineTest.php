<?php

declare(strict_types=1);

namespace Tests\Unit\DocumentIntelligence;

use MaaleArna\MASES\Core\DocumentIntelligence\Engines\Tesseract\TesseractEngine;
use RuntimeException;
use Tests\TestCase;

final class TesseractEngineTest extends TestCase
{
    public function test_only_approved_printed_ocr_languages_are_accepted(): void
    {
        config(['ocr.languages' => ['amh', 'eng']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OCR_LANGUAGE_NOT_SUPPORTED');

        (new TesseractEngine())->recognize('/not-read/because-language-is-invalid', 'image/png', 'amh+tir');
    }
}
