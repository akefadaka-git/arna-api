<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Auth\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\HasApiTokens;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Domains\People\Models\Person;
use MaaleArna\MASES\Core\Tenancy\Models\TenantMembership;
use MaaleArna\MASES\Core\Authorization\Models\Role;
use MaaleArna\MASES\Core\Authorization\Models\Permission;

/**
 * User Model
 *
 * The central identity authority for all MASES users, across every
 * tenant and every application.
 *
 * =============================================================================
 * Historical changes are recorded in Git.
 * @property int           $id
 * @property string|null   $first_name_am            First name in Amharic
 * @property string|null   $first_name_en            First name in English
 * @property string|null   $father_name_am           Father name in Amharic
 * @property string|null   $father_name_en           Father name in English
 * @property string|null   $grandfather_name_am      Grandfather name in Amharic
 * @property string|null   $grandfather_name_en      Grandfather name in English
 * @property string|null   $username                 Login username (nullable, unique)
 * @property string        $email                    User email (unique)
 * @property string|null   $phone                    User phone number (indexed)
 * @property string|null   $address                  Residential address
 * @property int|null      $default_tenant_id        Default tenant ID
 * @property string        $password                 Hashed password
 * @property string|null   $remember_token           Remember token
 * @property \Carbon\Carbon|null $email_verified_at  Email verification timestamp
 * @property \Carbon\Carbon|null $last_login_at      Last successful login timestamp
 * @property string|null   $last_login_ip            Last successful login source IP
 * @property \Carbon\Carbon|null $password_changed_at Password change timestamp
 * @property string|null   $locale                   Preferred locale ('am', 'en')
 * @property bool          $is_active                Whether the user is active
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 * @property-read string   $name                     Computed full name (compatibility accessor)
 * @property-read string   $full_name                Computed full name (Amharic-first policy)
 * @property-read string   $display_name             Computed display name (locale-aware)
 *
 * @package MaaleArna\MASES\Core\Auth\Models
 * @version 1.2.1
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'users';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        // Ethiopian bilingual name components.
        'first_name_am',
        'first_name_en',
        'father_name_am',
        'father_name_en',
        'grandfather_name_am',
        'grandfather_name_en',

        // Login identifiers and contact details.
        'username',
        'email',
        'phone',
        'address',

        // Authentication.
        'password',
        'default_tenant_id',

        // Preferences and lifecycle.
        'locale',
        'is_active',
        'email_verified_at',

        // Login audit (populated by recordSuccessfulLogin and
        // recordPasswordChange, not by mass assignment in normal
        // flows; included here so the seeder and administrative
        // tooling can set them where needed).
        'last_login_at',
        'last_login_ip',
        'password_changed_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'phone_verified_phone_hash',
        'totp_secret',
        'totp_pending_secret',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at'   => 'datetime',
        'phone_verified_at'   => 'datetime',
        'totp_enabled_at'     => 'datetime',
        'totp_last_used_step' => 'integer',
        'last_login_at'       => 'datetime',
        'password_changed_at' => 'datetime',
        'created_at'          => 'datetime',
        'updated_at'          => 'datetime',
        'deleted_at'          => 'datetime',
        'is_active'           => 'boolean',
    ];

    /**
     * Create a new factory instance for the model.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory<static>
     */
    protected static function newFactory()
    {
        return UserFactory::new();
    }

    // =========================================================================
    // Static lookup helpers
    // =========================================================================

    /**
     * Find a user by any of the three login identifiers.
     *
     * Resolution order:
     *   1. If the identifier contains "@" and parses as an email,
     *      look up by `email` — normalized to lowercase.
     *   2. Otherwise, look up by `username` — normalized to
     *      lowercase.
     *   3. If neither matches, look up by `phone` — trimmed but not
     *      case-folded (phone numbers have no case).
     *
     * Returns null when nothing matches. The caller is responsible
     * for producing the appropriate error response; the null return
     * is not itself an error.
     *
     * The three lookups run as a single query — the OR conditions
     * are evaluated together. On a table with the three indexed
     * columns (`email` unique, `username` unique, `phone` non-
     * unique), the query is a constant-time lookup regardless of
     * table size.
     *
     * @param  string $identifier
     * @return self|null
     */
    public static function findForLogin(string $identifier): ?self
    {
        $trimmed = trim($identifier);

        if ($trimmed === '') {
            return null;
        }

        $lowered = strtolower($trimmed);

        /** @var self|null $user */
        $user = self::query()
            ->where(function (Builder $query) use ($lowered, $trimmed): void {
                $query
                    ->where('email', $lowered)
                    ->orWhere('username', $lowered)
                    ->orWhere('phone', $trimmed);
            })
            ->first();

        return $user;
    }

    // =========================================================================
    // Relationships
    // =========================================================================

    /**
     * Get the user's default tenant.
     *
     * @return BelongsTo<Tenant, self>
     */
    public function defaultTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'default_tenant_id');
    }

    /**
     * Get all tenants the user belongs to.
     *
     * @return BelongsToMany<Tenant>
     */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(
            Tenant::class,
            'tenant_memberships',
            'user_id',
            'tenant_id'
        )->withTimestamps();
    }

    /**
     * Get tenants for which this user has an active membership.
     *
     * Keep tenants() unfiltered for administration and reporting; access
     * checks must use this status-aware relation.
     *
     * @return BelongsToMany<Tenant>
     */
    public function activeTenants(): BelongsToMany
    {
        return $this->belongsToMany(
            Tenant::class,
            'tenant_memberships',
            'user_id',
            'tenant_id'
        )
            ->withPivot('status')
            ->wherePivot('status', TenantMembership::STATUS_ACTIVE)
            ->withTimestamps();
    }

    /**
     * Get the membership records for this user.
     *
     * @return HasMany<TenantMembership>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class, 'user_id');
    }

    /**
     * Get all roles assigned to the user.
     *
     * @return BelongsToMany<Role>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Role::class,
            'user_has_roles',
            'user_id',
            'role_id'
        )->withPivot('assigned_at', 'assigned_by');
    }

    /**
     * Get all permissions directly assigned to the user.
     *
     * @return BelongsToMany<Permission>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            Permission::class,
            'user_has_permissions',
            'user_id',
            'permission_id'
        )->withPivot('assigned_at', 'assigned_by');
    }

    /**
     * The optional canonical natural-person profile for this account.
     *
     * This relation does not grant tenant membership or domain authority.
     */
    public function person(): HasOne
    {
        return $this->hasOne(Person::class, 'user_id');
    }

    // =========================================================================
    // Permission & role checks
    // =========================================================================

    /**
     * Check if the user has a specific role.
     *
     * @param string   $roleSlug
     * @param int|null $tenantId Optional tenant context.
     * @return bool
     */
    public function hasRole(string $roleSlug, ?int $tenantId = null): bool
    {
        return $this->roles()
            ->where('slug', $roleSlug)
            ->when($tenantId !== null, function ($query) use ($tenantId) {
                $query->where(function ($q) use ($tenantId) {
                    $q->where('tenant_id', $tenantId)
                      ->orWhereNull('tenant_id');
                });
            })
            ->exists();
    }

    /**
     * Check if the user has a specific permission (directly or via roles).
     *
     * @param string   $permissionSlug
     * @param int|null $tenantId Optional tenant context.
     * @return bool
     */
    public function hasPermission(string $permissionSlug, ?int $tenantId = null): bool
    {
        // Check direct permissions first.
        $hasDirect = $this->permissions()
            ->where('slug', $permissionSlug)
            ->exists();

        if ($hasDirect) {
            return true;
        }

        // Check permissions via roles.
        return $this->roles()
            ->whereHas('permissions', function ($query) use ($permissionSlug) {
                $query->where('slug', $permissionSlug);
            })
            ->when($tenantId !== null, function ($query) use ($tenantId) {
                $query->where(function ($q) use ($tenantId) {
                    $q->where('tenant_id', $tenantId)
                      ->orWhereNull('tenant_id');
                });
            })
            ->exists();
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope a query to only include active users.
     *
     * @param  Builder $query
     * @return Builder
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to filter by user email.
     *
     * @param  Builder $query
     * @param  string  $email
     * @return Builder
     */
    public function scopeByEmail(Builder $query, string $email): Builder
    {
        return $query->where('email', strtolower(trim($email)));
    }

    /**
     * Scope a query to filter by username.
     *
     * @param  Builder $query
     * @param  string  $username
     * @return Builder
     */
    public function scopeByUsername(Builder $query, string $username): Builder
    {
        return $query->where('username', strtolower(trim($username)));
    }

    /**
     * Scope a query to filter by phone number.
     *
     * Phone numbers are not case-folded (they have no case), but
     * they are trimmed. Further normalization — stripping
     * separators, converting to E.164 — is the responsibility of
     * whatever process wrote the phone number to the database, not
     * the query layer.
     *
     * @param  Builder $query
     * @param  string  $phone
     * @return Builder
     */
    public function scopeByPhone(Builder $query, string $phone): Builder
    {
        return $query->where('phone', trim($phone));
    }

    // =========================================================================
    // Status helpers
    // =========================================================================

    /**
     * Determine if the user is active.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->is_active === true;
    }

    /**
     * Determine if the user has verified their email.
     *
     * @return bool
     */
    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    /**
     * Determine whether the currently stored phone value is the one verified.
     *
     * Keeping a digest of the verified value prevents a phone edit from
     * silently inheriting verification for a different number.
     */
    public function hasVerifiedPhone(): bool
    {
        $phone = trim((string) $this->phone);
        $verifiedHash = $this->phone_verified_phone_hash;

        return $phone !== ''
            && $this->phone_verified_at !== null
            && is_string($verifiedHash)
            && hash_equals($verifiedHash, hash('sha256', $phone));
    }

    /**
     * Mark the user's email as verified.
     *
     * @return bool
     */
    public function markEmailAsVerified(): bool
    {
        if ($this->hasVerifiedEmail()) {
            return true;
        }

        $this->email_verified_at = now();
        return $this->save();
    }

    /**
     * Get the email address that should be used for verification.
     *
     * @return string
     */
    public function getEmailForVerification(): string
    {
        return $this->email;
    }

    /**
     * Determine if the user has a username set.
     *
     * @return bool
     */
    public function hasUsername(): bool
    {
        return $this->username !== null && $this->username !== '';
    }

    // =========================================================================
    // Login auditing
    // =========================================================================

    /**
     * Record a successful login.
     *
     * Called by the AuthController immediately after authentication
     * succeeds. Persists the timestamp and the source IP.
     *
     * The method is a safe no-op when the corresponding columns do
     * not exist — it checks the schema before assigning. This lets
     * the model be deployed before the migration that adds the
     * columns, without requiring the controller to know which
     * version of the schema is running.
     *
     * Uses a direct assignment and `save()` rather than `update()`
     * so that the User model's `updated_at` timestamp reflects the
     * change. A login is a meaningful event on the user row, and
     * the timestamp should advance.
     *
     * @param  string $ip Source IP address, in IPv4 or IPv6 form.
     * @return void
     */
    public function recordSuccessfulLogin(string $ip): void
    {
        if (!Schema::hasColumn($this->getTable(), 'last_login_at')) {
            return;
        }

        $this->last_login_at = now();

        if (Schema::hasColumn($this->getTable(), 'last_login_ip')) {
            $this->last_login_ip = $ip;
        }

        $this->save();
    }

    /**
     * Record a password change.
     *
     * Called by any flow that changes the user's password: the reset-
     * password endpoint, an administrative reset, or a self-service
     * change from a profile page.
     *
     * The timestamp is separate from `updated_at` because a password
     * change is a security-relevant event that should survive
     * subsequent, unrelated updates to the user row (an address
     * change, a locale change). `updated_at` moves for any of those;
     * `password_changed_at` moves only for a password change.
     *
     * Persistence (v1.2.1): the timestamp is written to the database
     * by `$this->save()` at the end of this method. Prior to v1.2.1,
     * the assignment was held in memory only and discarded; the
     * method now matches the behaviour of `recordSuccessfulLogin()`
     * and the persistence guarantee stated in this docblock.
     *
     * Schema guard: if the `password_changed_at` column is not
     * present (migration not yet run), the method is a safe no-op.
     * This lets the model be deployed before the migration that adds
     * the column.
     *
     * @return void
     */
    public function recordPasswordChange(): void
    {
        if (!Schema::hasColumn($this->getTable(), 'password_changed_at')) {
            return;
        }

        $this->password_changed_at = now();

        $this->save();
    }

    // =========================================================================
    // Notification overrides (API-friendly, no named-route dependency)
    // =========================================================================
    //
    // Both methods wrap their work in try/catch (\Throwable) and swallow
    // the exception after logging it. This is deliberate. See the class
    // docblock §"1.2.0 (b) FAILURE ISOLATION" for the full rationale.
    //
    // In summary: notification delivery is a side effect of an API
    // request, not the request's primary purpose. A failure to build a
    // verification URL or a failure in the mail transport must not turn
    // an otherwise-successful request into a 5xx response.
    // =========================================================================

    /**
     * Send the password reset notification.
     *
     * Failure isolation (v1.2.0): any \Throwable raised while building
     * the URL or while dispatching the notification is caught, logged
     * via Log::error() with structured context, and swallowed. The
     * method returns normally.
     *
     * @param string $token
     * @return void
     */
    public function sendPasswordResetNotification($token): void
    {
        try {
            $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
            $url = $frontendUrl . '/password/reset?token=' . $token . '&email=' . urlencode($this->email);

            $this->notify(new CustomResetPasswordNotification($url));
        } catch (\Throwable $e) {
            Log::error('Failed to send password reset notification', [
                'user_id'   => $this->getKey(),
                'email'     => $this->email,
                'error'     => $e->getMessage(),
                'exception' => get_class($e),
            ]);
        }
    }

    /**
     * Send the email verification notification.
     *
     * Failure isolation (v1.2.0): any \Throwable raised while building
     * the signed verification URL or while dispatching the notification
     * is caught, logged via Log::error() with structured context, and
     * swallowed. The method returns normally.
     *
     * Route name (v1.2.0): the signed URL is generated against
     * `api.auth.verify-email`, which is the route that exists in
     * routes/api.php. The prior value `verification.verify` refers to a
     * route name that this platform does not register — see the class
     * docblock §"1.2.0 (a) ROUTE NAME CORRECTION" for the full detail.
     *
     * @return void
     */
    public function sendEmailVerificationNotification(): void
    {
        try {
            $verificationUrl = URL::temporarySignedRoute(
                'api.auth.verify-email',
                now()->addMinutes(config('auth.verification.expire', 60)),
                [
                    'id' => $this->getKey(),
                    'hash' => sha1($this->getEmailForVerification()),
                ]
            );

            $this->notify(new CustomVerifyEmailNotification($verificationUrl));
        } catch (\Throwable $e) {
            Log::error('Failed to send email verification notification', [
                'user_id'   => $this->getKey(),
                'email'     => $this->email,
                'error'     => $e->getMessage(),
                'exception' => get_class($e),
            ]);
        }
    }

    // =========================================================================
    // Name accessors
    // =========================================================================

    /**
     * Get the user's `name` attribute — compatibility accessor.
     *
     * Laravel and many generic serializers expect a `name` attribute
     * on Authenticatable models. MASES stores Ethiopian names in six
     * separate bilingual columns, so a plain `name` column does not
     * and must not exist.
     *
     * This accessor projects the computed `full_name` value onto the
     * conventional `name` attribute:
     *
     *     $user->name === $user->full_name
     *
     * Read-only by design. Writing a single string cannot be
     * losslessly decomposed into six bilingual, three-component name
     * fields without inventing a splitting policy that has not been
     * approved. Callers that need to write names must assign the
     * specific `first_name_*`, `father_name_*`, and
     * `grandfather_name_*` attributes directly.
     *
     * @return string
     */
    public function getNameAttribute(): string
    {
        return $this->getFullNameAttribute();
    }

    /**
     * Get the user's full name using the Ethiopian naming convention.
     * Format: FirstName FatherName GrandfatherName.
     *
     * Preference order per component follows the MASES Amharic-first
     * policy: use the Amharic value when present, fall back to the
     * English value. Missing components are omitted; the return is
     * never "  " with stranded spaces — the array_filter removes
     * nulls and empty strings before the join.
     *
     * @return string
     */
    public function getFullNameAttribute(): string
    {
        $parts = [
            $this->first_name_am ?? $this->first_name_en,
            $this->father_name_am ?? $this->father_name_en,
            $this->grandfather_name_am ?? $this->grandfather_name_en,
        ];

        return implode(' ', array_filter($parts));
    }

    /**
     * Get the user's display name in the current locale, falling back
     * to the other language if the preferred one is missing.
     *
     * @return string
     */
    public function getDisplayNameAttribute(): string
    {
        $locale = app()->getLocale();

        if ($locale === 'am') {
            return $this->first_name_am ?? $this->first_name_en ?? $this->email;
        }

        return $this->first_name_en ?? $this->first_name_am ?? $this->email;
    }

    /**
     * Get the user's first name in the current locale.
     *
     * @return string|null
     */
    public function getFirstNameAttribute(): ?string
    {
        $locale = app()->getLocale();
        return $locale === 'am' ? $this->first_name_am : $this->first_name_en;
    }

    /**
     * Get the user's father name in the current locale.
     *
     * @return string|null
     */
    public function getFatherNameAttribute(): ?string
    {
        $locale = app()->getLocale();
        return $locale === 'am' ? $this->father_name_am : $this->father_name_en;
    }

    /**
     * Get the user's grandfather name in the current locale.
     *
     * @return string|null
     */
    public function getGrandfatherNameAttribute(): ?string
    {
        $locale = app()->getLocale();
        return $locale === 'am' ? $this->grandfather_name_am : $this->grandfather_name_en;
    }
}

