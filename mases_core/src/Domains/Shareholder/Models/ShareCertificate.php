<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Shareholder\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use MaaleArna\MASES\Core\Domains\Shareholder\Enums\CertificateStatus;

/**
 * ShareCertificate
 *
 * Eloquent model for a share certificate issued to a shareholder.
 *
 * =============================================================================
 * What this model represents
 * =============================================================================
 * A share certificate is an official document attesting that a specific
 * shareholder holds a specific quantity of a specific share class. It is a
 * DERIVED ARTEFACT — it reflects the state of the holding at the moment of
 * issuance but does not itself define the holding.
 *
 * The authoritative source of quantity is always:
 *
 *     ShareHolding.current_quantity
 *
 * which is itself a projection of the append-only ledger:
 *
 *     ShareHoldingEntry rows
 *
 * The certificate is downstream of both. It can be cancelled, replaced, or
 * re-issued without touching either. It can be issued for a holding whose
 * quantity later changes — the certificate records what was certified at
 * issue time, not the current quantity. If the current state matters, read
 * the holding.
 *
 * =============================================================================
 * Certificate lifecycle (D-preflight)
 * =============================================================================
 * Four states, declared in CertificateStatus (2A.5):
 *
 *     draft      — prepared but not yet issued. Non-terminal.
 *     issued     — the certificate is in force. Non-terminal.
 *     cancelled  — withdrawn. Terminal.
 *     replaced   — superseded by another certificate. Terminal.
 *
 * The transition matrix is enforced by CertificateStatus::canTransitionTo()
 * and applied by CertificateService (2A.15). This model does not enforce
 * transitions — it just stores the current state.
 *
 * In Phase 2A, newly created certificates default to `issued`
 * (config: `shareholder.certificate.default_status`). The `draft` state is
 * reserved for a future workflow that separates preparation from issuance.
 *
 * =============================================================================
 * The `replaced_by_id` link
 * =============================================================================
 * When a certificate is replaced:
 *
 *   1. The ORIGINAL certificate's `status` is set to `replaced`.
 *   2. The ORIGINAL certificate's `cancelled_at` remains null (it was not
 *      cancelled; it was replaced).
 *   3. The ORIGINAL certificate's `replaced_by_id` is set to the id of the
 *      NEW certificate.
 *   4. The NEW certificate is created with `status = issued`.
 *
 * The link is one-directional: only the replaced (older) certificate points
 * to the replacing (newer) one. If a reader needs "what did this certificate
 * replace", the reverse query is a simple lookup, but the schema does not
 * store it redundantly. This avoids the two-way consistency problem of
 * having both `replaced_by_id` and `replaces_id`.
 *
 * =============================================================================
 * Numbering (pre-flight answer)
 * =============================================================================
 * `certificate_number` is a NULLABLE string. In Phase 2A, no automatic
 * allocation is performed by the domain. The operator or a future
 * numbering CR populates the value. Two certificates may not share the
 * same non-null number (UNIQUE index).
 *
 * If the corporate evidence later requires a specific numbering scheme
 * (sequential per class, year-prefixed, register-based, etc.), a Change
 * Request will add the allocation logic and, if needed, a dedicated
 * sequence table. Until then, the column exists, is nullable, and is
 * populated by the caller.
 *
 * =============================================================================
 * Soft deletes
 * =============================================================================
 * Certificates are long-lived business artefacts. When a certificate is
 * cancelled, the row is not deleted — `status` becomes `cancelled`. When a
 * certificate is replaced, the row is not deleted — `status` becomes
 * `replaced` and `replaced_by_id` points to the replacement.
 *
 * SoftDeletes is provided as a final escape hatch for administrative
 * corrections, NOT as a normal workflow path. A soft-deleted certificate
 * that was previously `issued` should be treated as an integrity anomaly.
 * The normal lifecycle is state transitions, not deletion.
 *
 * =============================================================================
 * What this model deliberately does NOT do
 * =============================================================================
 *   - It does not change the ShareHolding balance. Certificates are
 *     downstream of the ledger (D16-CORE).
 *
 *   - It does not validate lifecycle transitions. That is the
 *     CertificateService's responsibility, using
 *     CertificateStatus::canTransitionTo().
 *
 *   - It does not allocate certificate numbers. Number allocation is not
 *     defined in Phase 2A.
 *
 *   - It does not provide PDF rendering, signing, or email delivery.
 *     Those are presentation concerns for a future phase.
 *
 *   - It does not enforce the "at most one active certificate per holding"
 *     rule at the schema level. That rule (if the business requires it)
 *     is enforced by the CertificateService inside the issuance
 *     transaction, because it is a domain invariant, not a schema
 *     constraint. See F-2A10-5 for the full reasoning.
 *
 * =============================================================================
 * Relations
 * =============================================================================
 *   shareholder()    — the shareholder to whom this certificate is issued.
 *   shareClass()     — the share class the certificate is denominated in.
 *   shareHolding()   — the holding the certificate attests to (nullable).
 *   replacedBy()     — the certificate that replaced this one (nullable).
 *   replaces()       — the certificates that this one replaced (reverse
 *                      lookup; normally at most one).
 *
 * @property int                   $id
 * @property int                   $shareholder_id
 * @property int                   $share_class_id
 * @property int|null              $share_holding_id
 * @property string|null           $certificate_number
 * @property string                $status
 * @property Carbon|null $issued_at
 * @property Carbon|null $cancelled_at
 * @property int|null              $replaced_by_id
 * @property string|null           $notes
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 *
 * @property-read Shareholder|null     $shareholder
 * @property-read ShareClass|null      $shareClass
 * @property-read ShareHolding|null    $shareHolding
 * @property-read ShareCertificate|null $replacedBy
 * @property-read Collection<int, ShareCertificate> $replaces
 *
 * @package MaaleArna\MASES\Core\Domains\Shareholder\Models
 */
