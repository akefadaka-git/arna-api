<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use MaaleArna\MASES\Core\Audit\Models\AuditLog;
use MaaleArna\MASES\Core\Domains\PublicContent\Models\CorporateSitePage;

final class CorporateSiteController extends Controller
{
    public function home(Request $request): JsonResponse
    {
        $locale = $this->locale($request->query('locale', 'am'));
        $page = CorporateSitePage::query()->published()->where('page_key', 'home')->first();

        if (!$page) {
            return response()->json(['success' => true, 'data' => ['locale' => $locale, 'page' => null, 'sections' => []]]);
        }

        return response()->json(['success' => true, 'data' => $this->resource($page, $locale)]);
    }

    public function page(string $locale, string $slug): JsonResponse
    {
        $locale = $this->locale($locale);
        $column = $locale === 'am' ? 'slug_am' : 'slug_en';
        $page = CorporateSitePage::query()->published()->where($column, $slug)->firstOrFail();

        return response()->json(['success' => true, 'data' => $this->resource($page, $locale)]);
    }


    public function adminIndex(): JsonResponse
    {
        $pages = CorporateSitePage::query()->orderBy('page_key')->paginate(30);

        return response()->json(['success' => true, 'data' => $pages]);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'page_key' => ['required', 'string', 'max:100', 'alpha_dash', 'unique:corporate_site_pages,page_key'],
            'slug_am' => ['nullable', 'string', 'max:180', 'unique:corporate_site_pages,slug_am'],
            'slug_en' => ['nullable', 'string', 'max:180', 'unique:corporate_site_pages,slug_en'],
            'translations' => ['required', 'array'],
            'translations.am' => ['sometimes', 'array'],
            'translations.en' => ['sometimes', 'array'],
            'sections' => ['nullable', 'array', 'max:50'],
            'seo' => ['nullable', 'array'],
        ])->validate();

        $page = CorporateSitePage::query()->create([
            ...$validated,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);
        $this->audit($request, 'corporate_site_page.created', $page, null, ['page_key' => $page->page_key, 'status' => $page->status]);

        return response()->json(['success' => true, 'data' => $page], 201);
    }

    public function adminUpdate(Request $request, int $id): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'expected_version' => ['required', 'integer', 'min:1'],
            'slug_am' => ['sometimes', 'nullable', 'string', 'max:180', 'unique:corporate_site_pages,slug_am,' . $id],
            'slug_en' => ['sometimes', 'nullable', 'string', 'max:180', 'unique:corporate_site_pages,slug_en,' . $id],
            'translations' => ['sometimes', 'required', 'array'],
            'sections' => ['sometimes', 'nullable', 'array', 'max:50'],
            'seo' => ['sometimes', 'nullable', 'array'],
        ])->validate();

        return DB::transaction(function () use ($request, $id, $validated): JsonResponse {
            $page = CorporateSitePage::query()->lockForUpdate()->findOrFail($id);
            abort_unless($page->version === (int) $validated['expected_version'], 409, 'This page changed after it was loaded.');

            $old = ['version' => $page->version, 'status' => $page->status];
            $changes = collect($validated)->except('expected_version')->all();
            $page->fill($changes);
            $page->status = 'draft';
            $page->scheduled_at = null;
            $page->published_at = null;
            $page->reviewed_by = null;
            $page->updated_by = $request->user()->id;
            $page->version++;
            $page->save();
            $this->audit($request, 'corporate_site_page.updated', $page, $old, ['version' => $page->version, 'status' => 'draft']);

            return response()->json(['success' => true, 'data' => $page]);
        });
    }

    public function adminReview(Request $request, int $id, string $decision): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'expected_version' => ['required', 'integer', 'min:1'],
            'scheduled_at' => ['required_if:decision,schedule', 'nullable', 'date', 'after:now'],
        ])->validate();

        abort_unless(in_array($decision, ['approve', 'publish', 'schedule', 'unpublish'], true), 404);

        return DB::transaction(function () use ($request, $id, $decision, $validated): JsonResponse {
            $page = CorporateSitePage::query()->lockForUpdate()->findOrFail($id);
            abort_unless($page->version === (int) $validated['expected_version'], 409, 'This page changed after it was loaded.');
            $old = ['version' => $page->version, 'status' => $page->status];

            if ($decision === 'approve') {
                abort_unless($page->status === 'draft', 422, 'Only draft pages can be approved.');
                $page->status = 'approved';
                $page->reviewed_by = $request->user()->id;
            } elseif ($decision === 'publish') {
                abort_unless($page->status === 'approved', 422, 'Only approved pages can be published.');
                $page->status = 'published';
                $page->published_at = now();
                $page->scheduled_at = null;
            } elseif ($decision === 'schedule') {
                abort_unless($page->status === 'approved', 422, 'Only approved pages can be scheduled.');
                $page->status = 'scheduled';
                $page->scheduled_at = $validated['scheduled_at'];
                $page->published_at = null;
            } else {
                abort_unless(in_array($page->status, ['published', 'scheduled', 'approved'], true), 422);
                $page->status = 'unpublished';
                $page->scheduled_at = null;
                $page->published_at = null;
            }

            $page->version++;
            $page->updated_by = $request->user()->id;
            $page->save();
            $this->audit($request, 'corporate_site_page.' . $decision, $page, $old, ['version' => $page->version, 'status' => $page->status]);

            return response()->json(['success' => true, 'data' => $page]);
        });
    }

    private function audit(Request $request, string $action, CorporateSitePage $page, ?array $old, array $new): void
    {
        AuditLog::create([
            'user_id' => $request->user()?->id,
            'tenant_id' => null,
            'action' => $action,
            'resource_type' => 'corporate_site_page',
            'resource_id' => (string) $page->id,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1000),
            'request_id' => $request->header('X-Request-ID') ? substr((string) $request->header('X-Request-ID'), 0, 64) : null,
            'metadata' => ['public_id' => $page->public_id],
        ]);
    }

    private function locale(mixed $value): string
    {
        return in_array($value, ['am', 'en'], true) ? $value : 'am';
    }

    private function resource(CorporateSitePage $page, string $locale): array
    {
        $translation = $page->translations[$locale] ?? null;

        if (!is_array($translation)) {
            abort(404);
        }

        return [
            'public_id' => $page->public_id,
            'page_key' => $page->page_key,
            'locale' => $locale,
            'slug' => $locale === 'am' ? $page->slug_am : $page->slug_en,
            'title' => (string) ($translation['title'] ?? ''),
            'summary' => (string) ($translation['summary'] ?? ''),
            'sections' => array_values(array_filter($page->sections ?? [], static fn ($section): bool =>
                is_array($section) && isset($section['type'], $section['content'][$locale])
            )),
            'seo' => $page->seo[$locale] ?? null,
            'published_at' => $page->published_at?->toIso8601String(),
        ];
    }
}
