<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceMeeting;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class GovernanceMeetingService
{
    /**
     * Create a meeting only for a body owned by the resolved tenant.
     * Tenant identity is supplied by the server-side caller, never request input.
     *
     * @param array<string, mixed> $data
     */
    public function create(int $tenantId, int $actorId, array $data): GovernanceMeeting
    {
        $body = GovernanceBody::query()
            ->where('tenant_id', $tenantId)
            ->whereKey((int) $data['governance_body_id'])
            ->firstOrFail();

        if (!$body->is_active) {
            throw ValidationException::withMessages([
                'governance_body_id' => ['Meetings cannot be created for an inactive governance body.'],
            ]);
        }

        return DB::transaction(function () use ($tenantId, $actorId, $data): GovernanceMeeting {
            return GovernanceMeeting::query()->create([
                'tenant_id' => $tenantId,
                'governance_body_id' => (int) $data['governance_body_id'],
                'title_am' => $data['title_am'] ?? null,
                'title_en' => $data['title_en'] ?? null,
                'meeting_type' => $data['meeting_type'] ?? 'ordinary',
                'status' => 'draft',
                'scheduled_at' => $data['scheduled_at'] ?? null,
                'location' => $data['location'] ?? null,
                'meeting_mode' => $data['meeting_mode'] ?? 'in_person',
                'notes' => $data['notes'] ?? null,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);
        });
    }

    /**
     * Update only draft meetings; approved/scheduled records use explicit transitions.
     *
     * @param array<string, mixed> $data
     */
    public function update(GovernanceMeeting $meeting, int $tenantId, int $actorId, array $data): GovernanceMeeting
    {
        return DB::transaction(function () use ($meeting, $tenantId, $actorId, $data): GovernanceMeeting {
            $locked = GovernanceMeeting::query()->where('tenant_id', $tenantId)
                ->whereKey($meeting->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'draft') {
                throw new ConflictHttpException('Only draft meetings can be edited. Use an explicit lifecycle transition.');
            }

            if (array_key_exists('governance_body_id', $data)) {
                GovernanceBody::query()->where('tenant_id', $tenantId)
                    ->where('is_active', true)
                    ->whereKey((int) $data['governance_body_id'])->firstOrFail();
            }

            $locked->fill($data);
            $locked->updated_by = $actorId;
            $locked->save();

            return $locked->refresh();
        });
    }

    public function approve(GovernanceMeeting $meeting, int $tenantId, int $actorId): GovernanceMeeting
    {
        return DB::transaction(function () use ($meeting, $tenantId, $actorId): GovernanceMeeting {
            $locked = GovernanceMeeting::query()->where('tenant_id', $tenantId)
                ->whereKey($meeting->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'draft') {
                throw new ConflictHttpException('Only draft meetings can be approved.');
            }
            GovernanceBody::query()->where('tenant_id', $tenantId)
                ->where('is_active', true)->whereKey($locked->governance_body_id)->firstOrFail();
            if ($locked->scheduled_at === null || $locked->scheduled_at->lessThan(Carbon::now())) {
                throw ValidationException::withMessages([
                    'scheduled_at' => ['A future scheduled date and time are required before approval.'],
                ]);
            }

            $locked->status = 'scheduled';
            $locked->approved_at = now();
            $locked->approved_by = $actorId;
            $locked->updated_by = $actorId;
            $locked->save();

            return $locked->refresh();
        });
    }
}
