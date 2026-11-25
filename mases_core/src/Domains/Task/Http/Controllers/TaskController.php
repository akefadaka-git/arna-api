<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Task\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use MaaleArna\MASES\Core\Domains\Task\Services\TaskService;
use MaaleArna\MASES\Core\Localization\Calendar\Contracts\LocalDate;

final class TaskController
{
    public function __construct(private readonly TaskService $tasks)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'in:todo,in_progress,blocked,completed,cancelled'],
            'task_scope' => ['sometimes', 'in:personal,corporate'],
            'due_before' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->tasks->tasks($request->user(), $filters),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->tasks->find($request->user(), $id),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'task_scope' => ['sometimes', 'in:personal,corporate'],
            'title' => ['required', 'string', 'max:240'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'priority' => ['sometimes', 'in:low,normal,high,urgent'],
            'assigned_to_user_id' => ['sometimes', 'integer', 'min:1'],
            'due_on' => ['sometimes', 'date_format:Y-m-d'],
            'due_on_ec' => ['sometimes', 'array:year,month,day'],
            'due_on_ec.year' => ['required_with:due_on_ec', 'integer', 'min:1', 'max:9999'],
            'due_on_ec.month' => ['required_with:due_on_ec', 'integer', 'min:1', 'max:13'],
            'due_on_ec.day' => ['required_with:due_on_ec', 'integer', 'min:1', 'max:30'],
        ]);

        if (isset($data['due_on'], $data['due_on_ec'])) {
            throw ValidationException::withMessages(['due_on' => ['Supply either Gregorian due_on or Ethiopian due_on_ec, not both.']]);
        }
        if (isset($data['due_on_ec'])) {
            try {
                $ec = $data['due_on_ec'];
                $data['due_on'] = LocalDate::fromEthiopianDate(
                    (int) $ec['year'],
                    (int) $ec['month'],
                    (int) $ec['day'],
                )->value();
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['due_on_ec' => [$exception->getMessage()]]);
            }
            unset($data['due_on_ec']);
        }
        if (($data['task_scope'] ?? 'personal') === 'corporate' && !isset($data['assigned_to_user_id'])) {
            throw ValidationException::withMessages(['assigned_to_user_id' => ['Corporate tasks require an active tenant assignee.']]);
        }

        return response()->json([
            'success' => true,
            'data' => $this->tasks->create($request->user(), $data),
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'title' => ['sometimes', 'string', 'max:240'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'priority' => ['sometimes', 'in:low,normal,high,urgent'],
            'status' => ['sometimes', 'in:todo,in_progress,blocked,completed,cancelled'],
            'progress_percent' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'due_on_ec' => ['sometimes', 'array:year,month,day'],
            'due_on_ec.year' => ['required_with:due_on_ec', 'integer', 'min:1', 'max:9999'],
            'due_on_ec.month' => ['required_with:due_on_ec', 'integer', 'min:1', 'max:13'],
            'due_on_ec.day' => ['required_with:due_on_ec', 'integer', 'min:1', 'max:30'],
        ]);

        if (isset($data['due_on'], $data['due_on_ec'])) {
            throw ValidationException::withMessages(['due_on' => ['Supply either Gregorian due_on or Ethiopian due_on_ec, not both.']]);
        }
        if (isset($data['due_on_ec'])) {
            try {
                $ec = $data['due_on_ec'];
                $data['due_on'] = LocalDate::fromEthiopianDate(
                    (int) $ec['year'],
                    (int) $ec['month'],
                    (int) $ec['day'],
                )->value();
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['due_on_ec' => [$exception->getMessage()]]);
            }
            unset($data['due_on_ec']);
        }

        return response()->json([
            'success' => true,
            'data' => $this->tasks->update($request->user(), $id, $data),
        ]);
    }

    public function assign(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'assigned_to_user_id' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->tasks->assign($request->user(), $id, $data),
        ]);
    }
}
