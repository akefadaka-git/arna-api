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
            $meeting = GovernanceMeeting::query()->create([
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
            $this->recordAudit($tenantId, (int) $meeting->id, $actorId, 'meeting.created', null, $meeting->only(['governance_body_id', 'title_am', 'title_en', 'status', 'scheduled_at']));
            return $meeting;
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

            $before = $locked->only(['governance_body_id', 'title_am', 'title_en', 'status', 'scheduled_at', 'location', 'meeting_mode', 'notes']);
            $locked->fill($data);
            $locked->updated_by = $actorId;
            $locked->save();
            $after = $locked->refresh()->only(['governance_body_id', 'title_am', 'title_en', 'status', 'scheduled_at', 'location', 'meeting_mode', 'notes']);
            $this->recordAudit($tenantId, (int) $locked->id, $actorId, 'meeting.updated', $before, $after);

            return $locked;
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

            $before = $locked->only(['status', 'scheduled_at']);
            $locked->status = 'scheduled';
            $locked->approved_at = now();
            $locked->approved_by = $actorId;
            $locked->updated_by = $actorId;
            $locked->save();
            $after = $locked->refresh()->only(['status', 'scheduled_at', 'approved_at', 'approved_by']);
            $this->recordAudit($tenantId, (int) $locked->id, $actorId, 'meeting.approved', $before, $after);

            return $locked;
        });
    }

    /** @param array<string, mixed>|null $before @param array<string, mixed>|null $after */
    private function recordAudit(int $tenantId, int $meetingId, int $actorId, string $event, ?array $before, ?array $after): void
    {
        DB::table('governance_meeting_audit_events')->insert([
            'tenant_id' => $tenantId, 'meeting_id' => $meetingId, 'actor_id' => $actorId,
            'event_type' => $event,
            'before_json' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_json' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
            'correlation_id' => request()->headers->get('X-Correlation-ID'),
            'occurred_at' => now(),
        ]);
    }
}
