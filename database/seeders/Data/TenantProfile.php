<?php

declare(strict_types=1);

namespace Database\Seeders\Data;

/**
 * TenantProfile
 *
 * The default MASES tenant — the single row that MasesCoreSeeder
 * seeds before any other reference data. Every user, role,
 * application, and shareholder the seeder creates is scoped to this
 * tenant, so this is the first record the seeder writes.
 *
 * =============================================================================
 * What this class is — and is not
 * =============================================================================
 * This is a DATA class. It contains no logic, no queries, and no side
 * effects. It exists so that the tenant's identity — its slug, its
 * bilingual names, its domain, and its settings — lives in a file
 * whose contents can be reviewed and corrected independently of the
 * seeding algorithm.
 *
 * The class was extracted from MasesCoreSeeder during the seeder
 * refactoring (Unit S-2). Its contents are byte-for-byte identical
 * to the TENANT constant it replaces; no value was altered.
 *
 * =============================================================================
 * The row shape
 * =============================================================================
 *   slug       — the tenant's canonical identifier, used in
 *                database relations, route resolution, and the
 *                permission system. Immutable in practice;
 *                renaming a tenant slug is a coordinated CR.
 *
 *   name_am    — the tenant's display name in Amharic. Primary,
 *                consistent with the platform's Amharic-first
 *                policy.
 *
 *   name_en    — the tenant's display name in Latin script. Fallback
 *                for the English locale, and the fallback for a
 *                missing Amharic name.
 *
 *   domain     — the tenant's canonical domain. This is a hostname
 *                without a scheme (e.g. 'main.maalearna.com.et'),
 *                matching the convention used by the
 *                platform_applications table's domain column.
 *
 *   settings   — a JSON-serialisable map of tenant-scoped
 *                configuration. The two keys below are the ones the
 *                platform currently reads:
 *                  timezone — the tenant's display and business-logic
 *                             timezone. Set to Africa/Addis_Ababa so
 *                             that Ethiopian calendar and time
 *                             presentation line up with the
 *                             platform's Amharic-first policy.
 *                  locale   — the tenant's default locale. Set to
 *                             'am', matching the platform-wide
 *                             default and the tenant's Amharic-first
 *                             intent.
 *
 *   is_active  — whether the tenant is enabled. Inactive tenants are
 *                excluded from tenant switching, and their members
 *                cannot authenticate.
 *
 * =============================================================================
 * Why there is only one tenant
 * =============================================================================
 * MASES is architecturally multi-tenant: the Tenant model, the
 * tenant.membership middleware, and the tenant-scoped route group
 * all support more than one tenant. The current deployment seeds
 * only 'main' — the primary Maale Arna tenant. Additional tenants
 * are a deployment concern, not a seeder concern; this file will be
 * extended at that time.
 *
 * =============================================================================
 * Why the settings are inline, not in the main config
 * =============================================================================
 * These settings are TENANT-specific. The platform's cross-tenant
 * defaults (Amharic-first locale, Africa/Addis_Ababa timezone, etc.)
 * live in config/mases.php. The settings below are the tenant's own
 * values, which may diverge from the platform default for a future
 * tenant. Keeping them here makes that divergence explicit at the
 * point of seeding rather than implicit through inheritance.
 *
 * =============================================================================
 * What this class deliberately does NOT do
 * =============================================================================
 *   - It does NOT validate. A malformed row (missing 'slug', an
 *     unparseable 'settings' map) produces whatever the seeder does
 *     with it; the profile is not defended against its own editor.
 *
 *   - It does NOT expose the settings as typed constants. The
 *     'settings' array is opaque to this class — its keys are read
 *     by the seeder's seedTenant() method and by the Tenant model,
 *     not here.
 *
 *   - It does NOT carry an ID. The row is upserted by slug, so the
 *     numeric ID is assigned by the database on first insert and
 *     preserved on subsequent runs.
 *
 * @package Database\Seeders\Data
 */
final class TenantProfile
{
    /**
     * The default tenant, keyed by the column names the Tenant model
     * persists. The seeder passes this array to Tenant::updateOrCreate()
     * with the 'slug' as the lookup key.
     *
     * @var array<string, mixed>
     */
    public const ROW = [
        'slug'      => 'main',
        'name_am'   => 'ማሌ ዓርና ዋና',
        'name_en'   => 'Maale Arna Main',
        'domain'    => 'main.maalearna.com.et',
        'settings'  => ['timezone' => 'Africa/Addis_Ababa', 'locale' => 'am'],
        'is_active' => true,
    ];
}