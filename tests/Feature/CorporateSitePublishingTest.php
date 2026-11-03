<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use MaaleArna\MASES\Core\Domains\PublicContent\Models\CorporateSitePage;
use MaaleArna\MASES\Core\Domains\PublicContent\Models\CorporateSiteEntry;
use MaaleArna\MASES\Core\Domains\PublicContent\Models\CorporateSiteInquiry;
use Tests\TestCase;

final class CorporateSitePublishingTest extends TestCase
{
    use RefreshDatabase;

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
            ->assertJsonPath('data.title', 'Test news');

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
        $this->assertDatabaseHas('corporate_site_inquiries', ['email' => 'contact@example.test', 'consented_at' => now()->toDateTimeString()]);

        $this->getJson('/api/admin/corporate-site/inquiries')->assertUnauthorized();
    }


}
