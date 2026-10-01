<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Repositories;

use Illuminate\Database\Eloquent\Collection;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Obligation;

/**
 * ObligationRepository
 *
 * Read-only query surface for the `Obligation` scoped business aggregate.
 *
 * =============================================================================
 * What this repository is
 * =============================================================================
 * A focused query helper for obligation lookups. It enforces the
 * wallet-scoped lookup invariant that every caller of the wallet domain
 * must respect: an obligation is addressable only through the wallet it
 * belongs to. A caller that supplies a wallet id from a different wallet
 * (or, transitively, a different tenant) receives `null`; the caller
 * translates `null` into a `ModelNotFoundException` → 404,
 * indistinguishable from a non-existent obligation id.
 *
 * The repository performs NO writes. Obligation creation is performed by
 * ObligationService (2B.16) via `Obligation::create([...])` directly; the
 * repository does not wrap that operation. This mirrors the 2A pattern,
 * where `ShareholderService::register()` calls `Shareholder::create([...])`
 * directly and `ShareholderRepository` provides only read methods.
 *
 * The repository performs NO locking. Row-level locks for transactional
 * write paths are the responsibility of the writing services, inside their
 * transactions, following the deterministic lock order frozen in
 * Architecture §4.7 (`Wallet → Payment → Obligation → PaymentAllocation`).
 * A repository that locked rows would couple the read surface to the write
 * surface and make it harder to reason about lock acquisition order.
 *
 * =============================================================================
 * Tenant-scoping composition (Step 7 v1.1.0 §C.6)
 * =============================================================================
 * The invariant, frozen at Step 7 v1.1.0 §C.6:
 *
 *     Every cross-entity reference in a 2B operation must be resolved
 *     through a tenant-scoped repository or service lookup. Existence-only
 *     checks are insufficient.
 *
 * An obligation does not carry a `tenant_id` column directly. Its tenant
 * scope is derived transitively: `obligation.wallet_id → wallet.tenant_id`.
 * The composition is deliberate — the wallet is the aggregate root that
 * carries the tenant scope, and its children resolve scope through it.
 *
 * The invariant is enforced by the CALLER'S SEQUENCE, not by any single
 * method in this repository:
 *
 *   1. The caller resolves the wallet through
 *      `WalletRepository::findForTenant($tenantId, $walletId)`. A
 *      cross-tenant wallet id yields `null`, and the caller short-circuits
 *      with 404.
 *
 *   2. The caller then resolves the obligation through
 *      `ObligationRepository::findForWallet($wallet->id, $obligationId)`.
 *      A cross-wallet obligation id yields `null`, and the caller
 *      short-circuits with 404.
 *
 * Both lookups are required. A caller that skips the first lookup — that
 * resolves the obligation directly by id without first establishing the
 * wallet's tenant scope — reintroduces the existence-only anti-pattern
 * the invariant forbids. This is documented explicitly so that no future
 * contributor mistakes `findForWallet()` for a tenant-scoping primitive.
 *
 * `findForWallet()` is intentionally named for the scope it enforces. A
 * method named `find()` or `findOrFail()` would invite the mistaken
 * caller to skip the tenant-scoping composition.
 *
 * =============================================================================
 * What this repository deliberately does NOT do
 * =============================================================================
 *   - It does not create, update, or delete. Writes belong to
 *     ObligationService.
 *
 *   - It does not lock rows. Locking belongs to ObligationService and
 *     AllocationService, inside their transactions.
 *
 *   - It does not accept a tenant id. The obligation's tenant scope is
 *     established by the wallet lookup that precedes every call to this
 *     repository. Accepting a tenant id here would duplicate the wallet's
 *     tenant_id column into the obligation query and would either require
 *     a join or risk disagreement between the two scoping paths.
 *
 *   - It does not eager-load relations. Callers that need the wallet or
 *     the allocations loaded issue `->load()` / `->loadMissing()` on the
 *     returned model. No repeated eager-load pattern exists in Phase 2B;
 *     a future Change Request adds a dedicated method if one emerges.
 *
 *   - It does not throw. Absence is represented by `null` (for single-
 *     value lookups) or by an empty collection (for the lists). Callers
 *     translate absence into the appropriate domain outcome.
 *
 *   - It does not paginate. A wallet's obligation count is bounded by the
 *     number of distinct components the consuming domains record against
 *     it — a small number in every deployment scenario Phase 2B
 *     anticipates. If a future wallet accumulates enough obligations to
 *     warrant pagination, a Change Request adds a
 *     `paginateForWallet()` method with an explicit page-size parameter.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Architecture v1.2.0 §2      — Bounded context and domain boundary
 *   Architecture v1.2.0 §3.3    — Obligation entity
 *   Architecture v1.2.0 §4.7    — Deterministic lock acquisition order
 *   Architecture v1.2.0 §6.1    — Obligation projection
 *   Architecture v1.2.0 §12     — Tenant isolation
 *   Architecture v1.2.0 §16.1   — Repositories (this class's role)
 *   Step 5 (manifest) 2B.12     — This file
 *   Step 7 v1.1.0 §C.6          — Cross-tenant child-validation invariant
 *   Step 7 v1.1.0 §C.1          — Nine-route API structure (obligations
 *                                 are store-only; no direct show or index)
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Repositories
 * @version 1.0.0
 */
