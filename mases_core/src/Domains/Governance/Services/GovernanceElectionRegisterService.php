<?php
declare(strict_types=1);
namespace MaaleArna\MASES\Core\Domains\Governance\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceElection;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use RuntimeException;

/** Freezes an externally reconciled and legally approved voter entitlement set.
 * Voting weight is never inferred from the live ShareHolding projection.
 */
final class GovernanceElectionRegisterService
{
    /**
     * Calculate the default share-weighted entitlement using decimal arithmetic.
     * Any class-specific override requires separately verified entitlement evidence.
     */
    public static function defaultVotingWeight(string|int $shareQuantity): string
    {
        $quantity = (string) $shareQuantity;
        if (!function_exists('bcmul') || !function_exists('bccomp')) {
            throw new RuntimeException('BCMath is required for precise share-voting calculations.');
        }
        if (bccomp($quantity, '0', 4) <= 0 || bccomp($quantity, bcadd($quantity, '0', 0), 4) !== 0) {
            throw ValidationException::withMessages(['share_quantity' => 'Voting shares must be a positive whole-share quantity.']);
        }

        $votesPerShare = (string) config('governance.election_voting.default_votes_per_share', 1);
        return bcmul($quantity, $votesPerShare, 4);
    }

    /** @param array<int,array{shareholder_id:int,share_class_code:string,share_quantity:string|int|float,voting_weight?:string|int|float,entitlement_reference:string,proxy_authorized?:bool}> $entries */
    public function freeze(int $tenantId, int $electionId, string $recordDate, string $sourceReference, string $ruleVersion, int $actorId, array $entries): int
    {
        if ($entries === []) {
            throw ValidationException::withMessages(['entries' => 'The approved voter register cannot be empty.']);
        }
        return DB::transaction(function () use ($tenantId, $electionId, $recordDate, $sourceReference, $ruleVersion, $actorId, $entries): int {
            $election = GovernanceElection::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($electionId);
            if (!$election->isAuthorityVerified()) {
                throw new RuntimeException('Election authority must be verified before the register can be frozen.');
            }
            if ($election->status !== 'planned') {
                throw new RuntimeException('The voter register can only be frozen while the election is planned.');
            }
            if ($election->record_date !== null && $election->record_date->toDateString() !== $recordDate) {
                throw new RuntimeException('The supplied record date does not match the approved record date.');
            }
            if (trim($sourceReference) === '' || trim($ruleVersion) === '') {
                throw ValidationException::withMessages(['source_reference' => 'A source reference and approved rule version are required.']);
            }

            $normalised = [];
            $ids = [];
            foreach ($entries as $entry) {
                $id = (int) ($entry['shareholder_id'] ?? 0);
                $quantity = (string) ($entry['share_quantity'] ?? '0');
                $weight = (string) ($entry['voting_weight'] ?? '');
                $expectedWeight = self::defaultVotingWeight($quantity);
                $class = trim((string) ($entry['share_class_code'] ?? ''));
                $evidence = trim((string) ($entry['entitlement_reference'] ?? ''));
                if ($id < 1 || $class === '' || $evidence === '') {
                    throw ValidationException::withMessages(['entries' => 'Each entry requires a shareholder, positive quantity, non-negative approved weight, class and evidence reference.']);
                }
                if (isset($ids[$id])) {
                    throw ValidationException::withMessages(['entries' => 'A shareholder may appear only once in an election register.']);
                }
                if ($weight !== '' && bccomp($weight, $expectedWeight, 4) !== 0) {
                    throw ValidationException::withMessages([
                        'entries' => 'Voting weight must equal the verified eligible share quantity multiplied by the configured votes-per-share rate. One-holder-one-vote is not permitted.',
                    ]);
                }
                $ids[$id] = true;
                $normalised[] = [
                    'shareholder_id' => $id,
                    'share_class_code' => $class,
                    'share_quantity' => bcadd($quantity, '0', 4),
                    'voting_weight' => $expectedWeight,
                    'entitlement_reference' => $evidence,
                    'proxy_authorized' => (bool) ($entry['proxy_authorized'] ?? false),
                ];
            }
            $found = Shareholder::query()->where('tenant_id', $tenantId)->whereIn('id', array_keys($ids))->count();
            if ($found !== count($ids)) {
                throw ValidationException::withMessages(['entries' => 'One or more shareholders are unavailable in this tenant.']);
            }
            usort($normalised, static fn (array $a, array $b): int => $a['shareholder_id'] <=> $b['shareholder_id']);
            $canonical = json_encode($normalised, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $now = Carbon::now();
            $snapshotId = DB::table('governance_election_register_snapshots')->insertGetId([
                'tenant_id' => $tenantId, 'election_id' => $election->id, 'record_date' => $recordDate,
                'source_reference' => $sourceReference, 'rule_version' => $ruleVersion,
                'content_sha256' => hash('sha256', $canonical), 'voter_count' => count($normalised),
                'created_by' => $actorId, 'frozen_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $rows = array_map(static fn (array $entry): array => [
                'tenant_id' => $tenantId, 'election_id' => $election->id, 'snapshot_id' => $snapshotId,
                ...$entry, 'created_at' => $now, 'updated_at' => $now,
            ], $normalised);
            DB::table('governance_election_voters')->insert($rows);
            $election->forceFill(['record_date' => $recordDate, 'voting_rule_version' => $ruleVersion])->save();
            return $snapshotId;
        });
    }
}
