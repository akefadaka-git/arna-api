<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Calendar\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MaaleArna\MASES\Core\Calendar\Services\CalendarService;

final class CalendarController
{
    public function __construct(private readonly CalendarService $calendar)
    {
    }

    public function calendars(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->calendar->calendars(request()->user())]);
    }

    public function storeCalendar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'visibility' => ['sometimes', 'in:private,tenant'],
            'timezone' => ['sometimes', 'timezone'],
        ]);
        return response()->json(['success' => true, 'data' => $this->calendar->createCalendar($request->user(), $data)], 201);
    }

    public function events(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => ['sometimes', 'date'], 'to' => ['sometimes', 'date', 'after_or_equal:from']]);
        return response()->json(['success' => true, 'data' => $this->calendar->events($request->user(), $data)]);
    }

    public function storeEvent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'calendar_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:240'],
            'description' => ['nullable', 'string', 'max:20000'],
            'event_type' => ['sometimes', 'string', 'max:64'],
            'all_day' => ['sometimes', 'boolean'],
            'starts_on' => ['required_if:all_day,true', 'date_format:Y-m-d'],
            'ends_on' => ['required_if:all_day,true', 'date_format:Y-m-d'],
            'starts_at' => ['required_unless:all_day,true', 'date'],
            'ends_at' => ['required_unless:all_day,true', 'date', 'after:starts_at'],
            'timezone' => ['sometimes', 'timezone'],
            'recurrence_rule' => ['nullable', 'array'],
            'recurrence_rule.frequency' => ['required_with:recurrence_rule', 'in:daily,weekly,monthly,yearly'],
            'recurrence_rule.interval' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'recurrence_rule.count' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'attendee_user_ids' => ['sometimes', 'array', 'max:200'],
            'attendee_user_ids.*' => ['integer', 'distinct'],
            'links' => ['sometimes', 'array', 'max:30'],
            'links.*.domain' => ['required_with:links', 'string', 'max:64'],
            'links.*.resource_type' => ['required_with:links', 'string', 'max:100'],
            'links.*.resource_id' => ['required_with:links', 'string', 'max:100'],
        ]);
        return response()->json(['success' => true, 'data' => $this->calendar->createEvent($request->user(), $data)], 201);
    }

    public function updateEvent(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'title' => ['sometimes', 'string', 'max:240'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'status' => ['sometimes', 'in:scheduled,cancelled,completed'],
        ]);
        return response()->json(['success' => true, 'data' => $this->calendar->updateEvent($request->user(), $id, $data)]);
    }

    public function resources(): JsonResponse { return response()->json(['success' => true, 'data' => $this->calendar->resources()]); }

    public function storeResource(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:160'], 'resource_type' => ['required', 'string', 'max:64'], 'capacity' => ['nullable', 'integer', 'min:1', 'max:100000']]);
        return response()->json(['success' => true, 'data' => $this->calendar->createResource($request->user(), $data)], 201);
    }

    public function updateResource(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:160'], 'resource_type' => ['sometimes', 'string', 'max:64'], 'capacity' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'], 'is_active' => ['sometimes', 'boolean']]);
        return response()->json(['success' => true, 'data' => $this->calendar->updateResource($request->user(), $id, $data)]);
    }

    public function respond(Request $request, string $eventId): JsonResponse
    {
        $data = $request->validate(['response' => ['required', 'in:accepted,declined,tentative']]);
        return response()->json(['success' => true, 'data' => $this->calendar->respondToInvitation($request->user(), $eventId, $data['response'])]);
    }

    public function occurrences(Request $request, string $eventId): JsonResponse
    {
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);
        return response()->json(['success' => true, 'data' => $this->calendar->recurringOccurrences($eventId, $data['from'], $data['to'])]);
    }

    public function reserve(Request $request): JsonResponse
    {
        $data = $request->validate([
            'resource_id' => ['required', 'uuid'], 'event_id' => ['required', 'uuid'],
            'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at'],
        ]);
        return response()->json(['success' => true, 'data' => $this->calendar->reserveResource($request->user(), $data)], 201);
    }
}
