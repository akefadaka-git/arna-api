<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Http\Controllers\CorporateSiteController;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Http\Controllers\CorporateSiteController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use App\Services\CorporateSite\CorporateMediaScanner;
use MaaleArna\MASES\Core\Domains\PublicContent\Models\CorporateSitePage;
use MaaleArna\MASES\Core\Domains\PublicContent\Models\CorporateSiteEntry;
use MaaleArna\MASES\Core\Domains\PublicContent\Models\CorporateSiteInquiry;
use Tests\TestCase;

final class CorporateSitePublishingTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_store_rejects_home_without_bilingual_community_responsibility_section(): void
    {
        $request = Request::create('/api/admin/corporate-site/pages', 'POST', [
            'page_key' => 'home',
            'translations' => [
                'am' => ['title' => 'መነሻ'],
                'en' => ['title' => 'Home'],
            ],
            'sections' => [
                ['type' => 'text', 'content' => [
                    'am' => ['title' => 'ስለ እኛ'],
                    'en' => ['title' => 'About'],
                ]],
            ],
        ]);

        $this->assertInvariantViolation(
            fn () => app(CorporateSiteController::class)->adminStore($request),
        );
    }

    public function test_admin_update_cannot_remove_home_community_responsibility_section(): void
    {
        $page = CorporateSitePage::query()->create([
            'page_key' => 'home',
            'translations' => [
                'am' => ['title' => 'መነሻ'],
                'en' => ['title' => 'Home'],
            ],
            'sections' => [
                ['type' => 'community_responsibility', 'content' => [
                    'am' => ['title' => 'ማኅበረሰባዊ ኃላፊነት'],
                    'en' => ['title' => 'Community Responsibility'],
                ]],
            ],
        ]);

        $request = Request::create('/api/admin/corporate-site/pages/' . $page->id, 'PUT', [
            'expected_version' => $page->version,
            'sections' => [
                ['type' => 'text', 'content' => [
                    'am' => ['title' => 'ስለ እኛ'],
                    'en' => ['title' => 'About'],
                ]],
            ],
        ]);

        $this->assertInvariantViolation(
            fn () => app(CorporateSiteController::class)->adminUpdate($request, $page->id),
        );
    }

    public function test_legacy_home_without_community_section_cannot_be_approved(): void
    {
        $page = CorporateSitePage::query()->create([
            'page_key' => 'home',
            'translations' => [
                'am' => ['title' => 'መነሻ'],
                'en' => ['title' => 'Home'],
            ],
            'sections' => [],
        ]);

        $request = Request::create('/api/admin/corporate-site/pages/' . $page->id . '/approve', 'POST', [
            'expected_version' => $page->version,
        ]);

        $this->assertInvariantViolation(
            fn () => app(CorporateSiteController::class)->adminReview($request, $page->id, 'approve'),
        );
    }

    private function assertInvariantViolation(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected the Community Responsibility invariant to reject this homepage mutation.');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->getStatusCode());
        }
    }

    public function test_public_home_returns_only_published_localized_content(): void
    {
        $page = CorporateSitePage::query()->create([
            'page_key' => 'home',
            'slug_am' => 'ber',
            'slug_en' => 'home',
            'translations' => [
                'am' => ['title' => 'ወደ አርና እንኳን በደህና መጡ', 'summary' => 'የአርና መነሻ'],
                'en' => ['title' => 'Welcome to ARNA', 'summary' => 'The ARNA homepage'],
            ],
            'sections' => [
                ['type' => 'text', 'content' => [
                    'am' => ['title' => 'ስለ እኛ', 'body' => 'የተጸደቀ ይዘት'],
                    'en' => ['title' => 'About', 'body' => 'Approved content'],
                ]],
            ],
            'seo' => ['am' => ['title' => 'ARNA', 'description' => 'ARNA official website']],
        ]);

        $page->forceFill(['status' => 'published', 'published_at' => now()->subMinute()])->save();

        $this->getJson('/api/public/site/home?locale=am')
            ->assertOk()
            ->assertJsonPath('data.locale', 'am')
            ->assertJsonPath('data.title', 'ወደ አርና እንኳን በደህና መጡ')
            ->assertJsonPath('data.sections.0.content.am.title', 'ስለ እኛ');

        $this->getJson('/api/public/site/home?locale=en')
            ->assertOk()
            ->assertJsonPath('data.title', 'Welcome to ARNA');
    }

    public function test_draft_pages_are_not_publicly_readable(): void
    {
        CorporateSitePage::query()->create([
            'page_key' => 'about',
            'slug_am' => 'about-am',
            'slug_en' => 'about',
            'translations' => [
                'am' => ['title' => 'የግል ረቂቅ'],
                'en' => ['title' => 'Private draft'],
            ],
        ]);

        $this->getJson('/api/public/site/pages/en/about')->assertNotFound();
        $this->getJson('/api/public/site/home')->assertJsonPath('data', null);
    }

    public function test_only_published_corporate_entries_are_listed_and_detail_is_localized(): void
    {
        $entry = CorporateSiteEntry::query()->create([
            'entry_type' => 'news',
            'slug_am' => 'test-news',
            'slug_en' => 'test-news-en',
            'translations' => [
                'am' => ['title' => 'የሙከራ ዜና', 'body' => 'የአማርኛ ይዘት'],
                'en' => ['title' => 'Test news', 'body' => 'English content'],
            ],
        ]);
        $entry->forceFill(['status' => 'published', 'published_at' => now()->subMinute()])->save();

        CorporateSiteEntry::query()->create([
            'entry_type' => 'news',
            'slug_am' => 'draft-news',
            'slug_en' => 'draft-news-en',
            'translations' => ['am' => ['title' => 'Draft'], 'en' => ['title' => 'Draft']],
        ]);

        $this->getJson('/api/public/site/content/news?locale=am')
            ->assertOk()
            ->assertJsonPath('data.items.0.title', 'የሙከራ ዜና')
            ->assertJsonMissing(['title' => 'Draft']);

        $this->getJson('/api/public/site/content/news/test-news-en?locale=en')
            ->assertOk()
            ->assertJsonPath('data.title', 'Test news')
            ->assertJsonPath('data.alternate_slug', 'test-news');

        $this->getJson('/api/public/site/content/news/draft-news-en?locale=en')->assertNotFound();
    }

    public function test_contact_inquiry_is_private_and_starts_in_new_status(): void
    {
        $response = $this->postJson('/api/public/site/inquiries', [
            'inquiry_type' => 'partnership',
            'name' => 'Test Contact',
            'email' => 'contact@example.test',
            'subject' => 'Partnership inquiry',
            'message' => 'Please contact me about a partnership.',
            'consent' => true,
        ]);

        $response->assertAccepted()->assertJsonPath('data.status', 'received');
        $this->assertDatabaseHas('corporate_site_inquiries', [
            'email' => 'contact@example.test',
            'status' => 'new',
            'inquiry_type' => 'partnership',
        ]);
        $this->assertNotNull(DB::table('corporate_site_inquiries')->where('email', 'contact@example.test')->value('consented_at'));

        $this->getJson('/api/admin/corporate-site/inquiries')->assertUnauthorized();
    }



    public function test_media_scanner_accepts_only_a_clean_verdict_from_configured_https_service(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('corporate-quarantine/test.jpg', 'test image bytes');
        config([
            'services.corporate_media_scanner.url' => 'https://scanner.example.test/scan',
            'services.corporate_media_scanner.token' => 'scanner-token',
        ]);
        Http::fake([
            'scanner.example.test/*' => Http::response(['status' => 'clean', 'signature' => 'test-signature'], 200),
        ]);

        $verdict = app(CorporateMediaScanner::class)->scan('corporate-quarantine/test.jpg', 'public-reference');

        $this->assertSame('clean', $verdict['status']);
        $this->assertSame('test-signature', $verdict['signature']);
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer scanner-token'));
    }

    public function test_media_scanner_fails_closed_when_not_configured(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('corporate-quarantine/test.jpg', 'test image bytes');
        config(['services.corporate_media_scanner.url' => null]);

        $this->expectException(\RuntimeException::class);
        app(CorporateMediaScanner::class)->scan('corporate-quarantine/test.jpg', 'public-reference');
    }


}
