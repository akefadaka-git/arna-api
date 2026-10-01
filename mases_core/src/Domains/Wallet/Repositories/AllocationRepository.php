<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Repositories;

use Illuminate\Database\Eloquent\Collection;
use MaaleArna\MASES\Core\Domains\Wallet\Models\PaymentAllocation;
use MaaleArna\MASES\Core\Domains\Wallet\Models\PaymentAllocationReversal;

/**
 * AllocationRepository
 *
 * Read-only query surface for the `PaymentAllocation` dependent attribution
 * entity and its `PaymentAllocationReversal` child entity.
 *
 * =============================================================================
 * What this repository is
 * =============================================================================
 * A focused query helper for allocation and allocation-reversal lookups.
 * It enforces the wallet-scoped lookup invariant that every caller of the
 * wallet domain must respect: an allocation is addressable only through
 * the wallet it belongs to. A caller that supplies a wallet id from a
 * different wallet (or, transitively, a different tenant) receives `null`;
 * the caller translates `null` into a `ModelNotFoundException` → 404,
 * indistinguishable from a non-existent allocation id.
 *
 * The repository performs NO writes. Allocation creation is performed by
 * AllocationService (2B.18) via `PaymentAllocation::create([...])`
 * directly; the repository does not wrap that operation. This mirrors the
 * 2A pattern, where the writing services call `Model::create([...])`
 * directly and the corresponding repository provides only read methods.
 *
 * The repository performs NO locking. Row-level locks for transactional
 * write paths are the responsibility of the writing services, inside their
 * transactions, following the deterministic lock order frozen in
 * Architecture §4.7 (`Wallet → Payment → Obligation → PaymentAllocation`).
 * A repository that locked rows would couple the read surface to the write
 * surface and make it harder to reason about lock acquisition order.
 *
 * =============================================================================
 * Two entities, one repository
 * =============================================================================
 * This repository covers BOTH `PaymentAllocation` and
 * `PaymentAllocationReversal`. The two entities form a single attribution
 * chain: an allocation records the original attribution; a reversal records
 * a correction to it; the effective attribution of the parent allocation is
 * the difference of the two. Splitting them into two repositories would
 * separate two halves of the same read model and force every consumer to
 * inject two dependencies to answer a single conceptual question
 * ("what is the attribution history of this wallet?").
 *
 * The corresponding Policy decision (Architecture §13.2) is that neither
 * entity has its own policy: both are addressed through `WalletPolicy`. The
 * repository mirrors that posture — a single query surface under one
 * wallet-scoped gate.
 *
 * The single `PaymentAllocation` lookup on this repository
 * (`findForWallet`) is the tenant-isolation entry point that Step 7
 * v1.1.0 §C.6 freezes; a separate lookup for a `PaymentAllocationReversal`
 * by id is deliberately not exposed, because no 2B operation addresses a
 * reversal by id. Reversals are always reached through their parent
 * allocation (see `listReversalsForAllocation`).
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
 * A `PaymentAllocation` does not carry a `tenant_id` column directly. Its
 * tenant scope is derived transitively:
 *
 *     allocation.wallet_id → wallet.tenant_id
 *
 * The wallet id column on the allocation is denormalized (2B.8 §"Relations")
 * from the parent payment/obligation for exactly this purpose: it enables
 * direct wallet-scoped queries without traversing either parent, and it
 * gives the repository a single, unambiguous scoping column.
 *
 * The invariant is enforced by the CALLER'S SEQUENCE, not by any single
 * method in this repository:
 *
 *   1. The caller resolves the wallet through
 *      `WalletRepository::findForTenant($tenantId, $walletId)`. A
 *      cross-tenant wallet id yields `null`, and the caller short-circuits
 *      with 404.
 *
 *   2. The caller then resolves the allocation through
 *      `AllocationRepository::findForWallet($wallet->id, $allocationId)`.
 *      A cross-wallet allocation id yields `null`, and the caller
 *      short-circuits with 404.
 *
 * Both lookups are required. The allocation request body (`payment_id`,
 * `obligation_id`) is resolved through the sibling repositories'
 * `findForWallet()` methods (2B.12, 2B.13), which enforce the same
 * wallet-scoping composition. A cross-wallet `payment_id` or
 * `obligation_id` yields `null`, and the caller short-circuits with 404
 * — this is the "cross-tenant indirect-reference protection" that Step 7
 * v1.1.0 §C.3 test methods T8 and T9 exercise.
 *
 * =============================================================================
 * What this repository deliberately does NOT do
 * =============================================================================
 *   - It does not create, update, or delete. Writes belong to
 *     AllocationService.
 *
 *   - It does not lock rows. Locking belongs to AllocationService, inside
 *     its transaction, following the deterministic lock order of
 *     Architecture §4.7.
 *
 *   - It does not accept a tenant id. The allocation's tenant scope is
 *     established by the wallet lookup that precedes every call to this
 *     repository.
 *
 *   - It does not expose a `findReversalForWallet()` method. No 2B
 *     operation addresses a reversal by id; reversals are reached through
 *     their parent allocation (`listReversalsForAllocation`). Adding the
 *     method would advertise a capability no code path uses, and would
 *     invite callers to bypass the reversal-through-parent access pattern.
 *
 *   - It does not eagerly aggregate reversal sums. The
 *     `effective_allocated()` derivation (Architecture §3.6) requires
 *     summing `reversed_amount` across each allocation's reversals. The
 *     projection-cached values on the parent payment
 *     (`unapplied_amount`) and obligation (`outstanding_amount`) already
 *     reflect the effective state; a repository method that recomputed the
 *     derivation would duplicate that projection work and produce
 *     potentially-divergent results. Callers that need the effective
 *     amount of a specific allocation read the parent projections or query
 *     the reversals collection explicitly.
 *
 *   - It does not throw. Absence is represented by `null` (for single-
 *     value lookups) or by an empty collection (for the lists). Callers
 *     translate absence into the appropriate domain outcome.
 *
 *   - It does not paginate. A wallet's allocation count is bounded by the
 *     number of (payment, obligation) pairs recorded against it — a small
 *     number in every deployment scenario Phase 2B anticipates. If a
 *     future wallet accumulates enough allocations to warrant pagination,
 *     a Change Request adds a `paginateForWallet()` method with an
 *     explicit page-size parameter.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Architecture v1.2.0 §2      — Bounded context and domain boundary
 *   Architecture v1.2.0 §3.5    — PaymentAllocation entity
 *   Architecture v1.2.0 §3.6    — PaymentAllocationReversal entity (A1)
 *   Architecture v1.2.0 §4.7    — Deterministic lock acquisition order
 *   Architecture v1.2.0 §6.1    — Obligation projection
 *   Architecture v1.2.0 §6.2    — Payment projection
 *   Architecture v1.2.0 §6.3    — Allocation invariants
 *   Architecture v1.2.0 §6.5    — Allocation-reversal invariants
 *   Architecture v1.2.0 §12     — Tenant isolation
 *   Architecture v1.2.0 §13.2   — Policies (allocation addressed through
 *                                 WalletPolicy; no dedicated policy)
 *   Architecture v1.2.0 §16.1   — Repositories (this class's role)
 *   Step 5 (manifest) 2B.14     — This file
 *   Step 7 v1.1.0 §C.1          — Nine-route API structure (route #8 uses
 *                                 `{allocation}` as the reversal target)
 *   Step 7 v1.1.0 §C.3          — Cross-tenant test matrix (T8, T9)
 *   Step 7 v1.1.0 §C.6          — Cross-tenant child-validation invariant
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Repositories
 * @version 1.0.0
 */
