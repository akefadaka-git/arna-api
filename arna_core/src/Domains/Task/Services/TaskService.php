<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Task\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MaaleArna\Arna\Core\Auth\Models\User;
use MaaleArna\Arna\Core\Authorization\Services\AuthorizationService;
use MaaleArna\Arna\Core\Domains\Task\Models\Task;
use MaaleArna\Arna\Core\Tenancy\Services\TenantContextService;
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

    /**
     * Read-only Calendar projection of D08-owned task deadlines.
     * Task lifecycle remains exclusively in this domain.
     */
    public function deadlines(User $actor, string $from, string $to): Collection
    {
        $tenantId = $this->tenantId();
        $query = Task::query()->where('tenant_id', $tenantId)
            ->whereNotNull('due_on')
            ->whereBetween('due_on', [$from, $to]);

        if (!$this->authorization->hasPermission('task.view_all', $actor, $tenantId)) {
            $query->where(fn (Builder $visible): Builder => $visible
                ->where('created_by', $actor->getKey())
                ->orWhere('assigned_to_user_id', $actor->getKey()));
        }

        return $query->orderBy('due_on')->orderBy('title')->limit(200)->get();
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

        $task = $query->firstOrFail();
        $dependencies = $task->dependencies()->wherePivot('tenant_id', $tenantId)
            ->where('tasks.tenant_id', $tenantId);

        if (!$this->authorization->hasPermission('task.view_all', $actor, $tenantId)) {
            $dependencies->where(fn (Builder $visible): Builder => $visible
                ->where('tasks.created_by', $actor->getKey())
                ->orWhere('tasks.assigned_to_user_id', $actor->getKey()));
        }

        $task->setRelation('dependencies', $dependencies->orderBy('tasks.due_on')->get());

        return $task;
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
            if ($effectiveStatus === 'completed' && $task->status !== 'completed') {
                $hasIncompleteDependencies = DB::table('task_dependencies as td')
                    ->join('tasks as dependency', 'dependency.id', '=', 'td.depends_on_task_id')
                    ->where('td.tenant_id', $tenantId)
                    ->where('td.task_id', $task->id)
                    ->where('dependency.status', '!=', 'completed')
                    ->exists();

                if ($hasIncompleteDependencies) {
                    throw new BadRequestHttpException('Complete all prerequisite tasks before completing this task.');
                }
            }
            if ($task->status === 'completed' && $effectiveStatus === 'in_progress') {
                $hasCompletedDependents = DB::table('task_dependencies as td')
                    ->join('tasks as dependent', 'dependent.id', '=', 'td.task_id')
                    ->where('td.tenant_id', $tenantId)
                    ->where('td.depends_on_task_id', $task->id)
                    ->where('dependent.status', 'completed')
                    ->exists();

                if ($hasCompletedDependents) {
                    throw new BadRequestHttpException('A prerequisite cannot be reopened while a dependent task is completed.');
                }
            }
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

    public function addDependency(User $actor, string $id, array $data): Task
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
            $this->assertCanManage($actor, $task, $tenantId);
            if (in_array($task->status, ['completed', 'cancelled'], true)) {
                throw new BadRequestHttpException('Dependencies cannot be changed on a completed or cancelled task.');
            }

            $dependsOnId = (string) $data['depends_on_task_id'];
            if ($dependsOnId === (string) $task->id) {
                throw new BadRequestHttpException('A task cannot depend on itself.');
            }

            $dependency = Task::query()->where('tenant_id', $tenantId)->whereKey($dependsOnId)->first();
            if (!$dependency || !$this->canViewTask($actor, $dependency, $tenantId)) {
                throw new NotFoundHttpException('Task dependency not found.');
            }
            if ($dependency->status === 'cancelled') {
                throw new BadRequestHttpException('A cancelled task cannot be used as a prerequisite.');
            }
            if (DB::table('task_dependencies')->where('tenant_id', $tenantId)
                ->where('task_id', $task->id)->where('depends_on_task_id', $dependency->id)->exists()) {
                throw new ConflictHttpException('This task dependency already exists.');
            }
            if ($this->wouldCreateDependencyCycle($tenantId, (string) $task->id, (string) $dependency->id)) {
                throw new BadRequestHttpException('This dependency would create a task cycle.');
            }

            DB::table('task_dependencies')->insert([
                'tenant_id' => $tenantId,
                'task_id' => $task->id,
                'depends_on_task_id' => $dependency->id,
                'created_by' => $actor->getKey(),
                'created_at' => now(),
            ]);
            $task->updated_by = $actor->getKey();
            $task->version++;
            $task->save();
            $this->audit($task, $actor, 'dependency_added', [
                'depends_on_task_id' => $dependency->id,
            ]);

            return $this->find($actor, $id);
        });
    }

    public function removeDependency(User $actor, string $id, string $dependsOnId, int $version): Task
    {
        return DB::transaction(function () use ($actor, $id, $dependsOnId, $version): Task {
            $tenantId = $this->tenantId();
            $task = Task::query()->where('tenant_id', $tenantId)->whereKey($id)->lockForUpdate()->first();

            if (!$task) {
                throw new NotFoundHttpException('Task not found.');
            }
            if ($version !== (int) $task->version) {
                throw new ConflictHttpException('Task version conflict; reload before retrying.');
            }
            $this->assertCanManage($actor, $task, $tenantId);
            if (in_array($task->status, ['completed', 'cancelled'], true)) {
                throw new BadRequestHttpException('Dependencies cannot be changed on a completed or cancelled task.');
            }

            $deleted = DB::table('task_dependencies')->where('tenant_id', $tenantId)
                ->where('task_id', $task->id)->where('depends_on_task_id', $dependsOnId)->delete();

            if ($deleted === 0) {
                throw new NotFoundHttpException('Task dependency not found.');
            }

            $task->updated_by = $actor->getKey();
            $task->version++;
            $task->save();
            $this->audit($task, $actor, 'dependency_removed', [
                'depends_on_task_id' => $dependsOnId,
            ]);

            return $this->find($actor, $id);
        });
    }

    private function assertCanManage(User $actor, Task $task, int|string $tenantId): void
    {
        if ((string) $task->created_by !== (string) $actor->getKey()
            && !$this->authorization->hasPermission('task.assign', $actor, $tenantId)) {
            throw new NotFoundHttpException('Task not found.');
        }
    }

    private function canViewTask(User $actor, Task $task, int|string $tenantId): bool
    {
        return (string) $task->created_by === (string) $actor->getKey()
            || (string) $task->assigned_to_user_id === (string) $actor->getKey()
            || $this->authorization->hasPermission('task.view_all', $actor, $tenantId);
    }

    private function wouldCreateDependencyCycle(int|string $tenantId, string $taskId, string $dependsOnId): bool
    {
        $pending = [$dependsOnId];
        $visited = [];

        while ($pending !== []) {
            $current = array_pop($pending);
            if ($current === $taskId) {
                return true;
            }
            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;
            if (count($visited) > 1000) {
                throw new BadRequestHttpException('The task dependency graph exceeds the supported validation depth.');
            }

            $next = DB::table('task_dependencies')->where('tenant_id', $tenantId)
                ->where('task_id', $current)->pluck('depends_on_task_id')->map(fn ($value): string => (string) $value)->all();
            array_push($pending, ...$next);
        }

        return false;
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
