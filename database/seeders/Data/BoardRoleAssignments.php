<?php

declare(strict_types=1);

namespace Database\Seeders\Data;

/**
 * Seed-time mapping of shareholder register IDs to board roles; keep assignments aligned with the approved register.
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