final class AllocationRepository
{
    // =========================================================================
    // Single-value lookups
    // =========================================================================

    /**
     * Find a payment allocation by its id, scoped to a wallet.
     *
     * Returns the allocation only if it belongs to the given wallet;
     * returns `null` otherwise. This is the primary wallet-scoped lookup
     * for the allocation entity, and the sole entry point for resolving a
     * route-bound `{allocation}` parameter in the reverse endpoint
     * (`POST /wallets/{wallet}/allocations/{allocation}/reverse`).
     *
     * The wallet id supplied to this method MUST have been resolved through
     * `WalletRepository::findForTenant()` first. The allocation inherits
     * the tenant scope through its wallet; this method enforces only the
     * wallet boundary. See the class docblock §"Tenant-scoping
     * composition" for the full sequence.
     *
     * A caller that receives `null` must not distinguish between
     * "the id does not exist" and "the id belongs to another wallet" in
     * any response — both produce the same 404, so that the endpoint leaks
     * no information about cross-wallet or cross-tenant existence.
     *
     * Used by AllocationService::reverse() (2B.18) to resolve the target
     * allocation when a caller triggers the reverse endpoint.
     *
     * @param int $walletId
     * @param int $allocationId
     * @return PaymentAllocation|null
     */
    public function findForWallet(int $walletId, int $allocationId): ?PaymentAllocation
    {
        /** @var PaymentAllocation|null $allocation */
        $allocation = PaymentAllocation::query()
            ->forWallet($walletId)
            ->whereKey($allocationId)
            ->first();

        return $allocation;
    }

    // =========================================================================
    // Collection lookups
    // =========================================================================

    /**
     * List every allocation belonging to a wallet.
     *
     * The result is not paginated and not eager-loaded. Callers that need
     * related data issue `->load()` on the collection or on individual
     * models as appropriate.
     *
     * Ordering is by `id` ascending — a stable, monotone key that reflects
     * insertion order. Allocation rows do not carry a `reversed_at`-style
     * business timestamp that would justify chronological ordering the way
     * obligations and payments do; `allocated_at` exists but its ordering
     * is equivalent to `id` for a single wallet in practice. Using `id`
     * avoids the tiebreaker ambiguity that a same-second business
     * timestamp would introduce.
     *
     * @param int $walletId
     * @return Collection<int, PaymentAllocation>
     */
    public function listForWallet(int $walletId): Collection
    {
        /** @var Collection<int, PaymentAllocation> $allocations */
        $allocations = PaymentAllocation::query()
            ->forWallet($walletId)
            ->orderBy('id')
            ->get();

        return $allocations;
    }

