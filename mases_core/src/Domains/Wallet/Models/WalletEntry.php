<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Wallet\Enums\ObligationComponent;
use MaaleArna\MASES\Core\Domains\Wallet\Enums\WalletEntryType;

/**
 * WalletEntry
 *
 * Eloquent model for a wallet ledger entry — the append-only ledger of the
 * Wallet domain.
 *
 * =============================================================================
 * What this model represents
 * =============================================================================
 * The authoritative record of every monetary movement in the wallet
 * domain. Every change to `Wallet.current_balance` produces exactly one
 * row here, written inside the same database transaction that updates the
 * projection.
 *
 * The ledger is the source of truth; the wallet's `current_balance` is a
 * projection of it (Architecture §4.6). If the two ever disagree, the
 * ledger is authoritative and the projection is rebuilt from it.
 *
 * Each entry records:
 *
 *   entry_type         — one of the four `WalletEntryType` cases (2B.4).
 *                        Describes WHAT kind of business event produced
 *                        the entry.
 *
 *   component          — nullable; set only for `obligation_recorded`
 *                        entries. When set, one of the six
 *                        `ObligationComponent` cases (2B.3).
 *
 *   signed_quantity    — the SIGNED change applied to the wallet. Positive
 *                        for credit movements, negative for debit
 *                        movements. The sign encodes direction; there is
 *                        no separate direction column.
 *
 *   balance_before     — the value of `Wallet.current_balance`
 *                        immediately before this entry.
 *
 *   balance_after      — the value of `Wallet.current_balance`
 *                        immediately after this entry.
 *
 *   reference_type     — the short class basename of the source object
 *                        (`Obligation`, `Payment`, `Wallet`, or
 *                        `WalletEntry`). Permitted combinations per entry
 *                        type are enumerated in Architecture §5.3 and
 *                        enforced by the services at write time.
 *
 *   reference_id       — the identifier of the source object.
 *
 *   reversed_entry_id  — set on a `reversal` entry. Points back at the
 *                        original entry being reversed.
 *
 *   occurred_at        — the business time of the event. Distinct from
 *                        `created_at`: an administrative backfill may
 *                        insert an entry now for an event that occurred
 *                        earlier. Ledger reads order by this column.
 *
 *   created_by         — the user who recorded the entry. Nullable for
 *                        system-generated entries.
 *
 *   description        — optional free text.
 *
 * =============================================================================
 * The ledger invariant (Architecture §4.6)
 * =============================================================================
 * Two invariants govern the ledger:
 *
 *     Entry invariant:
 *         balance_before + signed_quantity = balance_after
 *
 *     Projection invariant:
 *         wallet.current_balance = latest committed wallet_entries.balance_after
 *
 * Both hold for every entry ever written. The entry invariant is verified
 * per-row; the projection invariant is verified across the ledger. Both
 * are exercised by the WalletService unit tests (2B.57).
 *
 * =============================================================================
 * Immutability (Architecture §3.7)
 * =============================================================================
 * This model is FULLY IMMUTABLE once recorded. A posted entry is never
 * modified:
 *
 *   - The table (2B.54) declares NO `updated_at` column, and the model
 *     sets `$timestamps = false`. Eloquent does not manage timestamps on
 *     this row.
 *
 *   - `created_at` is written explicitly by WalletService (2B.15) at
 *     insert time.
 *
 *   - The `booted()` guard throws `LogicException` on ANY update attempt.
 *     There is no attribute whose mutation is legitimate; the ledger is
 *     append-only by construction.
 *
 *   - The domain code provides no path that updates or deletes a posted
 *     entry. Corrections flow through `reversal` entries: a new row whose
 *     `reversed_entry_id` points at the original, and whose signed
 *     quantity is the numeric negation of the original's.
 *
 * If a future business requirement genuinely needs to modify a posted
 * entry, the correct response is a Change Request that adds a new entry
 * type or a new reversal pattern — never a mutation of an existing row.
 *
 * =============================================================================
 * Reversal linkage (Architecture §4.3)
 * =============================================================================
 * The `reversed_entry_id` column is a nullable self-reference. It is
 * non-null only on a `reversal` entry, and it points at the original
 * posted entry that the reversal undoes.
 *
 * The link is ONE-DIRECTIONAL as a COLUMN: only the reversal points at the
 * original; the original carries no "reversed by" column. Two relations
 * expose the linkage in both directions:
 *
 *     reversedEntry()  — the original that this entry reverses (BelongsTo).
 *                        Non-null only on a `reversal` entry.
 *
 *     reversals()      — the entries that reverse this one (HasMany).
 *                        Empty for a non-reversed entry; contains at most
 *                        one element, because the "no double reversal"
 *                        rule allows each entry to be reversed at most
 *                        once.
 *
 * On delete of the original: RESTRICT (Step 6 v1.1.0 amendment S6-B).
 * The FK prevents silent severing of a reversal's provenance link.
 *
 * =============================================================================
 * No direction column (Architecture §4.2)
 * =============================================================================
 * The direction of the movement is encoded by the SIGN of
 * `signed_quantity`. Positive is a credit movement (per the member-position
 * convention: the organization holds credit for the member); negative is a
 * debit movement (the member owes). Zero is not produced by any Phase 2B
 * operation — the services reject zero-amount entries — but the helpers
 * below handle it as neither-credit-nor-debit rather than imposing a
 * direction on a value that does not carry one.
 *
 * Adding a `direction` column would duplicate state that is already
 * unambiguous from the sign and could drift out of agreement with it.
 *
 * =============================================================================
 * Enum casts
 * =============================================================================
 * `entry_type` is cast to `WalletEntryType` (2B.4). `component` is cast to
 * `ObligationComponent` (2B.3), and remains null when the column is null.
 *
 * Both casts follow the documented rationale for `Obligation.component`
 * (2B.6 §"Enum cast"): the vocabularies are CLOSED, enforced at write time
 * by the services, and an unrecognised value in either column indicates a
 * deployment defect or a manual DB edit. Failing loudly on read is
 * preferable to silently passing an unrecognised string through the
 * domain.
 *
 * This is a deliberate divergence from the 2A precedent on
 * `ShareHoldingEntry.entry_type`, which is not enum-cast because its
 * vocabulary lives in configuration rather than in an enum. The 2B
 * vocabulary is an enum; the cast is the correct expression of that.
 *
 * =============================================================================
 * Relations
 * =============================================================================
 *   wallet()         — the owning Wallet (BelongsTo).
 *   createdBy()      — the recording user (BelongsTo, nullable).
 *   reversedEntry()  — the entry this one reverses (BelongsTo, nullable).
 *   reversals()      — the entries that reverse this one (HasMany).
 *
 * =============================================================================
 * What this model deliberately does NOT do
 * =============================================================================
 *   - It does not maintain `Wallet.current_balance`. WalletService owns
 *     that projection.
 *
 *   - It does not enumerate the permitted `reference_type` per entry type
 *     as a method. The mapping in Architecture §5.3 is a service-layer
 *     concern; encoding it in the model would couple the ledger to a
 *     serialization choice.
 *
 *   - It does not soft-delete. The ledger is append-only by construction;
 *     a soft-deleted entry would be a conceptual contradiction.
 *
 *   - It does not expose a `direction()` enum accessor. The 2B architecture
 *     does not declare a direction enum, and the signed quantity is
 *     self-documenting. Two boolean helpers (`isCredit()`, `isDebit()`)
 *     express the sign convention at the call site without introducing a
 *     new vocabulary.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Architecture v1.2.0 §3.7     — WalletEntry ledger table
 *   Architecture v1.2.0 §4.2     — Sign convention
 *   Architecture v1.2.0 §4.3     — Corrections
 *   Architecture v1.2.0 §4.6     — Balance projection invariant
 *   Architecture v1.2.0 §5.1     — Entry-type vocabulary
 *   Architecture v1.2.0 §5.3     — Permitted reference_type combinations
 *   Architecture v1.2.0 §6.1     — Obligation projection
 *   Architecture v1.2.0 §6.2     — Payment projection
 *   Architecture v1.2.0 §19      — Q8 resolution
 *   Step 5 (manifest) 2B.10      — This file
 *   Step 6 v1.1.0 §3.7           — wallet_entries table specification
 *   Step 6 v1.1.0 S6-B           — reversed_entry_id uses RESTRICT
 *
 * @property int                       $id
 * @property int                       $wallet_id
 * @property WalletEntryType           $entry_type
 * @property ObligationComponent|null  $component
 * @property string                    $signed_quantity
 * @property string                    $balance_before
 * @property string                    $balance_after
 * @property string|null               $reference_type
 * @property string|null               $reference_id
 * @property int|null                  $reversed_entry_id
 * @property \Carbon\Carbon            $occurred_at
 * @property \Carbon\Carbon|null       $created_at
 * @property int|null                  $created_by
 * @property string|null               $description
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Models
 * @version 1.0.0
 */
