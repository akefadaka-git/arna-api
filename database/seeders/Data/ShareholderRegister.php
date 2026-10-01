<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — P2.1 · File 1 of 2
 * =============================================================================
 *
 * File:            ShareholderRegister.php
 * Unit:            P2.1 — reclassify the two entity shareholders
 * Delivery type:   Amendment (data + docblock)
 * Net lines:       +~15 docblock lines; 2 row edits
 *
 * Before:
 *   The register carried two entity rows (SH-0023, SH-0088) whose
 *   `type` field was the literal string 'organization'. That string is
 *   a row-shape discriminator, not a member of the ShareholderType
 *   enum (D9). The seeder (ShareholderSeeder.php, File 2 of this unit)
 *   read `type` verbatim and wrote it into
 *   `shareholders.shareholder_type`, producing two rows that violate
 *   D9. The same seeder unconditionally wrote `user_id` for every row,
 *   producing two rows that violate D11 (a legal entity must not carry
 *   a direct user link).
 *
 * After:
 *   The two entity rows carry `type => 'religious_body'` — a member of
 *   the ShareholderType enum (D9). The register's `type` field now
 *   carries the enum value directly, so the seeder can write it
 *   verbatim into `shareholders.shareholder_type` (File 2's change)
 *   without a separate mapping table.
 *
 *   The register's value for individual rows remains 'individual'.
 *   Both values are now members of ShareholderType::values().
 *
 *   The docblock is corrected in three places:
 *     · §"The row shape" now describes `type` as a member of the
 *       ShareholderType enum, and enumerates the two values currently
 *       in use ('individual', 'religious_body') without implying that
 *       'organization' is a permitted value.
 *     · The paragraph naming the two entity rows no longer calls them
 *       "cooperatives"; the term is replaced with "religious bodies",
 *       matching the classification decision.
 *     · The class-header summary paragraph is updated from "two
 *       organization entries" to "two entity entries".
 *
 *   No other row is touched. All 98 individual rows are byte-for-byte
 *   identical to the source. The register's ordering (SH-0001 through
 *   SH-0100) is preserved, and therefore the collision-suffix
 *   assignment performed by the seeder is unaffected.
 *
 * =============================================================================
 * Deployment dependency — Files 1 and 2 are one change
 * =============================================================================
 * This file alone does not fix P2.1, and deploying it against the
 * current ShareholderSeeder produces a broken intermediate state:
 *
 *   · The seeder (before File 2 lands) evaluates
 *         $definition['type'] === self::SHAREHOLDER_TYPE_ORGANIZATION
 *     where the constant value is 'organization'. After this file,
 *     SH-0023 and SH-0088 no longer carry 'organization', so the check
 *     returns false and the seeder treats both rows as individuals.
 *
 *   · A re-seed in that state would give SH-0023 and SH-0088
 *     shareholder_type = 'individual' — a new D9 violation, replacing
 *     the old one.
 *
 * Files 1 and 2 must deploy together, and the re-seed follows both.
 * See File 2's change-verification block for the seeder-side changes.
 *
 * =============================================================================
 * Files modified:            this file only
 * Files added:               none
 * Files deleted:             none
 * Rows changed:              2 (SH-0023, SH-0088)
 * Rows unchanged:            98
 * =============================================================================
 */

namespace Database\Seeders\Data;

