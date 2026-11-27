<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Wallet\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializes payment data without an envelope; the controller owns the success/data response. Unapplied amount is a derived projection.
 *
 * @package MaaleArna\Arna\Core\Domains\Wallet\Http\Resources
 * @version 1.0.1
 */
final class PaymentResource extends JsonResource
{
    /**
     * Disable the resource's default `data` wrapping.
     *
     * The controller provides the envelope (`{ success, data }`). See the
     * class docblock §"Envelope responsibility".
     *
     * @var string|null
     */
    public static $wrap = null;

    /**
     * Transform the resource into an array.
     *
     * The `$request` parameter is required by the `JsonResource` contract
     * but is not used by this resource — no input-dependent fields exist
     * on the payment payload. It is retained for interface compliance
     * and for the day a request-aware field (e.g. a `can` block) is added
     * by a future CR.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // -----------------------------------------------------------------
            // Identity.
            // -----------------------------------------------------------------
            'id'        => (int) $this->id,
            'wallet_id' => (int) $this->wallet_id,

            // -----------------------------------------------------------------
            // Economic fact (Q8).
            //
            // No `component` field: payments are not component-tagged
            // (Architecture §3.4, D109). See the class docblock
            // §"Contrast with ObligationResource".
            // -----------------------------------------------------------------
            'currency'         => (string) $this->currency,
            'amount'           => (string) $this->amount,
            'unapplied_amount' => (string) $this->unapplied_amount,

            // -----------------------------------------------------------------
            // Business time.
            // -----------------------------------------------------------------
            'received_at' => $this->received_at?->toIso8601String(),

            // -----------------------------------------------------------------
            // Reference pair (D109, opaque to this domain) and description.
            // -----------------------------------------------------------------
            'reference_type' => $this->reference_type,
            'reference_id'   => $this->reference_id,
            'description'    => $this->description,

            // -----------------------------------------------------------------
            // Actor.
            // -----------------------------------------------------------------
            'created_by' => $this->created_by !== null
                ? (int) $this->created_by
                : null,

            // -----------------------------------------------------------------
            // Derived projection flags — computed by CALLING the model's
            // methods (2B.7 §"Domain helpers"). Reading them as attributes
            // returns null because the model has no such columns. See the
            // class docblock §"Correction history (v1.0.1)".
            //
            // The three are mutually exclusive and exhaustive for a
            // well-formed row.
            // -----------------------------------------------------------------
            'is_unapplied'         => $this->isUnapplied(),
            'is_partially_applied' => $this->isPartiallyApplied(),
            'is_fully_applied'     => $this->isFullyApplied(),

            // -----------------------------------------------------------------
            // Conditional relations.
            //
            // Each key is present only when the relation was eager-loaded.
            // When absent, the key is OMITTED from the payload.
            //
            // The `created_by_user` key name is deliberately distinct from
            // the `created_by` integer field above: one is the actor's id,
            // the other is the actor's full nested payload. A caller that
            // needs only the id reads `created_by`; a caller that needs
            // the display name loads and reads `created_by_user`.
            //
            // The actor is a `User` (Core), not a `Shareholder`. The
            // inline payload emits the three identity fields a consumer
            // needs, with a null guard for the case where the relation is
            // loaded but the FK is null. See the class docblock
            // §"Actor serialization".
            // -----------------------------------------------------------------
            'wallet' => $this->whenLoaded(
                'wallet',
                fn () => new WalletResource($this->wallet),
            ),

            'created_by_user' => $this->whenLoaded(
                'createdBy',
                fn () => $this->createdBy === null ? null : [
                    'id'    => (int) $this->createdBy->id,
                    'email' => (string) $this->createdBy->email,
                    'name'  => (string) $this->createdBy->name,
                ],
            ),

            'allocations' => $this->whenLoaded(
                'allocations',
                fn () => PaymentAllocationResource::collection($this->allocations),
            ),

            // -----------------------------------------------------------------
            // Standard Eloquent timestamps.
            // -----------------------------------------------------------------
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}