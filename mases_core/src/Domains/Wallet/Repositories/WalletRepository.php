<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;

/**
 * WalletRepository
 *
 * Read-only query surface for the `Wallet` aggregate root.
 *
 * =============================================================================
 * Revision history
 * =============================================================================
 * 1.0.0  — initial delivery.
 *
 * 1.0.1  — listForTenant() gains filter support.
 *
 *          The frontend's wallet list sends query parameters `is_active`,
 *          `search`, and `shareholder_id` (see src/features/wallet/api.ts
 *          §ListWalletsQuery). The v1.0.0 repository accepted only a
 *          tenant id and returned every wallet for that tenant — the
 *          filter parameters arrived at the controller and were silently
 *          ignored, so the UI's search and status filter had no effect on
 *          the response.
 *
 *          `listForTenant()` now takes an optional `$filters` array and
 *          applies each supported key:
 *
 *            · `is_active`      — strict equality against the wallet's
 *                                 boolean flag.
 *
 *            · `shareholder_id` — strict equality against the wallet's
 *                                 owner.
 *
 *            · `search`         — a case-insensitive LIKE across the
 *                                 wallet id, the owner's id, the
 *                                 currency code, and the owner's
 *                                 bilingual name columns.
 *
 * 1.0.2  — search filter: ID-prefix handling hardened.
 *
 *          The v1.0.1 `search` filter's numeric-ID branch was reached
 *          only when the trimmed, `#`-stripped input was all digits.
 *          The frontend sends the term `#4` verbatim in the query
 *          string; browsers and the framework's query-string parser
 *          deliver it to PHP in one of several forms depending on the
 *          encoding the client used:
 *
 *            '#4'      — the literal (unencoded) form
 *            '%234'    — the URL-encoded form of '#4'
 *            '%25234'  — the doubly-encoded form
 *
 *          v1.0.1 handled only the first. `%234` and `%25234` reached
 *          the ID branch unchanged, failed the `ctype_digit` test, and
 *          fell through to the name/currency LIKE branches, which
 *          matched nothing — producing an empty result set.
 *
 *          The fix normalises the input before the numeric-ID branch
 *          is evaluated:
 *
 *            · `rawurldecode()` is applied once. It is a no-op on
 *              already-decoded strings, so unconditional application
 *              is safe. It converts `%234` → `#4`, `%25234` →
 *              `%234` → the second decode is not applied (a single
 *              pass is correct; the framework's parser already
 *              decoded one level).
 *
 *            · Leading non-digit characters are stripped with a
 *              Unicode-aware regex (`/^\D+/u`) and the remainder is
 *              trimmed. `#4` → `4`; `  #4  ` → `4`; `abc4` → `4`
 *              (the `abc` prefix is discarded in favour of the
 *              trailing digits, matching the intent "the caller
 *              supplied an identifier").
 *
 *          The remaining name and currency LIKE branches use the
 *          ORIGINAL `$search` term (not the normalised `$idCandidate`)
 *          so that a search for a partial name such as `Akefa` is
 *          unaffected, and a search for `#Akefa` (unusual, but
 *          possible) still matches the name branch.
 *
 *          No other method changed. `findForTenant()`,
 *          `findForShareholder()`, and `existsForShareholder()` are
 *          byte-for-byte identical to 1.0.0.
 *
 * =============================================================================
 * What this repository is
 * =============================================================================
 * A focused query helper for wallet lookups. It centralises the
 * tenant-scoping invariant that every caller of the wallet domain must
 * respect: a wallet is addressable only within the tenant that owns it.
 * A caller that supplies a foreign tenant id receives `null`; the caller
 * (typically a controller) translates `null` into a `ModelNotFoundException`
 * → 404, indistinguishable from a non-existent id.
 *
 * The repository performs NO writes. Wallet creation is performed by
 * WalletService (2B.15) via `Wallet::create([...])`; the repository does
 * not wrap that operation. This mirrors the 2A pattern, where
 * `ShareholderService::register()` calls `Shareholder::create([...])`
 * directly and `ShareholderRepository` provides only read methods.
 *
 * The repository performs NO locking. Row-level locks for transactional
 * write paths (`Wallet::query()->whereKey(...)->lockForUpdate()->firstOrFail()`)
 * are the responsibility of the writing service, inside its transaction,
 * following the pattern established by 2A's
 * `ShareHoldingService::persistEntry()`. A repository that locked rows
 * would couple the read surface to the write surface and make it harder
 * to reason about lock acquisition order — which Architecture §4.7
 * requires to be deterministic.
 *
 * =============================================================================
 * Tenant-scoping invariant (Step 7 v1.1.0 §C.6)
 * =============================================================================
 * The invariant, frozen at Step 7 v1.1.0 §C.6:
 *
 *     Every cross-entity reference in a 2B operation must be resolved
 *     through a tenant-scoped repository or service lookup. Existence-only
 *     checks are insufficient.
 *
 * For the wallet aggregate, the enforcement is:
 *
 *   - `findForTenant()` is the ONLY wallet lookup by id. It requires a
 *     tenant id and scopes the query by `tenant_id`. A cross-tenant id
 *     yields `null`.
 *
 *   - `findForShareholder()` requires a tenant id and scopes by
 *     `tenant_id`. It returns the wallet for the given (tenant,
 *     shareholder) pair, or `null` if the pair has no wallet.
 *
 *   - `listForTenant()` scopes by `tenant_id`. It never returns wallets
 *     belonging to other tenants, even when a filter is applied.
 *
 *   - `existsForShareholder()` scopes by `tenant_id`. It answers the
 *     question "does this tenant have a wallet for this shareholder?"
 *
 * No method in this repository accepts a wallet id without also requiring
 * a tenant id. There is no `find($id)` overload; a caller cannot
 * accidentally bypass tenant scoping.
 *
 * The controllers of the wallet domain (2B.26) resolve every route-bound
 * `{wallet}` parameter through `findForTenant()`. No controller calls
 * `Wallet::find()` or `Wallet::findOrFail()` directly — that would
 * reintroduce the existence-only anti-pattern the invariant forbids.
 *
 * =============================================================================
 * What this repository deliberately does NOT do
 * =============================================================================
 *   - It does not create, update, or delete. Writes belong to
 *     WalletService.
 *
 *   - It does not lock rows. Locking belongs to WalletService, inside its
 *     transaction, following the deterministic lock order of
 *     Architecture §4.7.
 *
 *   - It does not eager-load relations on its own initiative. Callers
 *     that need relations loaded issue `->load()` / `->loadMissing()` on
 *     the returned model or collection. The `search` filter's
 *     `whereHas('shareholder', ...)` subquery is a WHERE-clause existence
 *     check — it does not hydrate the relation.
 *
 *   - It does not throw. Absence is represented by `null` (for single-
 *     value lookups) or by an empty collection (for the list). Callers
 *     translate absence into the appropriate domain outcome.
 *
 *   - It does not paginate. The index endpoint returns the tenant's
 *     wallets as an unpaginated collection; a wallet count per tenant is
 *     bounded by the tenant's shareholder count, which is a small number
 *     in every deployment scenario Phase 2B anticipates. If a future
 *     tenant accumulates hundreds of wallets and pagination becomes
 *     necessary, a Change Request adds a `paginateForTenant()` method
 *     with an explicit page-size parameter.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Architecture v1.2.0 §2      — Bounded context and domain boundary
 *   Architecture v1.2.0 §3.2    — Wallet aggregate root
 *   Architecture v1.2.0 §4.7    — Deterministic lock acquisition order
 *   Architecture v1.2.0 §12     — Tenant isolation
 *   Architecture v1.2.0 §16.1   — Repositories (this class's role)
 *   Step 5 (manifest) 2B.11     — This file
 *   Step 7 v1.1.0 §C.6          — Cross-tenant child-validation invariant
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Repositories
 * @version 1.0.2
 */
