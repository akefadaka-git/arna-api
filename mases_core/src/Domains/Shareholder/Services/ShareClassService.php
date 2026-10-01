<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Shareholder\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareClass;
use RuntimeException;

/**
 * ShareClassService
 *
 * Read-only access to the two seeded share classes: FOUNDERS and STANDARD.
 *
 * =============================================================================
 * What this service is for
 * =============================================================================
 * The Shareholder domain recognises exactly two share classes, both seeded
 * into the database by the ShareClassSeeder:
 *
 *     FOUNDERS  (name_am = መስራች)  — held by the 29 founding shareholders.
 *     STANDARD  (name_am = መደበኛ)  — the default class for new shareholders.
 *
 * Callers throughout the domain need to resolve these classes by code
 * without writing an Eloquent query each time. This service centralises
 * that resolution:
 *
 *   - The Shareholder domain's seeders (2A.42, 2A.43) use `all()` to know
 *     which classes exist.
 *
 *   - The ShareholderService (2A.12) uses `defaultClassOrFail()` when it
 *     needs to attach a newly registered shareholder to a class.
 *
 *   - The StoreShareClassRequest (2A.18) uses `codeIsValid()` to validate
 *     an admin's proposed class code.
 *
 *   - Resources (2A.26) call `findByCode()` for label resolution.
 *
 * There is one deliberately absent method: any form of write. Share
 * classes are reference data. In Phase 2A they are seeded and never
 * created, updated, or deleted through this service. If a future phase
 * adds an admin UI for class management, a Change Request will add the
 * write methods and the accompanying validation.
 *
 * =============================================================================
 * Deterministic ordering
 * =============================================================================
 * `all()` returns classes in a stable order:
 *
 *   1. FOUNDERS first (the historical, foundational class).
 *   2. STANDARD second (the default for new shareholders).
 *   3. Any other class alphabetically by code.
 *
 * The order is derived from `config('shareholder.share_classes.codes')`,
 * which declares the canonical vocabulary in its intended presentation
 * order. The service does not rely on the database's `ORDER BY id` because
 * that would be sensitive to seeding order, which is an implementation
 * detail rather than a domain fact.
 *
 * The order is imposed in PHP rather than in SQL because:
 *
 *   - The list is tiny (two rows in Phase 2A, at most a handful in future).
 *   - The order is a domain concept (founders first), not a query plan.
 *   - The configured order is the single source of truth; keeping it in
 *     PHP means editing one array in one file, not an `ORDER BY FIELD(...)`
 *     clause that drifts.
 *
 * =============================================================================
 * Caching strategy
 * =============================================================================
 * Two layers:
 *
 *   1. Static per-instance memo. The service is bound as a singleton
 *      (2A.2), so within one HTTP request the static `$memo` array is
 *      consulted before any cache or database call. This is the hot path
 *      for callers that invoke the service multiple times per request.
 *
 *   2. Framework cache. `Cache::remember()` with a 5-minute TTL protects
 *      a cold request from hitting the database on the first call. The
 *      cache key is stable and short.
 *
 * The cache is invalidated only by TTL expiry. There is no explicit
 * invalidation because share classes never change during Phase 2A. If a
 * future admin UI adds class creation/deletion, that CR must also add
 * cache invalidation — most likely by forgetting the specific key on
 * every write in this service.
 *
 * =============================================================================
 * Failure modes
 * =============================================================================
 * The two named-class methods have two variants each:
 *
 *   `defaultClass(): ?ShareClass`   — returns null if the default class is
 *                                     missing from the database.
 *
 *   `defaultClassOrFail(): ShareClass` — throws RuntimeException if the
 *                                        default class is missing.
 *
 * The `OrFail` variant is intended for callers for whom a missing default
 * class is a deployment defect that should surface as a 500. The nullable
 * variant is intended for callers that can tolerate absence (e.g. a
 * health-check endpoint that reports "share classes not seeded").
 *
 * `RuntimeException` — not `ValidationException` — is used because a
 * missing seeded class is not the caller's fault. It is a broken
 * deployment. The canonical error renderer in `bootstrap/app.php` maps
 * unhandled exceptions to `INTERNAL_SERVER_ERROR` with a generic message.
 *
 * =============================================================================
 * What this service does NOT do
 * =============================================================================
 *   - It does not create, update, or delete share classes.
 *
 *   - It does not validate arbitrary codes. `codeIsValid()` returns a
 *     boolean; it does not throw for an unknown code.
 *
 *   - It does not enforce per-class business rules (e.g. "FOUNDERS may
 *     only be held by the 29 founders"). Such rules, if they exist, belong
 *     to the services that manipulate holdings (2A.14) and certificates
 *     (2A.15).
 *
 *   - It does not provide localized display strings. Those come from
 *     resources (2A.26) via translation keys.
 *
 *   - It does not return the parse value or currency. Those are read from
 *     the model's own attributes by the caller.
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