    /**
     * List the allocations recorded against a specific payment.
     *
     * Scoped to the wallet AND the payment, so that the caller cannot
     * accidentally obtain allocations from a different payment in the same
     * wallet. The payment id supplied to this method SHOULD have been
     * resolved through `PaymentRepository::findForWallet()` first, when the
     * caller is validating the payment reference in an HTTP request body;
     * the wallet scope on the query provides a second layer of defence.
     *
     * Used by the PaymentResource (2B.33) when it embeds the payment's
     * allocation history, and by any reporting surface that needs the
     * attribution detail of a single payment.
     *
     * Ordering is by `id` ascending, matching `listForWallet()`.
     *
     * @param int $walletId
     * @param int $paymentId
     * @return Collection<int, PaymentAllocation>
     */
    public function listForPayment(int $walletId, int $paymentId): Collection
    {
        /** @var Collection<int, PaymentAllocation> $allocations */
        $allocations = PaymentAllocation::query()
            ->forWallet($walletId)
            ->forPayment($paymentId)
            ->orderBy('id')
            ->get();

        return $allocations;
    }

    /**
     * List the allocations recorded against a specific obligation.
     *
     * Scoped to the wallet AND the obligation, so that the caller cannot
     * accidentally obtain allocations to a different obligation in the
     * same wallet. The obligation id supplied to this method SHOULD have
     * been resolved through `ObligationRepository::findForWallet()` first,
     * when the caller is validating the obligation reference in an HTTP
     * request body; the wallet scope on the query provides a second layer
     * of defence.
     *
     * Used by the ObligationResource (2B.32) when it embeds the
     * obligation's allocation history, and by any reporting surface that
     * needs the settlement detail of a single obligation.
     *
     * Ordering is by `id` ascending, matching `listForWallet()`.
     *
     * @param int $walletId
     * @param int $obligationId
     * @return Collection<int, PaymentAllocation>
     */
    public function listForObligation(int $walletId, int $obligationId): Collection
    {
        /** @var Collection<int, PaymentAllocation> $allocations */
        $allocations = PaymentAllocation::query()
            ->forWallet($walletId)
            ->forObligation($obligationId)
            ->orderBy('id')
            ->get();

        return $allocations;
    }

    /**
     * List the reversals recorded against a specific allocation.
     *
     * Scoped to the wallet AND the parent allocation. The parent allocation
     * must already have been resolved through `findForWallet()` — this
     * method does NOT resolve it, and does not accept an allocation id
     * without an accompanying wallet id. That mirrors the composition
     * pattern used throughout the wallet domain: the wallet scope is
     * established first, then the entity scope.
     *
     * Used by the PaymentAllocationResource (2B.34) when it embeds the
     * allocation's reversal history, and by any reporting surface that
     * needs the correction detail of a single allocation.
     *
     * Ordering is by `id` ascending. A `reversed_at` ordering would be
     * equivalent in practice (reversals are always appended after their
     * parent allocation), but `id` is monotone and requires no tiebreaker.
     *
     * The sum of `reversed_amount` across the returned collection, when
     * subtracted from the parent allocation's `amount`, yields the
     * allocation's effective allocated amount (Architecture §3.6).
     *
     * @param int $walletId
     * @param int $allocationId
     * @return Collection<int, PaymentAllocationReversal>
     */
    public function listReversalsForAllocation(int $walletId, int $allocationId): Collection
    {
        /** @var Collection<int, PaymentAllocationReversal> $reversals */
        $reversals = PaymentAllocationReversal::query()
            ->forWallet($walletId)
            ->forAllocation($allocationId)
            ->orderBy('id')
            ->get();

        return $reversals;
    }

    // =========================================================================
    // Existence checks
    // =========================================================================

    /**
     * Whether a payment allocation belongs to a wallet.
     *
     * An existence check, not a lookup: the method returns a boolean and
     * does not hydrate a PaymentAllocation model. Callers that need the
     * model itself use `findForWallet()`.
     *
     * The check is scoped to the wallet; it does not need to be scoped to
     * the tenant because the wallet id supplied to this method is assumed
     * to have been resolved through `WalletRepository::findForTenant()`
     * first. See the class docblock §"Tenant-scoping composition".
     *
     * @param int $walletId
     * @param int $allocationId
     * @return bool
     */
    public function existsForWallet(int $walletId, int $allocationId): bool
    {
        return PaymentAllocation::query()
            ->forWallet($walletId)
            ->whereKey($allocationId)
            ->exists();
    }
}