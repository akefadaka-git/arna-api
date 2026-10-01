<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Repositories;

use Illuminate\Database\Eloquent\Collection;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Payment;

/**
 * PaymentRepository
 *
 * Read-only query surface for the `Payment` scoped business aggregate.
 *
 * =============================================================================
 * What this repository is
 * =============================================================================
 * A focused query helper for payment lookups. It enforces the
 * wallet-scoped lookup invariant that every caller of the wallet domain
 * must respect: a payment is addressable only through the wallet it
 * belongs to. A caller that supplies a wallet id from a different wallet
 * (or, transitively, a different tenant) receives `null`; the caller
 * translates `null` into a `ModelNotFoundException` → 404,
 * indistinguishable from a non-existent payment id.
 *
 * The repository performs NO writes. Payment creation is performed by
 * PaymentService (2B.17) via `Payment::create([...])` directly; the
 * repository does not wrap that operation. This mirrors the 2A pattern,
 * where `ShareholderService::register()` calls `Shareholder::create([...])`
 * directly and `ShareholderRepository` provides only read methods.
 *
 * The repository performs NO locking. Row-level locks for transactional
 * write paths are the responsibility of the writing services, inside
 * their transactions, following the deterministic lock order frozen in
 * Architecture §4.7 (`Wallet → Payment → Obligation → PaymentAllocation`).
 * A repository that locked rows would couple the read surface to the
 * write surface and make it harder to reason about lock acquisition
 * order.
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
 * A payment does not carry a `tenant_id` column directly. Its tenant scope
 * is derived transitively: `payment.wallet_id → wallet.tenant_id`. The
 * composition is deliberate — the wallet is the aggregate root that
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
 *   2. The caller then resolves the payment through
 *      `PaymentRepository::findForWallet($wallet->id, $paymentId)`. A
 *      cross-wallet payment id yields `null`, and the caller short-
 *      circuits with 404.
 *
 * Both lookups are required. A caller that skips the first lookup — that
 * resolves the payment directly by id without first establishing the
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
 *     PaymentService.
 *
 *   - It does not lock rows. Locking belongs to PaymentService and
 *     AllocationService, inside their transactions, following the
 *     deterministic lock order of Architecture §4.7.
 *
 *   - It does not accept a tenant id. The payment's tenant scope is
 *     established by the wallet lookup that precedes every call to this
 *     repository. Accepting a tenant id here would duplicate the wallet's
 *     tenant_id into the payment query and would either require a join or
 *     risk disagreement between the two scoping paths.
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
 *   - It does not paginate. A wallet's payment count is bounded by the
 *     number of receipt events the consuming domains record against it —
 *     a small number in every deployment scenario Phase 2B anticipates.
 *     If a future wallet accumulates enough payments to warrant
 *     pagination, a Change Request adds a `paginateForWallet()` method
 *     with an explicit page-size parameter.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Architecture v1.2.0 §2      — Bounded context and domain boundary
 *   Architecture v1.2.0 §3.4    — Payment entity
 *   Architecture v1.2.0 §4.7    — Deterministic lock acquisition order
 *   Architecture v1.2.0 §6.2    — Payment projection
 *   Architecture v1.2.0 §6.4    — Overpayment and unapplied amounts (D110)
 *   Architecture v1.2.0 §12     — Tenant isolation
 *   Architecture v1.2.0 §16.1   — Repositories (this class's role)
 *   Step 5 (manifest) 2B.13     — This file
 *   Step 7 v1.1.0 §C.6          — Cross-tenant child-validation invariant
 *   Step 7 v1.1.0 §C.1          — Nine-route API structure (payments are
 *                                 store-only; no direct show or index)
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Repositories
 * @version 1.0.0
 */
final class PaymentRepository
{
    // =========================================================================
    // Single-value lookups
    // =========================================================================

