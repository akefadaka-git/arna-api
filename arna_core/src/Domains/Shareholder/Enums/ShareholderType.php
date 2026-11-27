<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Shareholder\Enums;

/**
 * Closed D9 shareholder-type vocabulary: individual, company, trust, cooperative, government and religious_body. Changes require a controlled contract and data review.
 *
 * @package MaaleArna\Arna\Core\Domains\Shareholder\Enums
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