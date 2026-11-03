<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
