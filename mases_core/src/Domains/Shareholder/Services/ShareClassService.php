<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Shareholder\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareClass;
use RuntimeException;

/**
 * Read-only lookup service for configured, seeded share classes.
 *
 * @package MaaleArna\MASES\Core\Domains\Shareholder\Services
 */
final class ShareClassService
{
    /**
     * Cache key for the full share-class list.
     */
    private const CACHE_KEY = 'shareholder:share_classes:all';

    /**
     * Cache duration in seconds.
     *
     * Five minutes: long enough that a healthy request path almost always
     * hits the cache; short enough that a modified configuration (or a
     * freshly-seeded class) becomes visible without an explicit cache flush.
     */
    private const CACHE_TTL = 300;

    /**
     * In-process memo of the ordered list, keyed by an "active only" flag.
     *
     * Populated lazily by `all()`. Lives for the lifetime of the service
     * instance — effectively one HTTP request on PHP-FPM.
     *
     * @var array<int, Collection<int, ShareClass>>
     */
    private array $memo = [];

    // =========================================================================
    // Listing
    // =========================================================================

    /**
     * Return every share class, in canonical presentation order.
     *
     * Order:
     *   1. FOUNDERS
     *   2. STANDARD
     *   3. Any other code alphabetically
     *
     * The order is imposed here rather than in SQL. See the class docblock.
     *
     * @param bool $activeOnly  When true, only classes with `is_active = true`.
     * @return Collection<int, ShareClass>
     */
    public function all(bool $activeOnly = false): Collection
    {
        $memoKey = $activeOnly ? 1 : 0;

        if (isset($this->memo[$memoKey])) {
            return $this->memo[$memoKey];
        }

        $classes = $this->loadOrderedClasses($activeOnly);

        $this->memo[$memoKey] = $classes;

        return $classes;
    }

    /**
     * Return every active share class.
     *
     * Convenience wrapper around `all(true)`.
     *
     * @return Collection<int, ShareClass>
     */
    public function allActive(): Collection
    {
        return $this->all(true);
    }

    // =========================================================================
    // Lookups by code
    // =========================================================================

    /**
     * Find a share class by its code, returning null if not present.
     *
     * The lookup is case-insensitive (the model mutator normalises both
     * sides of the comparison to uppercase), so callers can pass either
     * case safely.
     *
     * @param string $code
     * @return ShareClass|null
     */
    public function findByCode(string $code): ?ShareClass
    {
        $normalised = strtoupper(trim($code));

        // Prefer the cached list when it is already warm, so a repeated
        // lookup in the same request does not query the DB per call.
        foreach ($this->all() as $class) {
            if ($class->code === $normalised) {
                return $class;
            }
        }

        // Fallback: direct DB query. This branch runs only when the class
        // is not in the cached list — for example, if a class was created
        // after the cache was populated, or if the caller asked for a code
        // that is not present.
        return ShareClass::findByCode($normalised);
    }

    /**
     * Find a share class by its code, throwing if it does not exist.
     *
     * @param string $code
     * @return ShareClass
     *
     * @throws RuntimeException
     */
    public function findByCodeOrFail(string $code): ShareClass
    {
        $class = $this->findByCode($code);

        if ($class === null) {
            throw new RuntimeException(
                sprintf('Share class with code "%s" is not seeded in the database.', $code)
            );
        }

        return $class;
    }

    /**
     * Whether a share class with the given code exists.
     *
     * Intended for input validation (e.g. StoreShareClassRequest, 2A.18).
     * Does not distinguish between "class exists but is inactive" and
     * "class does not exist" — the caller can read `is_active` from the
     * model if that distinction matters.
     *
     * @param string $code
     * @return bool
     */
    public function existsByCode(string $code): bool
    {
        return $this->findByCode($code) !== null;
    }

