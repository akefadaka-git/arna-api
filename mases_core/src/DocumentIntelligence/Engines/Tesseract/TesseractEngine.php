<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\DocumentIntelligence\Engines\Tesseract;

use MaaleArna\MASES\Core\DocumentIntelligence\Contracts\OcrEngineInterface;
use RuntimeException;

final class TesseractEngine implements OcrEngineInterface
{
    public function recognize(string $sourcePath, string $mimeType, string $languages): array
    {
        $this->assertLanguageSet($languages);
        $this->assertBinary((string) config('ocr.tesseract_binary'));

        $work = $this->temporaryDirectory();
        $images = [];
        try {
            if ($mimeType === 'application/pdf') {
                $images = $this->renderPdf($sourcePath, $work);
            } else {
                $images = [$sourcePath];
            }

            if (count($images) > (int) config('ocr.max_pages', 100)) {
                throw new RuntimeException('OCR_PAGE_LIMIT_EXCEEDED');
            }

            $pages = [];
            foreach ($images as $index => $imagePath) {
                $tsv = $this->run([
                    (string) config('ocr.tesseract_binary'),
                    $imagePath,
                    'stdout',
                    '-l', $languages,
                    '--psm', '3',
                    'tsv',
                ]);
                $pages[] = $this->parseTsv($tsv, $index + 1);
            }

            $version = trim($this->run([(string) config('ocr.tesseract_binary'), '--version']));
            return [
                'engine' => 'tesseract',
                'version' => strtok($version, "\n") ?: 'unknown',
                'pages' => $pages,
            ];
        } finally {
            foreach (glob($work . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            @rmdir($work);
        }
    }

    private function assertLanguageSet(string $languages): void
    {
        $allowed = (array) config('ocr.languages', ['amh', 'eng']);
        $parts = array_values(array_filter(explode('+', $languages)));
        if ($parts === [] || array_diff($parts, $allowed) !== []) {
            throw new RuntimeException('OCR_LANGUAGE_NOT_SUPPORTED');
        }
    }

    private function assertBinary(string $binary): void
    {
        if ($binary === '' || !is_executable($binary)) {
            throw new RuntimeException('OCR_ENGINE_NOT_INSTALLED');
        }
    }

    private function temporaryDirectory(): string
    {
        $base = (string) config('ocr.temp_directory');
        if (!is_dir($base) && !mkdir($base, 0700, true) && !is_dir($base)) {
            throw new RuntimeException('OCR_TEMP_DIRECTORY_UNAVAILABLE');
        }
        $path = $base . DIRECTORY_SEPARATOR . bin2hex(random_bytes(16));
        if (!mkdir($path, 0700)) {
            throw new RuntimeException('OCR_TEMP_DIRECTORY_UNAVAILABLE');
        }
        return $path;
    }

    /** @return list<string> */
    private function renderPdf(string $sourcePath, string $work): array
    {
        $pdfinfo = (string) config('ocr.pdfinfo_binary');
        $pdftoppm = (string) config('ocr.pdftoppm_binary');
        $this->assertBinary($pdfinfo);
        $this->assertBinary($pdftoppm);
        $info = $this->run([$pdfinfo, $sourcePath]);
        if (!preg_match('/^Pages:\s+(\d+)$/mi', $info, $matches)) {
            throw new RuntimeException('OCR_PDF_PAGE_COUNT_UNAVAILABLE');
        }
        $count = (int) $matches[1];
        if ($count < 1 || $count > (int) config('ocr.max_pages', 100)) {
            throw new RuntimeException('OCR_PAGE_LIMIT_EXCEEDED');
        }

        $prefix = $work . DIRECTORY_SEPARATOR . 'page';
        $this->run([
            $pdftoppm, '-r', (string) config('ocr.pdf_dpi', 200),
            '-png', '-f', '1', '-l', (string) $count, $sourcePath, $prefix,
        ]);
        $images = glob($prefix . '-*.png') ?: [];
        sort($images, SORT_NATURAL);
        if (count($images) !== $count) {
            throw new RuntimeException('OCR_PDF_RENDER_INCOMPLETE');
        }
        return $images;
    }

    /** @param list<string> $command */
    private function run(array $command): string
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes, null, ['PATH' => '/usr/bin:/bin', 'LANG' => 'C.UTF-8', 'LC_ALL' => 'C.UTF-8']);
        if (!is_resource($process)) {
            throw new RuntimeException('OCR_PROCESS_START_FAILED');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $maxOutputBytes = (int) config('ocr.max_process_output_bytes', 10485760);
        $deadline = microtime(true) + (int) config('ocr.timeout_seconds', 90);
        $exitCode = null;

        try {
            while (true) {
                $stdout .= stream_get_contents($pipes[1]) ?: '';
                $stderr .= stream_get_contents($pipes[2]) ?: '';
                if (strlen($stdout) + strlen($stderr) > $maxOutputBytes) {
                    proc_terminate($process, 9);
                    throw new RuntimeException('OCR_PROCESS_OUTPUT_LIMIT_EXCEEDED');
                }
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $exitCode = $status['exitcode'];
                    break;
                }
                if (microtime(true) >= $deadline) {
                    proc_terminate($process, 9);
                    throw new RuntimeException('OCR_PROCESS_TIMEOUT');
                }
                usleep(50000);
            }
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }

        if ($exitCode !== 0) {
            throw new RuntimeException('OCR_PROCESS_FAILED: ' . substr($stderr, 0, 1000));
        }
        return $stdout;
    }

    /** @return array{page_number:int,text:string,confidence:?float,blocks:list<array<string,mixed>>} */
    private function parseTsv(string $tsv, int $pageNumber): array
    {
        $lines = preg_split('/\r?\n/', trim($tsv)) ?: [];
        $blocks = [];
        $texts = [];
        $confidence = [];
        foreach (array_slice($lines, 1) as $line) {
            $columns = explode("\t", $line, 12);
            if (count($columns) < 12 || trim($columns[11]) === '') {
                continue;
            }
            $conf = (float) $columns[10];
            $text = trim($columns[11]);
            $blocks[] = [
                'left' => (int) $columns[6],
                'top' => (int) $columns[7],
                'width' => (int) $columns[8],
                'height' => (int) $columns[9],
                'confidence' => $conf >= 0 ? round($conf, 2) : null,
                'text' => $text,
            ];
            $texts[] = $text;
            if ($conf >= 0) {
                $confidence[] = $conf;
            }
        }
        return [
            'page_number' => $pageNumber,
            'text' => implode(' ', $texts),
            'confidence' => $confidence === [] ? null : round(array_sum($confidence) / count($confidence), 2),
            'blocks' => $blocks,
        ];
    }
}
