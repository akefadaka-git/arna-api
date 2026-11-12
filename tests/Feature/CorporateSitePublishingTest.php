<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Client\ConnectionException;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use Laravel\Sanctum\Sanctum;
use App\Services\CorporateSite\CorporateMediaScanner;
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
        $keys = $this->scannerKeyPair();
        $signature = $this->signedScannerSignature('clean', 'public-reference', 'test image bytes', $keys['secret']);
        config([
            'services.corporate_media_scanner.url' => 'https://scanner.example.test/scan',
            'services.corporate_media_scanner.token' => 'scanner-token',
            'services.corporate_media_scanner.public_key' => base64_encode($keys['public']),
        ]);
        Http::fake([
            'scanner.example.test/*' => Http::response(['status' => 'clean', 'signature' => $signature], 200),
        ]);

        $verdict = app(CorporateMediaScanner::class)->scan('corporate-quarantine/test.jpg', 'public-reference');

        $this->assertSame('clean', $verdict['status']);
        $this->assertSame($signature, $verdict['signature']);
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


    public function test_media_scanner_rejects_missing_authentication_token(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('corporate-quarantine/test.jpg', 'test image bytes');
        config([
            'services.corporate_media_scanner.url' => 'https://scanner.example.test/scan',
            'services.corporate_media_scanner.token' => '',
        ]);

        $this->expectException(\RuntimeException::class);
        app(CorporateMediaScanner::class)->scan('corporate-quarantine/test.jpg', 'public-reference');
    }

    public function test_media_scanner_preserves_an_infected_verdict_as_infected(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('corporate-quarantine/test.jpg', 'test image bytes');
        $keys = $this->scannerKeyPair();
        $signature = $this->signedScannerSignature('infected', 'public-reference', 'test image bytes', $keys['secret']);
        config([
            'services.corporate_media_scanner.url' => 'https://scanner.example.test/scan',
            'services.corporate_media_scanner.token' => 'scanner-token',
            'services.corporate_media_scanner.public_key' => base64_encode($keys['public']),
        ]);
        Http::fake([
            'scanner.example.test/*' => Http::response(['status' => 'infected', 'signature' => $signature], 200),
        ]);

        $verdict = app(CorporateMediaScanner::class)->scan('corporate-quarantine/test.jpg', 'public-reference');

        $this->assertSame('infected', $verdict['status']);
        $this->assertSame($signature, $verdict['signature']);
    }

    public function test_media_scanner_rejects_http_errors_and_invalid_verdicts(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('corporate-quarantine/test.jpg', 'test image bytes');
        $keys = $this->scannerKeyPair();
        config([
            'services.corporate_media_scanner.url' => 'https://scanner.example.test/scan',
            'services.corporate_media_scanner.token' => 'scanner-token',
            'services.corporate_media_scanner.public_key' => base64_encode($keys['public']),
        ]);

        Http::fake([
            'scanner.example.test/*' => Http::response(['status' => 'clean', 'signature' => 'ignored'], 503),
        ]);
        try {
            app(CorporateMediaScanner::class)->scan('corporate-quarantine/test.jpg', 'public-reference');
            $this->fail('A non-success HTTP response must not be accepted.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('did not return a successful response', $exception->getMessage());
        }

        Http::fake([
            'scanner.example.test/*' => Http::response(['status' => 'unknown', 'signature' => 'ignored'], 200),
        ]);
        $this->expectException(\RuntimeException::class);
        app(CorporateMediaScanner::class)->scan('corporate-quarantine/test.jpg', 'public-reference');
    }

    public function test_media_scanner_rejects_missing_scan_signature(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('corporate-quarantine/test.jpg', 'test image bytes');
        $keys = $this->scannerKeyPair();
        config([
            'services.corporate_media_scanner.url' => 'https://scanner.example.test/scan',
            'services.corporate_media_scanner.token' => 'scanner-token',
            'services.corporate_media_scanner.public_key' => base64_encode($keys['public']),
        ]);
        Http::fake([
            'scanner.example.test/*' => Http::response(['status' => 'clean'], 200),
        ]);

        $this->expectException(\RuntimeException::class);
        app(CorporateMediaScanner::class)->scan('corporate-quarantine/test.jpg', 'public-reference');
    }

    public function test_media_scanner_propagates_connection_failures_for_fail_closed_handling(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('corporate-quarantine/test.jpg', 'test image bytes');
        $keys = $this->scannerKeyPair();
        config([
            'services.corporate_media_scanner.url' => 'https://scanner.example.test/scan',
            'services.corporate_media_scanner.token' => 'scanner-token',
            'services.corporate_media_scanner.public_key' => base64_encode($keys['public']),
        ]);
        Http::fake(static function () {
            throw new ConnectionException('scanner connection timed out');
        });

        $this->expectException(ConnectionException::class);
        app(CorporateMediaScanner::class)->scan('corporate-quarantine/test.jpg', 'public-reference');
    }

    public function test_media_scanner_rejects_a_forged_signature(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('corporate-quarantine/test.jpg', 'test image bytes');
        $keys = $this->scannerKeyPair();
        config([
            'services.corporate_media_scanner.url' => 'https://scanner.example.test/scan',
            'services.corporate_media_scanner.token' => 'scanner-token',
            'services.corporate_media_scanner.public_key' => base64_encode($keys['public']),
        ]);
        Http::fake([
            'scanner.example.test/*' => Http::response([
                'status' => 'clean',
                'signature' => base64_encode(str_repeat(chr(0), SODIUM_CRYPTO_SIGN_BYTES)),
            ], 200),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('signature could not be verified');
        app(CorporateMediaScanner::class)->scan('corporate-quarantine/test.jpg', 'public-reference');
    }

    public function test_media_scanner_signature_is_bound_to_the_file_and_reference(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('corporate-quarantine/test.jpg', 'different file bytes');
        $keys = $this->scannerKeyPair();
        $signature = $this->signedScannerSignature('clean', 'another-reference', 'test image bytes', $keys['secret']);
        config([
            'services.corporate_media_scanner.url' => 'https://scanner.example.test/scan',
            'services.corporate_media_scanner.token' => 'scanner-token',
            'services.corporate_media_scanner.public_key' => base64_encode($keys['public']),
        ]);
        Http::fake([
            'scanner.example.test/*' => Http::response(['status' => 'clean', 'signature' => $signature], 200),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('signature could not be verified');
        app(CorporateMediaScanner::class)->scan('corporate-quarantine/test.jpg', 'public-reference');
    }

    public function test_media_scanner_fails_closed_without_a_signing_public_key(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('corporate-quarantine/test.jpg', 'test image bytes');
        config([
            'services.corporate_media_scanner.url' => 'https://scanner.example.test/scan',
            'services.corporate_media_scanner.token' => 'scanner-token',
            'services.corporate_media_scanner.public_key' => '',
        ]);

        $this->expectException(\RuntimeException::class);
        app(CorporateMediaScanner::class)->scan('corporate-quarantine/test.jpg', 'public-reference');
    }


    public function test_media_scan_is_required_and_post_scan_file_changes_block_publication(): void
    {
        Storage::fake('local');
        $originalContents = 'original media bytes';
        Storage::disk('local')->put('corporate-quarantine/media.jpg', $originalContents);

        $author = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $reviewer = $this->corporateSiteReviewer();
        $entry = CorporateSiteEntry::query()->create([
            'entry_type' => 'media',
            'slug_am' => 'media-am',
            'slug_en' => 'media-en',
            'translations' => [
                'am' => ['title' => 'የሚዲያ ፋይል'],
                'en' => ['title' => 'Media file'],
            ],
            'asset_path' => 'corporate-quarantine/media.jpg',
            'asset_mime' => 'image/jpeg',
            'asset_size' => strlen($originalContents),
            'created_by' => $author->id,
            'payload' => ['quarantine' => true, 'scan_status' => 'pending'],
        ]);

        $keys = $this->scannerKeyPair();
        $signature = $this->signedScannerSignature('clean', $entry->public_id, $originalContents, $keys['secret']);
        config([
            'services.corporate_media_scanner.url' => 'https://scanner.example.test/scan',
            'services.corporate_media_scanner.token' => 'scanner-token',
            'services.corporate_media_scanner.public_key' => base64_encode($keys['public']),
        ]);
        Http::fake([
            'scanner.example.test/*' => Http::response(['status' => 'clean', 'signature' => $signature], 200),
        ]);
        Sanctum::actingAs($reviewer);

        $this->postJson('/api/admin/corporate-site/entries/' . $entry->id . '/scan', [
            'expected_version' => 1,
        ])->assertOk()->assertJsonPath('data.scan_status', 'clean');

        $this->postJson('/api/admin/corporate-site/entries/' . $entry->id . '/approve', [
            'expected_version' => 2,
        ])->assertOk()->assertJsonPath('data.status', 'approved');

        Storage::disk('local')->put('corporate-quarantine/media.jpg', 'changed after scan');

        $this->postJson('/api/admin/corporate-site/entries/' . $entry->id . '/publish', [
            'expected_version' => 3,
        ])->assertUnprocessable();

        $this->assertDatabaseHas('corporate_site_entries', [
            'id' => $entry->id,
            'status' => 'approved',
            'version' => 3,
        ]);
    }

    public function test_media_scan_metadata_cannot_be_forged_through_admin_update(): void
    {
        $author = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $reviewer = $this->corporateSiteReviewer();
        $entry = CorporateSiteEntry::query()->create([
            'entry_type' => 'media',
            'slug_am' => 'media-am',
            'slug_en' => 'media-en',
            'translations' => [
                'am' => ['title' => 'የሚዲያ ፋይል'],
                'en' => ['title' => 'Media file'],
            ],
            'asset_path' => 'corporate-quarantine/media.jpg',
            'asset_mime' => 'image/jpeg',
            'asset_size' => 10,
            'created_by' => $author->id,
            'payload' => ['quarantine' => true, 'scan_status' => 'pending'],
        ]);
        Sanctum::actingAs($reviewer);

        $this->putJson('/api/admin/corporate-site/entries/' . $entry->id, [
            'expected_version' => 1,
            'payload' => [
                'quarantine' => false,
                'scan_status' => 'clean',
                'scan_signature' => 'forged',
                'scan_sha256' => str_repeat('a', 64),
            ],
        ])->assertUnprocessable();

        $this->assertDatabaseHas('corporate_site_entries', [
            'id' => $entry->id,
            'version' => 1,
            'status' => 'draft',
        ]);
    }

    public function test_generic_admin_entry_creation_cannot_create_media_without_quarantine_upload(): void
    {
        Sanctum::actingAs($this->corporateSiteReviewer());

        $this->postJson('/api/admin/corporate-site/entries', [
            'entry_type' => 'media',
            'translations' => [
                'am' => ['title' => 'የሚዲያ ፋይል'],
                'en' => ['title' => 'Media file'],
            ],
            'payload' => ['scan_status' => 'clean'],
        ])->assertUnprocessable();
    }

    private function corporateSiteReviewer(): User
    {
        $user = User::factory()->create([
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $permission = Permission::query()->firstOrCreate(
            ['slug' => 'public-content.review'],
            [
                'name_am' => 'ይፋዊ ይዘት መገምገምና ማተም',
                'name_en' => 'Review and publish public content',
                'scope' => 'global',
                'is_active' => true,
            ],
        );
        $user->permissions()->attach($permission->id);

        return $user;
    }

    /**
     * Deterministic test-only Ed25519 key material. Never use this key in production.
     */
    private function scannerKeyPair(): array
    {
        $keyPair = sodium_crypto_sign_seed_keypair(str_repeat('a', SODIUM_CRYPTO_SIGN_SEEDBYTES));

        return [
            'public' => sodium_crypto_sign_publickey($keyPair),
            'secret' => sodium_crypto_sign_secretkey($keyPair),
        ];
    }

    private function signedScannerSignature(string $status, string $reference, string $contents, string $secretKey): string
    {
        $message = implode("\n", [
            'ARNA-CORPORATE-MEDIA-SCAN-V1',
            $status,
            $reference,
            hash('sha256', $contents),
        ]);

        return base64_encode(sodium_crypto_sign_detached($message, $secretKey));
    }

}
