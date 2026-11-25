<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Task\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Domains\Task\Models\Task;
use MaaleArna\MASES\Core\Tenancy\Services\TenantContextService;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class TaskService
{
    public function __construct(
        private readonly TenantContextService $tenantContext,
        private readonly AuthorizationService $authorization,
    )
    {
    }

    public function tasks(User $actor, array $filters = []): mixed
    {
        $tenantId = $this->tenantId();

        return Task::query()
            ->where('tenant_id', $tenantId)
            ->when(!$this->authorization->hasPermission('task.view_all', $actor, $tenantId), function (Builder $query) use ($actor): void {
                $query->where(fn (Builder $visible): Builder => $visible
                    ->where('created_by', $actor->getKey())
                    ->orWhere('assigned_to_user_id', $actor->getKey()));
            })
            ->when(isset($filters['status']), fn (Builder $query) => $query->where('status', $filters['status']))
            ->when(isset($filters['task_scope']), fn (Builder $query) => $query->where('task_scope', $filters['task_scope']))
            ->when(isset($filters['due_before']), fn (Builder $query) => $query->whereNotNull('due_on')->where('due_on', '<=', $filters['due_before']))
            ->orderByRaw('CASE WHEN due_on IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_on')
            ->orderByDesc('updated_at')
            ->paginate(50);
    }

    public function find(User $actor, string $id): Task
    {
        $tenantId = $this->tenantId();
        $query = Task::query()->where('tenant_id', $tenantId)->whereKey($id);

        if (!$this->authorization->hasPermission('task.view_all', $actor, $tenantId)) {
            $query->where(fn (Builder $visible): Builder => $visible
                ->where('created_by', $actor->getKey())
                ->orWhere('assigned_to_user_id', $actor->getKey()));
        }

        return $query->firstOrFail();
    }

    public function create(User $actor, array $data): Task
    {
        return DB::transaction(function () use ($actor, $data): Task {
            $tenantId = $this->tenantId();
            $scope = $data['task_scope'] ?? 'personal';
            $assigneeId = $data['assigned_to_user_id'] ?? $actor->getKey();

            if ($scope === 'personal' && (string) $assigneeId !== (string) $actor->getKey()) {
                throw new BadRequestHttpException('Personal tasks must remain assigned to their creator.');
            }
            if ((string) $assigneeId !== (string) $actor->getKey()
                && !$this->authorization->hasPermission('task.assign', $actor, $tenantId)) {
                throw new BadRequestHttpException('Task assignment permission is required to assign another member.');
            }
            $this->assertActiveMember($tenantId, $assigneeId);

            $id = (string) Str::uuid();
            $task = new Task();
            $task->forceFill([
                'id' => $id,
                'tenant_id' => $tenantId,
                'task_scope' => $scope,
                'title' => trim($data['title']),
                'description' => $data['description'] ?? null,
                'status' => 'todo',
                'priority' => $data['priority'] ?? 'normal',
                'due_on' => $data['due_on'] ?? null,
                'progress_percent' => 0,
                'created_by' => $actor->getKey(),
                'assigned_to_user_id' => $assigneeId,
                'updated_by' => $actor->getKey(),
                'version' => 1,
            ]);
            $task->save();
            $this->audit($task, $actor, 'created', [
                'task_scope' => $scope,
                'assigned_to_user_id' => $assigneeId,
                'due_on' => $task->due_on?->format('Y-m-d'),
            ]);

            return $task->refresh();
        });
    }

    public function update(User $actor, string $id, array $data): Task
    {
        return DB::transaction(function () use ($actor, $id, $data): Task {
            $tenantId = $this->tenantId();
            $task = Task::query()->where('tenant_id', $tenantId)->whereKey($id)->lockForUpdate()->first();

            if (!$task) {
                throw new NotFoundHttpException('Task not found.');
            }
            if ((int) ($data['version'] ?? 0) !== (int) $task->version) {
                throw new ConflictHttpException('Task version conflict; reload before retrying.');
            }

            $isCreator = (string) $task->created_by === (string) $actor->getKey();
            $isAssignee = (string) $task->assigned_to_user_id === (string) $actor->getKey();
            $canManage = $isCreator || $this->authorization->hasPermission('task.assign', $actor, $tenantId);

            if (!$canManage && !$isAssignee) {
                throw new NotFoundHttpException('Task not found.');
            }
            if ($task->status === 'cancelled' || ($task->status === 'completed' && !$canManage)) {
                throw new NotFoundHttpException('Task not found.');
            }
            if (!$canManage && array_diff(array_keys($data), ['version', 'status', 'progress_percent']) !== []) {
                throw new NotFoundHttpException('Task not found.');
            }

            $allowed = $canManage
                ? ['title', 'description', 'priority', 'due_on', 'status', 'progress_percent']
                : ['status', 'progress_percent'];
            $changes = array_intersect_key($data, array_flip($allowed));

            if (isset($changes['status'])) {
                $this->assertTransition((string) $task->status, (string) $changes['status'], $canManage);
            }
            if (isset($changes['progress_percent']) && (int) $changes['progress_percent'] > 99
                && ($changes['status'] ?? $task->status) !== 'completed') {
                throw new BadRequestHttpException('Progress can reach 100 percent only when a task is completed.');
            }
            $effectiveStatus = $changes['status'] ?? $task->status;
            if ($effectiveStatus === 'completed') {
                if (isset($changes['progress_percent']) && (int) $changes['progress_percent'] !== 100) {
                    throw new BadRequestHttpException('A completed task must remain at 100 percent progress.');
                }
                $changes['progress_percent'] = 100;
                if (($changes['status'] ?? null) === 'completed') {
                    $changes['completed_at'] = now();
                }
            } elseif (isset($changes['status'])) {
                $changes['completed_at'] = null;
                if ((int) $task->progress_percent === 100) {
                    $changes['progress_percent'] = 99;
                }
            }

            foreach ($changes as $field => $value) {
                $task->{$field} = $value;
            }
            $task->updated_by = $actor->getKey();
            $task->version++;
            $task->save();
            $this->audit($task, $actor, 'updated', $changes);

            return $task->refresh();
        });
    }

    public function assign(User $actor, string $id, array $data): Task
    {
        return DB::transaction(function () use ($actor, $id, $data): Task {
            $tenantId = $this->tenantId();
            $task = Task::query()->where('tenant_id', $tenantId)->whereKey($id)->lockForUpdate()->first();

            if (!$task) {
                throw new NotFoundHttpException('Task not found.');
            }
            if ((int) ($data['version'] ?? 0) !== (int) $task->version) {
                throw new ConflictHttpException('Task version conflict; reload before retrying.');
            }
            if (!$this->authorization->hasPermission('task.assign', $actor, $tenantId)
                || $task->task_scope === 'personal'
                || in_array($task->status, ['completed', 'cancelled'], true)) {
                throw new NotFoundHttpException('Task not found.');
            }
            $this->assertActiveMember($tenantId, $data['assigned_to_user_id']);

            $before = $task->assigned_to_user_id;
            $task->assigned_to_user_id = $data['assigned_to_user_id'];
            $task->updated_by = $actor->getKey();
            $task->version++;
            $task->save();
            $this->audit($task, $actor, 'reassigned', [
                'assigned_to_user_id' => ['from' => $before, 'to' => $task->assigned_to_user_id],
            ]);

            return $task->refresh();
        });
    }

    private function assertTransition(string $from, string $to, bool $canManage): void
    {
        $allowed = [
            'todo' => ['in_progress', 'blocked', 'completed', 'cancelled'],
            'in_progress' => ['todo', 'blocked', 'completed', 'cancelled'],
            'blocked' => ['todo', 'in_progress', 'completed', 'cancelled'],
            'completed' => $canManage ? ['in_progress'] : [],
            'cancelled' => [],
        ];

        if ($from !== $to && !in_array($to, $allowed[$from] ?? [], true)) {
            throw new BadRequestHttpException('The requested task status transition is not allowed.');
        }
    }

    private function assertActiveMember(int|string $tenantId, int|string $userId): void
    {
        $active = DB::table('tenant_memberships')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->exists();

        if (!$active) {
            throw new BadRequestHttpException('The assignee must be an active member of this tenant.');
        }
    }

    private function audit(Task $task, User $actor, string $action, array $changes): void
    {
        DB::table('task_audit_entries')->insert([
            'tenant_id' => $task->tenant_id,
            'task_id' => $task->id,
            'actor_user_id' => $actor->getKey(),
            'action' => $action,
            'changes' => json_encode($changes, JSON_THROW_ON_ERROR),
            'request_id' => request()?->headers->get('X-Request-ID'),
            'created_at' => now(),
        ]);
    }

    private function tenantId(): int|string
    {
        $tenantId = $this->tenantContext->getTenantId();
        if ($tenantId === null) {
            throw new BadRequestHttpException('A resolved tenant context is required.');
        }

        return $tenantId;
    }
}
