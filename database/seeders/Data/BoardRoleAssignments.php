<?php

declare(strict_types=1);

namespace Database\Seeders\Data;

/**
 * BoardRoleAssignments
 *
 * The board-member role assignments: which of the register's
 * shareholders receive which roles beyond the default 'shareholder'
 * role. Eight entries, keyed by the register's own SH-ID identifier.
 *
 * =============================================================================
 * What this class is — and is not
 * =============================================================================
 * This is a DATA class. It contains no logic, no queries, and no side
 * effects. It exists so that the board's composition — who sits on
 * the board and in what capacity — lives in a file whose contents can
 * be reviewed and corrected independently of the seeding algorithm.
 *
 * The class was extracted from MasesCoreSeeder during the seeder
 * refactoring (Unit S-8). Its contents are byte-for-byte identical to
 * the BOARD_ROLE_ASSIGNMENTS constant it replaces; no entry was
 * altered.
 *
 * =============================================================================
 * The row shape
 * =============================================================================
 * Each entry is a key-value pair:
 *
 *   key    — an SH-ID from ShareholderRegister (Unit S-1). Every key
 *            in this map must resolve to a register entry; a key that
 *            does not resolve is silently skipped by the seeder.
 *
 *   value  — a list of role slugs. Every slug must resolve to a role
 *            in RoleCatalogue (Unit S-5); a slug that does not
 *            resolve is silently skipped.
 *
 * The seeder's behavior for a shareholder whose SH-ID does not
 * appear in this map is to assign only the default role,
 * 'shareholder'. That default is applied by the seeder's
 * assignUserRelations method, not declared here. This map is the
 * OVERRIDE layer, not the complete assignment table.
 *
 * =============================================================================
 * The eight board members and their roles
 * =============================================================================
 *   SH-0003  Amanuel Salfa          — shareholder, board, secretary
 *   SH-0006  Yohannes Giche         — shareholder, board, president
 *   SH-0008  Aschalew Kebede        — shareholder, board
 *   SH-0009  Gelebo Gelefa          — shareholder, board, finance_manager
 *   SH-0011  Amare Dekiro           — shareholder, board, cashier
 *   SH-0014  Oysha Tushkulo         — shareholder, board
 *   SH-0020  Konta Koda             — shareholder, board
 *   SH-0024  Akefa Ayele            — shareholder, board, ceo
 *
 * Every entry includes 'shareholder' and 'board'. Four of the eight
 * additionally carry a functional office:
 *
 *   secretary        — SH-0003  (prepares documents, keeps minutes)
 *   president        — SH-0006  (chairs meetings, approves resolutions)
 *   finance_manager  — SH-0009  (finance application access)
 *   cashier          — SH-0011  (manages cash flow)
 *   ceo              — SH-0024  (leads day-to-day operations)
 *
 * The 'finance_manager' role assigned to SH-0009 is worth noting: it
 * is the same role slug as the application-scoped role defined in
 * RoleCatalogue. A shareholder who holds it gains access to the
 * Finance application — the same way a non-board finance manager
 * would. There is no separate 'board_finance_manager' role; the
 * board's finance function is exercised through the existing
 * application-scoped role.
 *
 * =============================================================================
 * The list order is preserved
 * =============================================================================
 * The seeder attaches roles in the order they appear in each list.
 * This affects only the order of rows in the pivot table and the
 * order of 'assigned_at' timestamps; it does not affect the
 * resulting permission set, because permissions are the union of all
 * roles a user holds regardless of the order they were attached.
 *
 * The lists below preserve the source constant's order verbatim.
 *
 * =============================================================================
 * Why these eight and not others
 * =============================================================================
 * The board composition is a governance fact, not a technical choice.
 * The eight SH-IDs above are the shareholders the register records as
 * board members. When the board's membership changes, this map is the
 * single place to update — no seeder code change is required for a
 * change to who holds which board office.
 *
 * A shareholder leaving the board is removed from this map; on the
 * next seeder run, they receive only the default 'shareholder' role.
 * A shareholder joining the board is added to this map with their
 * office; on the next run, they receive the corresponding roles.
 *
 * =============================================================================
 * What this class deliberately does NOT do
 * =============================================================================
 *   - It does NOT validate that the SH-IDs exist in the register. The
 *     seeder resolves each key against the register at seed time; an
 *     unresolvable key is skipped silently, matching the current
 *     behavior.
 *
 *   - It does NOT validate that the role slugs exist in the catalogue.
 *     The seeder resolves each slug against RoleCatalogue at seed
 *     time; an unresolvable slug is skipped silently.
 *
 *   - It does NOT list the default 'shareholder' role as an implicit
 *     assignment. The default is applied by the seeder to every
 *     shareholder who is NOT in this map. Every entry above
 *     nonetheless lists 'shareholder' explicitly, because the map
 *     REPLACES the default rather than augmenting it — a shareholder
 *     who is in the map receives exactly the roles listed, no more.
 *
 *   - It does NOT carry primary keys or pivot metadata. The
 *     'assigned_at' and 'assigned_by' pivot columns are populated by
 *     the seeder's attachRoleToUser method, which attributes every
 *     board grant to the super administrator.
 *
 * @package Database\Seeders\Data
 */
final class BoardRoleAssignments
{
    /**
     * The board-member role assignments, keyed by SH-ID.
     *
     * Each value is a list of role slugs. Every list includes
     * 'shareholder' and 'board'; four of the eight additionally
     * include a functional office (secretary, president,
     * finance_manager, cashier, or ceo).
     *
     * @var array<string, array<int, string>>
     */
    public const MAP = [
        'SH-0003' => ['shareholder', 'board', 'secretary'],
        'SH-0006' => ['shareholder', 'board', 'president'],
        'SH-0008' => ['shareholder', 'board'],
        'SH-0009' => ['shareholder', 'board', 'finance_manager'],
        'SH-0011' => ['shareholder', 'board', 'cashier'],
        'SH-0014' => ['shareholder', 'board'],
        'SH-0020' => ['shareholder', 'board'],
        'SH-0024' => ['shareholder', 'board', 'ceo'],
    ];
}