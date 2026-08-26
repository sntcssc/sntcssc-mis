<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Actions\Support\UrnGenerator;
use App\Concerns\Auditable;
use App\Concerns\HasTeams;
use App\Services\FileUploadService;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string|null $uuid
 * @property string|null $urn
 * @property string $name
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string $email
 * @property string|null $phone
 * @property string|null $whatsapp_no
 * @property Carbon|null $dob
 * @property string|null $gender
 * @property string|null $tenth_roll
 * @property string|null $id_type
 * @property string|null $id_number
 * @property string|null $designation
 * @property string $status
 * @property string|null $avatar
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $phone_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property int|null $current_team_id
 * @property Carbon|null $last_login_at
 * @property string|null $last_login_ip
 * @property int $failed_login_attempts
 * @property Carbon|null $locked_untill
 * @property int|null $deleted_by
 * @property int|null $updated_by
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Team|null $currentTeam
 * @property-read Collection<int, Team> $ownedTeams
 * @property-read Collection<int, Membership> $teamMemberships
 * @property-read Collection<int, Team> $teams
 * @property-read User|null $deletedByUser
 * @property-read User|null $updatedByUser
 * @property-read User|null $createdByUser
 */
#[Fillable([
    'uuid',
    'urn',
    'name',
    'first_name',
    'last_name',
    'email',
    'phone',
    'whatsapp_no',
    'dob',
    'gender',
    'tenth_roll',
    'id_type',
    'id_number',
    'designation',
    'status',
    'avatar',
    'password',
    'phone_verified_at',
    'email_verified_at',
    'current_team_id',
    'last_login_at',
    'last_login_ip',
    'failed_login_attempts',
    'locked_untill',
    'deleted_by',
    'updated_by',
    'created_by',
])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasFactory, HasRoles, HasTeams, Notifiable, PasskeyAuthenticatable, SoftDeletes, TwoFactorAuthenticatable {
        HasTeams::teams insteadof HasRoles;
        HasRoles::teams as permissionTeams;
    }

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if (empty($user->uuid)) {
                $user->uuid = (string) Str::uuid();
            }

            if (empty($user->urn)) {
                $user->urn = UrnGenerator::generate();
            }

            if (empty($user->name) && (! empty($user->first_name) || ! empty($user->last_name))) {
                $user->name = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));
            } elseif (! empty($user->name) && empty($user->first_name)) {
                $parts = explode(' ', trim($user->name), 2);
                $user->first_name = $parts[0] ?? '';
                $user->last_name = $parts[1] ?? '';
            }

            if (empty($user->status)) {
                $user->status = 'active';
            }

            if (auth()->check() && empty($user->created_by)) {
                $user->created_by = auth()->id();
            }
        });

        static::updating(function (User $user) {
            if (auth()->check() && ! $user->isDirty('updated_by')) {
                $user->updated_by = auth()->id();
            }

            if ($user->isDirty(['first_name', 'last_name']) && ! $user->isDirty('name')) {
                $user->name = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));
            }
        });

        static::deleting(function (User $user) {
            if (auth()->check() && ! $user->isForceDeleting() && empty($user->deleted_by)) {
                $user->deleted_by = auth()->id();
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'dob' => 'date',
            'last_login_at' => 'datetime',
            'locked_untill' => 'datetime',
            'failed_login_attempts' => 'integer',
        ];
    }

    /**
     * Generate next Unique Registration Number using UrnGenerator.
     */
    public static function generateNextUrn(): string
    {
        return UrnGenerator::generate();
    }

    /**
     * Creator relationship.
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Updater relationship.
     */
    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Deleter relationship.
     */
    public function deletedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    /**
     * Internal App Notifications relationship.
     *
     * @return HasMany<AppNotification>
     */
    public function appNotifications(): HasMany
    {
        return $this->hasMany(AppNotification::class, 'user_id');
    }

    /**
     * Count unread app notifications.
     */
    public function unreadAppNotificationsCount(): int
    {
        return $this->appNotifications()->unread()->count();
    }

    /**
     * Count unread live chat messages for user.
     */
    public function unreadChatMessagesCount(): int
    {
        return ChatMessageStatus::where('user_id', $this->id)
            ->where('is_read', false)
            ->where('is_deleted_for_me', false)
            ->whereHas('message', fn ($q) => $q->where('is_deleted_for_everyone', false))
            ->count();
    }

    /**
     * Count open tickets for user or system if staff.
     */
    public function openTicketsCount(): int
    {
        if ($this->hasAnyRole(['Super Administrator', 'Administrator', 'Staff'])) {
            return Ticket::whereIn('status', [Ticket::STATUS_OPEN, Ticket::STATUS_IN_PROGRESS, Ticket::STATUS_PENDING_USER])->count();
        }

        return Ticket::where('user_id', $this->id)
            ->whereIn('status', [Ticket::STATUS_OPEN, Ticket::STATUS_IN_PROGRESS, Ticket::STATUS_PENDING_USER])
            ->count();
    }

    /**
     * Determine if user account is locked.
     */
    public function isLocked(): bool
    {
        return ! is_null($this->locked_untill) && $this->locked_untill->isFuture();
    }

    /**
     * Lock user account until given time or default 30 minutes.
     */
    public function lockAccount(?\DateTimeInterface $until = null): bool
    {
        return $this->forceFill([
            'locked_untill' => $until ?? now()->addMinutes(30),
            'status' => 'locked',
        ])->save();
    }

    /**
     * Unlock user account and reset failed login attempts.
     */
    public function unlockAccount(): bool
    {
        return $this->forceFill([
            'locked_untill' => null,
            'failed_login_attempts' => 0,
            'status' => $this->status === 'locked' ? 'active' : $this->status,
        ])->save();
    }

    /**
     * Record a successful login.
     */
    public function recordLogin(?string $ip = null): void
    {
        $this->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $ip ?? request()->ip(),
            'failed_login_attempts' => 0,
            'locked_untill' => null,
        ])->save();
    }

    /**
     * Record a failed login attempt with auto-lockout threshold.
     */
    public function recordFailedLogin(int $maxAttempts = 5, int $lockoutMinutes = 30): void
    {
        $attempts = $this->failed_login_attempts + 1;
        $updates = ['failed_login_attempts' => $attempts];

        if ($attempts >= $maxAttempts) {
            $updates['locked_untill'] = now()->addMinutes($lockoutMinutes);
            $updates['status'] = 'locked';
        }

        $this->forceFill($updates)->save();
    }

    /**
     * Get badge color representation of status.
     */
    public function statusBadgeColor(): string
    {
        if ($this->isLocked()) {
            return 'rose';
        }

        return match (strtolower((string) $this->status)) {
            'active' => 'emerald',
            'inactive' => 'zinc',
            'suspended' => 'amber',
            'invited' => 'sky',
            'pending' => 'indigo',
            default => 'secondary',
        };
    }

    /**
     * Determine if the user has verified their phone number.
     */
    public function hasVerifiedPhone(): bool
    {
        return ! is_null($this->phone_verified_at);
    }

    /**
     * Mark the given user's phone as verified.
     */
    public function markPhoneAsVerified(): bool
    {
        return $this->forceFill([
            'phone_verified_at' => $this->freshTimestamp(),
        ])->save();
    }

    /**
     * Get the user's initials.
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name ?: ($this->first_name.' '.$this->last_name), true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : ($initials ?: 'U');
    }

    /**
     * Get the public URL to the user's profile avatar.
     */
    public function avatarUrl(): ?string
    {
        if (empty($this->avatar)) {
            return null;
        }

        return FileUploadService::url($this->avatar);
    }

    /**
     * Check if the user is currently online.
     */
    public function isOnline(): bool
    {
        if ($this->id === auth()->id()) {
            return true;
        }

        if (Cache::has('user-online-'.$this->id)) {
            return true;
        }

        if ($this->last_login_at && $this->last_login_at->gt(now()->subMinutes(15))) {
            return true;
        }

        return false;
    }

    /**
     * Get human readable last seen or online status string.
     */
    public function lastSeenText(): string
    {
        if ($this->isOnline()) {
            return __('Online');
        }

        if ($this->last_login_at) {
            return __('Last seen :time', ['time' => $this->last_login_at->shortRelativeDiffForHumans()]);
        }

        return __('Offline');
    }
}
