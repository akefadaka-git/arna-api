<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Domains\People\Models\Person;
use MaaleArna\MASES\Core\Domains\People\Models\PersonContactPoint;

final class PersonContactPointController extends Controller
{
    public function index(string $publicId): JsonResponse
    {
        $tenantId = mases_tenant_id();
        abort_if($tenantId === null, 400, 'A validated tenant context is required.');
        $person = $this->visiblePerson($publicId, (int) $tenantId);

        $contacts = PersonContactPoint::query()
            ->where('tenant_id', $tenantId)
            ->where('person_id', $person->getKey())
            ->where('status', 'active')
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->limit(100)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $contacts->map(fn (PersonContactPoint $contact): array => $this->present($contact))->values(),
        ]);
    }

    public function store(Request $request, string $publicId): JsonResponse
    {
        $tenantId = mases_tenant_id();
        abort_if($tenantId === null, 400, 'A validated tenant context is required.');
        $person = $this->visiblePerson($publicId, (int) $tenantId);
        $attributes = $request->validate([
            'contact_type' => ['required', 'in:email,phone,other'],
            'label' => ['nullable', 'string', 'max:80'],
            'contact_value' => ['required', 'string', 'min:2', 'max:320'],
            'is_primary' => ['sometimes', 'boolean'],
        ]);
        $value = trim($attributes['contact_value']);
        if ($value === '') {
            abort(422, 'A non-empty contact value is required.');
        }
        if ($attributes['contact_type'] === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            abort(422, 'A valid email address is required.');
        }
        if ($attributes['contact_type'] === 'phone' && ! preg_match('/^[+0-9().\-\s]{6,32}$/', $value)) {
            abort(422, 'A valid phone number format is required.');
        }

        $contact = DB::transaction(function () use ($tenantId, $person, $request, $attributes, $value): PersonContactPoint {
            $person = Person::query()->whereKey($person->getKey())->lockForUpdate()->firstOrFail();
            if (($attributes['is_primary'] ?? false) === true) {
                PersonContactPoint::query()
                    ->where('tenant_id', $tenantId)
                    ->where('person_id', $person->getKey())
                    ->where('contact_type', $attributes['contact_type'])
                    ->where('status', 'active')
                    ->update(['is_primary' => false]);
            }

            $contact = new PersonContactPoint();
            $contact->fill([
                'tenant_id' => $tenantId,
                'person_id' => $person->getKey(),
                'contact_type' => $attributes['contact_type'],
                'label' => $attributes['label'] ?? null,
                'contact_value' => $value,
                'normalized_hash' => hash_hmac(
                    'sha256',
                    $attributes['contact_type'] === 'email' ? mb_strtolower($value) : preg_replace('/\\s+/', '', $value),
                    (string) config('app.key'),
                ),
                'is_primary' => (bool) ($attributes['is_primary'] ?? false),
                'is_verified' => false,
                'verified_at' => null,
                'source' => 'manual',
                'status' => 'active',
                'created_by' => $request->user()?->getKey(),
            ]);
            $contact->save();

            return $contact;
        });

        return response()->json(['success' => true, 'data' => $this->present($contact)], 201);
    }

    public function deactivate(Request $request, string $publicId, int $contactId): JsonResponse
    {
        $tenantId = mases_tenant_id();
        abort_if($tenantId === null, 400, 'A validated tenant context is required.');
        $person = $this->visiblePerson($publicId, (int) $tenantId);
        $attributes = $request->validate([
            'reason' => ['required', 'string', 'min:8', 'max:1000'],
        ]);

        $contact = DB::transaction(function () use ($tenantId, $person, $contactId, $request, $attributes): PersonContactPoint {
            $contact = PersonContactPoint::query()
                ->where('tenant_id', $tenantId)
                ->where('person_id', $person->getKey())
                ->whereKey($contactId)
                ->where('status', 'active')
                ->lockForUpdate()
                ->firstOrFail();
            $contact->status = 'inactive';
            $contact->is_primary = false;
            $contact->deactivation_reason = $attributes['reason'];
            $contact->save();

            return $contact;
        });

        return response()->json(['success' => true, 'data' => $this->present($contact)]);
    }

    private function visiblePerson(string $publicId, int $tenantId): Person
    {
        return Person::query()
            ->where('public_id', $publicId)
            ->where('status', 'active')
            ->whereHas('tenantAccesses', static fn ($query) => $query
                ->where('tenant_id', $tenantId)
                ->where('status', 'active'))
            ->firstOrFail();
    }

    private function present(PersonContactPoint $contact): array
    {
        $value = (string) $contact->contact_value;
        $masked = match ($contact->contact_type) {
            'email' => $this->maskEmail($value),
            'phone' => $this->maskPhone($value),
            default => '••••••',
        };

        return [
            'id' => (int) $contact->getKey(),
            'contact_type' => $contact->contact_type,
            'label' => $contact->label,
            'masked_value' => $masked,
            'is_primary' => (bool) $contact->is_primary,
            'is_verified' => $contact->verified_at !== null,
            'verified_at' => $contact->verified_at?->toISOString(),
            'status' => $contact->status,
            'created_at' => $contact->created_at?->toISOString(),
        ];
    }

    private function maskEmail(string $value): string
    {
        $parts = explode('@', $value, 2);
        if (count($parts) !== 2) {
            return '••••••';
        }

        return mb_substr($parts[0], 0, 1).'•••@'.$parts[1];
    }

    private function maskPhone(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        return $digits === '' ? '••••••' : '••••'.substr($digits, -min(3, strlen($digits)));
    }
}