final class WalletEntry extends Model
{
    use HasFactory;

    /**
     * The database table backing the model.
     *
     * @var string
     */
    protected $table = 'wallet_entries';

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
     * Disable Eloquent's automatic timestamp management.
     *
     * The table has no `updated_at` column, and `created_at` is written
     * explicitly by WalletService (2B.15) at insert time. Enabling
     * `$timestamps` would cause Eloquent to attempt to set an
     * `updated_at` that does not exist, producing a column-not-found
     * error on save.
     *
     * This mirrors the 2A `ShareHoldingEntry` model's posture on the same
     * class of ledger table.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * Order mirrors the migration's column order (2B.54). Every attribute
     * is set once, at insert time, by WalletService. There is no update
     * path.
     *
     * `created_at` IS fillable — it is written explicitly (the model does
     * not manage timestamps).
     *
     * @var list<string>
     */
    protected $fillable = [
        'wallet_id',
        'entry_type',
        'component',
        'signed_quantity',
        'balance_before',
        'balance_after',
        'reference_type',
        'reference_id',
        'reversed_entry_id',
        'occurred_at',
        'created_at',
        'created_by',
        'description',
    ];

    /**
     * The attributes that should be cast.
     *
     * See the class docblock §"Enum casts" for the rationale behind the
     * enum casts on `entry_type` and `component`. The three monetary
     * columns are cast as `decimal:4` so that reads return the exact
     * decimal strings that bcmath operates on.
     *
     * `created_at` is cast to `datetime` so that reads return a Carbon
     * instance even though the column is written explicitly. This is a
     * read-side convenience; it does not cause Eloquent to manage the
     * column (see `$timestamps = false`).
     *
     * @var array<string, string>
     */
    protected $casts = [
        'wallet_id'         => 'integer',
        'entry_type'        => WalletEntryType::class,
        'component'         => ObligationComponent::class,
        'signed_quantity'   => 'decimal:4',
        'balance_before'    => 'decimal:4',
        'balance_after'     => 'decimal:4',
        'reversed_entry_id' => 'integer',
        'occurred_at'       => 'datetime',
        'created_at'        => 'datetime',
        'created_by'        => 'integer',
    ];

