<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use MaaleArna\MASES\Core\Audit\Models\AuditLog;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\PublicContent\Models\PublicContent;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class PublicContentController extends Controller
{
    public function publicIndex(Request $request): JsonResponse
    {
        $items = PublicContent::query()->published()
            ->when($request->filled('type'), fn ($query) => $query->where('content_type', $request->string('type')->toString()))
            ->orderByDesc('published_at')
            ->paginate(min(max((int) $request->query('per_page', 12), 1), 50));

        return response()->json([
            'success' => true,
            'data' => $items->through(fn (PublicContent $item): array => $this->publicResource($item)),
        ]);
    }

    public function publicShow(string $publicId): JsonResponse
    {
        $item = PublicContent::query()->published()->where('public_id', $publicId)->firstOrFail();

        return response()->json(['success' => true, 'data' => $this->publicResource($item)]);
    }

    public function submit(Request $request): JsonResponse
    {
        // Quietly accept but discard automated honeypot submissions.
        if (is_string($request->input('website')) && trim($request->input('website')) !== '') {
            return response()->json([
                'success' => true,
                'data' => ['reference' => null, 'status' => 'received'],
            ], 202);
        }

        $validated = Validator::make($request->all(), [
            'content_type' => ['required', 'string', 'in:news,article,announcement,press_release'],
            'title' => ['required', 'string', 'min:5', 'max:200'],
            'body' => ['required', 'string', 'min:20', 'max:50000'],
            'author_name' => ['required', 'string', 'min:2', 'max:160'],
            'author_email' => ['nullable', 'email:rfc', 'max:254'],
            'consent' => ['required', 'accepted'],
        ])->validate();

        $user = $request->user();
        $item = PublicContent::create([
            ...array_diff_key($validated, ['consent' => true]),
            'consented_at' => now(),
            'submitted_by' => $user?->id,
        ]);

        $this->audit($request, 'public_content.submitted', $item, null, [
            'content_type' => $item->content_type,
            'status' => 'pending_review',
            'version' => $item->version,
        ]);

        return response()->json([
            'success' => true,
            'data' => ['reference' => $item->public_id, 'status' => 'pending_review'],
            'message' => 'Your submission has been received for administrative review.',
        ], 201);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $items = PublicContent::query()
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('type'), fn ($query) => $query->where('content_type', $request->string('type')->toString()))
            ->orderByRaw("CASE WHEN status = 'pending_review' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->paginate(min(max((int) $request->query('per_page', 25), 1), 100));

        return response()->json(['success' => true, 'data' => $items]);
    }

    public function review(Request $request, int $id, string $decision): JsonResponse
    {
        abort_unless(in_array($decision, ['approve', 'reject', 'request-changes', 'publish', 'unpublish'], true), 404);

        $validated = Validator::make($request->all(), [
            'expected_version' => ['required', 'integer', 'min:1'],
            'reason' => [$decision === 'approve' || $decision === 'publish' ? 'nullable' : 'required', 'string', 'max:2000'],
            'scheduled_at' => [$decision === 'publish' ? 'nullable' : 'prohibited', 'date', 'after:now'],
        ])->validate();

        /** @var User $actor */
        $actor = $request->user();

        $item = DB::transaction(function () use ($request, $id, $decision, $validated, $actor): PublicContent {
            $item = PublicContent::query()->lockForUpdate()->findOrFail($id);
            if ($item->version !== (int) $validated['expected_version']) {
                throw new ConflictHttpException('The content changed after it was loaded. Refresh before reviewing.');
            }

            if (in_array($decision, ['approve', 'publish'], true) && $item->submitted_by === $actor->id) {
                abort(403, 'Authors cannot approve or publish their own submissions.');
            }

            $old = ['status' => $item->status, 'version' => $item->version];
            $item->reviewed_by = $actor->id;
            $item->reviewed_at = now();
            $item->review_note = $validated['reason'] ?? null;

            if ($decision === 'approve') {
                abort_unless(in_array($item->status, ['pending_review', 'changes_requested'], true), 409);
                $item->status = 'approved';
            } elseif ($decision === 'reject') {
                abort_unless(in_array($item->status, ['pending_review', 'changes_requested'], true), 409);
                $item->status = 'rejected';
            } elseif ($decision === 'request-changes') {
                abort_unless(in_array($item->status, ['pending_review', 'changes_requested'], true), 409);
                $item->status = 'changes_requested';
            } elseif ($decision === 'publish') {
                abort_unless(in_array($item->status, ['approved', 'scheduled'], true), 409);
                if (!empty($validated['scheduled_at'])) {
                    $item->status = 'scheduled';
                    $item->scheduled_at = $validated['scheduled_at'];
                    $item->published_at = null;
                } else {
                    $item->status = 'published';
                    $item->scheduled_at = null;
                    $item->published_at = now();
                }
            } else {
                abort_unless($item->status === 'published', 409);
                $item->status = 'unpublished';
                $item->published_at = null;
                $item->scheduled_at = null;
            }

            $item->save();
            $this->audit($request, 'public_content.' . str_replace('-', '_', $decision), $item, $old, [
                'status' => $item->status,
                'version' => $item->version,
                'reason' => $item->review_note,
                'scheduled_at' => $item->scheduled_at?->toIso8601String(),
            ]);

            return $item;
        });

        return response()->json(['success' => true, 'data' => $this->adminResource($item)]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'expected_version' => ['required', 'integer', 'min:1'],
            'content_type' => ['required', 'string', 'in:news,article,announcement,press_release'],
            'title' => ['required', 'string', 'min:5', 'max:200'],
            'body' => ['required', 'string', 'min:20', 'max:50000'],
            'author_name' => ['required', 'string', 'min:2', 'max:160'],
            'author_email' => ['nullable', 'email:rfc', 'max:254'],
        ])->validate();

        $item = DB::transaction(function () use ($request, $id, $validated): PublicContent {
            $item = PublicContent::query()->lockForUpdate()->findOrFail($id);
            if ($item->version !== (int) $validated['expected_version']) {
                throw new ConflictHttpException('The content changed after it was loaded. Refresh before editing.');
            }
            $old = ['status' => $item->status, 'version' => $item->version];
            $item->fill(collect($validated)->except('expected_version')->all());
            $item->version++;
            $item->status = 'pending_review';
            $item->reviewed_by = null;
            $item->reviewed_at = null;
            $item->review_note = null;
            $item->scheduled_at = null;
            $item->published_at = null;
            $item->save();

            $this->audit($request, 'public_content.edited_requeued', $item, $old, [
                'status' => $item->status, 'version' => $item->version,
            ]);
            return $item;
        });

        return response()->json(['success' => true, 'data' => $this->adminResource($item)]);
    }

    private function audit(Request $request, string $action, PublicContent $item, ?array $old, array $new): void
    {
        AuditLog::create([
            'user_id' => $request->user()?->id,
            'tenant_id' => null,
            'action' => $action,
            'resource_type' => 'public_content',
            'resource_id' => (string) $item->id,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1000),
            'request_id' => $request->header('X-Request-ID'),
            'metadata' => ['public_id' => $item->public_id],
        ]);
    }

    private function publicResource(PublicContent $item): array
    {
        return [
            'id' => $item->public_id,
            'type' => $item->content_type,
            'title' => $item->title,
            'body' => $item->body,
            'published_at' => $item->published_at?->toIso8601String(),
        ];
    }

    private function adminResource(PublicContent $item): array
    {
        return [
            'id' => $item->id,
            'public_id' => $item->public_id,
            'content_type' => $item->content_type,
            'title' => $item->title,
            'body' => $item->body,
            'author_name' => $item->author_name,
            'author_email' => $item->author_email,
            'submitted_by' => $item->submitted_by,
            'status' => $item->status,
            'version' => $item->version,
            'review_note' => $item->review_note,
            'created_at' => $item->created_at?->toIso8601String(),
            'scheduled_at' => $item->scheduled_at?->toIso8601String(),
            'published_at' => $item->published_at?->toIso8601String(),
        ];
    }
}
