<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Documents\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Documents\Models\IncomingDocumentRegistration;
use MaaleArna\MASES\Core\Domains\Documents\Services\IncomingDocumentRegistrationService;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

final class IncomingDocumentController extends Controller
{
    public function __construct(private readonly IncomingDocumentRegistrationService $registrations)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'legal_entity_id' => ['required', 'integer', 'min:1'],
            'bb_code' => ['required', 'string', 'regex:/^[1-9]{2}$/'],
            'file_id' => ['required', 'integer', 'min:1'],
            'subject' => ['required', 'string', 'min:1', 'max:255'],
            'sender_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Laravel string validation does not trim the supplied value.
            'sender_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'received_at' => ['sometimes', 'date'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'metadata' => ['sometimes', 'array'],
        ]);

        $registration = $this->registrations->register(
            $validated,
            $this->actor(),
            (string) $request->header('Idempotency-Key', '')
        );

        return response()->json(['success' => true, 'data' => $this->present($registration)], 201);
    }

    public function show(string $uuid): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->present($this->registrations->find($uuid, $this->actor())),
        ]);
    }

    private function actor(): User
    {
        $actor = auth()->user();
        if (!$actor instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'A platform user session is required.');
        }
        return $actor;
    }

    /** @return array<string,mixed> */
    private function present(IncomingDocumentRegistration $registration): array
    {
        return [
            'incoming_registration_id' => $registration->registration_uuid,
            'sender_name' => $registration->sender_name,
            'sender_reference' => $registration->sender_reference,
            'subject' => $registration->subject,
            'notes' => $registration->notes,
            'bb_code' => $registration->bb_code,
            'file_id' => $registration->file_id,
            'file_version' => $registration->file_version,
            'received_at' => $registration->received_at?->toIso8601String(),
            'registered_at' => $registration->registered_at?->toIso8601String(),
            'registered_by' => $registration->registered_by,
        ];
    }
}