    /**
     * The attributes that should be hidden from array/JSON serialization.
     *
     * Empty. Nothing on the ledger row is secret in the ordinary sense.
     * The resource layer (2B.36) selects which fields to expose.
     *
     * @var list<string>
     */
    protected $hidden = [];

    /**
     * The accessors to append to the model's array form.
     *
     * Empty on purpose. The model does not force-serialize any computed
     * attribute. Resources select fields explicitly.
     *
     * @var list<string>
     */
    protected $appends = [];

    // =========================================================================
    // Lifecycle — full immutability
    // =========================================================================

    /**
     * Register the model's event listeners.
     *
     * Rejects ANY update attempt and ANY delete attempt. WalletEntry is
     * fully immutable once recorded: the ledger is append-only by
     * construction, and no attribute has a legitimate mutation path.
     *
     * `creating` is intentionally NOT guarded — creating an entry is the
     * sole legitimate write, performed by WalletService (2B.15) inside its
     * transactional write path.
     *
     * The `deleting` guard is included even though the domain provides no
     * delete path: a defense-in-depth layer prevents an accidental
     * `->delete()` call from silently removing a ledger row.
     *
     * Note on Eloquent mechanics: the `updating` event fires when `save()`
     * detects at least one dirty attribute. Because the table has no
     * `updated_at`, the event fires only when a user-visible attribute
     * differs from its loaded value. That is the intended semantics — the
     * ledger has no timestamp column that Eloquent might mark dirty on
     * its own.
     *
     * Note on the limit of these guards: the checks apply to
     * Eloquent-mediated writes (`save()`, `update()`, `fill()->save()`,
     * `increment()`, `decrement()`, `delete()`). They do NOT intercept
     * raw query-builder operations
     * (`Model::where(...)->update(...)`, `->delete()`). Those bypass model
     * events entirely. The service-layer discipline — WalletService
     * exposes no update or delete path for ledger rows — is the ultimate
     * guarantee against such bypasses.
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::updating(static function (WalletEntry $entry): void {
            throw new LogicException(
                sprintf(
                    'WalletEntry rows are immutable once recorded '
                    . '(Architecture §3.7). The wallet ledger is append-only. '
                    . 'To correct a posted entry, write a reversal entry '
                    . 'that points at the original via reversed_entry_id — '
                    . 'do not modify the original. '
                    . 'Attempted update on wallet_entries.id=%s.',
                    (string) $entry->getKey(),
                )
            );
        });

        static::deleting(static function (WalletEntry $entry): void {
            throw new LogicException(
                sprintf(
                    'WalletEntry rows cannot be deleted '
                    . '(Architecture §3.7). The wallet ledger is append-only. '
                    . 'Corrections flow through reversal entries; deletions '
                    . 'are not an application operation. '
                    . 'Attempted delete on wallet_entries.id=%s.',
                    (string) $entry->getKey(),
                )
            );
        });
    }

    // =========================================================================
    // Relations
    // =========================================================================

    /**
     * The owning wallet.
     *
     * An entry belongs to exactly one wallet. The wallet carries the tenant
     * scope; this entry resolves tenant transitively through its wallet.
     *
     * @return BelongsTo
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'wallet_id', 'id');
    }

    /**
     * The user who recorded this entry.
     *
     * Nullable at the schema level because system-generated entries (a
     * future scheduled accrual, an automated reconciliation) have no human
     * actor. Null is a legitimate state, not an error.
     *
     * @return BelongsTo
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'id');
    }

    /**
     * The original entry that this entry reverses.
     *
     * Non-null only on a `reversal` entry. On every other entry type, the
     * attribute is null and this relation resolves to null.
     *
     * The relation is read-only; the ledger does not support traversal in
     * a mutating direction.
     *
     * @return BelongsTo
     */
    public function reversedEntry(): BelongsTo
    {
        return $this->belongsTo(
            self::class,
            'reversed_entry_id',
            'id',
        );
    }

