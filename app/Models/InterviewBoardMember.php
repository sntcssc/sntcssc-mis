<?php

namespace App\Models;

use App\Concerns\Auditable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $member_code
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $designation
 * @property string|null $organization
 * @property string|null $specialization
 * @property string|null $bio
 * @property string|null $avatar
 * @property string $status
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User|null $user
 * @property-read Collection<int, MockInterviewSessionMember> $sessionMembers
 * @property-read Collection<int, MockInterviewSession> $sessions
 */
class InterviewBoardMember extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'user_id' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'deleted_by' => 'integer',
    ];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model) {
            if (empty($model->member_code)) {
                $model->member_code = static::generateNextMemberCode();
            }
            if (auth()->check()) {
                $model->created_by = auth()->id();
                $model->updated_by = auth()->id();
            }
        });

        static::updating(function (self $model) {
            if (auth()->check()) {
                $model->updated_by = auth()->id();
            }
        });

        static::deleting(function (self $model) {
            if (auth()->check()) {
                $model->deleted_by = auth()->id();
                $model->saveQuietly();
            }
        });
    }

    /**
     * Generate unique member code: PANEL-YYYY-###
     */
    public static function generateNextMemberCode(): string
    {
        $year = date('Y');
        $prefix = "PANEL-{$year}-";

        $latest = static::withTrashed()
            ->where('member_code', 'like', "{$prefix}%")
            ->orderByDesc('id')
            ->value('member_code');

        if ($latest && preg_match('/PANEL-\d+-(\d+)/', $latest, $matches)) {
            $next = ((int) $matches[1]) + 1;
        } else {
            $next = static::withTrashed()->count() + 1;
        }

        return sprintf('%s%03d', $prefix, $next);
    }

    // Relationships

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function sessionMembers(): HasMany
    {
        return $this->hasMany(MockInterviewSessionMember::class, 'interview_board_member_id');
    }

    public function sessions(): BelongsToMany
    {
        return $this->belongsToMany(
            MockInterviewSession::class,
            'mock_interview_session_members',
            'interview_board_member_id',
            'mock_interview_session_id'
        )->withPivot(['id', 'role', 'order_index', 'attendance_status', 'invitation_sent_at', 'invitation_channels', 'remarks'])
            ->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    // Scopes

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $term = trim($term);

        return $query->where(function (Builder $q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('member_code', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('designation', 'like', "%{$term}%")
                ->orWhere('organization', 'like', "%{$term}%")
                ->orWhere('specialization', 'like', "%{$term}%");
        });
    }

    // Accessors

    public function getAvatarUrlAttribute(): ?string
    {
        if ($this->avatar) {
            return Storage::disk('public')->url($this->avatar);
        }

        if ($this->user && $this->user->avatar) {
            return Storage::disk('public')->url($this->user->avatar);
        }

        return null;
    }

    public function getInitialsAttribute(): string
    {
        $words = explode(' ', trim($this->name));
        $initials = '';
        foreach (array_slice($words, 0, 2) as $w) {
            $initials .= mb_substr($w, 0, 1);
        }

        return strtoupper($initials ?: 'BM');
    }
}