final class WalletRepository
{
    // =========================================================================
    // Single-value lookups
    // =========================================================================

    /**
     * Find a wallet by its id, scoped to a tenant.
     *
     * Returns the wallet only if it belongs to the given tenant; returns
     * `null` otherwise. This is the primary tenant-scoped lookup for the
     * wallet aggregate and the sole entry point for resolving a route-bound
     * `{wallet}` parameter in the wallet domain's controllers.
     *
     * A caller that receives `null` must not distinguish between
     * "the id does not exist" and "the id belongs to another tenant" in
     * any response — both produce the same 404, so that the endpoint leaks
     * no information about cross-tenant existence.
     *
     * @param int $tenantId
     * @param int $walletId
     * @return Wallet|null
     */
    public function findForTenant(int $tenantId, int $walletId): ?Wallet
    {
        /** @var Wallet|null $wallet */
        $wallet = Wallet::query()
            ->forTenant($tenantId)
            ->whereKey($walletId)
            ->first();

        return $wallet;
    }

    /**
     * Find the wallet for a (tenant, shareholder) pair.
     *
     * A shareholder has at most one wallet per tenant — the composite
     * UNIQUE constraint on `(tenant_id, shareholder_id)` (2B.49) enforces
     * this. Therefore the method returns either the single matching wallet
     * or `null`.
     *
     * Used by WalletService::open() (2B.15) to check whether the target
     * shareholder already has a wallet before attempting to create one.
     *
     * @param int $tenantId
     * @param int $shareholderId
     * @return Wallet|null
     */
    public function findForShareholder(int $tenantId, int $shareholderId): ?Wallet
    {
        /** @var Wallet|null $wallet */
        $wallet = Wallet::query()
            ->forTenant($tenantId)
            ->where('shareholder_id', $shareholderId)
            ->first();

        return $wallet;
    }

