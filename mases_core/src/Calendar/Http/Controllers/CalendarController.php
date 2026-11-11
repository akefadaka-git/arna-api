<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Calendar\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use MaaleArna\MASES\Core\Localization\Calendar\Contracts\LocalDate;
use MaaleArna\MASES\Core\Calendar\Services\CalendarService;

final class CalendarController
{
    public function __construct(private readonly CalendarService $calendar)
    {
    }

    public function calendars(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->calendar->calendars($request->user())]);
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

    public function updateCalendar(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'name' => ['sometimes', 'string', 'max:160'],
            'visibility' => ['sometimes', 'in:private,tenant'],
            'timezone' => ['sometimes', 'timezone'],
        ]);
        return response()->json(['success' => true, 'data' => $this->calendar->updateCalendar($request->user(), $id, $data)]);
    }

    public function events(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'from_ec' => ['sometimes', 'array:year,month,day'],
            'from_ec.year' => ['required_with:from_ec', 'integer', 'min:1', 'max:9999'],
            'from_ec.month' => ['required_with:from_ec', 'integer', 'min:1', 'max:13'],
            'from_ec.day' => ['required_with:from_ec', 'integer', 'min:1', 'max:30'],
            'to_ec' => ['sometimes', 'array:year,month,day'],
            'to_ec.year' => ['required_with:to_ec', 'integer', 'min:1', 'max:9999'],
            'to_ec.month' => ['required_with:to_ec', 'integer', 'min:1', 'max:13'],
            'to_ec.day' => ['required_with:to_ec', 'integer', 'min:1', 'max:30'],
        ]);

        foreach (['from', 'to'] as $field) {
            $ecField = $field.'_ec';
            if (isset($data[$field], $data[$ecField])) {
                throw ValidationException::withMessages([$field => ['Supply either Gregorian or Ethiopian date, not both.']]);
            }
            if (isset($data[$ecField])) {
                $civilDate = LocalDate::fromString($this->ethiopianDateToGregorian($data[$ecField], $ecField));
                $addisZone = new \DateTimeZone('Africa/Addis_Ababa');
                $boundary = $civilDate->atStartOfDay($addisZone);
                if ($field === 'to') {
                    $boundary = $boundary->modify('+1 day')->modify('-1 second');
                }
                // Event instants are stored in UTC; convert the inclusive E.C.
                // civil-day boundary from Addis Ababa to UTC before querying.
                $data[$field] = $boundary->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                unset($data[$ecField]);
            }
        }
        if (isset($data['from'], $data['to']) && $data['to'] < $data['from']) {
            throw ValidationException::withMessages(['to' => ['The end date must not precede the start date.']]);
        }

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
            'starts_on' => ['sometimes', 'date_format:Y-m-d'],
            'ends_on' => ['sometimes', 'date_format:Y-m-d'],
            'starts_on_ec' => ['sometimes', 'array:year,month,day'],
            'starts_on_ec.year' => ['required_with:starts_on_ec', 'integer', 'min:1', 'max:9999'],
            'starts_on_ec.month' => ['required_with:starts_on_ec', 'integer', 'min:1', 'max:13'],
            'starts_on_ec.day' => ['required_with:starts_on_ec', 'integer', 'min:1', 'max:30'],
            'ends_on_ec' => ['sometimes', 'array:year,month,day'],
            'ends_on_ec.year' => ['required_with:ends_on_ec', 'integer', 'min:1', 'max:9999'],
            'ends_on_ec.month' => ['required_with:ends_on_ec', 'integer', 'min:1', 'max:13'],
            'ends_on_ec.day' => ['required_with:ends_on_ec', 'integer', 'min:1', 'max:30'],
            'starts_at' => ['required_unless:all_day,true', 'date'],
            'ends_at' => ['required_unless:all_day,true', 'date', 'after:starts_at'],
            'timezone' => ['sometimes', 'timezone'],
            'recurrence_rule' => ['nullable', 'array'],
            'recurrence_rule.frequency' => ['required_with:recurrence_rule', 'in:daily,weekly,monthly,yearly'],
            'recurrence_rule.interval' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'recurrence_rule.count' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'recurrence_rule.until' => ['sometimes', 'date_format:Y-m-d'],
            'recurrence_rule.until_ec' => ['sometimes', 'array:year,month,day'],
            'recurrence_rule.until_ec.year' => ['required_with:recurrence_rule.until_ec', 'integer', 'min:1', 'max:9999'],
            'recurrence_rule.until_ec.month' => ['required_with:recurrence_rule.until_ec', 'integer', 'min:1', 'max:13'],
            'recurrence_rule.until_ec.day' => ['required_with:recurrence_rule.until_ec', 'integer', 'min:1', 'max:30'],
            'recurrence_rule.calendar_system' => ['sometimes', 'in:gregorian,ethiopian'],
            'attendee_user_ids' => ['sometimes', 'array', 'max:200'],
            'attendee_user_ids.*' => ['integer', 'distinct'],
            'reminders' => ['sometimes', 'array', 'max:10'],
            'reminders.*' => ['integer', 'min:1', 'max:10080', 'distinct'],
            'links' => ['sometimes', 'array', 'max:30'],
            'links.*.domain' => ['required_with:links', 'string', 'max:64'],
            'links.*.resource_type' => ['required_with:links', 'string', 'max:100'],
            'links.*.resource_id' => ['required_with:links', 'string', 'max:100'],
            'links.*.relationship' => ['sometimes', 'in:related,agenda,attachment,context'],
        ]);

        if (($data['all_day'] ?? false) === true) {
            foreach (['starts_on', 'ends_on'] as $field) {
                $ecField = $field.'_ec';
                if (isset($data[$field], $data[$ecField])) {
                    throw ValidationException::withMessages([$field => ['Supply either Gregorian date or Ethiopian date, not both.']]);
                }
                if (!isset($data[$field]) && !isset($data[$ecField])) {
                    throw ValidationException::withMessages([$field => ['An all-day event requires both civil dates; Ethiopian date input is also accepted.']]);
                }
                if (isset($data[$ecField])) {
                    try {
                        $ec = $data[$ecField];
                        $data[$field] = LocalDate::fromEthiopianDate(
                            (int) $ec['year'],
                            (int) $ec['month'],
                            (int) $ec['day'],
                        )->value();
                    } catch (InvalidArgumentException $exception) {
                        throw ValidationException::withMessages([$ecField => [$exception->getMessage()]]);
                    }
                    unset($data[$ecField]);
                }
            }
        } elseif (isset($data['starts_on_ec']) || isset($data['ends_on_ec']) || isset($data['starts_on']) || isset($data['ends_on'])) {
            throw ValidationException::withMessages(['all_day' => ['Civil-date fields are only valid for all-day events.']]);
        }

        if (isset($data['recurrence_rule']['until_ec'])) {
            if (($data['recurrence_rule']['calendar_system'] ?? 'gregorian') !== 'ethiopian') {
                throw ValidationException::withMessages(['recurrence_rule.until_ec' => ['Ethiopian recurrence end dates require calendar_system=ethiopian.']]);
            }
            if (isset($data['recurrence_rule']['until'])) {
                throw ValidationException::withMessages(['recurrence_rule.until' => ['Supply either Gregorian until or Ethiopian until_ec, not both.']]);
            }
            $data['recurrence_rule']['until'] = $this->ethiopianDateToGregorian(
                $data['recurrence_rule']['until_ec'],
                'recurrence_rule.until_ec',
            );
            unset($data['recurrence_rule']['until_ec']);
        }
        if (($data['recurrence_rule']['calendar_system'] ?? 'gregorian') === 'ethiopian' && !($data['all_day'] ?? false)) {
            throw ValidationException::withMessages(['recurrence_rule.calendar_system' => ['Ethiopian calendar recurrence is supported for all-day civil-date events only.']]);
        }

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
        $data = $request->validate([
            'from' => ['required_without:from_ec', 'date'],
            'to' => ['required_without:to_ec', 'date'],
            'from_ec' => ['required_without:from', 'array:year,month,day'],
            'from_ec.year' => ['required_with:from_ec', 'integer', 'min:1', 'max:9999'],
            'from_ec.month' => ['required_with:from_ec', 'integer', 'min:1', 'max:13'],
            'from_ec.day' => ['required_with:from_ec', 'integer', 'min:1', 'max:30'],
            'to_ec' => ['required_without:to', 'array:year,month,day'],
            'to_ec.year' => ['required_with:to_ec', 'integer', 'min:1', 'max:9999'],
            'to_ec.month' => ['required_with:to_ec', 'integer', 'min:1', 'max:13'],
            'to_ec.day' => ['required_with:to_ec', 'integer', 'min:1', 'max:30'],
        ]);
        if (isset($data['from'], $data['from_ec']) || isset($data['to'], $data['to_ec'])) {
            throw ValidationException::withMessages(['from' => ['Supply either Gregorian or Ethiopian range dates, not both.']]);
        }
        $from = isset($data['from_ec']) ? $this->ethiopianDateToGregorian($data['from_ec'], 'from_ec') : $data['from'];
        $to = isset($data['to_ec']) ? $this->ethiopianDateToGregorian($data['to_ec'], 'to_ec') : $data['to'];
        if ($to < $from) {
            throw ValidationException::withMessages(['to' => ['The end date must not precede the start date.']]);
        }
        return response()->json(['success' => true, 'data' => $this->calendar->recurringOccurrences($request->user(), $eventId, $from, $to)]);
    }

    public function moveOccurrence(Request $request, string $eventId): JsonResponse
    {
        $data = $request->validate([
            'original_starts_at' => ['required_without:original_starts_on_ec', 'date'],
            'override_starts_at' => ['required_without:override_starts_on_ec', 'date'],
            'override_ends_at' => ['required_without:override_ends_on_ec', 'date'],
            'original_starts_on_ec' => ['required_without:original_starts_at', 'array:year,month,day'],
            'original_starts_on_ec.year' => ['required_with:original_starts_on_ec', 'integer', 'min:1', 'max:9999'],
            'original_starts_on_ec.month' => ['required_with:original_starts_on_ec', 'integer', 'min:1', 'max:13'],
            'original_starts_on_ec.day' => ['required_with:original_starts_on_ec', 'integer', 'min:1', 'max:30'],
            'override_starts_on_ec' => ['required_without:override_starts_at', 'array:year,month,day'],
            'override_starts_on_ec.year' => ['required_with:override_starts_on_ec', 'integer', 'min:1', 'max:9999'],
            'override_starts_on_ec.month' => ['required_with:override_starts_on_ec', 'integer', 'min:1', 'max:13'],
            'override_starts_on_ec.day' => ['required_with:override_starts_on_ec', 'integer', 'min:1', 'max:30'],
            'override_ends_on_ec' => ['required_without:override_ends_at', 'array:year,month,day'],
            'override_ends_on_ec.year' => ['required_with:override_ends_on_ec', 'integer', 'min:1', 'max:9999'],
            'override_ends_on_ec.month' => ['required_with:override_ends_on_ec', 'integer', 'min:1', 'max:13'],
            'override_ends_on_ec.day' => ['required_with:override_ends_on_ec', 'integer', 'min:1', 'max:30'],
        ]);
        $ecFields = ['original_starts_on_ec', 'override_starts_on_ec', 'override_ends_on_ec'];
        $gregorianFields = ['original_starts_at', 'override_starts_at', 'override_ends_at'];
        $hasEc = count(array_filter($ecFields, fn (string $field): bool => isset($data[$field]))) > 0;
        $hasGregorian = count(array_filter($gregorianFields, fn (string $field): bool => isset($data[$field]))) > 0;
        if (($hasEc && count(array_filter($ecFields, fn (string $field): bool => isset($data[$field]))) !== 3)
            || ($hasGregorian && count(array_filter($gregorianFields, fn (string $field): bool => isset($data[$field])) ) !== 3)
            || ($hasEc && $hasGregorian)) {
            throw ValidationException::withMessages(['occurrence' => ['Supply all three override dates in one calendar representation only.']]);
        }
        if ($hasEc) {
            foreach ($ecFields as $field) {
                $data[$field === 'original_starts_on_ec' ? 'original_starts_at' : ($field === 'override_starts_on_ec' ? 'override_starts_at' : 'override_ends_at')] =
                    $this->ethiopianDateToGregorian($data[$field], $field);
            }
        }
        $this->calendar->moveOccurrence(
            $request->user(),
            $eventId,
            $data['original_starts_at'],
            $data['override_starts_at'],
            $data['override_ends_at'],
        );
        return response()->json(['success' => true, 'data' => ['overridden' => true]]);
    }

    public function cancelOccurrence(Request $request, string $eventId): JsonResponse
    {
        $data = $request->validate([
            'original_starts_at' => ['required_without:original_starts_on_ec', 'date'],
            'original_starts_on_ec' => ['required_without:original_starts_at', 'array:year,month,day'],
            'original_starts_on_ec.year' => ['required_with:original_starts_on_ec', 'integer', 'min:1', 'max:9999'],
            'original_starts_on_ec.month' => ['required_with:original_starts_on_ec', 'integer', 'min:1', 'max:13'],
            'original_starts_on_ec.day' => ['required_with:original_starts_on_ec', 'integer', 'min:1', 'max:30'],
        ]);
        if (isset($data['original_starts_at'], $data['original_starts_on_ec'])) {
            throw ValidationException::withMessages(['original_starts_at' => ['Supply either Gregorian or Ethiopian occurrence date, not both.']]);
        }
        $original = isset($data['original_starts_on_ec'])
            ? $this->ethiopianDateToGregorian($data['original_starts_on_ec'], 'original_starts_on_ec')
            : $data['original_starts_at'];
        $this->calendar->cancelOccurrence($request->user(), $eventId, $original);
        return response()->json(['success' => true, 'data' => ['cancelled' => true]]);
    }

    private function ethiopianDateToGregorian(array $parts, string $field): string
    {
        try {
            return LocalDate::fromEthiopianDate(
                (int) $parts['year'],
                (int) $parts['month'],
                (int) $parts['day'],
            )->value();
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([$field => [$exception->getMessage()]]);
        }
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
