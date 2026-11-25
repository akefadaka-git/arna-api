<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Services;

use DomainException;

/**
 * Fail-closed gate for legally consequential shareholder-meeting actions.
 *
 * It deliberately does not calculate statutory rules. Those require
 * authenticated legal form and operative corporate instruments.
 */
final class GovernanceMeetingLegalGate
{
    private const REQUIRED_EVIDENCE = [
        'notice' => ['notice_rule'],
        'quorum' => ['quorum_rule', 'meeting_category_rule'],
        'voting' => ['voting_rights_rule', 'share_register_basis'],
        'resolution' => ['resolution_approval_rule'],
        'proxy' => ['proxy_formality_rule', 'proxy_authority'],
        'penalty' => ['penalty_authority', 'penalty_due_process'],
        'minutes' => ['minutes_approval_rule', 'minutes_signatory_authority'],
    ];

    /** @param array<string,mixed> $meeting
     *  @param array<string,mixed> $evidence
     *  @return array{allowed:bool,code:string,missing:list<string>}
     */
    public function evaluate(string $action, array $meeting, array $evidence): array
    {
        if (!isset(self::REQUIRED_EVIDENCE[$action])) {
            return ['allowed' => false, 'code' => 'UNKNOWN_LEGAL_ACTION', 'missing' => [$action]];
        }

        $missing = [];
        if (($meeting['legal_form'] ?? null) !== 'share_company') {
            $missing[] = 'verified_share_company_legal_form';
        }
        if (!in_array($meeting['category'] ?? null, ['ordinary', 'extraordinary', 'special'], true)) {
            $missing[] = 'verified_meeting_category';
        }
        if (($meeting['legal_basis_status'] ?? null) !== 'verified') {
            $missing[] = 'verified_operative_legal_basis';
        }
        foreach (['legal_basis_reference', 'legal_basis_verified_at', 'legal_basis_verified_by'] as $field) {
            if (empty($meeting[$field])) {
                $missing[] = $field;
            }
        }
        if (!empty($meeting['created_by']) && !empty($meeting['legal_basis_verified_by'])
            && (string) $meeting['created_by'] === (string) $meeting['legal_basis_verified_by']) {
            $missing[] = 'independent_reviewer_separation';
        }

        foreach (self::REQUIRED_EVIDENCE[$action] as $key) {
            $item = $evidence[$key] ?? null;
            if (!is_array($item) || ($item['status'] ?? null) !== 'verified'
                || empty($item['source_reference']) || empty($item['approved_at'])
                || empty($item['approved_by'])) {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            return ['allowed' => false, 'code' => 'LEGAL_BASIS_UNVERIFIED', 'missing' => array_values(array_unique($missing))];
        }

        return ['allowed' => true, 'code' => 'LEGAL_BASIS_VERIFIED', 'missing' => []];
    }

    /** @param array<string,mixed> $meeting
     *  @param array<string,mixed> $evidence
     */
    public function assertAllowed(string $action, array $meeting, array $evidence): void
    {
        $decision = $this->evaluate($action, $meeting, $evidence);
        if (!$decision['allowed']) {
            throw new DomainException('Shareholder-meeting action blocked: '.$decision['code'].' ('.implode(', ', $decision['missing']).').');
        }
    }
}
