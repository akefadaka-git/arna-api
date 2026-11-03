<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use MaaleArna\MASES\Core\Domains\PublicContent\Models\CorporateSitePage;
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
        $this->getJson('/api/public/site/home')->assertJsonPath('data.page', null);
    }
}
