<?php

declare(strict_types=1);

namespace App\Services\CorporateSite;

final class CorporateHomeInvariant
{
    private const COMMUNITY_TYPES = [
        'community_responsibility',
        'community-responsibility',
        'responsibility',
    ];

    /**
     * A homepage must always retain a bilingual Community Responsibility
     * section. This validates CMS structure, not project claims or
     * commitments; those remain subject to editorial approval and evidence.
     *
     * @param array<int, mixed>|null $sections
     */
    public static function hasRequiredCommunityResponsibilitySection(?array $sections): bool
    {
        foreach ($sections ?? [] as $section) {
            if (!is_array($section)) {
                continue;
            }

            $type = strtolower(trim((string) ($section['type'] ?? '')));
            if (!in_array($type, self::COMMUNITY_TYPES, true)) {
                continue;
            }

            $content = $section['content'] ?? null;
            if (!is_array($content)) {
                continue;
            }

            $hasBothLocales = true;
            foreach (['am', 'en'] as $locale) {
                $translation = $content[$locale] ?? null;
                if (!is_array($translation) || !is_string($translation['title'] ?? null) || trim($translation['title']) === '') {
                    $hasBothLocales = false;
                    break;
                }
            }

            if ($hasBothLocales) {
                return true;
            }
        }

        return false;
    }
}
