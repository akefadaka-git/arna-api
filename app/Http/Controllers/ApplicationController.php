<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MaaleArna\MASES\Core\Authorization\Models\PlatformApplication;

/**
 * ApplicationController
 *
 * Serves the launcher surface's application catalog to authenticated
 * users. One endpoint, one method.
 *
 * =============================================================================
 * Revision history
 * =============================================================================
 * 1.0.0  — initial delivery.
 *
 * 1.0.1  — permission-source fix: read role-derived grants too.
 *
 *          Symptom: every user saw an empty launcher — the Amharic
 *          empty state ("ምንም ተደራሽ አፕሊኬሽኖች የሉም") — regardless of
 *          their roles or application permissions.
 *
 *          Cause: the permission filter called
 *          `$request->user()->permissions()`, which is the User
 *          model's DIRECT-grant relation (`user_has_permissions`
 *          pivot). In the MASES deployment that pivot is deliberately
 *          empty; every permission is granted via roles
 *          (`user_has_roles` → `role_has_permissions`). The filter
 *          therefore built an empty set, every application's
 *          `app.{slug}.access` check missed, and the response was
 *          `"data": []`.
 *
 *          Fix: eager-load both `permissions` and `roles.permissions`,
 *          merge the two slug lists, deduplicate, and build the
 *          lookup set from the union. This is the same pattern the
 *          /api/user endpoint uses (see routes/api.php revision
 *          1.3.1) — the two surfaces must stay consistent, because
 *          the frontend's PermissionGate reads `/api/user` and this
 *          controller independently re-derives the same set from the
 *          database.
 *
 *          The controller's external contract is unchanged: same
 *          route, same response shape, same filters (is_active,
 *          is_frontend_ready, permission), same ordering.
 *
 * =============================================================================
 * What this controller is — and is not
 * =============================================================================
 * It is the read side of the platform_applications registry, scoped
 * to the caller's authorization context. It returns the applications
 * that are:
 *
 *   1. Registered and active                (is_active = true)
 *   2. Deployed with a frontend             (is_frontend_ready = true)
 *   3. Reachable by the authenticated user  (the caller holds the
 *                                            matching app.{slug}.access
 *                                            permission, granted
 *                                            directly or via a role)
 *
 * The three filters are applied in that order. The result is the
 * caller's launchable catalog — a set that may be empty for a user
 * with no application access, and that is the correct empty state,
 * not an error.
 *
 * It is not an administrative CRUD controller. It does not create,
 * update, or delete applications. Those operations do not exist yet;
 * when they do, they belong in a separate controller under a
 * distinct route prefix and a distinct authorization gate.
 *
 * It is not the registry itself. The registry lives in the database
 * (`platform_applications`) and is seeded from
 * Database\Seeders\Data\ApplicationRegistry. This controller reads
 * the registry; it does not define it.
 *
 * =============================================================================
 * The endpoint
 * =============================================================================
 *   GET /api/launcher
 *
 * Registered in routes/api.php inside the tenant-scoped group (the
 * group that applies `auth:sanctum` and `tenant.membership`). The
 * route name is `api.launcher`.
 *
 * Because the route is in the tenant-scoped group, this controller
 * assumes:
 *
 *   · An authenticated user is available via $request->user().
 *   · A tenant has been resolved and the user is a member of it.
 *
 * Neither assumption is defended against by this controller. The
 * middleware is the enforcement point; a request that reaches this
 * method has already passed both checks. A request that has not
 * would be rejected before the method ran.
 *
 * =============================================================================
 * The three filters
 * =============================================================================
 * 1. is_active = true
 *      The application is registered as part of the ecosystem. An
 *      inactive application is not shown to any user, regardless of
 *      role or permission. This is the platform's "disable" switch.
 *
 * 2. is_frontend_ready = true
 *      A build exists at the application's domain. An application
 *      whose frontend is not yet deployed is registered but not
 *      launchable; returning it would produce a tile that links to
 *      a subdomain with no deployed service.
 *
 * 3. app.{slug}.access — the caller's permission
 *      The user holds the permission that corresponds to the
 *      application. Every registered application is guarded by an
 *      `app.{slug}.access` permission (see Data\PermissionCatalogue).
 *      The check is applied in PHP after the query, against the
 *      user's effective permissions — the union of direct grants and
 *      role-derived grants. See §"Why the permission filter runs in
 *      PHP, not in SQL" and the 1.0.1 revision entry.
 *
 * =============================================================================
 * Why the permission filter runs in PHP, not in SQL
 * =============================================================================
 * The permission's slug is derived from the application's slug:
 * `'app.' . $application->slug . '.access'`. A pure SQL filter would
 * need a join between `platform_applications` and `permissions`
 * and `role_has_permissions` and `user_has_roles` and
 * `user_has_permissions` — five tables, with a synthetic key
 * constructed in the join predicate. That is possible but hard to
 * read, hard to cache, and easy to get subtly wrong.
 *
 * The alternative — load the seven applications, then filter in
 * PHP against the user's already-loaded permission slugs — has
 * three advantages:
 *
 *   · The permission set is loaded once per request. The User
 *     model's relations are eager-loadable and cacheable; the SQL
 *     join form would re-query it as a sub-select on every call.
 *
 *   · The filter's intent is visible in the code: "keep the
 *     applications whose matching permission the user holds." A
 *     reader sees the rule without tracing a five-table join.
 *
 *   · The seven-row result set is trivial to filter in PHP. The
 *     register has seven rows today; even at a hundred rows the
 *     cost is negligible.
 *
 * When the register grows to a size where the PHP filter becomes
 * measurably expensive — several hundred rows, or a hot path
 * queried many times per second — a future CR can push the filter
 * into SQL. Until then, the PHP form is the correct trade.
 *
 * =============================================================================
 * The response shape
 * =============================================================================
 * The response is the canonical MASES envelope:
 *
 *     {
 *       "success": true,
 *       "data": [
 *         {
 *           "slug":             "portal",
 *           "name_am":          "የባለአክሲዮኖች ፖርታል",
 *           "name_en":          "Shareholder Portal",
 *           "description_am":   "የባለአክሲዮኖች መረጃና ተሳትፎ",
 *           "description_en":   "Shareholder information and engagement.",
 *           "domain":           "portal.maalearna.com.et",
 *           "is_active":        true,
 *           "is_frontend_ready": true,
 *           "icon_key":         "users",
 *           "category":         "shareholder",
 *           "display_order":    10
 *         },
 *         …
 *       ]
 *     }
 *
 * WHY THE RESPONSE IS SHAPED IN PHP, NOT RETURNED AS MODELS:
 * The Eloquent model has `display_name` and `display_description`
 * accessors that resolve a single locale-aware string. The frontend
 * does not want a single string — its LauncherTile component
 * resolves the locale itself, at render time, from the two language
 * fields it receives. The frontend therefore wants the flat
 * `name_am` / `name_en` pair, not the accessor output.
 *
 * The response also omits the model's `id`, `created_at`,
 * `updated_at`, and `deleted_at` columns. They are not part of the
 * frontend's contract; returning them would leak the register's
 * internal bookkeeping without a consumer.
 *
 * WHY THE FOUR EXTENDED FIELDS ARE INCLUDED:
 * The frontend's current LauncherApplication interface declares
 * seven fields (slug, names, descriptions, domain, is_active). The
 * response carries eleven — those seven plus is_frontend_ready,
 * icon_key, category, and display_order.
 *
 * The four extras are what a future frontend revision (Unit L-12)
 * will consume. Returning them now means the future revision is a
 * pure frontend change; it needs no backend coordination. The
 * current frontend ignores unknown fields — its LauncherTile reads
 * only the seven it declares.
 *
 * WHY THE RESPONSE IS A PLAIN array, NOT A RESOURCE CLASS:
 * The platform's controllers in App\Http\Controllers (AuthController,
 * FileController) return response()->json([...]) with the envelope
 * built inline. The domain controllers (Shareholder, Loan, Wallet)
 * use Resource classes. The two conventions coexist; this controller
 * follows the App\Http\Controllers one, matching its siblings in the
 * same namespace.
 *
 * The response is small and its shape is stable. A Resource class
 * would add a layer without adding clarity.
 *
 * =============================================================================
 * The ordering
 * =============================================================================
 * The applications are ordered by `display_order` ascending. The
 * column was added by the L-9 migration; the seeder (L-10b) writes
 * values 10 through 70 in steps of 10, matching the register's
 * current declaration order.
 *
 * The ordering is intentionally not by `id`. Sorting by `id` would
 * couple the visible order to the database's insertion history; a
 * future CR that reorders the tiles is then a data change (a
 * `display_order` edit) rather than an `id`-renumbering operation.
 * The current values render the same order as `id` does today.
 *
 * =============================================================================
 * The empty result
 * =============================================================================
 * A user with no `app.{slug}.access` permission receives:
 *
 *     { "success": true, "data": [] }
 *
 * This is not an error. It is the platform's honest answer to "what
 * can this user launch?" when the answer is "nothing." The frontend
 * renders its Amharic empty state.
 *
 * The response does NOT distinguish "no applications are ready" from
 * "you have no access to the ready applications." Both cases return
 * an empty array. The distinction is not actionable by the caller —
 * the empty state's message is the same either way — and adding a
 * discriminator would leak the register's total count to users who
 * are not authorized to see it.
 *
 * =============================================================================
 * What this controller deliberately does NOT do
 * =============================================================================
 *   - It does NOT paginate. The register has seven rows; the caller
 *     receives all of them or none. When the register grows to a
 *     size that warrants pagination, a future CR adds it — with a
 *     corresponding change to the frontend's consumption.
 *
 *   - It does NOT expose `display_name` or `display_description`.
 *     See §"The response shape". The frontend resolves locale
 *     itself.
 *
 *   - It does NOT cache. The query is cheap and the response is
 *     user-scoped — a shared cache would need per-user
 *     invalidation that costs more than the query saves. When
 *     profiling shows the query matters, a future CR adds a
 *     user-keyed short-TTL cache.
 *
 *   - It does NOT audit. Reading the launcher's catalog is not a
 *     security-relevant action — the user is viewing the
 *     applications they already have access to. If a future CR
 *     wants to log launcher access, it adds the audit event at the
 *     route's middleware layer, not in this controller.
 *
 *   - It does NOT mutate. There is no POST, PUT, PATCH, or DELETE
 *     method. Every application mutation is a future CR with its
 *     own controller and its own authorization gate.
 *
 * @package App\Http\Controllers
 */
