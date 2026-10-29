<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — P2.3 · File 6c of 7
 * =============================================================================
 *
 * File:            CertificateController.php
 * Unit:            P2.3 — dispatch to CertificatePolicy
 * Delivery type:   Amendment
 * Net lines:       3 dispatch changes; 1 dead-code removal; ~40 docblock
 *                  lines rewritten
 *
 * Before:
 *   index()  → Gate::authorize('view', $shareholder)
 *              dispatched to ShareholderPolicy::view.
 *   show()   → Gate::authorize('view', $shareholder)
 *              dispatched to ShareholderPolicy::view (guarded by a
 *              null-check on the certificate's shareholder).
 *   store()  → Gate::authorize('view', $shareholder)
 *              dispatched to ShareholderPolicy::view.
 *   cancel() → Gate::authorize('update', $shareholder)
 *              dispatched to ShareholderPolicy::update.
 *
 *   Net effect: the two certificate-domain slugs (`certificate.view`,
 *   `certificate.view_own`) were never consulted. The read authorization
 *   boundary was purely the shareholder policy, expressed in the
 *   shareholder vocabulary. The write authorization (cancel) was gated
 *   by the shareholder policy's `update` ability.
 *
 * After:
 *   index()  → Gate::authorize('viewForShareholder', [ShareCertificate::class, $shareholder])
 *              dispatches to CertificatePolicy::viewForShareholder.
 *   show()   → Gate::authorize('view', $certificate)
 *              dispatches to CertificatePolicy::view.
 *   store()  → Gate::authorize('viewForShareholder', [ShareCertificate::class, $shareholder])
 *              dispatches to CertificatePolicy::viewForShareholder.
 *   cancel() → Gate::authorize('update', $shareholder)
 *              UNCHANGED — still dispatches to ShareholderPolicy::update.
 *
 *   Net effect for reads: the certificate-domain slugs are now consulted
 *   through CertificatePolicy's three-path OR. A caller admitted through
 *   `certificate.view`, `certificate.view_own` + a direct link, or the
 *   delegated ShareholderPolicy::view (which transitively admits
 *   `shareholder.view`, `shareholder.view_own` + a direct link, and
 *   representatives) reaches the read endpoints.
 *
 * =============================================================================
 * Why cancel() is NOT switched
 * =============================================================================
 * cancel() is a WRITE action. Its per-object check has been
 * `Gate::authorize('update', $shareholder)` since 2A.24 — the caller
 * must hold `shareholder.update` (an administrative grant that the
 * ShareholderPolicy's `update` ability requires) and must be operating
 * on a shareholder in the resolved tenant. The route-level
 * `auth.permission:certificate.cancel` middleware enforces the action-
 * specific grant independently.
 *
 * Switching cancel() to a certificate-target ability such as
 * `Gate::authorize('view', $certificate)` — as flagged in the P2.3
 * preamble as a possible change — would WEAKEN the check. The `view`
 * ability grants through a three-path OR that includes self-service and
 * representative access; the `update` ability grants only to a caller
 * holding `shareholder.update`. A self-service shareholder should not
 * gain the ability to cancel their own certificates merely because they
 * can read them; cancellation is an administrative act.
 *
 * The P2.3 directive was to wire the READ slugs (`certificate.view`,
 * `certificate.view_own`). cancel() is not a read endpoint. It retains
 * its pre-P2.3 authorization unchanged.
 *
 * =============================================================================
 * What does NOT change
 * =============================================================================
 *   · Method signatures (index, show, store, cancel).
 *   · The tenant-crosscheck helper (assertBelongsToTenant).
 *   · The service delegation (`$this->certificateService->issue`,
 *     `::issueFor`, `::cancel`).
 *   · The response shapes and resource resolution.
 *   · The `Gate` import (already present).
 *   · The route-level middleware on store (`certificate.issue`) and
 *     cancel (`certificate.cancel`).
 *
 * =============================================================================
 * Docblock correction
 * =============================================================================
 * The class docblock's §"Authorization" section previously asserted:
 *
 *   "There is no `ShareCertificatePolicy` in the frozen manifest.
 *    Authorization for certificate operations is mediated through the
 *    Shareholder policy: if the caller cannot view the shareholder,
 *    they cannot view or modify that shareholder's certificates."
 *
 * That assertion is no longer true — File 2 of this sequence created
 * CertificatePolicy, File 4 registered it, and this file dispatches to
 * it. The section is rewritten to describe the current state: reads
 * delegate to CertificatePolicy; cancel retains the shareholder-update
 * check; both route-level middlewares remain in force.
 *
 * Files modified:            this file only
 * Files added:               none
 * Files deleted:             none
 * Route middleware touched:  none
 * Policy files touched:      none
 * =============================================================================
 */

namespace MaaleArna\MASES\Core\Domains\Shareholder\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use MaaleArna\MASES\Core\Audit\Services\AuditService;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Shareholder\Http\Requests\StoreCertificateRequest;
use MaaleArna\MASES\Core\Domains\Shareholder\Http\Resources\ShareCertificateResource;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareCertificate;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareClass;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareHolding;
use MaaleArna\MASES\Core\Domains\Shareholder\Repositories\ShareholderRepository;
use MaaleArna\MASES\Core\Domains\Shareholder\Services\CertificateService;

/**
 * CertificateController
 *
 * HTTP boundary for share certificates.
 *
 * =============================================================================
 * Scope
 * =============================================================================
 * Four operations, mapped by the routes file (2A.36):
 *
 *   GET    /api/share-certificates?shareholder_id=X     → index()   list
 *   GET    /api/share-certificates/{certificate}        → show()    single
 *   POST   /api/share-certificates                       → store()   issue
 *   POST   /api/share-certificates/{certificate}/cancel  → cancel()  cancel
 *
 * The paths are provisional; the final paths are declared by the routes
 * file. The method contracts are frozen here.
 *
 * =============================================================================
 * Why replace is NOT exposed
 * =============================================================================
 * The certificate lifecycle (2A.5) supports four states and three
 * transitions:
 *
 *   draft → issued | cancelled
 *   issued → cancelled | replaced
 *
 * CertificateService (2A.15) implements all three transitions:
 * `issue()`, `cancel()`, and `replace()`. This controller exposes
 * `issue` and `cancel` only. `replace()` is not in the frozen manifest's
 * scope for 2A.24, which lists "index / show / store / cancel".
 *
 * The consequence: replacing a certificate is reachable from Artisan
 * commands and tests but not from HTTP in Phase 2A. A follow-up Change
 * Request (CR-014 — Certificate replacement endpoint) can add it in a
 * future phase, at which point the same pattern used by `cancel()` below
 * will apply:
 *
 *   POST /api/share-certificates/{certificate}/replace
 *       → validates optional `certificate_number` and `notes`
 *       → delegates to CertificateService::replace()
 *       → returns the NEW certificate in the response
 *
 * Until then, replacement is not a Phase 2A operation. Recorded as
 * F-2A24-1.
 *
 * =============================================================================
 * What this controller does NOT do
 * =============================================================================
 *   - It does not render, sign, or deliver certificates. Those are
 *     presentation and infrastructure concerns for a future phase.
 *   - It does not accept `status` from the caller. The state of a
 *     certificate is set exclusively by the transitions that produce it:
 *     `issued` by store(), `cancelled` by cancel(), `replaced` by the
 *     future replacement endpoint.
 *   - It does not delete certificates. `cancelled` and `replaced` are
 *     terminal states; the row persists (SoftDeletes is provided by the
 *     model but not exercised through this controller).
 *   - It does not expose bulk operations.
 *
 * =============================================================================
 * Tenant scoping
 * =============================================================================
 * A certificate's tenant is determined by its shareholder's `tenant_id`.
 * The controller therefore performs the same tenant cross-check used by
 * the rest of the domain:
 *
 *   - `index()` requires `shareholder_id` and resolves it via
 *     ShareholderRepository::findForTenant() — cross-tenant id → 404.
 *
 *   - `show()` and `cancel()` load the route-bound certificate, load its
 *     shareholder, and verify `shareholder.tenant_id` matches the resolved
 *     tenant. Mismatch → 404.
 *
 *   - `store()` resolves the target shareholder via findForTenant(),
 *     then verifies the optional `share_holding_id` (if supplied) belongs
 *     to that same shareholder. The service (2A.15) also cross-checks the
 *     holding against the shareholder and class inside its transaction.
 *
 * The tenant itself is resolved via `mases_tenant_id()`, never from the
 * request.
 *
 * =============================================================================
 * Authorization (policy layer, as of P2.3)
 * =============================================================================
 * Per-object and per-collection authorization uses Laravel's Gate against
 * the model → policy mapping registered by ShareholderServiceProvider.
 * As of P2.3 (File 4 of this sequence), four mappings are in place:
 *
 *   Shareholder      → ShareholderPolicy
 *   ShareHolding     → ShareHoldingPolicy
 *   ShareCertificate → CertificatePolicy    (new in P2.3)
 *   ShareClass       → ShareClassPolicy     (new in P2.3)
 *
 * The four actions of this controller dispatch as follows:
 *
 *   index()  → Gate::authorize('viewForShareholder', [ShareCertificate::class, $shareholder])
 *              [CertificatePolicy::viewForShareholder]
 *
 *   show()   → Gate::authorize('view', $certificate)
 *              [CertificatePolicy::view]
 *
 *   store()  → Gate::authorize('viewForShareholder', [ShareCertificate::class, $shareholder])
 *              [CertificatePolicy::viewForShareholder]
 *
 *   cancel() → Gate::authorize('update', $shareholder)
 *              [ShareholderPolicy::update]  — UNCHANGED by P2.3
 *
 * The three read actions (`index`, `show`, `store`) delegate to
 * CertificatePolicy, whose two abilities grant through an OR of three
 * paths:
 *
 *   1. the caller holds `certificate.view`           (administrative);
 *   2. the caller holds `certificate.view_own` AND
 *      is the individual shareholder's direct user link (self-service);
 *   3. `ShareholderPolicy::view` returns true        (delegation — this
 *                                                     transitively admits
 *                                                     `shareholder.view`,
 *                                                     `shareholder.view_own`
 *                                                     plus a direct link,
 *                                                     and representatives).
 *
 * The read endpoints therefore admit any caller who can be admitted by
 * either the shareholder vocabulary (`shareholder.view`,
 * `shareholder.view_own` plus a direct link, or a representative
 * relationship) or the certificate vocabulary (`certificate.view`,
 * `certificate.view_own` plus a direct link). This is a strict superset
 * of the pre-P2.3 grant set, which was limited to the shareholder
 * vocabulary.
 *
 * The reason the read endpoints use the policy layer rather than route
 * middleware is that a single `auth.permission:*` middleware cannot
 * express OR-of-grants semantics. See the CertificatePolicy class
 * docblock for the full reasoning.
 *
 * `store()` and `cancel()` additionally require route-level permission
 * middleware, applied by the routes file (2A.36):
 *
 *   store()  → auth.permission:certificate.issue
 *   cancel() → auth.permission:certificate.cancel
 *
 * These action-specific grants are enforced before the controller runs.
 * They are independent of the policy check.
 *
 * ## Why cancel() retains the `update` check
 * ##
 * cancel() is a WRITE action. Its per-object check has been
 * `Gate::authorize('update', $shareholder)` since 2A.24 — the caller
 * must hold `shareholder.update` and must be operating on a shareholder
 * in the resolved tenant. Switching to a certificate-target `view`
 * ability would weaken the check: the `view` grant includes self-service
 * and representative paths that are not appropriate for an
 * administrative act such as cancellation.
 *
 * A shareholder may be able to read their own certificates without
 * being able to cancel them. The two grants are deliberately distinct.
 *
 * =============================================================================
 * Response envelope
 * =============================================================================
 * Success responses follow the canonical envelope (Master Prompt
 * Section 33). No `meta` block; the collection in `index()` is bounded by
 * the single-shareholder filter.
 *
 * Errors — validation failures, authorization denials, not-found
 * conditions, and illegal state transitions — are raised as exceptions
 * and rendered by the canonical error pipeline in bootstrap/app.php.
 *
 * @package MaaleArna\MASES\Core\Domains\Shareholder\Http\Controllers
 * @version 2.0.0
 */
final class CertificateController extends Controller
{
    /**
     * Constructor.
     *
     * @param CertificateService    $certificateService
     * @param ShareholderRepository $shareholderRepository
     */
    public function __construct(
        private readonly CertificateService $certificateService,
        private readonly ShareholderRepository $shareholderRepository,
    ) {
    }

    // =========================================================================
    // index — list certificates for a shareholder
    // =========================================================================

    /**
     * List certificates belonging to a single shareholder.
     *
     * The `shareholder_id` query parameter is required. It is resolved
     * against the tenant via ShareholderRepository, so a cross-tenant id
     * yields a 404.
     *
     * After resolution, the caller is authorized via
     * `Gate::authorize('viewForShareholder', [ShareCertificate::class, $shareholder])`,
     * which dispatches to `CertificatePolicy::viewForShareholder`. See the
     * class docblock §"Authorization" for the three paths the policy
     * grants through.
     *
     * Every certificate is returned, including cancelled and replaced
     * ones. A client that needs only active certificates filters on the
     * `status` field it receives in the resource payload. Filtering
     * server-side would add a query parameter without reducing the
     * response to a manageable size for a single shareholder.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId();

        $shareholderId = $request->query('shareholder_id');

        if (!is_numeric($shareholderId)) {
            throw ValidationException::withMessages([
                'shareholder_id' => [
                    'The shareholder_id query parameter is required and must be an integer.',
                ],
            ]);
        }

        $shareholder = $this->shareholderRepository->findForTenant(
            $tenantId,
            (int) $shareholderId,
        );

        if ($shareholder === null) {
            throw (new ModelNotFoundException())->setModel(
                Shareholder::class,
                [(int) $shareholderId],
            );
        }

        /*
         * Authorize the caller via CertificatePolicy::viewForShareholder.
         *
         * The dispatch form `[ShareCertificate::class, $shareholder]`
         * tells Laravel to resolve the CertificatePolicy (mapped to
         * ShareCertificate::class) and call its `viewForShareholder`
         * method with the user and the Shareholder instance. Without the
         * class-string leading argument, Laravel would resolve the policy
         * by the Shareholder model and call ShareholderPolicy::view —
         * the pre-P2.3 dispatch.
         */
        Gate::authorize('viewForShareholder', [ShareCertificate::class, $shareholder]);

        $certificates = ShareCertificate::query()
            ->where('shareholder_id', $shareholder->id)
            ->with(['shareholder', 'shareClass', 'shareHolding'])
            ->orderBy('issued_at', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        app(AuditService::class)->log(
            action: 'certificate.read.list',
            data: [
                'access_scope' => 'policy-authorized',
                'result_count' => $certificates->count(),
            ],
            user: $request->user(),
            tenantId: $tenantId,
            resourceType: Shareholder::class,
            resourceId: (string) $shareholder->getKey(),
        );

        return response()->json([
            'success' => true,
            'data'    => ShareCertificateResource::collection($certificates)->resolve(),
        ]);
    }

    // =========================================================================
    // show — single certificate
    // =========================================================================

    /**
     * Show a single certificate.
     *
     * The route-bound certificate is cross-checked against the resolved
     * tenant via its shareholder. A cross-tenant id is reported as not
     * found (404) — the same response a non-existent id would produce.
     *
     * Authorization dispatches to `CertificatePolicy::view` (the model-
     * instance form of the ability), which loads the certificate's
     * shareholder and applies the three-path OR. The policy fails closed
     * on a null shareholder (an orphaned certificate is a referential-
     * integrity incident, not a case to admit).
     *
     * @param Request         $request
     * @param ShareCertificate $certificate  Bound by implicit route model binding.
     * @return JsonResponse
     *
     * @throws ModelNotFoundException
     */
    public function show(Request $request, ShareCertificate $certificate): JsonResponse
    {
        $tenantId = $this->resolveTenantId();

        $this->assertBelongsToTenant($certificate, $tenantId);

        Gate::authorize('view', $certificate);

        $certificate->loadMissing(['shareholder', 'shareClass', 'shareHolding', 'replacedBy']);

        app(AuditService::class)->log(
            action: 'certificate.read.view',
            data: ['access_scope' => 'policy-authorized'],
            user: $request->user(),
            tenantId: $tenantId,
            resourceType: ShareCertificate::class,
            resourceId: (string) $certificate->getKey(),
        );

        return response()->json([
            'success' => true,
            'data'    => (new ShareCertificateResource($certificate))->resolve(),
        ]);
    }

    // =========================================================================
    // store — issue
    // =========================================================================

    /**
     * Issue a new certificate.
     *
     * The StoreCertificateRequest (2A.20) has already validated the input
     * shape: `shareholder_id` and `share_class_id` required, optional
     * `share_holding_id`, optional `certificate_number`, optional `notes`.
     *
     * The controller resolves the target models scoped to the tenant,
     * authorizes the caller via
     * `Gate::authorize('viewForShareholder', [ShareCertificate::class, $shareholder])`,
     * then calls either `issue()` (when a `share_holding_id` was supplied)
     * or `issueFor()` (when the holding should be resolved or created).
     * The `certificate.issue` route middleware enforces the action-specific
     * grant independently.
     *
     * The response carries the newly-created certificate with status
     * `issued`. The certificate is not editable in Phase 2A; subsequent
     * lifecycle actions go through `cancel()` (or, in a future phase,
     * `replace()`).
     *
     * @param StoreCertificateRequest $request
     * @return JsonResponse  201 Created
     *
     * @throws ModelNotFoundException
     * @throws ValidationException
     */
    public function store(StoreCertificateRequest $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId();

        // ---------------------------------------------------------------------
        // 1. Resolve the shareholder (scoped to tenant).
        // ---------------------------------------------------------------------
        $shareholder = $this->shareholderRepository->findForTenant(
            $tenantId,
            (int) $request->input('shareholder_id'),
        );

        if ($shareholder === null) {
            throw (new ModelNotFoundException())->setModel(
                Shareholder::class,
                [(int) $request->input('shareholder_id')],
            );
        }

        Gate::authorize('viewForShareholder', [ShareCertificate::class, $shareholder]);

        // ---------------------------------------------------------------------
        // 2. Resolve the share class (not tenant-scoped — reference data).
        // ---------------------------------------------------------------------
        /** @var ShareClass $shareClass */
        $shareClass = ShareClass::query()->findOrFail(
            (int) $request->input('share_class_id')
        );

        $certificateNumber = $request->input('certificate_number');
        $notes             = $request->input('notes');

        // ---------------------------------------------------------------------
        // 3. Branch on whether the caller supplied an explicit holding.
        // ---------------------------------------------------------------------
        if ($request->filled('share_holding_id')) {
            /** @var ShareHolding $holding */
            $holding = ShareHolding::query()->findOrFail(
                (int) $request->input('share_holding_id')
            );

            $certificate = $this->certificateService->issue(
                shareholder: $shareholder,
                shareClass: $shareClass,
                holding: $holding,
                certificateNumber: $certificateNumber,
                notes: $notes,
            );
        } else {
            $certificate = $this->certificateService->issueFor(
                shareholder: $shareholder,
                shareClass: $shareClass,
                certificateNumber: $certificateNumber,
                notes: $notes,
            );
        }

        $certificate->loadMissing(['shareholder', 'shareClass', 'shareHolding']);

        return response()->json([
            'success' => true,
            'data'    => (new ShareCertificateResource($certificate))->resolve(),
        ], 201);
    }

    // =========================================================================
    // cancel — cancel a certificate
    // =========================================================================

    /**
     * Cancel a certificate.
     *
     * The certificate is loaded via route model binding. Its state is
     * validated by CertificateService::cancel() (2A.15), which calls
     * CertificateStatus::canTransitionTo(Cancelled). Legal transitions:
     *
     *   Draft  → Cancelled
     *   Issued → Cancelled
     *
     * A certificate already in `cancelled` or `replaced` state cannot be
     * cancelled again; the service throws ValidationException → 422.
     *
     * Authorization is UNCHANGED from 2A.24:
     *
     *   Gate::authorize('update', $shareholder)
     *
     * The caller must hold `shareholder.update` in the resolved tenant.
     * The route-level `auth.permission:certificate.cancel` middleware
     * enforces the action-specific grant independently. The certificate-
     * domain `view` ability is NOT consulted here — cancellation is an
     * administrative act and requires the stronger grant. See the class
     * docblock §"Why cancel() retains the `update` check".
     *
     * The optional `reason` string is appended to the certificate's notes
     * by the service, preserving any prior notes.
     *
     * @param Request         $request
     * @param ShareCertificate $certificate  Bound by implicit route model binding.
     * @return JsonResponse
     *
     * @throws ModelNotFoundException
     * @throws ValidationException
     */
    public function cancel(Request $request, ShareCertificate $certificate): JsonResponse
    {
        $tenantId = $this->resolveTenantId();

        $this->assertBelongsToTenant($certificate, $tenantId);

        $shareholder = $certificate->shareholder;

        if ($shareholder instanceof Shareholder) {
            Gate::authorize('update', $shareholder);
        }

        // Inline validation for the optional reason. A dedicated
        // CancelCertificateRequest is not part of the frozen manifest; if
        // the payload grows (e.g. an attachment, a structured reason
        // code), a follow-up CR can promote this to a FormRequest.
        $validated = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $cancelled = $this->certificateService->cancel(
            certificate: $certificate,
            reason: $validated['reason'] ?? null,
        );

        $cancelled->loadMissing(['shareholder', 'shareClass', 'shareHolding']);

        return response()->json([
            'success' => true,
            'data'    => (new ShareCertificateResource($cancelled))->resolve(),
        ]);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Resolve the tenant id from the server-side request context.
     *
     * @return int
     *
     * @throws ValidationException
     */
    private function resolveTenantId(): int
    {
        $tenantId = function_exists('mases_tenant_id') ? mases_tenant_id() : null;

        if ($tenantId === null) {
            throw ValidationException::withMessages([
                'tenant' => [
                    'No tenant context is available for this request. '
                    . 'This route must be protected by the tenant resolution middleware.',
                ],
            ]);
        }

        return (int) $tenantId;
    }

    /**
     * Assert that a certificate belongs to a shareholder in the given tenant.
     *
     * The certificate's tenant is determined by its shareholder's
     * `tenant_id`. A certificate whose shareholder is null (broken
     * referential integrity) or whose shareholder belongs to a different
     * tenant is treated as not found — the same 404 a non-existent id
     * would produce.
     *
     * @param ShareCertificate $certificate
     * @param int              $tenantId
     * @return void
     *
     * @throws ModelNotFoundException
     */
    private function assertBelongsToTenant(ShareCertificate $certificate, int $tenantId): void
    {
        $certificate->loadMissing('shareholder');

        $shareholder = $certificate->shareholder;

        if ($shareholder === null || (int) $shareholder->tenant_id !== $tenantId) {
            throw (new ModelNotFoundException())->setModel(
                ShareCertificate::class,
                [$certificate->getKey()],
            );
        }
    }
}