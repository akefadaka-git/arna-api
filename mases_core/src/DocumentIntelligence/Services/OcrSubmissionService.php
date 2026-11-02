<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\DocumentIntelligence\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use MaaleArna\MASES\Core\DocumentIntelligence\Jobs\ProcessOcrDocument;
use MaaleArna\MASES\Core\DocumentIntelligence\Models\OcrProcessingJob;
use MaaleArna\MASES\Core\DocumentIntelligence\Models\OcrProcessingEvent;
use MaaleArna\MASES\Core\Files\Models\File;
use MaaleArna\MASES\Core\Auth\Models\User;
use RuntimeException;

final class OcrSubmissionService
{
    public function submit(File $file, User $user, int|string $tenantId, string $idempotencyKey, string $languages): OcrProcessingJob
    {
        if ($tenantId === '' || (string) $file->tenant_id !== (string) $tenantId || $file->deleted_at !== null) {
            throw new RuntimeException('OCR_SOURCE_ACCESS_DENIED');
        }
        if (!in_array($file->mime_type, (array) config('ocr.allowed_mime_types'), true) ||
            !in_array(strtolower((string) $file->extension), (array) config('ocr.allowed_extensions'), true) ||
            (int) $file->size > (int) config('ocr.max_file_bytes')) {
            throw new RuntimeException('OCR_SOURCE_FILE_NOT_SUPPORTED');
        }
        $allowed = (array) config('ocr.languages', ['amh', 'eng']);
        $parts = array_values(array_unique(array_filter(explode('+', $languages))));
        if ($parts === [] || array_diff($parts, $allowed) !== []) {
            throw new RuntimeException('OCR_LANGUAGE_NOT_SUPPORTED');
        }

        $key = hash('sha256', $idempotencyKey);
        $requestHash = hash('sha256', implode('|', [(string) $file->id, (string) $file->version, $languages]));
        return DB::transaction(function () use ($file, $user, $tenantId, $key, $requestHash, $languages): OcrProcessingJob {
            $existing = OcrProcessingJob::query()
                ->where('tenant_id', $tenantId)->where('idempotency_key', $key)->first();
            if ($existing) {
                if (!hash_equals($existing->request_hash, $requestHash)) {
                    throw new RuntimeException('OCR_IDEMPOTENCY_CONFLICT');
                }
                return $existing;
            }

            $job = OcrProcessingJob::query()->create([
                'tenant_id' => $tenantId,
                'file_id' => $file->id,
                'file_version' => $file->version,
                'requested_by' => $user->id,
                'idempotency_key' => $key,
                'request_hash' => $requestHash,
                'languages' => $languages,
                'status' => 'queued',
            ]);
            OcrProcessingEvent::query()->create([
                'tenant_id' => $tenantId, 'job_id' => $job->id, 'actor_id' => $user->id,
                'event' => 'job.created', 'details' => ['file_id' => $file->id, 'file_version' => $file->version],
            ]);
            ProcessOcrDocument::dispatch($job->id)
                ->onConnection((string) config('ocr.connection'))
                ->onQueue((string) config('ocr.queue'))
                ->afterCommit();
            return $job;
        });
    }
}
