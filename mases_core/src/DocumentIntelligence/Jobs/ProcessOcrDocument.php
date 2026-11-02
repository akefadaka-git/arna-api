<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\DocumentIntelligence\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use MaaleArna\MASES\Core\DocumentIntelligence\Contracts\OcrEngineInterface;
use MaaleArna\MASES\Core\DocumentIntelligence\Models\OcrPage;
use MaaleArna\MASES\Core\DocumentIntelligence\Models\OcrProcessingJob as OcrJob;
use MaaleArna\MASES\Core\DocumentIntelligence\Models\OcrResult;
use MaaleArna\MASES\Core\DocumentIntelligence\Events\OcrResultReady;
use RuntimeException;
use Throwable;

final class ProcessOcrDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 300;

    public function __construct(public readonly int $ocrJobId) {}

    public function handle(OcrEngineInterface $engine): void
    {
        $job = DB::transaction(function (): ?OcrJob {
            $locked = OcrJob::query()->lockForUpdate()->findOrFail($this->ocrJobId);
            if ($locked->status !== 'queued') {
                return null;
            }
            $locked->update(['status' => 'processing', 'attempts' => $locked->attempts + 1, 'started_at' => now()]);
            return $locked->fresh('file');
        });
        if ($job === null) {
            return;
        }
        $file = $job->file;
        if (!$file || (string) $file->tenant_id !== (string) $job->tenant_id ||
            (int) $file->version !== (int) $job->file_version || $file->deleted_at !== null) {
            $job->update(['status' => 'failed', 'failure_code' => 'SOURCE_VERSION_INVALID', 'completed_at' => now()]);
            return;
        }
        if (!in_array($file->mime_type, (array) config('ocr.allowed_mime_types'), true) ||
            !in_array(strtolower((string) $file->extension), (array) config('ocr.allowed_extensions'), true) ||
            (int) $file->size > (int) config('ocr.max_file_bytes')) {
            $job->update(['status' => 'rejected', 'failure_code' => 'SOURCE_FILE_NOT_SUPPORTED', 'completed_at' => now()]);
            return;
        }

        $disk = Storage::disk($file->disk);
        $stream = $disk->readStream($file->path);
        if (!is_resource($stream)) {
            throw new RuntimeException('OCR_SOURCE_UNAVAILABLE');
        }
        $temporary = tempnam(sys_get_temp_dir(), 'arna-ocr-');
        if ($temporary === false) {
            fclose($stream);
            throw new RuntimeException('OCR_TEMP_FILE_UNAVAILABLE');
        }

        try {
            $target = fopen($temporary, 'wb');
            if (!is_resource($target)) {
                throw new RuntimeException('OCR_TEMP_FILE_UNAVAILABLE');
            }
            stream_copy_to_stream($stream, $target, (int) config('ocr.max_file_bytes') + 1);
            fclose($target);
            fclose($stream);
            chmod($temporary, 0600);
            if (filesize($temporary) > (int) config('ocr.max_file_bytes')) {
                throw new RuntimeException('OCR_FILE_SIZE_LIMIT_EXCEEDED');
            }
            $checksum = hash_file('sha256', $temporary);
            $recognized = $engine->recognize($temporary, (string) $file->mime_type, $job->languages);
            $allText = implode("\n\n", array_column($recognized['pages'], 'text'));
            $confidences = array_values(array_filter(array_column($recognized['pages'], 'confidence'), static fn ($v) => $v !== null));

            DB::transaction(function () use ($job, $file, $recognized, $allText, $checksum, $confidences): void {
                $result = OcrResult::query()->create([
                    'tenant_id' => $job->tenant_id,
                    'job_id' => $job->id,
                    'file_id' => $file->id,
                    'file_version' => $job->file_version,
                    'engine_name' => $recognized['engine'],
                    'engine_version' => $recognized['version'],
                    'languages' => $job->languages,
                    'full_text' => $allText,
                    'mean_confidence' => $confidences === [] ? null : round(array_sum($confidences) / count($confidences), 2),
                    'source_checksum' => $checksum,
                    'result_checksum' => hash('sha256', $allText),
                    'metadata' => ['verification_status' => 'pending', 'page_count' => count($recognized['pages'])],
                ]);
                foreach ($recognized['pages'] as $page) {
                    OcrPage::query()->create([
                        'tenant_id' => $job->tenant_id,
                        'result_id' => $result->id,
                        'page_number' => $page['page_number'],
                        'text' => $page['text'],
                        'mean_confidence' => $page['confidence'],
                        'blocks' => $page['blocks'],
                    ]);
                }
                $job->update(['status' => 'awaiting_review', 'completed_at' => now(), 'failure_code' => null, 'failure_message' => null]);
                DB::afterCommit(static function () use ($job, $file, $result): void {
                    event(new OcrResultReady((int) $job->tenant_id, (int) $file->id, (int) $result->id));
                });
            });
        } catch (Throwable $exception) {
            report($exception);
            throw $exception;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            @unlink($temporary);
        }
    }

    public function failed(Throwable $exception): void
    {
        OcrJob::query()->whereKey($this->ocrJobId)->whereNotIn('status', ['completed', 'awaiting_review'])
            ->update(['status' => 'failed', 'failure_code' => 'QUEUE_ATTEMPTS_EXHAUSTED', 'failure_message' => 'OCR worker exhausted its retry attempts.', 'completed_at' => now()]);
    }
}
