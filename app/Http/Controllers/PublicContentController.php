<?php

declare(strict_types=1);

namespace App\\Http\\Controllers;

use Illuminate\\Http\\JsonResponse;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Hash;
use Illuminate\\Support\\Facades\\Mail;
use Illuminate\\Support\\Facades\\Validator;
use Illuminate\\Support\\Str;
use MaaleArna\\MASES\\Core\\Audit\\Models\\AuditLog;
use MaaleArna\\MASES\\Core\\Auth\\Models\\User;
use MaaleArna\\MASES\\Core\\Domains\\PublicContent\\Models\\PublicContent;
use Symfony\\Component\\HttpKernel\\Exception\\ConflictHttpException;

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

    /**
     * Issue a short-lived one-time email code. The response never reveals
     * whether an address belongs to an ARNA account.
     */
    public function requestContributorVerification(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'email' => ['required', 'email:rfc', 'max:254'],
        ])->validate();

        $email = mb_strtolower(trim($validated['email']));
        $challengeId = (string) Str::uuid();
        $code = (string) random_int(100000, 999999);

        DB::table('public_content_email_verifications')->insert([
            'id' => $challengeId,
            'email' => $email,
            'code_hash' => Hash::make($code),
            'verification_token_hash' => null,
            'attempts' => 0,
            'expires_at' => now()->addMinutes(15),
            'verified_at' => null,
            'consumed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            Mail::raw(
                "Your ARNA contributor verification code is {$code}. It expires in 15 minutes. If you did not request this code, you can ignore this message.",
                static function ($message) use ($email): void {
                    $message->to($email)->subject('ARNA contributor email verification');
                },
            );
        } catch (\\Throwable $exception) {
            DB::table('public_content_email_verifications')->where('id', $challengeId)->delete();
            report($exception);

            return response()->json([
                'success' => false,
                'error' => ['message' => 'Email verification is temporarily unavailable. Please try again later.'],
            ], 503);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'challenge_id' => $challengeId,
                'expires_in_seconds' => 900,
            ],
            'message' => 'If the address can receive email, a verification code has been sent.',
        ], 202);
    }

    /**
     * Verify the one-time code and issue a short-lived opaque submission proof.
     */
    public function verifyContributorEmail(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'challenge_id' => ['required', 'uuid'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'code' => ['required', 'digits:6'],
        ])->validate();

        $email = mb_strtolower(trim($validated['email']));
        $proof = null;
        $outcome = DB::transaction(function () use ($validated, $email, &$proof): string {
            $challenge = DB::table('public_content_email_verifications')
                ->where('id', $validated['challenge_id'])
                ->lockForUpdate()
                ->first();

            if (!$challenge || $challenge->email !== $email || $challenge->consumed_at !== null ||
                $challenge->verified_at !== null || now()->greaterThanOrEqualTo($challenge->expires_at) ||
                (int) $challenge->attempts >= 5) {
                return 'invalid';
            }

            if (!Hash::check($validated['code'], $challenge->code_hash)) {
                DB::table('public_content_email_verifications')->where('id', $challenge->id)->update([
                    'attempts' => (int) $challenge->attempts + 1,
                    'updated_at' => now(),
                ]);
                return 'invalid';
            }

            $proof = Str::random(64);
            DB::table('public_content_email_verifications')->where('id', $challenge->id)->update([
                'verification_token_hash' => Hash::make($proof),
                'verified_at' => now(),
                'updated_at' => now(),
            ]);

            return 'verified';
        });

        if ($outcome !== 'verified' || $proof === null) {
            return response()->json([
                'success' => false,
                'error' => ['message' => 'The verification code is invalid, expired, or already used. Request a new code.'],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'verification_token' => $proof,
                'expires_in_seconds' => 900,
            ],
        ]);
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

        $user = $request->user();
        $rules = [
            'content_type' => ['required', 'string', 'in:news,article,announcement,press_release'],
            'title' => ['required', 'string', 'min:5', 'max:200'],
            'body' => ['required', 'string', 'min:20', 'max:50000'],
            'author_name' => ['required', 'string', 'min:2', 'max:160'],
            'consent' => ['required', 'accepted'],
        ];
        if ($user === null) {
            $rules['verification_challenge_id'] = ['required', 'uuid'];
            $rules['verification_token'] = ['required', 'string', 'size:64'];
        }
        $validated = Validator::make($request->all(), $rules)->validate();

        if ($user !== null && ($user->email === null || $user->email_verified_at === null)) {
            return response()->json([
                'success' => false,
                'error' => ['message' => 'Verify your account email before submitting public content.'],
            ], 403);
        }

        $item = DB::transaction(function () use ($request, $validated, $user): PublicContent {
            $verifiedEmail = mb_strtolower(trim((string) $user?->email));
            $verificationId = null;
            $verifiedAt = now();

            if ($user === null) {
                $challenge = DB::table('public_content_email_verifications')
                    ->where('id', $validated['verification_challenge_id'])
                    ->lockForUpdate()
                    ->first();

                if (!$challenge || $challenge->verified_at === null || $challenge->consumed_at !== null ||
                    now()->greaterThanOrEqualTo($challenge->expires_at) ||
                    !Hash::check($validated['verification_token'], $challenge->verification_token_hash ?? '')) {
                    abort(422, 'The contributor verification is invalid, expired, or already used.');
                }

                $verifiedEmail = $challenge->email;
                $verificationId = $challenge->id;
                DB::table('public_content_email_verifications')->where('id', $challenge->id)->update([
                    'consumed_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $item = PublicContent::create([
                'content_type' => $validated['content_type'],
                'title' => $validated['title'],
                'body' => $validated['body'],
                'author_name' => $validated['author_name'],
                'author_email' => $verifiedEmail,
                'author_email_verified_at' => $verifiedAt,
                'contributor_verification_id' => $verificationId,
                'consented_at' => now(),
                'submitted_by' => $user?->id,
            ]);

            $this->audit($request, 'public_content.submitted', $item, null, [
                'content_type' => $item->content_type,
                'status' => 'pending_review',
                'version' => $item->version,
                'author_email_verified' => true,
            ]);

            return $item;
        });

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

            $authorEmail = mb_strtolower(trim((string) $item->author_email));
            $reviewerEmail = mb_strtolower(trim((string) $actor->email));
            if ($item->submitted_by === $actor->id || ($authorEmail !== '' && $reviewerEmail !== '' && hash_equals($authorEmail, $reviewerEmail))) {
                abort(403, 'Authors cannot review or publish their own submissions.');
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
        ])->validate();

        $item = DB::transaction(function () use ($request, $id, $validated): PublicContent {
            $item = PublicContent::query()->lockForUpdate()->findOrFail($id);
            if ($item->version !== (int) $validated['expected_version']) {
                throw new ConflictHttpException('The content changed after it was loaded. Refresh before editing.');
            }
            $old = [
                'status' => $item->status,
                'version' => $item->version,
                'content_type' => $item->content_type,
                'title' => $item->title,
                'body_sha256' => hash('sha256', $item->body),
                'author_name' => $item->author_name,
                'author_email' => $item->author_email,
            ];
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
                'status' => $item->status,
                'version' => $item->version,
                'content_type' => $item->content_type,
                'title' => $item->title,
                'body_sha256' => hash('sha256', $item->body),
                'author_name' => $item->author_name,
                'author_email' => $item->author_email,
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
            'request_id' => $request->header('X-Request-ID') ? substr((string) $request->header('X-Request-ID'), 0, 64) : null,
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