    // =========================================================================
    // Collection lookups
    // =========================================================================

    /**
     * List wallets belonging to a tenant, optionally filtered.
     *
     * The result is not paginated and not eager-loaded. Callers that need
     * related data issue `->load()` on the collection or on individual
     * models as appropriate.
     *
     * Ordering is by `id` ascending — a stable, monotone key that reflects
     * creation order without depending on a mutable column. The choice is
     * deliberate: ordering by `opened_at` would produce identical results
     * for wallets opened in the same second and would require a
     * deterministic tiebreaker to remain stable; ordering by `id` avoids
     * that ambiguity entirely.
     *
     * -----------------------------------------------------------------------------
     * Supported filters
     * -----------------------------------------------------------------------------
     * The `$filters` array is expected to contain any subset of the
     * following keys. Unknown keys are ignored. An empty array (the
     * default) reproduces the v1.0.0 behaviour — every wallet in the
     * tenant.
     *
     *   · `is_active`      (bool)    strict equality against the wallet
     *                                 flag; `false` filters to inactive
     *                                 wallets, `true` to active.
     *
     *   · `shareholder_id` (int)     strict equality against the wallet's
     *                                 owner.
     *
     *   · `search`         (string)  a case-insensitive LIKE. The term is
     *                                 matched against:
     *                                   - the wallet id
     *                                   - the owner's shareholder id
     *                                   - the currency code (prefix)
     *                                   - the owner's name columns
     *                                     (individual: first/father/
     *                                     grandfather, AM and EN;
     *                                     organisation: entity name,
     *                                     AM and EN)
     *
     *                                 A leading `#` on the search term —
     *                                 or its URL-encoded equivalents
     *                                 `%23` and `%2523` — is stripped
     *                                 before the id match, so a user
     *                                 searching `#4` (or the URL forms
     *                                 `%234` / `%25234`) matches wallet
     *                                 4 and also shareholder 4's wallet.
     *                                 See the class revision history
     *                                 (1.0.2) for the normalisation.
     *
     * The tenant scope is applied BEFORE any filter, so no filter can
     * escape the tenant boundary. The `search` predicate's `whereHas`
     * subquery is likewise nested inside the tenant-scoped query, so a
     * search term that happens to match another tenant's shareholder
     * cannot surface a wallet from that tenant.
     *
     * @param int                       $tenantId
     * @param array<string, mixed>      $filters
     * @return Collection<int, Wallet>
     */
    public function listForTenant(int $tenantId, array $filters = []): Collection
    {
        $query = Wallet::query()->forTenant($tenantId);

        $this->applyFilters($query, $filters);

        $query->orderBy('id');

        /** @var Collection<int, Wallet> $wallets */
        $wallets = $query->get();

        return $wallets;
    }

    // =========================================================================
    // Existence checks
    // =========================================================================

