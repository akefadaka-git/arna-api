<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CorporateSite\CorporateHomeInvariant;
use PHPUnit\Framework\TestCase;

final class CorporateHomeInvariantTest extends TestCase
{
    public function test_bilingual_community_responsibility_section_satisfies_homepage_invariant(): void
    {
        self::assertTrue(CorporateHomeInvariant::hasRequiredCommunityResponsibilitySection([
            ['type' => 'community_responsibility', 'content' => [
                'am' => ['title' => 'ማኅበረሰባዊ ኃላፊነት'],
                'en' => ['title' => 'Community Responsibility'],
            ]],
        ]));
    }

    public function test_missing_or_incompletely_translated_section_fails_homepage_invariant(): void
    {
        self::assertFalse(CorporateHomeInvariant::hasRequiredCommunityResponsibilitySection([
            ['type' => 'text', 'content' => [
                'am' => ['title' => 'ስለ እኛ'],
                'en' => ['title' => 'About'],
            ]],
        ]));

        self::assertFalse(CorporateHomeInvariant::hasRequiredCommunityResponsibilitySection([
            ['type' => 'responsibility', 'content' => [
                'am' => ['title' => 'ማኅበረሰባዊ ኃላፊነት'],
                'en' => [],
            ]],
        ]));
    }
}