    /**
     * Find a payment by its id, scoped to a wallet.
     *
     * Returns the payment only if it belongs to the given wallet; returns
     * `null` otherwise. This is the primary wallet-scoped lookup for the
     * payment aggregate.
     *
     * The wallet id supplied to this method MUST have been resolved through
     * `WalletRepository::findForTenant()` first. The payment inherits the
     * tenant scope through its wallet; this method enforces only the
     * wallet boundary. See the class docblock §"Tenant-scoping
     * composition" for the full sequence.
     *
     * A caller that receives `null` must not distinguish between
     * "the id does not exist" and "the id belongs to another wallet" in
     * any response — both produce the same 404, so that the endpoint leaks
     * no information about cross-wallet or cross-tenant existence.
     *
     * Used by AllocationService::allocate() (2B.18) to resolve the source
     * payment when a caller supplies `payment_id` in the allocation
     * request body.
     *
     * @param int $walletId
     * @param int $paymentId
     * @return Payment|null
     */
    public function findForWallet(int $walletId, int $paymentId): ?Payment
    {
        /** @var Payment|null $payment */
        $payment = Payment::query()
            ->forWallet($walletId)
            ->whereKey($paymentId)
            ->first();

        return $payment;
    }

    // =========================================================================
    // Collection lookups
    // =========================================================================

    /**
     * List every payment belonging to a wallet.
     *
     * The result is not paginated and not eager-loaded. Callers that need
     * related data issue `->load()` on the collection or on individual
     * models as appropriate.
     *
     * Ordering is by `received_at` ascending, then `id` ascending as a
     * deterministic tiebreaker for payments sharing a business timestamp.
     * This mirrors the canonical ledger read order established by
     * `WalletEntry::scopeChronological()` (2B.10) and the sibling
     * `ObligationRepository::listForWallet()` (2B.12), giving consumers a
     * stable presentation order without requiring them to sort.
     *
     * Used by the wallet resource layer (2B.31) when it embeds payments
     * via `whenLoaded()`. The embedding pattern is documented in Step 7
     * v1.1.0 §A.4 (F-2B-LIST-1 resolution): obligations and payments are
     * not exposed as separate index endpoints; they appear under the
     * wallet.
     *
     * @param int $walletId
     * @return Collection<int, Payment>
     */
    public function listForWallet(int $walletId): Collection
    {
        /** @var Collection<int, Payment> $payments */
        $payments = Payment::query()
            ->forWallet($walletId)
            ->orderBy('received_at')
            ->orderBy('id')
            ->get();

        return $payments;
    }

    /**
     * List the payments of a wallet that still carry an unapplied amount.
     *
     * "Unapplied" is defined by the projection: `unapplied_amount > 0`
     * (Architecture §6.2). The filter is the primary selection criterion
     * for allocation UIs — a payment whose unapplied amount is zero
     * cannot receive a further allocation.
     *
     * Ordering matches `listForWallet()`: chronological by receipt, with
     * an `id` tiebreaker. A caller that needs a different order (for
     * example, largest unapplied amount first) can re-sort the returned
     * collection in memory or request a future repository method with an
     * explicit ordering parameter.
     *
     * @param int $walletId
     * @return Collection<int, Payment>
     */
    public function listUnappliedForWallet(int $walletId): Collection
    {
        /** @var Collection<int, Payment> $payments */
        $payments = Payment::query()
            ->forWallet($walletId)
            ->unapplied()
            ->orderBy('received_at')
            ->orderBy('id')
            ->get();

        return $payments;
    }

    // =========================================================================
    // Existence checks
    // =========================================================================

    /**
     * Whether a payment belongs to a wallet.
     *
     * An existence check, not a lookup: the method returns a boolean and
     * does not hydrate a Payment model. Callers that need the model itself
     * use `findForWallet()`.
     *
     * The check is scoped to the wallet; it does not need to be scoped to
     * the tenant because the wallet id supplied to this method is assumed
     * to have been resolved through `WalletRepository::findForTenant()`
     * first. See the class docblock §"Tenant-scoping composition".
     *
     * @param int $walletId
     * @param int $paymentId
     * @return bool
     */
    public function existsForWallet(int $walletId, int $paymentId): bool
    {
        return Payment::query()
            ->forWallet($walletId)
            ->whereKey($paymentId)
            ->exists();
    }
}