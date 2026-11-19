<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class LeaveApplication extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'application_no',
        'user_id',
        'leave_category_id',
        'user_type',
        'from_date',
        'to_date',
        'total_days',
        'is_half_day',
        'half_day_type',
        'reason',
        'attachment_path',
        'emergency_contact',
        'status',
        'action_by',
        'action_at',
        'action_remarks',
        'is_synced_to_attendance',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
            'action_at' => 'datetime',
            'total_days' => 'decimal:1',
            'is_half_day' => 'boolean',
            'is_synced_to_attendance' => 'boolean',
        ];
    }

    public static function generateApplicationNumber(): string
    {
        $year = Carbon::now()->format('Y');
        $prefix = "LEV-{$year}-";
        $latest = static::withTrashed()
            ->where('application_no', 'like', "{$prefix}%")
            ->orderByDesc('id')
            ->first();

        $sequence = 1;
        if ($latest && preg_match('/LEV-\d{4}-(\d+)/', $latest->application_no, $matches)) {
            $sequence = ((int) $matches[1]) + 1;
        }

        return $prefix.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(LeaveCategory::class, 'leave_category_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'action_by');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class, 'leave_application_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopeForStaff(Builder $query): Builder
    {
        return $query->where('user_type', 'staff');
    }

    public function scopeForStudent(Builder $query): Builder
    {
        return $query->where('user_type', 'student');
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            self::STATUS_APPROVED => 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20',
            self::STATUS_REJECTED => 'bg-rose-500/10 text-rose-600 dark:text-rose-400 border-rose-500/20',
            self::STATUS_CANCELLED => 'bg-zinc-500/10 text-zinc-600 dark:text-zinc-400 border-zinc-500/20',
            default => 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20',
        };
    }
}
