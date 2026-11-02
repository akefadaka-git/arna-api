<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\DocumentIntelligence\Models\OcrCorrection;
use MaaleArna\MASES\Core\DocumentIntelligence\Models\OcrProcessingJob;
use MaaleArna\MASES\Core\DocumentIntelligence\Models\OcrResult;
use MaaleArna\MASES\Core\DocumentIntelligence\Models\OcrVerification;
use MaaleArna\MASES\Core\DocumentIntelligence\Models\OcrProcessingEvent;
use MaaleArna\MASES\Core\DocumentIntelligence\Jobs\ProcessOcrDocument;
use MaaleArna\MASES\Core\Audit\Traits\LogsAudit;
use MaaleArna\MASES\Core\DocumentIntelligence\Services\OcrSubmissionService;
use MaaleArna\MASES\Core\Files\Models\File;
use RuntimeException;

final class OcrController extends Controller
{
    use LogsAudit;

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly OcrSubmissionService $submissions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        $this->authorize('ocr.job.view', $request->user(), $tenantId);
        $jobs = OcrProcessingJob::query()->where('tenant_id', $tenantId)
            ->with(['result:id,job_id,mean_confidence', 'file:id,original_name,version'])
            ->latest()->paginate(min(max((int) $request->integer('per_page', 20), 1), 100));