    /**
     * Whether the given code is one of the codes declared in configuration.
     *
     * This does NOT consult the database. It only checks against the static
     * vocabulary in `config('shareholder.share_classes.codes')`.
     *
     * Use this to validate that an administrator's proposed new class code
     * matches the vocabulary. Use `existsByCode()` to check whether the
     * class has actually been seeded.
     *
     * The distinction matters during a redeploy: after a CR adds a code to
     * the config, `codeIsValid()` returns true immediately, but
     * `existsByCode()` still returns false until the class is seeded.
     *
     * @param string $code
     * @return bool
     */
    public function codeIsValid(string $code): bool
    {
        $normalised = strtoupper(trim($code));
        $codes = (array) config('shareholder.share_classes.codes', []);

        return in_array($normalised, array_map('strtoupper', $codes), true);
    }

    // =========================================================================
    // Named-class resolution
    // =========================================================================

    /**
     * Return the default share class for new shareholders, or null if
     * the class has not been seeded.
     *
     * The default code is read from
     * `config('shareholder.share_classes.default_code')`.
     *
     * @return ShareClass|null
     */
    public function defaultClass(): ?ShareClass
    {
        $code = (string) config('shareholder.share_classes.default_code', 'STANDARD');

        return $this->findByCode($code);
    }

    /**
     * Return the default share class, throwing if it has not been seeded.
     *
     * @return ShareClass
     *
     * @throws RuntimeException
     */
    public function defaultClassOrFail(): ShareClass
    {
        $class = $this->defaultClass();

        if ($class === null) {
            $code = (string) config('shareholder.share_classes.default_code', 'STANDARD');

            throw new RuntimeException(
                sprintf(
                    'Default share class "%s" is not seeded. The deployment is incomplete.',
                    $code,
                )
            );
        }

        return $class;
    }

    /**
     * Return the founders' share class, or null if it has not been seeded.
     *
     * The founder code is read from
     * `config('shareholder.share_classes.founder_code')`.
     *
     * @return ShareClass|null
     */
    public function founderClass(): ?ShareClass
    {
        $code = (string) config('shareholder.share_classes.founder_code', 'FOUNDERS');

        return $this->findByCode($code);
    }

    /**
     * Return the founders' share class, throwing if it has not been seeded.
     *
     * @return ShareClass
     *
     * @throws RuntimeException
     */
    public function founderClassOrFail(): ShareClass
    {
        $class = $this->founderClass();

        if ($class === null) {
            $code = (string) config('shareholder.share_classes.founder_code', 'FOUNDERS');

            throw new RuntimeException(
                sprintf(
                    'Founders share class "%s" is not seeded. The deployment is incomplete.',
                    $code,
                )
            );
        }

        return $class;
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Load every share class from the cache or the database, in canonical
     * order.
     *
     * The order applied to the returned collection matches the order of
     * `config('shareholder.share_classes.codes')`. Any code present in the
     * database but absent from the config is placed after the configured
     * codes, sorted alphabetically. This ensures that a class the operators
     * manually added to the database is visible (for diagnostics) but does
     * not disturb the canonical order.
     *
     * @param bool $activeOnly
     * @return Collection<int, ShareClass>
     */
    private function loadOrderedClasses(bool $activeOnly): Collection
    {
        $cacheKey = $activeOnly ? self::CACHE_KEY . ':active' : self::CACHE_KEY;

        /** @var Collection<int, ShareClass> $raw */
        $raw = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($activeOnly): Collection {
            $query = ShareClass::query();

            if ($activeOnly) {
                $query->where('is_active', true);
            }

            return $query->get();
        });

        return $this->orderByCanonicalCode($raw);
    }

    /**
     * Return a new collection ordered by the canonical code list.
     *
     * Classes whose code appears in the config are placed in config order.
     * Any other classes are appended, sorted alphabetically by code.
     *
     * @param Collection<int, ShareClass> $classes
     * @return Collection<int, ShareClass>
     */
    private function orderByCanonicalCode(Collection $classes): Collection
    {
        $canonical = array_map(
            'strtoupper',
            (array) config('shareholder.share_classes.codes', []),
        );

        // Stable sort: first by config index (or PHP_INT_MAX for unknown
        // codes), then alphabetically by code for the tail.
        $ordered = $classes->sortBy(function (ShareClass $class) use ($canonical): array {
            $position = array_search($class->code, $canonical, true);

            return [
                $position === false ? PHP_INT_MAX : $position,
                $class->code,
            ];
        })->values();

        return new Collection($ordered->all());
    }
}