<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The explicit name of the composite index on
     * (is_active, is_frontend_ready).
     *
     * Declared as a class constant so that up() and down() — and any
     * future reader — reference the same string. Laravel's
     * auto-generated name for this index would be
     * `platform_applications_is_active_is_frontend_ready_index`,
     * which is longer and coupled to the current column names. The
     * name below is shorter and greppable.
     */
    private const ACTIVE_FRONTEND_READY_INDEX = 'platform_applications_active_frontend_ready_index';

    /**
     * Run the migrations.
     *
     * Creates the `platform_applications` table — the registry of every
     * user-facing MASES application (Shareholder Portal, Finance, HR,
     * Administration, ARNA, the workspace, and the launcher itself).
     *
     * ይህ ማይግሬሽን የMASES ተጠቃሚ ፊት ለፊት የሚታዩትን መተግበሪያዎች ዝርዝር
     * (`platform_applications`) ይፈጥራል። ስርዓቱ አማርኛ‑ቅድሚያ ስለሚሰጥ፣ የማሳያ
     * ስሞች እና መግለጫዎች በሁለቱም በአማርኛ እና በእንግሊዝኛ ተቀምጠዋል።
     *
     * -----------------------------------------------------------------------------
     * Why this is a full create, not an alter
     * -----------------------------------------------------------------------------
     * This migration replaces the original
     * `create_platform_applications_table` migration in full. It is not
     * an incremental alter. The table's final shape — including the
     * presentation columns (icon_key, category, display_order) and the
     * launcher-correctness column (is_frontend_ready) — is declared in
     * a single create call, so that a fresh migrate produces the
     * complete schema in one step rather than in a chain of alters.
     *
     * The original migration file at this path must be overwritten by
     * this content, not supplemented by a second migration for the
     * same table. Two create calls against the same table name would
     * fail on the second call during a fresh migrate.
     *
     * -----------------------------------------------------------------------------
     * The state model
     * -----------------------------------------------------------------------------
     * Two orthogonal flags describe an application's state:
     *
     *   is_active            — the application is enabled on the
     *                          platform at all. An inactive
     *                          application is not shown to any user,
     *                          regardless of role or permission.
     *
     *   is_frontend_ready    — a deployable frontend exists at the
     *                          application's domain. A registered
     *                          application whose frontend is not yet
     *                          built (or not yet deployed) has
     *                          is_active = true and
     *                          is_frontend_ready = false.
     *
     * The launcher renders a tile as a clickable link only when BOTH
     * flags are true. Every other combination renders an honest
     * non-link state — a badge in the tile grid, a message in the
     * detail view, or the grid's Amharic empty state when no
     * applications qualify.
     *
     * Before is_frontend_ready existed, the only state signal was
     * is_active, and all seven seeded applications carried
     * is_active = true. The launcher would have rendered seven
     * clickable tiles, five of which pointed at domains with no
     * built frontend in the repository. The composite index below
     * supports the endpoint's filter — WHERE is_active = 1 AND
     * is_frontend_ready = 1 — directly.
     *
     * -----------------------------------------------------------------------------
     * The presentation columns
     * -----------------------------------------------------------------------------
     * Three columns prepare the launcher grid for features that the
     * frontend already anticipates but that the current endpoint and
     * tile do not yet consume:
     *
     *   icon_key       — the Lucide icon name for the tile's glyph.
     *                    NULL means the tile renders its default
     *                    ('layout-grid'). The frontend's BaseIcon
     *                    already validates the name against a frozen
     *                    56-icon registry.
     *
     *   category       — an optional grouping key (e.g. 'financial',
     *                    'governance'). NULL means ungrouped. The grid
     *                    renders flat today; a future revision adds
     *                    category sections without a schema change.
     *
     *   display_order  — a sort weight for tiles. Lower values appear
     *                    first. Today the grid renders in id order.
     *                    This column lets an administrator reorder
     *                    tiles without changing their ids.
     *
     * -----------------------------------------------------------------------------
     * The bilinguality of display strings
     * -----------------------------------------------------------------------------
     * MASES is Amharic-first. name_am and description_am are the
     * primary display strings; name_en and description_en are the
     * fallback. The Eloquent model's display_name and
     * display_description accessors implement the resolution rule —
     * read the Amharic value when the current locale is 'am' and the
     * value is present; otherwise fall back to the English value; if
     * both are absent, fall back to the slug.
     *
     * Both language columns are nullable because the register may
     * legitimately carry only one language for a given application.
     * The resolution rule guarantees a non-empty string is returned
     * for every persisted row.
     *
     * -----------------------------------------------------------------------------
     * Indexes
     * -----------------------------------------------------------------------------
     * The table carries seven indexes after this migration:
     *
     *   PRIMARY (id)                              — Laravel default
     *   UNIQUE  (slug)                            — identity
     *   UNIQUE  (domain)                          — routing
     *   INDEX   (is_active)                       — broad state filter
     *   INDEX   (deleted_at)                      — soft-delete scope
     *   INDEX   (category)                        — future grouping
     *   INDEX   (display_order)                   — ordered fetches
     *   COMPOSITE (is_active, is_frontend_ready)  — launcher endpoint filter
     *
     * The composite index is declared explicitly (named) rather than
     * via an array passed to `->index([...])`, so that a future rename
     * of either column does not silently invalidate the index name in
     * the down() method. The name is `platform_applications_active_frontend_ready_index`.
     *
     * The column order in the composite index is `is_active` first,
     * `is_frontend_ready` second. This matters for leftmost-prefix
     * use: a query filtering on is_active alone still benefits from
     * the index, while a query on is_frontend_ready alone does not —
     * which is the correct trade-off, since is_active is the broader
     * filter and appears first in the endpoint's WHERE clause.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('platform_applications', function (Blueprint $table): void {
            // -----------------------------------------------------------------
            // Primary key
            // -----------------------------------------------------------------
            $table->id();

            // -----------------------------------------------------------------
            // Identity
            // -----------------------------------------------------------------
            // The slug is the canonical identifier used in database
            // relations and permission checks (see the app_access
            // permission family in the seeder). It is immutable in
            // practice; renaming a slug is a breaking change that
            // requires a coordinated CR.
            $table->string('slug', 100)
                  ->unique()
                  ->comment('Application slug (e.g., finance, hr)');

            // -----------------------------------------------------------------
            // Bilingual display strings
            // -----------------------------------------------------------------
            // Amharic is primary, English is fallback. Both columns are
            // nullable; the model's accessors guarantee a non-empty
            // display string for every persisted row.
            $table->string('name_am', 150)
                  ->nullable()
                  ->comment('Application name in Amharic');
            $table->string('name_en', 150)
                  ->nullable()
                  ->comment('Application name in English');
            $table->string('description_am', 255)
                  ->nullable()
                  ->comment('Application description in Amharic');
            $table->string('description_en', 255)
                  ->nullable()
                  ->comment('Application description in English');

            // -----------------------------------------------------------------
            // Presentation
            // -----------------------------------------------------------------
            // The three columns below prepare the launcher grid for
            // features the frontend anticipates. None is consumed by
            // the current endpoint or tile; each is nullable or carries
            // a documented default so that existing rows and future
            // inserts are unaffected.
            $table->string('icon_key', 100)
                  ->nullable()
                  ->comment('Lucide icon name for the launcher tile (NULL = tile default)');

            $table->string('category', 50)
                  ->nullable()
                  ->comment('Optional grouping key for the launcher grid (NULL = ungrouped)');

            $table->unsignedInteger('display_order')
                  ->default(0)
                  ->comment('Sort weight within a category; lower values appear first');

            // -----------------------------------------------------------------
            // Routing
            // -----------------------------------------------------------------
            // The domain is a bare hostname without a scheme. The
            // frontend prepends https:// when building the tile's
            // href. This matches the original schema and the frontend's
            // LauncherTile implementation.
            $table->string('domain', 255)
                  ->unique()
                  ->comment('Primary domain (e.g., finance.example.com)');

            // -----------------------------------------------------------------
            // State
            // -----------------------------------------------------------------
            $table->boolean('is_active')
                  ->default(true)
                  ->comment('Whether the application is active');

            // is_frontend_ready is the launcher-correctness flag.
            // Default false: every row begins unready. The seeder
            // promotes the rows whose frontends are actually deployed.
            // A database that has been migrated but not seeded renders
            // the launcher's empty state — an honest "no applications
            // are ready" — rather than a set of broken links.
            $table->boolean('is_frontend_ready')
                  ->default(false)
                  ->comment('Whether a deployable frontend exists at the domain');

            // -----------------------------------------------------------------
            // Soft delete and timestamps
            // -----------------------------------------------------------------
            $table->softDeletes()
                  ->comment('Soft delete timestamp');

            $table->timestamps();

            // -----------------------------------------------------------------
            // Indexes
            // -----------------------------------------------------------------
            // slug and domain are indexed by their unique constraints
            // above. The remaining indexes are declared here.
            //
            // is_active and deleted_at were indexed by the original
            // migration; they are preserved here unchanged.
            $table->index('is_active');
            $table->index('deleted_at');

            // category and display_order are new; they support the
            // presentation features described above without a future
            // alter.
            $table->index('category');
            $table->index('display_order');

            // The composite index supports the launcher endpoint's
            // filter. See the class docblock §"Indexes" for the column
            // order rationale.
            $table->index(
                ['is_active', 'is_frontend_ready'],
                self::ACTIVE_FRONTEND_READY_INDEX,
            );
        });
    }

    /**
     * Reverse the migrations.
     *
     * Drops the entire table. This matches the original migration's
     * behavior — a full create is reversed by a full drop. No data is
     * preserved, and no other table is affected.
     *
     * ይህ ዘዴ ሙሉውን ሰንጠረዥ ይሰርዛል። ይህ የመጀመሪያው ማይግሬሽን ባህሪ ጋር ይመሳሰላል —
     * ሙሉ የመፍጠር ማይግሬሽን በሙሉ የመሰረዝ ተግባር ይመለሳል።
     *
     * The down() method does NOT drop the composite index first. On
     * MySQL, dropping a table implicitly drops every index it carries.
     * An explicit dropIndex() call before dropIfExists() would be
     * redundant and, on some MySQL versions, would fail because the
     * index's parent table is about to be dropped in the same
     * statement. The single dropIfExists call is the correct and
     * deterministic reversal.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_applications');
    }
};