/**
 * ShareholderRegister
 *
 * The complete shareholder register seeded by ShareholderSeeder (S-16):
 * one hundred entries keyed by the register's own SH-ID identifier. Each
 * entry carries the shareholder's type, the six bilingual individual
 * name components (or the two entity name components for the two entity
 * entries), gender, and address.
 *
 * =============================================================================
 * What this class is — and is not
 * =============================================================================
 * This is a DATA class. It contains no logic, no queries, and no
 * side effects. It exists so that the shareholder register — the
 * artefact most likely to be edited independently of the seeding
 * algorithm — lives in a file whose contents can be reviewed,
 * diffed, and corrected without reading any seeding code.
 *
 * The class was extracted from MasesCoreSeeder during the seeder
 * refactoring (Unit S-1). Its contents are byte-for-byte identical
 * to the SHAREHOLDERS constant it replaces, except for the two row
 * edits recorded in this file's change-verification block.
 *
 * =============================================================================
 * The row shape
 * =============================================================================
 * Every row is an array with one of two shapes, discriminated by the
 * 'type' field:
 *
 *   type = 'individual'
 *     first_am    — first name in Amharic
 *     father_am   — father's name in Amharic
 *     grand_am    — grandfather's name in Amharic (nullable)
 *     first_en    — first name in Latin script
 *     father_en   — father's name in Latin script
 *     grand_en    — grandfather's name in Latin script (nullable)
 *     gender      — 'male' | 'female'
 *     address     — free-form place name (village, town, or district)
 *
 *   type = <entity ShareholderType value>
 *     entity_am   — legal entity name in Amharic
 *     entity_en   — legal entity name in Latin script
 *     address     — free-form place name
 *
 * The 'type' value is a member of the ShareholderType enum (D9):
 *
 *     individual, company, trust, cooperative, government,
 *     religious_body
 *
 * It is NOT a row-shape discriminator invented for this register. The
 * seeder (ShareholderSeeder.php, S-16) writes it verbatim into
 * `shareholders.shareholder_type`, so the value here must be a member
 * of the enum.
 *
 * Two values are currently in use:
 *
 *     'individual'      — 98 rows
 *     'religious_body'  — 2 rows (SH-0023 and SH-0088)
 *
 * Adding a row of another entity type (a company, a trust, and so on)
 * requires no change to the seeder; the seeder treats every non-
 * 'individual' value as an entity row. See ShareholderSeeder's
 * docblock for the discriminator rule.
 *
 * The two entity rows are SH-0023 (Maale Hibret) and SH-0088
 * (Sarangi). Both are religious bodies, and their register entries
 * carry `type => 'religious_body'`. The remaining 98 rows are
 * individuals and carry `type => 'individual'`.
 *
 * =============================================================================
 * The register is authoritative
 * =============================================================================
 * The rows below are the register. Corrections to a shareholder's
 * name, type, gender, or address belong here and only here. The
 * seeder derives usernames and emails from these rows on every run;
 * a correction to a name therefore produces a corresponding
 * correction to the derived username and email on the next seeder
 * run. That is intended: the register is the source, the derived
 * identifiers are projections of it.
 *
 * =============================================================================
 * Email and username derivation
 * =============================================================================
 * The seeder derives the email local-part and the username from the
 * Latin-script name fields above. Two rules apply:
 *
 *   email      — {first_name_en}{father_name_en}, lowercased, letters
 *                only. For the two entity rows, the derivation falls
 *                back to the entity_name_en value.
 *
 *   username   — {first_name_en}, lowercased, letters only. For the
 *                two entity rows, the derivation falls back to the
 *                entity_name_en value.
 *
 * Both derivations are deterministic — the same register produces
 * the same usernames and emails on every run.
 *
 * =============================================================================
 * Collision suffixes
 * =============================================================================
 * Six first names appear twice in the register. The second occurrence
 * of each receives a numeric suffix, assigned in SH-ID iteration
 * order:
 *
 *   Amanuel (SH-0003, SH-0029)   — amanuel, amanuel2
 *   Oysha   (SH-0014, SH-0045)   — oysha, oysha2
 *   Wegene  (SH-0013, SH-0049)   — wegene, wegene2
 *   Aman    (SH-0028, SH-0066)   — aman, aman2
 *   Tareken (SH-0061, SH-0082)   — tareken, tareken2
 *   Matewos (SH-0015, SH-0094)   — matewos, matewos2
 *
 * One email collision exists: SH-0014 and SH-0045 both derive
 * `oyshatushkulo`. The second, in SH-ID order, is suffixed:
 * `oyshatushkulo2@maalearna.com.et`.
 *
 * The suffix assignment lives in ShareholderSeeder's
 * buildShareholderEmail and buildShareholderUsername methods, not
 * here. This class is the data the suffixing algorithm reads.
 *
 * =============================================================================
 * What this class deliberately does NOT do
 * =============================================================================
 *   - It does NOT validate. A malformed row (missing 'type', an
 *     unrecognized 'gender' value, an individual with only entity
 *     name fields, an entity row carrying a value not in the
 *     ShareholderType enum) produces whatever the seeder does with
 *     it; the register is not defended against its own editor.
 *     Validation of the 'type' value against the enum is the
 *     seeder's concern, or a future CR's.
 *
 *   - It does NOT sort. The order below is the register's order —
 *     ascending SH-ID — and is preserved by the seeder when it
 *     iterates. Changing the order changes the suffix assignment for
 *     the six collision cases above.
 *
 *   - It does NOT expose a lookup method. Consumers iterate
 *     ShareholderRegister::ROWS directly. A future CR that needs a
 *     keyed accessor adds it here; the current seeder does not.
 *
 * @package Database\Seeders\Data
 */