        return response()->json(['success' => true, 'data' => $jobs->items(), 'meta' => [
            'current_page' => $jobs->currentPage(), 'last_page' => $jobs->lastPage(),
            'per_page' => $jobs->perPage(), 'total' => $jobs->total(),
        ]]);
    }

    public function submit(Request $request): JsonResponse
    {
        abort_unless(config('ocr.enabled'), 503, 'OCR processing is not enabled on this environment.');
        $tenantId = $this->tenantId();
        /** @var User $user */
        $user = $request->user();
        $this->authorize('ocr.job.create', $user, $tenantId);
        $validated = $request->validate([
            'file_id' => ['required', 'integer', 'min:1'],
            'languages' => ['sometimes', 'string', 'max:32', 'regex:/^(amh|eng)(\+(amh|eng))*$/'],
            'idempotency_key' => ['sometimes', 'string', 'min:16', 'max:200'],
        ]);
        $idempotencyKey = (string) ($validated['idempotency_key'] ?? $request->header('Idempotency-Key', ''));
        if (strlen($idempotencyKey) < 16 || strlen($idempotencyKey) > 200) {
            abort(422, 'Provide an idempotency_key in the request body or Idempotency-Key header between 16 and 200 characters.');
        }
        $file = File::query()->where('tenant_id', $tenantId)->findOrFail($validated['file_id']);

        try {
            $job = $this->submissions->submit(
                $file, $user, $tenantId, $idempotencyKey, $validated['languages'] ?? 'amh+eng'
            );
        } catch (RuntimeException $exception) {
            $conflict = $exception->getMessage() === 'OCR_IDEMPOTENCY_CONFLICT';
            abort($conflict ? 409 : 422, $conflict
                ? 'The idempotency key was already used with a different request.'
                : 'The source file cannot be processed.');
        }

        $this->logAudit(action: 'ocr.job.created', resourceType: 'ocr_job', resourceId: (string) $job->id, newValues: ['file_id' => $job->file_id, 'file_version' => $job->file_version, 'languages' => $job->languages]);
        return response()->json(['success' => true, 'data' => $job], 202);
    }

    public function retry(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->tenantId();
        $this->authorize('ocr.job.retry', $request->user(), $tenantId);
        /** @var User $user */
        $user = $request->user();
        $job = DB::transaction(function () use ($tenantId, $id, $user): OcrProcessingJob {
            $job = OcrProcessingJob::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($id);
            abort_unless($job->status === 'failed', 409, 'Only failed OCR jobs may be retried.');
            $job->update(['status' => 'queued', 'failure_code' => null, 'failure_message' => null, 'completed_at' => null]);
            OcrProcessingEvent::query()->create(['tenant_id' => $tenantId, 'job_id' => $job->id, 'actor_id' => $user->id, 'event' => 'job.retried']);
            return $job;
        });
        ProcessOcrDocument::dispatch($job->id)
            ->onConnection((string) config('ocr.connection'))
            ->onQueue((string) config('ocr.queue'))
            ->afterCommit();
        $this->logAudit(action: 'ocr.job.retried', resourceType: 'ocr_job', resourceId: (string) $job->id);
        return response()->json(['success' => true, 'data' => $job], 202);
    }

    public function showJob(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->tenantId();
        $this->authorize('ocr.job.view', $request->user(), $tenantId);
        $job = OcrProcessingJob::query()->where('tenant_id', $tenantId)->with(['result:id,job_id,mean_confidence', 'file:id,original_name,version'])->findOrFail($id);
        $this->logAudit(action: 'ocr.job.viewed', resourceType: 'ocr_job', resourceId: (string) $job->id);
        return response()->json(['success' => true, 'data' => $job]);
    }

    public function events(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->tenantId();
        $this->authorize('ocr.audit.view', $request->user(), $tenantId);
        $job = OcrProcessingJob::query()->where('tenant_id', $tenantId)->findOrFail($id);
        $events = OcrProcessingEvent::query()->where('tenant_id', $tenantId)->where('job_id', $job->id)
            ->with('actor:id')->orderByDesc('created_at')->paginate(50);
        return response()->json(['success' => true, 'data' => $events->items(), 'meta' => [
            'current_page' => $events->currentPage(), 'last_page' => $events->lastPage(),
            'per_page' => $events->perPage(), 'total' => $events->total(),
        ]]);
    }

    public function showResult(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->tenantId();
        $this->authorize('ocr.result.view', $request->user(), $tenantId);
        $result = OcrResult::query()->where('tenant_id', $tenantId)->with(['pages', 'verifications', 'corrections'])->findOrFail($id);
        $this->logAudit(action: 'ocr.result.viewed', resourceType: 'ocr_result', resourceId: (string) $result->id);
        return response()->json(['success' => true, 'data' => $result]);
    }

    public function search(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        $this->authorize('ocr.result.search', $request->user(), $tenantId);
        $validated = $request->validate(['q' => ['required', 'string', 'min:2', 'max:200']]);
        $query = OcrResult::query()->where('tenant_id', $tenantId);
        if (DB::getDriverName() === 'mysql') {
            $query->whereRaw('MATCH(full_text) AGAINST (? IN BOOLEAN MODE)', [$validated['q'] . '*']);
        } else {
            $query->where('full_text', 'like', '%' . addcslashes($validated['q'], '%_\\\\') . '%');
        }
        $results = $query->with('file:id,original_name,version')->latest()->paginate(20);
        $this->logAudit(action: 'ocr.result.searched', resourceType: 'ocr_search', newValues: ['query_length' => mb_strlen($validated['q']), 'result_count' => $results->total()]);
        return response()->json(['success' => true, 'data' => $results->items(), 'meta' => [
            'current_page' => $results->currentPage(), 'last_page' => $results->lastPage(),
            'per_page' => $results->perPage(), 'total' => $results->total(),
        ]]);
    }

    public function verify(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->tenantId();
        /** @var User $user */
        $user = $request->user();
        $this->authorize('ocr.result.verify', $user, $tenantId);
        $validated = $request->validate([
            'decision' => ['required', 'in:verified,rejected'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);
        $result = OcrResult::query()->where('tenant_id', $tenantId)->findOrFail($id);

        $verification = DB::transaction(function () use ($result, $user, $tenantId, $validated): OcrVerification {
            $record = OcrVerification::query()->create([
                'tenant_id' => $tenantId, 'result_id' => $result->id,
                'verified_by' => $user->id, 'decision' => $validated['decision'],
                'notes' => $validated['notes'] ?? null, 'verified_at' => now(),
            ]);
            $metadata = $result->metadata ?? [];
            $metadata['verification_status'] = $validated['decision'];
            $metadata['last_verified_at'] = now()->toIso8601String();
            $result->update(['metadata' => $metadata]);
            return $record;
        });

        OcrProcessingEvent::query()->create(['tenant_id' => $tenantId, 'job_id' => $result->job_id, 'actor_id' => $user->id, 'event' => 'result.verified', 'details' => ['decision' => $validated['decision']]]);
        $this->logAudit(action: 'ocr.result.verified', resourceType: 'ocr_result', resourceId: (string) $result->id, newValues: ['decision' => $validated['decision']]);
        return response()->json(['success' => true, 'data' => $verification], 201);
    }

    public function correct(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->tenantId();
        /** @var User $user */
        $user = $request->user();
        $this->authorize('ocr.result.correct', $user, $tenantId);
        $validated = $request->validate([
            'page_number' => ['nullable', 'integer', 'min:1'],
            'original_text' => ['required', 'string', 'max:20000'],
            'corrected_text' => ['required', 'string', 'max:20000'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $result = OcrResult::query()->where('tenant_id', $tenantId)->with('pages')->findOrFail($id);
        if (isset($validated['page_number']) && !$result->pages->contains('page_number', $validated['page_number'])) {
            abort(422, 'The requested page does not belong to this result.');
        }
        $correction = OcrCorrection::query()->create([
            'tenant_id' => $tenantId, 'result_id' => $result->id, 'corrected_by' => $user->id,
            'page_number' => $validated['page_number'] ?? null,
            'original_text' => $validated['original_text'],
            'corrected_text' => $validated['corrected_text'],
            'reason' => $validated['reason'] ?? null,
        ]);
        OcrProcessingEvent::query()->create(['tenant_id' => $tenantId, 'job_id' => $result->job_id, 'actor_id' => $user->id, 'event' => 'result.corrected', 'details' => ['correction_id' => $correction->id, 'page_number' => $correction->page_number]]);
        $this->logAudit(action: 'ocr.result.corrected', resourceType: 'ocr_result', resourceId: (string) $result->id, newValues: ['correction_id' => $correction->id, 'page_number' => $correction->page_number]);
        return response()->json(['success' => true, 'data' => $correction], 201);
    }

    private function tenantId(): int|string
    {
        $tenantId = mases_tenant_id();
        abort_if($tenantId === null || $tenantId === '', 403, 'A verified tenant context is required.');
        return $tenantId;
    }

    private function authorize(string $permission, ?User $user, int|string $tenantId): void
    {
        abort_unless($user, 401);
        $this->authorization->requirePermission(
            permissionSlug: $permission,
            user: $user,
            tenantId: $tenantId,
        );
    }
}
