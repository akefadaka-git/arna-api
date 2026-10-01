<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Shareholder\Enums;

/**
 * ShareholderType
 *
 * The controlled vocabulary of shareholder entity types recognised by MASES.
 *
 * =============================================================================
 * Source of truth
 * =============================================================================
 * Decision D9 — Permitted shareholder entity types:
 *
 *     individual
 *     company
 *     trust
 *     cooperative
 *     government
 *     religious_body
 *
 * The six values above are the ONLY values permitted in the
 * `shareholders.shareholder_type` column. They are also the only values the
 * Shareholder domain accepts at its HTTP boundary (validated by
 * StoreShareholderRequest, 2A.16) and the only values the ShareholderService
 * (2A.12) will process.
 *
 * The list is frozen by D9. Adding, removing, or renaming a case is a
 * Change Request under Master Prompt Section 58, because it changes the
 * meaning of historical rows in the `shareholders` table.
 *
 * =============================================================================
 * Representative rule (D11)
 * =============================================================================
 * Decision D11 — Representative model:
 *
 *   Every shareholder type EXCEPT `individual` requires a designated
 *   authorized human representative User. The representative is not the
 *   owner; the legal entity is the shareholder.
 *
 * This rule is expressed by the `requiresRepresentative()` method below.
 * It is enforced in three places, all of which call this method rather than
 * reimplementing the rule:
 *
 *   1. StoreShareholderRequest (2A.16)  — validation rule conditional
 *                                          on shareholder_type.
 *   2. ShareholderService (2A.12)       — business invariant before persist.
 *   3. ShareholderPolicy (2A.29)        — authorization context.
 *
 * The method reads the enforcement list from
 * `config('shareholder.representative.require_for_types')` (declared in
 * Config/shareholder.php, file 2A.1), falling back to a hard-coded default
 * that mirrors D11 if the config is not loaded. The fallback exists so that
 * unit tests which instantiate the enum without booting the framework still
 * exercise the correct rule.
 *
 * =============================================================================
 * Why this is a backed string enum
 * =============================================================================
 * The enum is string-backed so that:
 *
 *   - It maps directly to the `shareholders.shareholder_type` column
 *     without a casting layer.
 *   - It survives database dumps: a `SHOW CREATE TABLE` on `shareholders`
 *     shows the plain string values, which match the enum cases exactly.
 *   - It can be used in validation rules via `Rule::enum(ShareholderType::class)`,
 *     which Laravel resolves against the enum's values.
 *
 * =============================================================================
 * Localization
 * =============================================================================
 * This enum deliberately does NOT provide a `label()` method. Display strings
 * are a presentation concern and belong to the ShareholderResource (2A.25),
 * which resolves them through the application localization system using
 * translation keys of the form `shareholder.type.{value}`. Hard-coding
 * English or Amharic display text in a domain enum would (a) violate the
 * Amharic-first policy by presenting one language as canonical, and (b)
 * require a Change Request to update, which is disproportionate for a
 * display string.
 *
 * @package MaaleArna\MASES\Core\Domains\Shareholder\Enums
 */
enum ShareholderType: string
{
    /**
     * A natural person. May optionally be linked to a User for self-service
     * access, but is not required to have one — an individual shareholder
     * may be represented by an administrator.
     *
     * D9 case 1 of 6.
     */
    case Individual = 'individual';

    /**
     * A legally incorporated company. Must be represented by a designated
     * authorized human representative User (D11).
     *
     * D9 case 2 of 6.
     */
    case Company = 'company';

    /**
     * A legally constituted trust. Must be represented by a designated
     * authorized human representative User (D11).
     *
     * D9 case 3 of 6.
     */
    case Trust = 'trust';

    /**
     * A legally registered cooperative society. Must be represented by a
     * designated authorized human representative User (D11).
     *
     * D9 case 4 of 6.
     */
    case Cooperative = 'cooperative';

    /**
     * A government body or state-owned entity. Must be represented by a
     * designated authorized human representative User (D11).
     *
     * D9 case 5 of 6.
     */
    case Government = 'government';

    /**
     * A legally recognized religious organization, including churches and
     * other qualifying religious bodies. Must be represented by a designated
     * authorized human representative User (D11).
     *
     * D9 case 6 of 6.
     */
    case ReligiousBody = 'religious_body';

    /**
     * Fallback list of shareholder types that require a representative,
     * used only when `config('shareholder.representative.require_for_types')`
     * is unavailable.
     *
     * Must be kept in sync with Config/shareholder.php and with D11. If the
     * config is edited, this list may still be stale for a moment — but the
     * config takes precedence at runtime, so the fallback only applies in
     * environments where config is not loaded (unit tests, bare scripts).
     *
     * @var array<int, string>
     */
    private const DEFAULT_REPRESENTATIVE_REQUIRED = [
        'company',
        'trust',
        'cooperative',
        'government',
        'religious_body',
    ];

    /**
     * Determine whether this shareholder type represents a natural person.
     *
     * True only for `individual`. All other cases are legal entities.
     *
     * @return bool
     */
    public function isIndividual(): bool
    {
        return $this === self::Individual;
    }

    /**
     * Determine whether this shareholder type represents a legal entity.
     *
     * The inverse of `isIndividual()`. Encoded as a separate method (rather
     * than `!$this->isIndividual()`) so that callers can express their
     * intent directly, and so that if a future decision introduces a type
     * that is neither a natural person nor a legal entity, the two methods
     * can be updated independently without silently breaking the semantics
     * of either.
     *
     * @return bool
     */
    public function isLegalEntity(): bool
    {
        return $this !== self::Individual;
    }

    /**
     * Determine whether this shareholder type requires a designated
     * representative User.
     *
     * Per D11: all types except `individual` require a representative.
     * The list of types that require representation is read from the domain
     * configuration so that a future policy change does not require editing
     * this enum — only the config, and a CR to record the change.
     *
     * @return bool
     */
    public function requiresRepresentative(): bool
    {
        $types = (array) config(
            'shareholder.representative.require_for_types',
            self::DEFAULT_REPRESENTATIVE_REQUIRED
        );

        return in_array($this->value, $types, true);
    }

    /**
     * Get the raw string values of every case, in declaration order.
     *
     * Useful as a validation whitelist and as an input to
     * `Rule::in(...)` when the caller cannot use `Rule::enum(...)` — e.g.
     * inside a config file, a migration, or a non-Laravel context.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $case): string => $case->value,
            self::cases()
        );
    }

    /**
     * Get the raw string values of every case that requires a representative.
     *
     * Equivalent to filtering `cases()` through `requiresRepresentative()`,
     * but expressed as a static method so that callers validating input
     * before the enum value is known (e.g. a conditional validation rule
     * keyed on a submitted `shareholder_type` string) can consult the
     * requirement without instantiating an enum case first.
     *
     * The result is derived from the same config key as
     * `requiresRepresentative()`, so the two cannot drift.
     *
     * @return array<int, string>
     */
    public static function valuesRequiringRepresentative(): array
    {
        $types = (array) config(
            'shareholder.representative.require_for_types',
            self::DEFAULT_REPRESENTATIVE_REQUIRED
        );

        // Intersect the caller-supplied list with the enum's own values so
        // that a misconfigured `shareholder.representative.require_for_types`
        // key cannot introduce a value that is not a valid ShareholderType.
        return array_values(array_intersect($types, self::values()));
    }
}