final class ShareholderRegister
{
    /**
     * The shareholder register, keyed by SH-ID.
     *
     * @var array<string, array<string, mixed>>
     */
    public const ROWS = [
        'SH-0001' => ['type' => 'individual', 'first_am' => 'ሳሊሌ', 'father_am' => 'ሳልፋ', 'grand_am' => 'አማሌ', 'first_en' => 'Salile', 'father_en' => 'Salfa', 'grand_en' => 'Amaale', 'gender' => 'male', 'address' => 'Koybe'],
        'SH-0002' => ['type' => 'individual', 'first_am' => 'አብርሃም', 'father_am' => 'ሳልፋ', 'grand_am' => 'አማሌ', 'first_en' => 'Abraham', 'father_en' => 'Salfa', 'grand_en' => 'Amaale', 'gender' => 'male', 'address' => 'Asho'],
        'SH-0003' => ['type' => 'individual', 'first_am' => 'አማኑኤል', 'father_am' => 'ሳልፋ', 'grand_am' => 'አማሌ', 'first_en' => 'Amanuel', 'father_en' => 'Salfa', 'grand_en' => 'Amaale', 'gender' => 'male', 'address' => 'Lemo Gento'],
        'SH-0004' => ['type' => 'individual', 'first_am' => 'ዕድሌ', 'father_am' => 'ኮዳ', 'grand_am' => 'ቡጆ', 'first_en' => 'Edle', 'father_en' => 'Koda', 'grand_en' => 'Bujo', 'gender' => 'male', 'address' => 'Lemo Gento'],
        'SH-0005' => ['type' => 'individual', 'first_am' => 'እርቀ', 'father_am' => 'ኢዮብ', 'grand_am' => 'አልዳቦ', 'first_en' => 'Erke', 'father_en' => 'Iyob', 'grand_en' => 'Aldabo', 'gender' => 'male', 'address' => 'Koybe'],
        'SH-0006' => ['type' => 'individual', 'first_am' => 'ዮሐንስ', 'father_am' => 'ግጨ', 'grand_am' => 'ቱባሼ', 'first_en' => 'Yohannes', 'father_en' => 'Giche', 'grand_en' => 'Tubashe', 'gender' => 'male', 'address' => 'Bala'],
        'SH-0007' => ['type' => 'individual', 'first_am' => 'ጋድቁሬ', 'father_am' => 'ጋቶ', 'grand_am' => 'ግርማ', 'first_en' => 'Gadkure', 'father_en' => 'Gato', 'grand_en' => 'Girma', 'gender' => 'male', 'address' => 'Lemo Gento'],
        'SH-0008' => ['type' => 'individual', 'first_am' => 'አስቻለው', 'father_am' => 'ከበደ', 'grand_am' => 'ጋንቲ', 'first_en' => 'Aschalew', 'father_en' => 'Kebede', 'grand_en' => 'Ganti', 'gender' => 'male', 'address' => 'Asho'],
        'SH-0009' => ['type' => 'individual', 'first_am' => 'ገለቦ', 'father_am' => 'ገለፋ', 'grand_am' => 'ኮሌ', 'first_en' => 'Gelebo', 'father_en' => 'Gelefa', 'grand_en' => 'Kole', 'gender' => 'male', 'address' => 'Koybe'],
        'SH-0010' => ['type' => 'individual', 'first_am' => 'ታክቲኩ', 'father_am' => 'ሳጌ', 'grand_am' => 'ጫሌ', 'first_en' => 'Taktiku', 'father_en' => 'Sage', 'grand_en' => 'Chale', 'gender' => 'male', 'address' => 'Koybe'],
        'SH-0011' => ['type' => 'individual', 'first_am' => 'አማሬ', 'father_am' => 'ዴክሮ', 'grand_am' => 'ጫሌ', 'first_en' => 'Amare', 'father_en' => 'Dekiro', 'grand_en' => 'Chale', 'gender' => 'male', 'address' => 'Koybe'],
        'SH-0012' => ['type' => 'individual', 'first_am' => 'ዮሴፍ', 'father_am' => 'ሙራሌ', 'grand_am' => 'ኩንሦ', 'first_en' => 'Yosef', 'father_en' => 'Murale', 'grand_en' => 'Kunso', 'gender' => 'male', 'address' => 'Lemo Gento'],
        'SH-0013' => ['type' => 'individual', 'first_am' => 'ወገነ', 'father_am' => 'ፃልካ', 'grand_am' => 'ሻካ', 'first_en' => 'Wegene', 'father_en' => 'Tsalka', 'grand_en' => 'Shaka', 'gender' => 'male', 'address' => 'Mashala'],
        'SH-0014' => ['type' => 'individual', 'first_am' => 'ኦይሻ', 'father_am' => 'ቱሽኩሎ', 'grand_am' => 'ሚክሳ', 'first_en' => 'Oysha', 'father_en' => 'Tushkulo', 'grand_en' => 'Miksa', 'gender' => 'male', 'address' => 'Beneta Chaliti'],
        'SH-0015' => ['type' => 'individual', 'first_am' => 'ማቴዎስ', 'father_am' => 'ማዳ', 'grand_am' => 'ውኔጫ', 'first_en' => 'Matewos', 'father_en' => 'Mada', 'grand_en' => 'Wunecha', 'gender' => 'male', 'address' => 'Bala'],
        'SH-0016' => ['type' => 'individual', 'first_am' => 'ደርገበ', 'father_am' => 'ዴክሮ', 'grand_am' => 'ጫሌ', 'first_en' => 'Dergebe', 'father_en' => 'Dekiro', 'grand_en' => 'Chale', 'gender' => 'male', 'address' => 'Koybe'],
        'SH-0017' => ['type' => 'individual', 'first_am' => 'ኃይለማርያም', 'father_am' => 'ጋርዳ', 'grand_am' => 'ታውጣ', 'first_en' => 'Haylemariam', 'father_en' => 'Garda', 'grand_en' => 'Tawta', 'gender' => 'male', 'address' => 'Bala'],
        'SH-0018' => ['type' => 'individual', 'first_am' => 'ደሳለኝ', 'father_am' => 'ሚካኔ', 'grand_am' => 'ማዛሪ', 'first_en' => 'Desalegn', 'father_en' => 'Mikane', 'grand_en' => 'Mazari', 'gender' => 'male', 'address' => 'Asho'],
        'SH-0019' => ['type' => 'individual', 'first_am' => 'ፋፃራ', 'father_am' => 'ፑፓ', 'grand_am' => 'ጋዲሎ', 'first_en' => 'Fatsara', 'father_en' => 'Pupa', 'grand_en' => 'Gadilo', 'gender' => 'male', 'address' => 'Sholeteka'],
        'SH-0020' => ['type' => 'individual', 'first_am' => 'ኮንታ', 'father_am' => 'ኮዳ', 'grand_am' => 'ቡጆ', 'first_en' => 'Konta', 'father_en' => 'Koda', 'grand_en' => 'Bujo', 'gender' => 'male', 'address' => 'Bala'],
        'SH-0021' => ['type' => 'individual', 'first_am' => 'ናዝሬት', 'father_am' => 'ኮዳ', 'grand_am' => 'ቡጆ', 'first_en' => 'Nazret', 'father_en' => 'Koda', 'grand_en' => 'Bujo', 'gender' => 'female', 'address' => 'Lemo Gento'],
        'SH-0022' => ['type' => 'individual', 'first_am' => 'ቶሚያስ', 'father_am' => 'ሺሻ', 'grand_am' => 'ጎሻ', 'first_en' => 'Tomiyas', 'father_en' => 'Shisha', 'grand_en' => 'Gosha', 'gender' => 'male', 'address' => 'Aleze'],
        'SH-0023' => ['type' => 'religious_body', 'entity_am' => 'የማሌ ቃ/ሕ/አ/ቤ/ክ ኅብረት', 'entity_en' => 'Maale Hibret', 'address' => 'Koybe'],
        'SH-0024' => ['type' => 'individual', 'first_am' => 'አቀፋ', 'father_am' => 'አየለ', 'grand_am' => 'ዳካ', 'first_en' => 'Akefa', 'father_en' => 'Ayele', 'grand_en' => 'Daka', 'gender' => 'male', 'address' => 'Koybe'],
        'SH-0025' => ['type' => 'individual', 'first_am' => 'ወቃሳ', 'father_am' => 'ጁቴ', 'grand_am' => 'ጎሻ', 'first_en' => 'Wekasa', 'father_en' => 'Jute', 'grand_en' => 'Gosha', 'gender' => 'male', 'address' => 'Aleze'],
        'SH-0026' => ['type' => 'individual', 'first_am' => 'ተሰጋ', 'father_am' => 'ግዛው', 'grand_am' => 'ጆርጋሪ', 'first_en' => 'Tesega', 'father_en' => 'Gizaw', 'grand_en' => 'Jorgari', 'gender' => 'male', 'address' => 'Koybe'],
        'SH-0027' => ['type' => 'individual', 'first_am' => 'ማኜ', 'father_am' => 'ጁጋ', 'grand_am' => 'ግራይኝ', 'first_en' => 'Manye', 'father_en' => 'Juga', 'grand_en' => 'Girayn', 'gender' => 'male', 'address' => 'Koybe'],
        'SH-0028' => ['type' => 'individual', 'first_am' => 'አማን', 'father_am' => 'አውላ', 'grand_am' => 'ማላ', 'first_en' => 'Aman', 'father_en' => 'Awla', 'grand_en' => 'Mala', 'gender' => 'male', 'address' => 'Lemo Gento'],
        'SH-0029' => ['type' => 'individual', 'first_am' => 'አማኑኤል', 'father_am' => 'አስናቄ', 'grand_am' => 'ባልግዛ', 'first_en' => 'Amanuel', 'father_en' => 'Asnaqe', 'grand_en' => 'Balgiza', 'gender' => 'male', 'address' => 'Lemo Gento'],
        'SH-0030' => ['type' => 'individual', 'first_am' => 'ማህተም', 'father_am' => 'ታድዮስ', 'grand_am' => 'ፒቴ', 'first_en' => 'Mahtem', 'father_en' => 'Tadiyos', 'grand_en' => 'Pite', 'gender' => 'male', 'address' => 'Koybe'],
        'SH-0031' => ['type' => 'individual', 'first_am' => 'መሠረት', 'father_am' => 'ቀያፈር', 'grand_am' => 'ዙዞ', 'first_en' => 'Meseret', 'father_en' => 'Qeyafar', 'grand_en' => 'Zuzo', 'gender' => 'male', 'address' => 'Aleze'],
        'SH-0032' => ['type' => 'individual', 'first_am' => 'ታጋይ', 'father_am' => 'ከፍያለው', 'grand_am' => 'ዳቆ', 'first_en' => 'Tagay', 'father_en' => 'Kefyalew', 'grand_en' => 'Dako', 'gender' => 'male', 'address' => 'Lemo Gento'],
        'SH-0033' => ['type' => 'individual', 'first_am' => 'አልጋጋ', 'father_am' => 'ባልንሴ', 'grand_am' => 'ባዚ', 'first_en' => 'Algaga', 'father_en' => 'Balnse', 'grand_en' => 'Bazi', 'gender' => 'male', 'address' => 'Lemo Gento'],
        'SH-0034' => ['type' => 'individual', 'first_am' => 'ካታላ', 'father_am' => 'ጋሴ', 'grand_am' => 'ዳቆ', 'first_en' => 'Katala', 'father_en' => 'Gase', 'grand_en' => 'Dako', 'gender' => 'male', 'address' => 'Koybe'],
        'SH-0035' => ['type' => 'individual', 'first_am' => 'አቤል', 'father_am' => 'አድነው', 'grand_am' => 'ጫሌ', 'first_en' => 'Abel', 'father_en' => 'Adnew', 'grand_en' => 'Chale', 'gender' => 'male', 'address' => 'Koybe'],
        'SH-0036' => ['type' => 'individual', 'first_am' => 'ምህረቱ', 'father_am' => 'ላዌ', 'grand_am' => 'ጎልብ', 'first_en' => 'Mihretu', 'father_en' => 'Lawe', 'grand_en' => 'Golb', 'gender' => 'male', 'address' => 'Jinka'],
        'SH-0037' => ['type' => 'individual', 'first_am' => 'ታምሩ', 'father_am' => 'ሩጫ', 'grand_am' => 'ቢሻ', 'first_en' => 'Tamru', 'father_en' => 'Rucha', 'grand_en' => 'Bisha', 'gender' => 'male', 'address' => 'Sholeteka'],
        'SH-0038' => ['type' => 'individual', 'first_am' => 'ቦታ', 'father_am' => 'እንኪያስ', 'grand_am' => 'ዲቻ', 'first_en' => 'Bota', 'father_en' => 'Enkiyas', 'grand_en' => 'Dicha', 'gender' => 'male', 'address' => 'Koybe'],
        'SH-0039' => ['type' => 'individual', 'first_am' => 'መንግስቱ', 'father_am' => 'ዱልቦ', 'grand_am' => 'ዶንቾ', 'first_en' => 'Mengistu', 'father_en' => 'Dulbo', 'grand_en' => 'Doncho', 'gender' => 'male', 'address' => 'Jinka'],
        'SH-0040' => ['type' => 'individual', 'first_am' => 'ተፈራ', 'father_am' => 'ዴክሮ', 'grand_am' => 'ጫሌ', 'first_en' => 'Tefera', 'father_en' => 'Dekiro', 'grand_en' => 'Chale', 'gender' => 'male', 'address' => 'Jinka'],
        'SH-0041' => ['type' => 'individual', 'first_am' => 'ደረጀ', 'father_am' => 'ኮምቦዳ', 'grand_am' => 'አራጤ', 'first_en' => 'Dereje', 'father_en' => 'Komboda', 'grand_en' => 'Arate', 'gender' => 'male', 'address' => 'Jinka'],
        'SH-0042' => ['type' => 'individual', 'first_am' => 'ሲዳ', 'father_am' => 'ሲውሶ', 'grand_am' => 'ቃዛሪ', 'first_en' => 'Sida', 'father_en' => 'Siwso', 'grand_en' => 'Qazari', 'gender' => 'male', 'address' => 'Doyso'],
        'SH-0043' => ['type' => 'individual', 'first_am' => 'አካሉ', 'father_am' => 'አድፋ', 'grand_am' => 'አረባሼ', 'first_en' => 'Akalu', 'father_en' => 'Adfa', 'grand_en' => 'Arebashe', 'gender' => 'male', 'address' => 'Jinka'],
        'SH-0044' => ['type' => 'individual', 'first_am' => 'አበነዘር', 'father_am' => 'አልቴ', 'grand_am' => 'ሂሎ', 'first_en' => 'Abenezer', 'father_en' => 'Alte', 'grand_en' => 'Hilo', 'gender' => 'male', 'address' => 'Beneta Chaliti'],
        'SH-0045' => ['type' => 'individual', 'first_am' => 'ኦይሻ', 'father_am' => 'ቱሽኩሎ', 'grand_am' => null, 'first_en' => 'Oysha', 'father_en' => 'Tushkulo', 'grand_en' => null, 'gender' => 'male', 'address' => 'Beneta Chaliti'],
        'SH-0046' => ['type' => 'individual', 'first_am' => 'መዝገቡ', 'father_am' => 'ሶሳ', 'grand_am' => 'ፆኮሶ', 'first_en' => 'Mezgebu', 'father_en' => 'Sosa', 'grand_en' => 'Tsokoso', 'gender' => 'male', 'address' => 'Beneta Chaliti'],
        'SH-0047' => ['type' => 'individual', 'first_am' => 'አይሰማ', 'father_am' => 'አጫ', 'grand_am' => 'ዙራ', 'first_en' => 'Aysema', 'father_en' => 'Acha', 'grand_en' => 'Zura', 'gender' => 'male', 'address' => 'Adjo'],
        'SH-0048' => ['type' => 'individual', 'first_am' => 'ተገኝ', 'father_am' => 'ዛርቄ', 'grand_am' => 'ጎይኬ', 'first_en' => 'Tegen', 'father_en' => 'Zarke', 'grand_en' => 'Goyke', 'gender' => 'male', 'address' => 'Beneta Chaliti'],
        'SH-0049' => ['type' => 'individual', 'first_am' => 'ወገነ', 'father_am' => 'ማርቆስ', 'grand_am' => 'ጊግሻ', 'first_en' => 'Wegene', 'father_en' => 'Markos', 'grand_en' => 'Gigsha', 'gender' => 'male', 'address' => 'Beneta Chaliti'],
        'SH-0050' => ['type' => 'individual', 'first_am' => 'መረጠ', 'father_am' => 'ሶሳ', 'grand_am' => 'ፆኮሶ', 'first_en' => 'Merete', 'father_en' => 'Sosa', 'grand_en' => 'Tsokoso', 'gender' => 'male', 'address' => 'Beneta Chaliti'],
        'SH-0051' => ['type' => 'individual', 'first_am' => 'አስራት', 'father_am' => 'ሚልኪያስ', 'grand_am' => 'ላሜ', 'first_en' => 'Asrat', 'father_en' => 'Milkiyas', 'grand_en' => 'Lame', 'gender' => 'male', 'address' => 'Lemo Gento'],
        'SH-0052' => ['type' => 'individual', 'first_am' => 'አለ', 'father_am' => 'አድማሱ', 'grand_am' => 'ባዞ', 'first_en' => 'Ale', 'father_en' => 'Admasu', 'grand_en' => 'Bazo', 'gender' => 'male', 'address' => 'Beneta Chaliti'],
        'SH-0053' => ['type' => 'individual', 'first_am' => 'ምንተስኖት', 'father_am' => 'ካፒቴ', 'grand_am' => 'ጣሳው', 'first_en' => 'Mintesnot', 'father_en' => 'Kapite', 'grand_en' => 'Tasaw', 'gender' => 'male', 'address' => 'Koybe'],
        'SH-0054' => ['type' => 'individual', 'first_am' => 'ዘውደ', 'father_am' => 'ዘገዬ', 'grand_am' => 'ዛካና', 'first_en' => 'Zewde', 'father_en' => 'Zegeye', 'grand_en' => 'Zakana', 'gender' => 'male', 'address' => 'Lemo Gento'],
        'SH-0055' => ['type' => 'individual', 'first_am' => 'ሰብስቤ', 'father_am' => 'ፋንጋዣ', 'grand_am' => 'ሲሬ', 'first_en' => 'Sebsibe', 'father_en' => 'Fangaja', 'grand_en' => 'Sire', 'gender' => 'male', 'address' => 'Lemo Gento'],
        'SH-0056' => ['type' => 'individual', 'first_am' => 'ኢያድግ', 'father_am' => 'ጫሊ', 'grand_am' => 'ማላ', 'first_en' => 'Iyadg', 'father_en' => 'Chali', 'grand_en' => 'Mala', 'gender' => 'male', 'address' => 'Lemo Gento'],
        'SH-0057' => ['type' => 'individual', 'first_am' => 'ጴጥሮስ', 'father_am' => 'ዋላጭሬ', 'grand_am' => 'ሃይሳ', 'first_en' => 'Petros', 'father_en' => 'Walachire', 'grand_en' => 'Haysa', 'gender' => 'male', 'address' => 'Lemo Gento'],
        'SH-0058' => ['type' => 'individual', 'first_am' => 'ቸርነት', 'father_am' => 'ምዕራፍ', 'grand_am' => 'ጌቴ', 'first_en' => 'Chernet', 'father_en' => 'Miraf', 'grand_en' => 'Gete', 'gender' => 'male', 'address' => 'Lemo Gento'],
        'SH-0059' => ['type' => 'individual', 'first_am' => 'አይናለም', 'father_am' => 'ከበደ', 'grand_am' => 'ጋንቲ', 'first_en' => 'Aynalem', 'father_en' => 'Kebede', 'grand_en' => 'Ganti', 'gender' => 'male', 'address' => 'Lemo Gento'],
        'SH-0060' => ['type' => 'individual', 'first_am' => 'አድቫንቴጅ', 'father_am' => 'ኖኅ', 'grand_am' => 'አድማሱ', 'first_en' => 'Advantej', 'father_en' => 'Noh', 'grand_en' => 'Admasu', 'gender' => 'male', 'address' => 'Beneta Chaliti'],
        'SH-0061' => ['type' => 'individual', 'first_am' => 'ታረቀኝ', 'father_am' => 'ሚሊዮን', 'grand_am' => 'ደምሴ', 'first_en' => 'Tareken', 'father_en' => 'Milion', 'grand_en' => 'Demse', 'gender' => 'male', 'address' => 'Koybe'],
        'SH-0062' => ['type' => 'individual', 'first_am' => 'መሠለች', 'father_am' => 'ሳውላ', 'grand_am' => 'ጨነቀ', 'first_en' => 'Meselch', 'father_en' => 'Sawla', 'grand_en' => 'Cheneke', 'gender' => 'female', 'address' => 'Asho'],
        'SH-0063' => ['type' => 'individual', 'first_am' => 'ዝናዬ', 'father_am' => 'ዝማ', 'grand_am' => 'ሳጶ', 'first_en' => 'Znaye', 'father_en' => 'Zima', 'grand_en' => 'Sapo', 'gender' => 'male', 'address' => 'Koybe'],
        'SH-0064' => ['type' => 'individual', 'first_am' => 'ማዕከል', 'father_am' => 'ማኮስ', 'grand_am' => null, 'first_en' => 'Maekel', 'father_en' => 'Makos', 'grand_en' => null, 'gender' => 'male', 'address' => 'Mashala'],
        'SH-0065' => ['type' => 'individual', 'first_am' => 'አለቱ', 'father_am' => 'ዋላካ', 'grand_am' => null, 'first_en' => 'Aletu', 'father_en' => 'Walaka', 'grand_en' => null, 'gender' => 'male', 'address' => 'Mashala'],
        'SH-0066' => ['type' => 'individual', 'first_am' => 'አማን', 'father_am' => 'ኢሳያስ', 'grand_am' => 'ቦንቃ', 'first_en' => 'Aman', 'father_en' => 'Isayas', 'grand_en' => 'Bonqa', 'gender' => 'male', 'address' => 'Koybe'],
        'SH-0067' => ['type' => 'individual', 'first_am' => 'ማዕረግ', 'father_am' => 'ቱርቄ', 'grand_am' => 'አርታሞ', 'first_en' => 'Maereg', 'father_en' => 'Turqe', 'grand_en' => 'Artamo', 'gender' => 'male', 'address' => 'Aleze'],
        'SH-0068' => ['type' => 'individual', 'first_am' => 'አብነት', 'father_am' => 'አሸናፊ', 'grand_am' => 'አድማሱ', 'first_en' => 'Abnet', 'father_en' => 'Ashenafi', 'grand_en' => 'Admasu', 'gender' => 'male', 'address' => 'Beneta Chaliti'],
        'SH-0069' => ['type' => 'individual', 'first_am' => 'ተስፋዬ', 'father_am' => 'ጣቦ', 'grand_am' => 'ባጆ', 'first_en' => 'Tesfaye', 'father_en' => 'Tabo', 'grand_en' => 'Bajo', 'gender' => 'male', 'address' => 'Mashala'],
        'SH-0070' => ['type' => 'individual', 'first_am' => 'በድሩ', 'father_am' => 'መለሰ', 'grand_am' => 'ሐርፎ', 'first_en' => 'Bedru', 'father_en' => 'Melese', 'grand_en' => 'Harfo', 'gender' => 'male', 'address' => 'Mashala'],
        'SH-0071' => ['type' => 'individual', 'first_am' => 'መለሰ', 'father_am' => 'ሉቃስ', 'grand_am' => 'አማዶ', 'first_en' => 'Melese', 'father_en' => 'Lukas', 'grand_en' => 'Amado', 'gender' => 'male', 'address' => 'Aleze'],
        'SH-0072' => ['type' => 'individual', 'first_am' => 'ተሰማ', 'father_am' => 'ሚካኔ', 'grand_am' => 'ማዛሪ', 'first_en' => 'Tesema', 'father_en' => 'Mikane', 'grand_en' => 'Mazari', 'gender' => 'male', 'address' => 'Asho'],
        'SH-0073' => ['type' => 'individual', 'first_am' => 'ንጎ', 'father_am' => 'ዶሎኮ', 'grand_am' => 'ቦኦ', 'first_en' => 'Ngo', 'father_en' => 'Doloko', 'grand_en' => 'Boo', 'gender' => 'male', 'address' => 'Beneta Chaliti'],
        'SH-0074' => ['type' => 'individual', 'first_am' => 'መሐመድ', 'father_am' => 'ካማዝል', 'grand_am' => null, 'first_en' => 'Mehammed', 'father_en' => 'Kamazl', 'grand_en' => null, 'gender' => 'male', 'address' => 'Chali'],
        'SH-0075' => ['type' => 'individual', 'first_am' => 'ሳዖል', 'father_am' => 'ሳልፋ', 'grand_am' => 'አማሌ', 'first_en' => 'Saol', 'father_en' => 'Salfa', 'grand_en' => 'Amaale', 'gender' => 'male', 'address' => 'Koybe'],
        'SH-0076' => ['type' => 'individual', 'first_am' => 'አራፋ', 'father_am' => 'አታና', 'grand_am' => 'ሀላቃ', 'first_en' => 'Arafa', 'father_en' => 'Atana', 'grand_en' => 'Halaqa', 'gender' => 'male', 'address' => 'Bala'],
        'SH-0077' => ['type' => 'individual', 'first_am' => 'ደስታ', 'father_am' => 'አራፋ', 'grand_am' => 'አታና', 'first_en' => 'Desta', 'father_en' => 'Arafa', 'grand_en' => 'Atana', 'gender' => 'male', 'address' => 'Bala'],
        'SH-0078' => ['type' => 'individual', 'first_am' => 'ሞተር', 'father_am' => 'ሊባኖ', 'grand_am' => 'ወጭፎ', 'first_en' => 'Moter', 'father_en' => 'Libano', 'grand_en' => 'Wechfo', 'gender' => 'male', 'address' => 'Mashala'],
        'SH-0079' => ['type' => 'individual', 'first_am' => 'አለኝታ', 'father_am' => 'አትሌ', 'grand_am' => 'ወሮስ', 'first_en' => 'Alenta', 'father_en' => 'Atle', 'grand_en' => 'Woros', 'gender' => 'male', 'address' => 'Mashala'],
        'SH-0080' => ['type' => 'individual', 'first_am' => 'አየለ', 'father_am' => 'ዳካ', 'grand_am' => 'ዲጋ', 'first_en' => 'Ayele', 'father_en' => 'Daka', 'grand_en' => 'Diga', 'gender' => 'male', 'address' => 'Koybe'],
        'SH-0081' => ['type' => 'individual', 'first_am' => 'በረከት', 'father_am' => 'ጋውሮሊ', 'grand_am' => null, 'first_en' => 'Bereket', 'father_en' => 'Gawroli', 'grand_en' => null, 'gender' => 'male', 'address' => 'Asho'],
        'SH-0082' => ['type' => 'individual', 'first_am' => 'ታረቀኝ', 'father_am' => 'ሞዴ', 'grand_am' => 'አራጤ', 'first_en' => 'Tareken', 'father_en' => 'Mode', 'grand_en' => 'Arate', 'gender' => 'male', 'address' => 'Lemo Gento'],
        'SH-0083' => ['type' => 'individual', 'first_am' => 'ሞያ', 'father_am' => 'ሊባኖ', 'grand_am' => 'ወጭፎ', 'first_en' => 'Moya', 'father_en' => 'Libano', 'grand_en' => 'Wechfo', 'gender' => 'male', 'address' => 'Mashala'],
        'SH-0084' => ['type' => 'individual', 'first_am' => 'ሕጌ', 'father_am' => 'ጎሻ', 'grand_am' => 'ሳኬ', 'first_en' => 'Hige', 'father_en' => 'Gosha', 'grand_en' => 'Sake', 'gender' => 'male', 'address' => 'Aleze'],
        'SH-0085' => ['type' => 'individual', 'first_am' => 'አንተነህ', 'father_am' => 'አዱማ', 'grand_am' => null, 'first_en' => 'Anteneh', 'father_en' => 'Aduma', 'grand_en' => null, 'gender' => 'male', 'address' => 'Lemo Gento'],
        'SH-0086' => ['type' => 'individual', 'first_am' => 'አዳኝ', 'father_am' => 'ትሽቴ', 'grand_am' => 'ሻምባቾ', 'first_en' => 'Adagn', 'father_en' => 'Tishte', 'grand_en' => 'Shambacho', 'gender' => 'male', 'address' => 'Mashala'],
        'SH-0087' => ['type' => 'individual', 'first_am' => 'መረበ', 'father_am' => 'ቱዳ', 'grand_am' => 'ዳቆ', 'first_en' => 'Merebe', 'father_en' => 'Tuda', 'grand_en' => 'Dako', 'gender' => 'male', 'address' => 'Aleze'],
        'SH-0088' => ['type' => 'religious_body', 'entity_am' => 'ሳራንጊ አ/ቃ/ሕ/ቤ/ክ', 'entity_en' => 'Sarangi', 'address' => 'Aleze'],
        'SH-0089' => ['type' => 'individual', 'first_am' => 'ደርጓ', 'father_am' => 'ዱባሌ', 'grand_am' => 'ወዳጄ', 'first_en' => 'Derga', 'father_en' => 'Dubale', 'grand_en' => 'Wedaje', 'gender' => 'female', 'address' => 'Lemo Gento'],
        'SH-0090' => ['type' => 'individual', 'first_am' => 'መሆን', 'father_am' => 'ግዛው', 'grand_am' => null, 'first_en' => 'Mehon', 'father_en' => 'Gizaw', 'grand_en' => null, 'gender' => 'male', 'address' => 'Irbo'],
        'SH-0091' => ['type' => 'individual', 'first_am' => 'ኃያሉ', 'father_am' => 'ባራኖ', 'grand_am' => null, 'first_en' => 'Hayalu', 'father_en' => 'Barano', 'grand_en' => null, 'gender' => 'male', 'address' => 'Damiker'],
        'SH-0092' => ['type' => 'individual', 'first_am' => 'ሰላሙ', 'father_am' => 'ምዕራፍ', 'grand_am' => 'ጌቴ', 'first_en' => 'Selamu', 'father_en' => 'Miraf', 'grand_en' => 'Gete', 'gender' => 'male', 'address' => 'Lemo Gento'],
        'SH-0093' => ['type' => 'individual', 'first_am' => 'ኢየሩስ', 'father_am' => 'ሲለሺ', 'grand_am' => 'ቲሳ', 'first_en' => 'Iyerus', 'father_en' => 'Sileshi', 'grand_en' => 'Tisa', 'gender' => 'female', 'address' => 'Koybe'],
        'SH-0094' => ['type' => 'individual', 'first_am' => 'ማቴዎስ', 'father_am' => 'ጋርማጬ', 'grand_am' => null, 'first_en' => 'Matewos', 'father_en' => 'Garmache', 'grand_en' => null, 'gender' => 'male', 'address' => 'Irbo'],
        'SH-0095' => ['type' => 'individual', 'first_am' => 'መብራቱ', 'father_am' => 'ፑይላቄ', 'grand_am' => 'ግንሶ', 'first_en' => 'Mebratu', 'father_en' => 'Puyilaqe', 'grand_en' => 'Ginso', 'gender' => 'male', 'address' => 'Irbo'],
        'SH-0096' => ['type' => 'individual', 'first_am' => 'ጮልባ', 'father_am' => 'በራ', 'grand_am' => 'ጋርሳር', 'first_en' => 'Cholba', 'father_en' => 'Bera', 'grand_en' => 'Garsar', 'gender' => 'male', 'address' => 'Irbo'],
        'SH-0097' => ['type' => 'individual', 'first_am' => 'ላየ', 'father_am' => 'ጋቾ', 'grand_am' => 'ዲማሪ', 'first_en' => 'Laye', 'father_en' => 'Gacho', 'grand_en' => 'Dimari', 'gender' => 'male', 'address' => 'Irbo'],
        'SH-0098' => ['type' => 'individual', 'first_am' => 'ሳውቴ', 'father_am' => 'ፎቃ', 'grand_am' => 'ከበደ', 'first_en' => 'Sawte', 'father_en' => 'Foqa', 'grand_en' => 'Kebede', 'gender' => 'male', 'address' => 'Irbo'],
        'SH-0099' => ['type' => 'individual', 'first_am' => 'ተሻለ', 'father_am' => 'ኮንታ', 'grand_am' => 'ኮዳ', 'first_en' => 'Teshale', 'father_en' => 'Konta', 'grand_en' => 'Koda', 'gender' => 'male', 'address' => 'Bala'],
        'SH-0100' => ['type' => 'individual', 'first_am' => 'ገድንቃ', 'father_am' => 'ገልሞ', 'grand_am' => 'ዱጎ', 'first_en' => 'Gednqa', 'father_en' => 'Gelmo', 'grand_en' => 'Dugo', 'gender' => 'male', 'address' => 'Irbo'],
    ];
}