final class ApplicationController extends Controller
{
    /**
     * Return the authenticated user's launchable applications.
     *
     * Filters: is_active, is_frontend_ready, and the caller's
     * matching app.{slug}.access permission. See the class docblock
     * for the semantics of each filter and the reasoning behind the
     * PHP-side permission check.
     *
     * @param Request $request the current HTTP request. The user is
     *                         read from $request->user(); the tenant
     *                         context is enforced by middleware and
     *                         not re-checked here.
     *
     * @return JsonResponse the canonical MASES success envelope
     *                      carrying the launchable applications
     */
    public function index(Request $request): JsonResponse
    {
        /*
         * Resolve the caller's effective permission slugs.
         *
         * The set is the union of:
         *
         *   1. Permissions granted DIRECTLY to the user
         *      (`user_has_permissions`, exposed via `$user->permissions`).
         *
         *   2. Permissions granted to ANY ROLE the user holds
         *      (`user_has_roles` → `role_has_permissions`, exposed
         *      via `$user->roles[*]->permissions`).
         *
         * Both paths are required. In the current MASES deployment,
         * path (1) is always empty — the platform grants permissions
         * exclusively through roles — but path (1) remains in the
         * merge so a future direct grant (an executive override, an
         * emergency elevation) is reflected without a code change.
         *
         * `loadMissing` eager-loads `permissions` and
         * `roles.permissions` in one call. It is a no-op for
         * relations already loaded by a route middleware; otherwise
         * it adds a single query per relation and one for the nested
         * `roles.permissions`, which is what avoids the N+1.
         *
         * The `array_flip(...)` idiom converts the merged slug list
         * into an associative array whose KEYS are the slugs — making
         * the subsequent `isset(...)` lookup O(1) per application
         * instead of O(n).
         *
         * See the class docblock revision history (1.0.1) and the
         * /api/user endpoint in routes/api.php (revision 1.3.1) for
         * the full diagnosis and the parallel fix on the sibling
         * endpoint. The two must stay consistent.
         */
        $user = $request->user();
        $user->loadMissing(['permissions', 'roles.permissions']);

        $permissionSlugs = array_flip(
            $user->permissions
                ->pluck('slug')
                ->merge($user->roles->flatMap->permissions->pluck('slug'))
                ->unique()
                ->values()
                ->all()
        );

        /*
         * Load the frontend-ready applications in display order. The
         * two SQL filters are the two that are properties of the
         * application row itself; the third filter (permission) runs
         * in PHP below.
         */
        $applications = PlatformApplication::query()
            ->where('is_active', true)
            ->where('is_frontend_ready', true)
            ->orderBy('display_order')
            ->get();

        /*
         * Apply the permission filter in PHP. For each application,
         * the corresponding permission slug is constructed from the
         * application's slug: `'app.' . $slug . '.access'`. The
         * application is kept iff the user holds that permission.
         *
         * The `->values()` call re-indexes the collection after
         * `filter`, so the JSON response's array is a plain list
         * rather than a map with sparse integer keys.
         *
         * See the class docblock §"Why the permission filter runs in
         * PHP, not in SQL" for the reasoning.
         */
        $launchable = $applications
            ->filter(function (PlatformApplication $application) use ($permissionSlugs): bool {
                return isset($permissionSlugs['app.' . $application->slug . '.access']);
            })
            ->values();

        /*
         * Shape the response. Each application becomes an eleven-key
         * array. The Eloquent model's accessors (display_name,
         * display_description) are intentionally not called — the
         * frontend resolves locale itself. See the class docblock
         * §"The response shape".
         *
         * The mapping is a closure over the model; each field is
         * read directly. The model's casts — is_active: bool,
         * is_frontend_ready: bool — ensure the JSON types are
         * correct without a manual cast here.
         */
        return response()->json([
            'success' => true,
            'data'    => $launchable->map(static fn (PlatformApplication $application): array => [
                'slug'              => $application->slug,
                'name_am'           => $application->name_am,
                'name_en'           => $application->name_en,
                'description_am'    => $application->description_am,
                'description_en'    => $application->description_en,
                'domain'            => $application->domain,
                'is_active'         => $application->is_active,
                'is_frontend_ready' => $application->is_frontend_ready,
                'icon_key'          => $application->icon_key,
                'category'          => $application->category,
                'display_order'     => $application->display_order,
            ])->all(),
        ]);
    }
}