    /**
     * The entries that reverse this one.
     *
     * Empty for a non-reversed entry. Contains at most one element for a
     * reversed entry, because the "no double reversal" rule
     * (Architecture §4.3) permits each entry to be reversed at most once.
     * The HasMany shape is retained over HasOne so that the relation
     * remains correct if a future CR relaxes the single-reversal rule.
     *
     * The relation does not filter or aggregate. Callers that need to know
     * whether an entry has been reversed either load this relation or use
     * the `reversalsOf()` scope with the entry's id.
     *
     * @return HasMany
     */
    public function reversals(): HasMany
    {
        return $this->hasMany(
            self::class,
            'reversed_entry_id',
            'id',
        );
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope a query to a specific wallet.
     *
     * The primary wallet-scoping entry point for ledger entries, mirroring
     * the pattern used by the Wallet model's `scopeForTenant()` and by the
     * sibling models' `scopeForWallet()`. Consumers that read the ledger
     * through a wallet always go through a wallet id, so this scope is the
     * tenant-isolation entry point for the ledger (Step 7 v1.1.0 §C.6).
     *
     * @param Builder $query
     * @param int     $walletId
     * @return Builder
     */
    public function scopeForWallet(Builder $query, int $walletId): Builder
    {
        return $query->where('wallet_id', $walletId);
    }

    /**
     * Scope a query to a specific entry type.
     *
     * Accepts either the enum case or its string value, so callers do not
     * need to normalise before invoking the scope. Mirrors the pattern used
     * by `Obligation::scopeOfComponent()`.
     *
     * @param Builder                $query
     * @param WalletEntryType|string $type
     * @return Builder
     */
    public function scopeOfType(Builder $query, WalletEntryType|string $type): Builder
    {
        $value = $type instanceof WalletEntryType ? $type->value : $type;

        return $query->where('entry_type', $value);
    }

    /**
     * Scope a query to the entries that reverse a given entry.
     *
     * Equivalent to reading the `reversals()` relation of the given entry
     * directly, expressed as a scope so that a reverse lookup does not
     * require loading the original model. Used by the double-reversal check
     * in WalletService (2B.15) and by any reporting surface that needs to
     * know whether an entry has been reversed.
     *
     * @param Builder $query
     * @param int     $originalEntryId
     * @return Builder
     */
    public function scopeReversalsOf(Builder $query, int $originalEntryId): Builder
    {
        return $query->where('reversed_entry_id', $originalEntryId);
    }

    /**
     * Scope a query to chronological order.
     *
     * Orders by `occurred_at` ascending, then by `id` ascending as a
     * deterministic tiebreaker for entries sharing a business timestamp.
     * This is the canonical ledger read order; consumers that render the
     * ledger for a wallet (the statement projection, the wallet resource)
     * use this scope rather than imposing their own ordering.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeChronological(Builder $query): Builder
    {
        return $query
            ->orderBy('occurred_at')
            ->orderBy('id');
    }

    // =========================================================================
    // Domain helpers
    // =========================================================================

    /**
     * Whether the entry is a reversal of another entry.
     *
     * @return bool
     */
    public function isReversal(): bool
    {
        return $this->entry_type === WalletEntryType::Reversal;
    }

    /**
     * Whether the entry has a linked original entry.
     *
     * True only on `reversal` entries whose `reversed_entry_id` is non-null.
     * A defensive check: for a well-formed ledger, a reversal always has a
     * non-null `reversed_entry_id`, and a non-reversal always has null.
     * The helper allows a reader to branch on the field without inspecting
     * it directly, and to detect a malformed row written by an external
     * process.
     *
     * @return bool
     */
    public function hasReversedEntry(): bool
    {
        return $this->reversed_entry_id !== null;
    }

    /**
     * Whether the entry carries a component tag.
     *
     * True on `obligation_recorded` entries, whose `component` column
     * carries the obligation's `ObligationComponent`. False on every other
     * entry type.
     *
     * @return bool
     */
    public function hasComponent(): bool
    {
        return $this->component !== null;
    }

    /**
     * Whether the entry moves the wallet in the credit direction.
     *
     * "Credit" means positive signed quantity, per the member-position
     * sign convention (Architecture §4.2): the organization holds credit
     * for the member.
     *
     * Compares against the literal '0' at scale 4 via bcmath, avoiding
     * float rounding.
     *
     * @return bool
     */
    public function isCredit(): bool
    {
        return bccomp((string) $this->signed_quantity, '0', 4) > 0;
    }

    /**
     * Whether the entry moves the wallet in the debit direction.
     *
     * "Debit" means negative signed quantity: the member owes the
     * organization. See `isCredit()` for the sign convention rationale.
     *
     * @return bool
     */
    public function isDebit(): bool
    {
        return bccomp((string) $this->signed_quantity, '0', 4) < 0;
    }

    /**
     * Whether the entry's ledger triple is internally consistent.
     *
     * Verifies `balance_before + signed_quantity = balance_after` with
     * bcmath at scale 4. This is the per-row entry invariant from
     * Architecture §4.6.
     *
     * Returns true for every well-formed row written by WalletService.
     * A false return indicates a data anomaly — a row written by an
     * external process that bypassed the service — and should be treated
     * as a data-integrity alarm rather than a normal case.
     *
     * @return bool
     */
    public function satisfiesBalanceInvariant(): bool
    {
        $before = (string) $this->balance_before;
        $delta  = (string) $this->signed_quantity;
        $after  = (string) $this->balance_after;

        return bccomp(bcadd($before, $delta, 4), $after, 4) === 0;
    }

    /**
     * Whether the entry carries a reference to a source object.
     *
     * The entry stores the reference as the pair
     * `reference_type` + `reference_id`. Both must be non-null for the
     * pair to be meaningful.
     *
     * @return bool
     */
    public function hasReference(): bool
    {
        return $this->reference_type !== null && $this->reference_id !== null;
    }

    /**
     * The business time of the entry, as a Carbon instance.
     *
     * Convenience accessor named for the domain — `occurredAt()` reads
     * more naturally than `$entry->occurred_at` in statement-rendering
     * code. The underlying attribute is `occurred_at`; the cast ensures
     * it is always a Carbon instance.
     *
     * @return Carbon
     */
    public function occurredAt(): Carbon
    {
        return $this->occurred_at;
    }
}