    /**
     * Whether a (tenant, shareholder) pair has a wallet.
     *
     * An existence check, not a lookup: the method returns a boolean and
     * does not hydrate a Wallet model. Callers that need the wallet itself
     * use `findForShareholder()`.
     *
     * Used by WalletService::open() (2B.15) as a pre-flight check before
     * insertion. The check is not a substitute for the composite UNIQUE
     * constraint on the table; two concurrent `open()` calls for the same
     * (tenant, shareholder) pair can both pass this check and then one
     * will fail at the database constraint. The service handles that rare
     * case explicitly — see WalletService::open() for the exception
     * translation.
     *
     * @param int $tenantId
     * @param int $shareholderId
     * @return bool
     */
    public function existsForShareholder(int $tenantId, int $shareholderId): bool
    {
        return Wallet::query()
            ->forTenant($tenantId)
            ->where('shareholder_id', $shareholderId)
            ->exists();
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Apply the caller-supplied filters to the given query.
     *
     * Extracted from `listForTenant()` so the filter logic is one
     * cohesive unit and can be unit-tested in isolation if needed.
     *
     * The method does not mutate the query's tenant scope. Filters are
     * additive WHERE clauses; the scope established by `forTenant()` in
     * the caller remains in force for every branch.
     *
     * -----------------------------------------------------------------------------
     * Search-term normalisation (revision 1.0.2)
     * -----------------------------------------------------------------------------
     * The `search` filter's numeric-ID branch is reached only when the
     * trimmed, prefix-stripped input is all digits. The frontend sends
     * the term `#4` in the query string; the parser at the PHP boundary
     * may deliver it as `#4`, `%234`, or `%25234` depending on the
     * client's URL encoding. The normalisation is:
     *
     *   1. `rawurldecode()` — a no-op on already-decoded strings; a
     *      single pass is correct because the framework's parser has
     *      already decoded one level.
     *
     *   2. `preg_replace('/^\D+/u', '', …)` — strip any leading
     *      non-digit characters. This removes a leading `#`, whitespace,
     *      or an unrecognised prefix.
     *
     *   3. `trim()` — belt-and-braces; the regex has consumed leading
     *      whitespace already but trailing whitespace may remain.
     *
     * The name and currency LIKE branches below use the ORIGINAL
     * `$search` term, not the normalised `$idCandidate`, so that a
     * partial-name search such as `Akefa` is unaffected.
     *
     * @param Builder                   $query   The already tenant-scoped query.
     * @param array<string, mixed>      $filters
     * @return void
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        // ---------------------------------------------------------------------
        // is_active — strict equality.
        //
        // The check is `array_key_exists` rather than `isset` because the
        // frontend sends the literal string "false" for the inactive
        // filter; a strict `isset` would treat "false" correctly but a
        // future caller that passes the boolean `false` (rather than the
        // string) would slip past a loose `!empty` check. `array_key_exists`
        // is explicit: the caller either supplied the filter or did not.
        // ---------------------------------------------------------------------
        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        // ---------------------------------------------------------------------
        // shareholder_id — strict equality.
        //
        // A null shareholder_id is treated as "filter not supplied"; a
        // numeric zero is treated as a filter value (0 will match no rows,
        // which is the correct outcome for an impossible owner).
        // ---------------------------------------------------------------------
        if (isset($filters['shareholder_id']) && $filters['shareholder_id'] !== '') {
            $query->where('shareholder_id', (int) $filters['shareholder_id']);
        }

        // ---------------------------------------------------------------------
        // search — multi-column LIKE with ID-prefix normalisation.
        //
        // The predicate is grouped inside a single nested `where(function
        // ...)` so its ORs do not escape and combine with the tenant scope
        // or the other filters. Without the nesting, an OR here would turn
        // the outer query into `tenant = X AND is_active = Y OR <search>`,
        // which would match rows outside the tenant — a security defect,
        // not just a correctness one.
        // ---------------------------------------------------------------------
        if (isset($filters['search']) && $filters['search'] !== '') {
            $search = (string) $filters['search'];

            /*
             * Normalise the search term for the numeric-ID branch.
             *
             * `rawurldecode` converts `%234` → `#4` and leaves an
             * already-decoded `#4` unchanged. The `preg_replace` strips
             * any leading non-digit characters (a `#`, a stray space,
             * an unrecognised prefix), and `trim` cleans up trailing
             * whitespace. The result is used only to test whether the
             * caller supplied an ID-shaped value.
             *
             * The regex uses the Unicode modifier (`/u`) so a leading
             * multi-byte character is treated as one non-digit rather
             * than being split byte-by-byte. This does not change the
             * outcome for ASCII input, but keeps the behaviour
             * well-defined if a future caller passes an Amharic prefix.
             */
            $decoded     = rawurldecode($search);
            $idCandidate = trim(preg_replace('/^\D+/u', '', $decoded) ?? '');

            $query->where(function (Builder $q) use ($search, $idCandidate): void {
                // Numeric term: match wallet id or shareholder id.
                if ($idCandidate !== '' && ctype_digit($idCandidate)) {
                    $id = (int) $idCandidate;
                    $q->orWhere('wallets.id', $id)
                      ->orWhere('wallets.shareholder_id', $id);
                }

                // Currency — prefix match, uppercased so the LIKE's right
                // side is comparable against the stored codes (which are
                // guaranteed uppercase by the service's validation).
                $q->orWhere('wallets.currency', 'like', strtoupper($search) . '%');

                // Owner display names — individual and organisation,
                // bilingual.
                $q->orWhereHas('shareholder', function (Builder $sq) use ($search): void {
                    $sq->where('first_name_am', 'like', "%{$search}%")
                       ->orWhere('first_name_en', 'like', "%{$search}%")
                       ->orWhere('father_name_am', 'like', "%{$search}%")
                       ->orWhere('father_name_en', 'like', "%{$search}%")
                       ->orWhere('grandfather_name_am', 'like', "%{$search}%")
                       ->orWhere('grandfather_name_en', 'like', "%{$search}%")
                       ->orWhere('entity_name_am', 'like', "%{$search}%")
                       ->orWhere('entity_name_en', 'like', "%{$search}%");
                });
            });
        }
    }
}