// -------------------------------------------------------------------------
// Custom notification classes (same namespace for simplicity)
// -------------------------------------------------------------------------

/**
 * Custom password reset notification that accepts a full URL.
 *
 * @package MaaleArna\MASES\Core\Auth\Models
 */
class CustomResetPasswordNotification extends Notification
{
    /**
     * The password reset URL.
     *
     * @var string
     */
    protected string $resetUrl;

    /**
     * Create a new notification instance.
     *
     * @param string $resetUrl
     */
    public function __construct(string $resetUrl)
    {
        $this->resetUrl = $resetUrl;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param mixed $notifiable
     * @return array
     */
    public function via($notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param mixed $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable): \Illuminate\Notifications\Messages\MailMessage
    {
        return (new \Illuminate\Notifications\Messages\MailMessage)
            ->subject('Reset Password Notification')
            ->line('You are receiving this email because we received a password reset request for your account.')
            ->action('Reset Password', $this->resetUrl)
            ->line('If you did not request a password reset, no further action is required.');
    }
}

/**
 * Custom email verification notification that accepts a full URL.
 *
 * @package MaaleArna\MASES\Core\Auth\Models
 */
class CustomVerifyEmailNotification extends Notification
{
    /**
     * The verification URL.
     *
     * @var string
     */
    protected string $verificationUrl;

    /**
     * Create a new notification instance.
     *
     * @param string $verificationUrl
     */
    public function __construct(string $verificationUrl)
    {
        $this->verificationUrl = $verificationUrl;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param mixed $notifiable
     * @return array
     */
    public function via($notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param mixed $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable): \Illuminate\Notifications\Messages\MailMessage
    {
        return (new \Illuminate\Notifications\Messages\MailMessage)
            ->subject('Verify Email Address')
            ->line('Please click the button below to verify your email address.')
            ->action('Verify Email', $this->verificationUrl)
            ->line('If you did not create an account, no further action is required.');
    }
}