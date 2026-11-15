<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Security;

use InvalidArgumentException;
use JsonException;

/**
 * Restricted RFC 8785-compatible canonical JSON for AGE receipt event bodies.
 *
 * Protocol event objects use ASCII property names and JSON scalar/list values.
 * Floating-point numbers and arbitrary PHP objects are rejected so runtime
 * serialization differences cannot silently alter a signed commitment.
 */
final class AgeReceiptCanonicalizer
{
    private const EVENT_DOMAIN = "arna:age:external-receipt:event:v1\0";
    private const MAX_SAFE_INTEGER = 9007199254740991;

    /**
     * @param array<string,mixed> $value
     *
     * @throws InvalidArgumentException
     * @throws JsonException
     */
    public static function encode(array $value): string
    {
        return json_encode(
            self::normalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * @param array<string,mixed> $eventBody
     *
     * @throws InvalidArgumentException
     * @throws JsonException
     */
    public static function eventHash(array $eventBody): string
    {
        return hash('sha256', self::EVENT_DOMAIN . self::encode($eventBody));
    }

    private static function normalize(mixed $value): mixed
    {
        if ($value === null || is_bool($value) || is_string($value)) {
            return $value;
        }

        if (is_int($value)) {
            if (abs($value) > self::MAX_SAFE_INTEGER) {
                throw new InvalidArgumentException('AGE receipt integers must be within the interoperable JSON safe-integer range.');
            }

            return $value;
        }

        if (is_float($value)) {
            throw new InvalidArgumentException('AGE receipt event bodies must not contain floating-point values.');
        }

        if (!is_array($value)) {
            throw new InvalidArgumentException('AGE receipt event bodies may contain only JSON-compatible arrays and scalar values.');
        }

        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            if (!is_string($key) || !preg_match('/^[\x20-\x7E]+$/D', $key)) {
                throw new InvalidArgumentException('AGE receipt object property names must be non-empty ASCII strings.');
            }

            $normalized[$key] = self::normalize($item);
        }

        ksort($normalized, SORT_STRING);

        return $normalized;
    }
}
