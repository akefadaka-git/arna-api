<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class GovernanceMeetingAgendaController extends Controller
{
    private function tenantId(): int
    {
        $tenantId = (int) (function_exists('mases_tenant_id') ? mases_tenant_id() : 0);
        if ($tenantId < 1) {
            throw new NotFoundHttpException('Tenant context is required.');
        }

        return $tenantId;
    }

    private function actorId(): int
    {
        return (int) auth()->id();
    }

    public function index(int $meeting): JsonResponse
    {
        $tenantId = $this->tenantId();
        $this->meeting($tenantId, $meeting);

        $items = DB::table('governance_meeting_agenda_items')
            ->where('tenant_id', $tenantId)
            ->where('meeting_id', $meeting)
            ->orderBy('item_number')
            ->get();

        return response()->json(['success' => true, 'data' => $items]);
    }

    public function store(Request $request, int $meeting): JsonResponse
    {
        $data = $request->validate([
            'item_number' => ['required', 'integer', 'min:1', 'max:99'],
            'title_am' => ['nullable', 'string', 'max:190', 'required_without:title_en'],
            'title_en' => ['nullable', 'string', 'max:190', 'required_without:title_am'],
            'description' => ['nullable', 'string', 'max:10000'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
        ]);

        $tenantId = $this->tenantId();
        $this->meeting($tenantId, $meeting);

        try {
            $id = DB::table('governance_meeting_agenda_items')->insertGetId([
                'tenant_id' => $tenantId,
                'meeting_id' => $meeting,
                'item_number' => $data['item_number'],
                'title_am' => $data['title_am'] ?? null,
                'title_en' => $data['title_en'] ?? null,
                'description' => $data['description'] ?? null,
                'duration_minutes' => $data['duration_minutes'] ?? null,
                'status' => 'draft',
                'version' => 1,
                'created_by' => $this->actorId(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw new ConflictHttpException('This agenda item number is already used for the meeting.');
            }
            throw $exception;
        }

        return response()->json([
            'success' => true,
            'data' => DB::table('governance_meeting_agenda_items')
                ->where('tenant_id', $tenantId)
                ->find($id),
        ], 201);
    }

    public function update(Request $request, int $agendaItem): JsonResponse
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'item_number' => ['sometimes', 'integer', 'min:1', 'max:99'],
            'title_am' => ['sometimes', 'nullable', 'string', 'max:190'],
            'title_en' => ['sometimes', 'nullable', 'string', 'max:190'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:600'],
        ]);

        if (array_key_exists('title_am', $data) && array_key_exists('title_en', $data)
            && trim((string) $data['title_am']) === '' && trim((string) $data['title_en']) === '') {
            throw ValidationException::withMessages([
                'title_am' => 'At least one Amharic or English title is required.',
            ]);
        }

        $tenantId = $this->tenantId();

        try {
            $updated = DB::transaction(function () use ($tenantId, $agendaItem, $data): object {
                $item = DB::table('governance_meeting_agenda_items')
                    ->where('tenant_id', $tenantId)
                    ->where('id', $agendaItem)
                    ->lockForUpdate()
                    ->first();

                if (!$item) {
                    throw new NotFoundHttpException('Agenda item was not found in this tenant.');
                }
                if ($item->status !== 'draft') {
                    throw new ConflictHttpException('Only draft agenda items can be edited.');
                }
                if ((int) $item->version !== (int) $data['version']) {
                    throw new ConflictHttpException('Agenda item changed since it was loaded. Reload before editing.');
                }

                $changes = ['version' => (int) $item->version + 1, 'updated_by' => $this->actorId(), 'updated_at' => now()];
                foreach (['item_number', 'title_am', 'title_en', 'description', 'duration_minutes'] as $field) {
                    if (array_key_exists($field, $data)) {
                        $changes[$field] = $data[$field];
                    }
                }

                DB::table('governance_meeting_agenda_items')
                    ->where('tenant_id', $tenantId)
                    ->where('id', $agendaItem)
                    ->update($changes);

                return DB::table('governance_meeting_agenda_items')
                    ->where('tenant_id', $tenantId)
                    ->find($agendaItem);
            });
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw new ConflictHttpException('This agenda item number is already used for the meeting.');
            }
            throw $exception;
        }

        return response()->json(['success' => true, 'data' => $updated]);
    }

    private function meeting(int $tenantId, int $meeting): object
    {
        $record = DB::table('governance_meetings')
            ->where('tenant_id', $tenantId)
            ->where('id', $meeting)
            ->first();

        if (!$record) {
            throw new NotFoundHttpException('Meeting was not found in this tenant.');
        }

        return $record;
    }
}