final class ShareCertificate extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The database table backing the model.
     *
     * @var string
     */
    protected $table = 'share_certificates';

    /**
     * The primary key column.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Whether the primary key auto-increments.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * The primary key type.
     *
     * @var string
     */
    protected $keyType = 'int';

    /**
     * The attributes that are mass assignable.
     *
     * This list is used by CertificateService (2A.15) exclusively.
     * Controllers, requests, and resources MUST NOT construct a certificate
     * directly — they submit a request to the service, which validates the
     * lifecycle transition and persists the row.
     *
     * @var list<string>
     */
    protected $fillable = [
        'shareholder_id',
        'share_class_id',
        'share_holding_id',
        'certificate_number',
        'status',
        'issued_at',
        'cancelled_at',
        'replaced_by_id',
        'notes',
    ];

    /**
     * The attributes that should be cast.
     *
     * `status` is cast to the `CertificateStatus` enum (2A.5), so callers
     * receive an enum case and can invoke `isActive()`, `isTerminal()`, and
     * `canTransitionTo()` directly. This differs from `Shareholder`'s
     * `shareholder_type`, which is deliberately left as a string — the
     * reasoning there was to allow diagnosing unrecognised values. For
     * certificates, the lifecycle is validated before persist by the
     * service, so an unrecognised status on a persisted row is a bug that
     * should surface loudly. Casting to the enum achieves that.
     *
     * `issued_at`, `cancelled_at`, `created_at`, `updated_at`, and
     * `deleted_at` are datetime casts.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'shareholder_id'   => 'integer',
        'share_class_id'   => 'integer',
        'share_holding_id' => 'integer',
        'status'           => CertificateStatus::class,
        'issued_at'        => 'datetime',
        'cancelled_at'     => 'datetime',
        'replaced_by_id'   => 'integer',
        'created_at'       => 'datetime',
        'updated_at'       => 'datetime',
        'deleted_at'       => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * Left empty on purpose. Resources (2A.28) select fields explicitly,
     * including whether to include the localized status label.
     *
     * @var list<string>
     */
    protected $appends = [];

    // =========================================================================
    // Relations
    // =========================================================================

    /**
     * The shareholder to whom this certificate is issued.
     *
     * @return BelongsTo
     */
    public function shareholder(): BelongsTo
    {
        return $this->belongsTo(Shareholder::class, 'shareholder_id', 'id');
    }

    /**
     * The share class this certificate is denominated in.
     *
     * @return BelongsTo
     */
    public function shareClass(): BelongsTo
    {
        return $this->belongsTo(ShareClass::class, 'share_class_id', 'id');
    }

    /**
     * The share holding this certificate attests to, if any.
     *
     * Nullable because a certificate may be issued against a shareholder
     * and a class without a specific holding row having been created yet
     * (unusual, but permitted — for example when migrating historical
     * certificates where the ledger rows are reconstructed separately).
     *
     * When present, the holding is the authoritative source for the current
     * quantity; the certificate records what was certified at issue time.
     *
     * @return BelongsTo
     */
    public function shareHolding(): BelongsTo
    {
        return $this->belongsTo(ShareHolding::class, 'share_holding_id', 'id');
    }

    /**
     * The certificate that replaced this one, if any.
     *
     * Non-null only when `status = replaced`.
     *
     * @return BelongsTo
     */
    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_id', 'id');
    }

    /**
     * The certificates that this one replaced, if any.
     *
     * Normally at most one. Declared as HasMany so that a violated
     * invariant (a certificate that replaced two others) surfaces as
     * multiple rows rather than being silently truncated by a HasOne.
     *
     * @return HasMany
     */
    public function replaces(): HasMany
    {
        return $this->hasMany(self::class, 'replaced_by_id', 'id');
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope a query to certificates of a specific status.
     *
     * Accepts either the enum case or its string value, so callers do not
     * need to normalise before invoking the scope.
     *
     * @param Builder                  $query
     * @param CertificateStatus|string $status
     * @return Builder
     */
    public function scopeOfStatus(Builder $query, CertificateStatus|string $status): Builder
    {
        $value = $status instanceof CertificateStatus ? $status->value : $status;

        return $query->where('status', $value);
    }

    /**
     * Scope a query to active (issued) certificates.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', CertificateStatus::Issued->value);
    }

    /**
     * Scope a query to certificates of a specific shareholder.
     *
     * @param Builder $query
     * @param int     $shareholderId
     * @return Builder
     */
    public function scopeForShareholder(Builder $query, int $shareholderId): Builder
    {
        return $query->where('shareholder_id', $shareholderId);
    }

    /**
     * Scope a query to certificates of a specific share class.
     *
     * @param Builder $query
     * @param int     $shareClassId
     * @return Builder
     */
    public function scopeOfClass(Builder $query, int $shareClassId): Builder
    {
        return $query->where('share_class_id', $shareClassId);
    }

    /**
     * Scope a query to certificates issued against a specific holding.
     *
     * @param Builder $query
     * @param int     $shareHoldingId
     * @return Builder
     */
    public function scopeForHolding(Builder $query, int $shareHoldingId): Builder
    {
        return $query->where('share_holding_id', $shareHoldingId);
    }

    // =========================================================================
    // Domain helpers (read-only)
    // =========================================================================

    /**
     * Resolve the certificate's status as an enum case.
     *
     * Because `$casts` maps `status` directly to `CertificateStatus`, this
     * method is a convenience wrapper: it returns the same value as reading
     * `$certificate->status`. It is provided so that callers who prefer a
     * method call over a property read have a consistent entry point
     * matching the pattern used by `Shareholder::type()`.
     *
     * @return CertificateStatus
     */
    public function status(): CertificateStatus
    {
        return $this->status;
    }

    /**
     * Whether the certificate is currently in force.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /**
     * Whether the certificate has reached a terminal state.
     *
     * @return bool
     */
    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * Whether the certificate is still in draft.
     *
     * @return bool
     */
    public function isDraft(): bool
    {
        return $this->status->isDraft();
    }

    /**
     * Whether the certificate has a manually-populated number.
     *
     * @return bool
     */
    public function hasNumber(): bool
    {
        return $this->certificate_number !== null && $this->certificate_number !== '';
    }

    /**
     * Whether the certificate has been linked to a specific holding row.
     *
     * @return bool
     */
    public function isLinkedToHolding(): bool
    {
        return $this->share_holding_id !== null;
    }

    /**
     * Whether this certificate was replaced by another certificate.
     *
     * @return bool
     */
    public function wasReplaced(): bool
    {
        return $this->status === CertificateStatus::Replaced
            && $this->replaced_by_id !== null;
    }

    /**
     * Whether this certificate is a replacement of an earlier certificate.
     *
     * True if at least one other certificate row has `replaced_by_id`
     * pointing to this certificate's id. That is a reverse query, so this
     * method issues a database round trip when the `replaces` relation has
     * not been eager-loaded.
     *
     * @return bool
     */
    public function isReplacementOfAnother(): bool
    {
        return $this->replaces()->exists();
    }
}