<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CorporateSiteBaselineSeeder extends Seeder
{
    /**
     * Insert only missing, source-grounded corporate pages.
     *
     * All records remain drafts. This seeder never approves or publishes
     * content and never overwrites an administrator's existing page.
     */
    public function run(): void
    {
        $now = now();

        foreach ($this->pages() as $page) {
            $exists = DB::table('corporate_site_pages')
                ->where('page_key', $page['page_key'])
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('corporate_site_pages')->insert([
                'public_id' => (string) Str::uuid(),
                'page_key' => $page['page_key'],
                'slug_am' => $page['slug_am'],
                'slug_en' => $page['slug_en'],
                'translations' => json_encode($page['translations'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'sections' => json_encode($page['sections'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'seo' => json_encode($page['seo'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'status' => 'draft',
                'version' => 1,
                'is_enabled' => true,
                'scheduled_at' => null,
                'published_at' => null,
                'created_by' => null,
                'updated_by' => null,
                'reviewed_by' => null,
                'archived_at' => null,
                'deleted_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pages(): array
    {
        $amCompany = 'ማሌ ዓርና አክሲዮን ማኅበር';
        $enCompany = 'Maale Arna Share Company';
        $amAddress = 'ኮይቤ ቀበሌ፣ ማሌ ወረዳ፣ ደቡብ ኦሞ ዞን፣ ኢትዮጵያ';
        $enAddress = 'Koybe Kebele, Maale Woreda, South Omo Zone, Ethiopia';

        $visionAm = 'በ2036 ዓ.ም በቴክኖሎጂ አጠቃቀም፣ በኢኮኖሚ አቅም እና በተደራሺነት በኢትዮጵያ ላይ ተወዳዳሪ ከከፍተኛ 10 ድርጅቶች ውስጥ መግባት ነው።';
        $visionEn = 'By 2036 E.C., to rank among Ethiopia’s ten leading competitive enterprises in technology adoption, economic capacity and accessibility.';
        $missionAm = 'በየጊዜው እያደገና እየተሻሻለ የመጣውን የቴክኖሎጂ ውጤቶችን በማወቅ የማኅበረሰቡን ፍላጎት ለሟሟላት በገበያ ጥናትና አዋጭነቱ በተረጋገጡ የንግድ ዘርፎች በመሳተፍ አክሲዮን ማኅበሩን በሁሉም ዘርፍ ተመራጭ ለማድረግ መሥራት ነው።';
        $missionEn = 'To understand and apply evolving technologies, meet community needs through market research and participation in commercially viable business sectors, and work to make the Share Company a preferred choice across its fields of activity.';

        $purposeAm = 'የኩባንያው መተዳደሪያ ደንብ በግብርና፣ በማዕድን፣ በማኑፋክቸሪንግ፣ በኮንስትራክሽን፣ በንግድ፣ በትራንስፖርት፣ በፋይናንስ ነክ ዘርፎች፣ በሪል እስቴትና በአገልግሎቶች የተለያዩ የንግድ ዓላማዎችን ይዘረዝራል። እነዚህ የመተዳደሪያ ደንብ ዓላማዎች ሁሉም ዘርፎች በአሁኑ ጊዜ እየሠሩ መሆናቸውን አያመለክቱም።';
        $purposeEn = 'The Company’s bylaws describe a diversified corporate purpose spanning agriculture, mining, manufacturing, construction, trade, transportation, finance-related sectors, real estate and services. These bylaw purposes do not mean that every listed activity is currently operational.';

        $valuesAm = [
            ['title' => 'ሐቀኝነት', 'body' => 'በሁሉም ግንኙነቶች ከፍተኛ የሥነ ምግባር መርሆዎችን መጠበቅና በታማኝነት መሥራት።'],
            ['title' => 'ልህቀት', 'body' => 'በምርት ጥራትና በአሠራር ቅልጥፍና ከፍተኛ ደረጃን መከታተል።'],
            ['title' => 'ፈጠራ', 'body' => 'ለማኅበረሰቡ ፍላጎት መፍትሔ የሚሰጡ አዳዲስ አቀራረቦችን መፈለግ።'],
            ['title' => 'ዘላቂነት', 'body' => 'አካባቢን የሚጠብቅና ለማኅበረሰብ ደህንነት የሚያበረክት ዘላቂ ንግድን መደገፍ።'],
        ];
        $valuesEn = [
            ['title' => 'Integrity', 'body' => 'Uphold high ethical standards and act honestly in all relationships.'],
            ['title' => 'Excellence', 'body' => 'Pursue high standards in product quality and operational efficiency.'],
            ['title' => 'Innovation', 'body' => 'Embrace change and seek new approaches that solve community needs.'],
            ['title' => 'Sustainability', 'body' => 'Support sustainable business that protects the environment and contributes to community well-being.'],
        ];

        $businessAm = [
            'የግብርና ሥራዎች', 'የማዕድን ሥራዎች', 'የማኑፋክቸሪንግ ሥራዎች',
            'የኮንስትራክሽን ሥራዎች', 'የጅምላ ንግድ፣ ሆቴልና ሬስቶራንት፣ አስመጪነትና ላኪነት',
            'የትራንስፖርት ሥራዎች', 'ፋይናንስ፣ ኢንሹራንስ፣ ሪል እስቴትና ንግድ',
            'ማኅበረሰባዊ፣ ማኅበራዊና የግል አገልግሎቶች',
        ];
        $businessEn = [
            'Agriculture', 'Mining', 'Manufacturing', 'Construction',
            'Wholesale trade, hotels and restaurants, import and export',
            'Transportation', 'Finance, insurance, real estate and trade',
            'Community, social and personal services',
        ];

        $communityAm = 'የማኅበረሰብ ኃላፊነት የኩባንያው ቋሚ ተቋማዊ ትኩረት ነው። ቅድሚያ የሚሰጣቸው ዘርፎች ትምህርትና የክህሎት ልማት፣ የማኅበረሰብ ጤናና ደህንነት፣ የአካባቢ ጥበቃ፣ እንዲሁም የአካባቢ ኢኮኖሚ ዕድሎችና ዘላቂ ኑሮ መሻሻል ናቸው። እነዚህ ቅድሚያዎች የተወሰነ ፕሮጀክት በአሁኑ ጊዜ እየተካሄደ መሆኑን አያመለክቱም።';
        $communityItemsAm = [
            ['kind' => 'priority', 'title' => 'ትምህርትና የክህሎት ልማት', 'body' => 'የትምህርትና የክህሎት እድገትን መደገፍ።'],
            ['kind' => 'priority', 'title' => 'የማኅበረሰብ ጤናና ደህንነት', 'body' => 'የማኅበረሰብ ጤናና ደህንነትን ማበረታታት።'],
            ['kind' => 'priority', 'title' => 'የአካባቢ ጥበቃ', 'body' => 'የአካባቢ ጥበቃና ዘላቂነትን መደገፍ።'],
            ['kind' => 'priority', 'title' => 'የአካባቢ ኢኮኖሚ ዕድሎች', 'body' => 'የአካባቢ ኢኮኖሚ ዕድሎችንና ዘላቂ ኑሮን ማበረታታት።'],
        ];
        $communityItemsEn = [
            ['kind' => 'priority', 'title' => 'Education and skills development', 'body' => 'Support education and the development of practical skills.'],
            ['kind' => 'priority', 'title' => 'Community health and well-being', 'body' => 'Promote community health and well-being.'],
            ['kind' => 'priority', 'title' => 'Environmental care', 'body' => 'Support environmental care and sustainability.'],
            ['kind' => 'priority', 'title' => 'Local economic opportunity', 'body' => 'Encourage local economic opportunity and sustainable livelihoods.'],
        ];

        $communityEn = 'Community Responsibility is a permanent institutional priority. Its focus areas include education and skills development, community health and well-being, environmental care, and local economic opportunity and sustainable livelihoods. These priorities do not imply that a specific project is currently underway.';

        $governanceAm = 'የዳይሬክተሮች ቦርድ በመተዳደሪያ ደንቡና በሚመለከታቸው የኩባንያ ውሳኔዎች መሠረት የስትራቴጂ አቅጣጫንና ቁጥጥርን ይመራል። የአሁኑ የቦርድ አባላት ስም፣ የሥራ ዘመንና የሙያ መግለጫ የሚታተመው ከኩባንያው ወቅታዊ መዝገብ ተረጋግጦ ሲፈቀድ ብቻ ነው።';
        $governanceEn = 'The Board of Directors provides strategic direction and oversight under the Company’s bylaws and applicable corporate decisions. Names, terms and biographies of current directors will be published only after verification against current company records and approval for public release.';

        $disclosureAm = 'ይህ ድረ ገጽ የኩባንያውን የተረጋገጠ ይፋዊ መረጃ ለማቅረብ የተዘጋጀ ነው። የምዝገባ ቁጥር፣ የፈቃድና የማረጋገጫ ዝርዝሮች፣ የተመረመሩ የፋይናንስ መግለጫዎችና የተወሰኑ የመንግሥት ተቋማት ግንኙነቶች ተገቢው ሰነድ ተረጋግጦ ለሕዝብ እንዲታይ ከተፈቀደ በኋላ ብቻ ይታተማሉ። የድረ ገጹ መግለጫዎች የመንግሥት ድጋፍ ወይም የማረጋገጫ ማስረጃ አይደሉም።';
        $disclosureEn = 'This website is intended to provide verified public information about the Company. Registration numbers, licences and permits, audited financial statements, and specific government-body relationships will be published only after the supporting records have been verified and public disclosure has been approved. Website statements do not constitute government endorsement or regulatory certification.';

        $contactAm = 'ዋና መሥሪያ ቤት፦ ' . $amAddress . '። ለአጠቃላይ ጥያቄ፣ ለኢንቨስትመንት፣ ለአጋርነት ወይም ለሚዲያ ግንኙነት የድረ ገጹን የጥያቄ ቅጽ ይጠቀሙ።';
        $contactEn = 'Head office: ' . $enAddress . '. For general, investment, partnership or media inquiries, use the website contact form.';

        $section = static fn (string $type, string $titleAm, string $bodyAm, string $titleEn, string $bodyEn, array $extra = []): array => [
            'id' => Str::slug($type . '-' . $titleEn),
            'type' => $type,
            'content' => [
                'visible' => true,
                'am' => ['title' => $titleAm, 'body' => $bodyAm, ...($extra['am'] ?? [])],
                'en' => ['title' => $titleEn, 'body' => $bodyEn, ...($extra['en'] ?? [])],
            ],
        ];

        $homeSections = [
            $section('hero', 'ማሌ ዓርና አክሲዮን ማኅበር', $amAddress, 'Maale Arna Share Company', $enAddress),
            $section('text', 'ስለ ማሌ ዓርና', $purposeAm, 'About Maale Arna', $purposeEn),
            $section('text', 'ራዕይ', $visionAm, 'Vision', $visionEn),
            $section('text', 'ተልዕኮ', $missionAm, 'Mission', $missionEn),
            $section('business_areas', 'የንግድ ዓላማዎች', $purposeAm, 'Corporate purpose', $purposeEn, [
                'am' => ['items' => array_map(static fn (string $value): array => ['title' => $value], $businessAm)],
                'en' => ['items' => array_map(static fn (string $value): array => ['title' => $value], $businessEn)],
            ]),
            $section('community_responsibility', 'ማኅበረሰባዊ ኃላፊነት', $communityAm, 'Community Responsibility', $communityEn, [
                'am' => ['items' => $communityItemsAm],
                'en' => ['items' => $communityItemsEn],
            ]),
            $section('text', 'የኩባንያ አስተዳደር', $governanceAm, 'Corporate governance', $governanceEn),
            $section('text', 'ይፋዊ መግለጫዎች', $disclosureAm, 'Public disclosures', $disclosureEn),
        ];

        return [
            [
                'page_key' => 'home', 'slug_am' => 'ber', 'slug_en' => 'home',
                'translations' => [
                    'am' => ['title' => $amCompany, 'summary' => 'የማሌ ዓርና አክሲዮን ማኅበር ይፋዊ ድረ ገጽ።', 'body' => $purposeAm],
                    'en' => ['title' => $enCompany, 'summary' => 'Official website of Maale Arna Share Company.', 'body' => $purposeEn],
                ],
                'sections' => $homeSections,
                'seo' => [
                    'am' => ['title' => $amCompany, 'description' => 'የማሌ ዓርና አክሲዮን ማኅበር ይፋዊ ድረ ገጽ።'],
                    'en' => ['title' => $enCompany, 'description' => 'Official website of Maale Arna Share Company.'],
                ],
            ],
            [
                'page_key' => 'about', 'slug_am' => 'ስለ-እኛ', 'slug_en' => 'about',
                'translations' => [
                    'am' => ['title' => 'ስለ እኛ', 'summary' => 'የማሌ ዓርና አክሲዮን ማኅበር መለያና የመተዳደሪያ ደንብ ዓላማዎች።', 'body' => $purposeAm . ' ዋና መሥሪያ ቤት፦ ' . $amAddress . '።'],
                    'en' => ['title' => 'About Us', 'summary' => 'The identity and bylaw-stated corporate purpose of Maale Arna Share Company.', 'body' => $purposeEn . ' Head office: ' . $enAddress . '.'],
                ],
                'sections' => [$section('text', 'የድርጅቱ መለያ', $amCompany . '። ' . $amAddress, 'Corporate identity', $enCompany . '. ' . $enAddress)],
                'seo' => ['am' => ['title' => 'ስለ እኛ | ማሌ ዓርና', 'description' => $purposeAm], 'en' => ['title' => 'About | Maale Arna', 'description' => $purposeEn]],
            ],
            [
                'page_key' => 'vision-mission-values', 'slug_am' => 'ራዕይ-ተልዕኮ-እሴቶች', 'slug_en' => 'vision-mission-values',
                'translations' => [
                    'am' => ['title' => 'ራዕይ፣ ተልዕኮና እሴቶች', 'summary' => 'የኩባንያው ራዕይ፣ ተልዕኮ፣ ስትራቴጂና ዋና እሴቶች።', 'body' => $visionAm . ' ' . $missionAm],
                    'en' => ['title' => 'Vision, Mission and Values', 'summary' => 'The Company’s Owner-provided vision, mission, strategy and core values.', 'body' => $visionEn . ' ' . $missionEn],
                ],
                'sections' => [
                    $section('text', 'ራዕይ', $visionAm, 'Vision', $visionEn),
                    $section('text', 'ተልዕኮ', $missionAm, 'Mission', $missionEn),
                    $section('text', 'ስትራቴጂ', 'በቴክኖሎጂ፣ በጠንካራ የኢኮኖሚ ስልቶች፣ በዘላቂነትና በፈጠራ ላይ ትኩረት ማድረግ።', 'Strategy', 'Focus on relevant technology, sound economic strategies, sustainability and innovation.'),
                    $section('feature_grid', 'ዋና እሴቶች', 'ሐቀኝነት፣ ልህቀት፣ ፈጠራና ዘላቂነት።', 'Core values', 'Integrity, excellence, innovation and sustainability.', [
                        'am' => ['items' => $valuesAm], 'en' => ['items' => $valuesEn],
                    ]),
                ],
                'seo' => ['am' => ['title' => 'ራዕይና ተልዕኮ | ማሌ ዓርና', 'description' => $visionAm], 'en' => ['title' => 'Vision and Mission | Maale Arna', 'description' => $visionEn]],
            ],
            [
                'page_key' => 'business-areas', 'slug_am' => 'የንግድ-ዘርፎች', 'slug_en' => 'business-areas',
                'translations' => [
                    'am' => ['title' => 'የንግድ ዓላማዎች', 'summary' => 'በመተዳደሪያ ደንቡ የተዘረዘሩ የንግድ ዓላማዎች።', 'body' => $purposeAm],
                    'en' => ['title' => 'Business Areas', 'summary' => 'Business purposes listed in the Company’s bylaws.', 'body' => $purposeEn],
                ],
                'sections' => [$section('business_areas', 'በመተዳደሪያ ደንብ የተዘረዘሩ ዘርፎች', $purposeAm, 'Bylaw-stated business areas', $purposeEn, [
                    'am' => ['items' => array_map(static fn (string $value): array => ['title' => $value], $businessAm)],
                    'en' => ['items' => array_map(static fn (string $value): array => ['title' => $value], $businessEn)],
                ])],
                'seo' => ['am' => ['title' => 'የንግድ ዘርፎች | ማሌ ዓርና', 'description' => $purposeAm], 'en' => ['title' => 'Business Areas | Maale Arna', 'description' => $purposeEn]],
            ],
            [
                'page_key' => 'governance', 'slug_am' => 'አስተዳደር', 'slug_en' => 'governance',
                'translations' => [
                    'am' => ['title' => 'አስተዳደርና አመራር', 'summary' => 'የኩባንያው አስተዳደር መርሆዎችና የይፋ መረጃ ማሳያ።', 'body' => $governanceAm],
                    'en' => ['title' => 'Governance and Leadership', 'summary' => 'Corporate governance principles and approved leadership disclosures.', 'body' => $governanceEn],
                ],
                'sections' => [$section('text', 'የዳይሬክተሮች ቦርድ', $governanceAm, 'Board of Directors', $governanceEn)],
                'seo' => ['am' => ['title' => 'አስተዳደር | ማሌ ዓርና', 'description' => $governanceAm], 'en' => ['title' => 'Governance | Maale Arna', 'description' => $governanceEn]],
            ],
            [
                'page_key' => 'community-responsibility', 'slug_am' => 'ማኅበረሰባዊ-ኃላፊነት', 'slug_en' => 'community-responsibility',
                'translations' => [
                    'am' => ['title' => 'ማኅበረሰባዊ ኃላፊነት', 'summary' => 'የኩባንያው ቋሚ ማኅበረሰባዊ ቅድሚያዎች።', 'body' => $communityAm],
                    'en' => ['title' => 'Community Responsibility', 'summary' => 'The Company’s permanent community responsibility priorities.', 'body' => $communityEn],
                ],
                'sections' => [$section('community_responsibility', 'ቋሚ ቅድሚያዎች', $communityAm, 'Permanent priorities', $communityEn, [
                    'am' => ['items' => $communityItemsAm],
                    'en' => ['items' => $communityItemsEn],
                ])],
                'seo' => ['am' => ['title' => 'ማኅበረሰባዊ ኃላፊነት | ማሌ ዓርና', 'description' => $communityAm], 'en' => ['title' => 'Community Responsibility | Maale Arna', 'description' => $communityEn]],
            ],
            [
                'page_key' => 'public-disclosures', 'slug_am' => 'ይፋዊ-መግለጫዎች', 'slug_en' => 'public-disclosures',
                'translations' => [
                    'am' => ['title' => 'ይፋዊ መግለጫዎች', 'summary' => 'የኩባንያው የሕዝብ መረጃ ማሳያና የማስረጃ መርሆዎች።', 'body' => $disclosureAm],
                    'en' => ['title' => 'Public Disclosures', 'summary' => 'Public information and evidence standards for corporate disclosures.', 'body' => $disclosureEn],
                ],
                'sections' => [$section('text', 'የመግለጫ መርሆዎች', $disclosureAm, 'Disclosure principles', $disclosureEn)],
                'seo' => ['am' => ['title' => 'ይፋዊ መግለጫዎች | ማሌ ዓርና', 'description' => $disclosureAm], 'en' => ['title' => 'Public Disclosures | Maale Arna', 'description' => $disclosureEn]],
            ],
            [
                'page_key' => 'contact', 'slug_am' => 'ያግኙን', 'slug_en' => 'contact',
                'translations' => [
                    'am' => ['title' => 'ያግኙን', 'summary' => $amAddress, 'body' => $contactAm],
                    'en' => ['title' => 'Contact', 'summary' => $enAddress, 'body' => $contactEn],
                ],
                'sections' => [$section('contact', 'ዋና መሥሪያ ቤት', $contactAm, 'Head Office', $contactEn)],
                'seo' => ['am' => ['title' => 'ያግኙን | ማሌ ዓርና', 'description' => $amAddress], 'en' => ['title' => 'Contact | Maale Arna', 'description' => $enAddress]],
            ],
        ];
    }
}