final class ObligationRepository
{
    // =========================================================================
    // Single-value lookups
    // =========================================================================

    /**
     * Find an obligation by its id, scoped to a wallet.
     *
     * Returns the obligation only if it belongs to the given wallet;
     * returns `null` otherwise. This is the primary wallet-scoped lookup
     * for the obligation aggregate.
     *
     * The wallet id supplied to this method MUST have been resolved through
     * `WalletRepository::findForTenant()` first. The obligation inherits
     * the tenant scope through its wallet; this method enforces only the
     * wallet boundary. See the class docblock §"Tenant-scoping composition"
     * for the full sequence.
     *
     * A caller that receives `null` must not distinguish between
     * "the id does not exist" and "the id belongs to another wallet" in
     * any response — both produce the same 404, so that the endpoint leaks
     * no information about cross-wallet or cross-tenant existence.
     *
     * Used by AllocationService::allocate() (2B.18) to resolve the target
     * obligation when a caller supplies `obligation_id` in the allocation
     * request body.
     *
     * @param int $walletId
     * @param int $obligationId
     * @return Obligation|null
     */
    public function findForWallet(int $walletId, int $obligationId): ?Obligation
    {
        /** @var Obligation|null $obligation */
        $obligation = Obligation::query()
            ->forWallet($walletId)
            ->whereKey($obligationId)
            ->first();

        return $obligation;
    }

    // =========================================================================
    // Collection lookups
    // =========================================================================

    /**
     * List every obligation belonging to a wallet.
     *
     * The result is not paginated and not eager-loaded. Callers that need
     * related data issue `->load()` on the collection or on individual
     * models as appropriate.
     *
     * Ordering is by `occurred_at` ascending, then `id` ascending as a
     * deterministic tiebreaker for obligations sharing a business
     * timestamp. This mirrors the canonical ledger read order established
     * by `WalletEntry::scopeChronological()` (2B.10) and gives consumers a
     * stable presentation order without requiring them to sort.
     *
     * Used by the wallet resource layer (2B.31) when it embeds obligations
     * via `whenLoaded()`. The embedding pattern is documented in Step 7
     * v1.1.0 §A.4 (F-2B-LIST-1 resolution): obligations and payments are
     * not exposed as separate index endpoints; they appear under the
     * wallet.
     *
     * @param int $walletId
     * @return Collection<int, Obligation>
     */
    public function listForWallet(int $walletId): Collection
    {
        /** @var Collection<int, Obligation> $obligations */
        $obligations = Obligation::query()
            ->forWallet($walletId)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        return $obligations;
    }

    /**
     * List the outstanding obligations of a wallet.
     *
     * "Outstanding" is defined by the projection: `outstanding_amount > 0`
     * (Architecture §6.1). The filter is the primary selection criterion
     * for allocation UIs — an obligation with zero outstanding amount
     * cannot receive an allocation.
     *
     * Ordering matches `listForWallet()`: chronological by occurrence,
     * with an `id` tiebreaker. A caller that needs a different order (for
     * example, due-date ascending) can re-sort the returned collection in
     * memory or request a future repository method with an explicit
     * ordering parameter.
     *
     * @param int $walletId
     * @return Collection<int, Obligation>
     */
    public function listOutstandingForWallet(int $walletId): Collection
    {
        /** @var Collection<int, Obligation> $obligations */
        $obligations = Obligation::query()
            ->forWallet($walletId)
            ->outstanding()
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        return $obligations;
    }

    // =========================================================================
    // Existence checks
    // =========================================================================

    /**
     * Whether an obligation belongs to a wallet.
     *
     * An existence check, not a lookup: the method returns a boolean and
     * does not hydrate an Obligation model. Callers that need the model
     * itself use `findForWallet()`.
     *
     * The check is scoped to the wallet; it does not need to be scoped to
     * the tenant because the wallet id supplied to this method is assumed
     * to have been resolved through `WalletRepository::findForTenant()`
     * first. See the class docblock §"Tenant-scoping composition".
     *
     * @param int $walletId
     * @param int $obligationId
     * @return bool
     */
    public function existsForWallet(int $walletId, int $obligationId): bool
    {
        return Obligation::query()
            ->forWallet($walletId)
            ->whereKey($obligationId)
            ->exists();
    }
}