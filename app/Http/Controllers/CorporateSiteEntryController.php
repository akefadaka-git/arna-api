<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use MaaleArna\MASES\Core\Audit\Models\AuditLog;
use MaaleArna\MASES\Core\Domains\PublicContent\Models\CorporateSiteEntry;
use MaaleArna\MASES\Core\Domains\PublicContent\Models\CorporateSiteInquiry;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class CorporateSiteEntryController extends Controller
{
    private const TYPES = ['news', 'announcement', 'publication', 'media', 'career', 'opportunity', 'business', 'service', 'advertisement', 'marketplace'];

    public function index(string $type, Request $request): JsonResponse
    {
        abort_unless(in_array($type, self::TYPES, true), 404);
        $locale = $this->locale($request->query('locale', 'am'));
        $entries = CorporateSiteEntry::query()->published()->where('entry_type', $type)
            ->latest('published_at')->paginate(min(max((int) $request->query('per_page', 12), 1), 50));

        return response()->json(['success' => true, 'data' => [
            'items' => $entries->getCollection()->map(fn (CorporateSiteEntry $entry): array => $this->resource($entry, $locale)),
            'current_page' => $entries->currentPage(),
            'last_page' => $entries->lastPage(),
            'total' => $entries->total(),
        ]]);
    }

    public function show(string $type, string $slug, Request $request): JsonResponse
    {
        abort_unless(in_array($type, self::TYPES, true), 404);
        $locale = $this->locale($request->query('locale', 'am'));
        $column = $locale === 'am' ? 'slug_am' : 'slug_en';
        $entry = CorporateSiteEntry::query()->published()->where('entry_type', $type)->where($column, $slug)->firstOrFail();

        return response()->json(['success' => true, 'data' => $this->resource($entry, $locale)]);
    }

    public function submit(Request $request, string $type): JsonResponse
    {
        abort_unless(in_array($type, ['career', 'opportunity', 'advertisement', 'marketplace', 'business', 'service'], true), 404);
        $validated = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:50'],
            'title' => ['required', 'string', 'max:180'],
            'description' => ['required', 'string', 'max:12000'],
            'organization' => ['nullable', 'string', 'max:180'],
            'locale' => ['nullable', 'in:am,en'],
        ])->validate();

        $entry = CorporateSiteEntry::query()->create([
            'entry_type' => $type,
            'translations' => [
                $validated['locale'] ?? 'am' => [
                    'title' => $validated['title'],
                    'body' => $validated['description'],
                    'contact_name' => $validated['name'],
                    'contact_email' => $validated['email'],
                    'contact_phone' => $validated['phone'] ?? null,
                    'organization' => $validated['organization'] ?? null,
                ],
            ],
            'payload' => ['submission_channel' => 'public_form'],
        ]);

        return response()->json(['success' => true, 'data' => ['reference' => $entry->public_id, 'status' => 'received']], 202);
    }

    public function uploadMedia(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:2000'],
            'locale' => ['required', 'in:am,en'],
            'file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,pdf,mp4'],
        ])->validate();

        $file = $request->file('file');
        $path = $file->storeAs('corporate-quarantine', Str::uuid() . '.' . $file->guessExtension(), 'local');
        $entry = CorporateSiteEntry::query()->create([
            'entry_type' => 'media',
            'translations' => [$validated['locale'] => [
                'title' => $validated['title'],
                'body' => $validated['description'] ?? '',
            ]],
            'asset_path' => $path,
            'asset_mime' => $file->getMimeType(),
            'asset_size' => $file->getSize(),
            'payload' => ['quarantine' => true, 'scan_status' => 'pending'],
        ]);

        return response()->json(['success' => true, 'data' => [
            'reference' => $entry->public_id,
            'status' => 'pending_review',
            'message' => 'The media file is quarantined until an administrator verifies it.',
        ]], 202);
    }

    public function asset(string $publicId)
    {
        $entry = CorporateSiteEntry::query()->published()->where('entry_type', 'media')->where('public_id', $publicId)->firstOrFail();
        abort_unless($entry->asset_path && Storage::disk('local')->exists($entry->asset_path), 404);
        abort_unless(in_array($entry->asset_mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf', 'video/mp4'], true), 404);

        return response()->file(Storage::disk('local')->path($entry->asset_path), [
            'Content-Type' => $entry->asset_mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    public function submitInquiry(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'inquiry_type' => ['nullable', 'in:general,investor,partnership,media,career,advertising,marketplace'],
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:50'],
            'subject' => ['required', 'string', 'max:200'],
            'message' => ['required', 'string', 'max:10000'],
            'website' => ['nullable', 'max:0'],
        ])->validate();

        $inquiry = CorporateSiteInquiry::query()->create([
            'inquiry_type' => $validated['inquiry_type'] ?? 'general',
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'subject' => $validated['subject'],
            'message' => $validated['message'],
        ]);

        return response()->json(['success' => true, 'data' => ['reference' => $inquiry->public_id, 'status' => 'received']], 202);
    }

    public function adminEntries(Request $request): JsonResponse
    {
        $query = CorporateSiteEntry::query()->orderByDesc('updated_at');
        if ($request->filled('status')) $query->where('status', $request->string('status')->toString());
        if ($request->filled('type')) $query->where('entry_type', $request->string('type')->toString());

        return response()->json(['success' => true, 'data' => $query->paginate(30)]);
    }

    public function review(Request $request, int $id, string $decision): JsonResponse
    {
        abort_unless(in_array($decision, ['approve', 'publish', 'reject', 'unpublish'], true), 404);
        $validated = Validator::make($request->all(), [
            'expected_version' => ['required', 'integer', 'min:1'],
            'moderation_note' => ['nullable', 'string', 'max:1000'],
        ])->validate();

        return DB::transaction(function () use ($request, $id, $decision, $validated): JsonResponse {
            $entry = CorporateSiteEntry::query()->lockForUpdate()->findOrFail($id);
            abort_unless($entry->version === (int) $validated['expected_version'], 409, 'This submission changed after it was loaded.');
            abort_unless((int) $entry->created_by !== (int) $request->user()->id, 403, 'The submitter cannot review their own submission.');
            $old = ['status' => $entry->status, 'version' => $entry->version];

            if ($decision === 'approve') {
                abort_unless($entry->status === 'draft', 422);
                $entry->status = 'approved';
                $entry->reviewed_by = $request->user()->id;
            } elseif ($decision === 'publish') {
                abort_unless($entry->status === 'approved', 422);
                if ($entry->entry_type === 'media') {
                    abort_unless(($entry->payload['scan_status'] ?? null) === 'clean', 422, 'Media must pass malware scanning before publication.');
                }
                $entry->status = 'published';
                $entry->published_at = now();
            } elseif ($decision === 'reject') {
                abort_unless(in_array($entry->status, ['draft', 'approved'], true), 422);
                $entry->status = 'rejected';
            } else {
                abort_unless($entry->status === 'published', 422);
                $entry->status = 'unpublished';
                $entry->published_at = null;
            }

            $entry->moderation_note = $validated['moderation_note'] ?? null;
            $entry->version++;
            $entry->save();
            AuditLog::create([
                'user_id' => $request->user()->id, 'tenant_id' => null,
                'action' => 'corporate_site_entry.' . $decision,
                'resource_type' => 'corporate_site_entry', 'resource_id' => (string) $entry->id,
                'old_values' => $old, 'new_values' => ['status' => $entry->status, 'version' => $entry->version],
                'ip_address' => $request->ip(), 'user_agent' => substr((string) $request->userAgent(), 0, 1000),
                'request_id' => null, 'metadata' => ['public_id' => $entry->public_id, 'entry_type' => $entry->entry_type],
            ]);

            return response()->json(['success' => true, 'data' => $entry]);
        });
    }

    public function adminInquiries(Request $request): JsonResponse
    {
        $query = CorporateSiteInquiry::query()->orderByDesc('created_at');
        if ($request->filled('status')) $query->where('status', $request->string('status')->toString());

        return response()->json(['success' => true, 'data' => $query->paginate(30)]);
    }

    public function updateInquiry(Request $request, int $id): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'status' => ['required', 'in:new,assigned,in_progress,resolved,closed,spam'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'internal_note' => ['nullable', 'string', 'max:5000'],
        ])->validate();
        $inquiry = CorporateSiteInquiry::query()->findOrFail($id);
        $inquiry->fill($validated);
        if (in_array($inquiry->status, ['resolved', 'closed', 'spam'], true)) $inquiry->handled_at = now();
        $inquiry->save();

        return response()->json(['success' => true, 'data' => $inquiry]);
    }

    private function locale(mixed $locale): string
    {
        return in_array($locale, ['am', 'en'], true) ? $locale : 'am';
    }

    private function resource(CorporateSiteEntry $entry, string $locale): array
    {
        $translation = $entry->translations[$locale] ?? $entry->translations['en'] ?? $entry->translations['am'] ?? [];
        return [
            'public_id' => $entry->public_id,
            'type' => $entry->entry_type,
            'slug' => $locale === 'am' ? $entry->slug_am : $entry->slug_en,
            'title' => (string) ($translation['title'] ?? ''),
            'summary' => (string) ($translation['summary'] ?? ''),
            'body' => (string) ($translation['body'] ?? ''),
            'payload' => $entry->payload,
            'asset_url' => $entry->asset_path ? url('/api/public/site/media/' . $entry->public_id . '/asset') : null,
            'seo' => $entry->seo[$locale] ?? null,
            'published_at' => $entry->published_at?->toIso8601String(),
        ];
    }
}
