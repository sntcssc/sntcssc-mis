<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AttendanceHoliday extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    public const TYPE_GOVT_HOLIDAY = 'govt_holiday';

    public const TYPE_INSTITUTION_HOLIDAY = 'institution_holiday';

    public const TYPE_WEEKLY_OFF = 'weekly_off';

    public const TYPE_WEEKLY_TEST = 'weekly_test';

    public const TYPE_EXAM_DAY = 'exam_day';

    public const TYPE_RESTRICTED_HOLIDAY = 'restricted_holiday';

    public const TYPE_EMERGENCY_OFF = 'emergency_off';

    public const TYPE_VACATION = 'vacation';

    protected $fillable = [
        'title',
        'date',
        'to_date',
        'type',
        'target_type',
        'course_id',
        'batch_id',
        'description',
        'is_restricted',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'to_date' => 'date',
            'is_restricted' => 'boolean',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_GOVT_HOLIDAY => __('Govt. Holiday'),
            self::TYPE_INSTITUTION_HOLIDAY => __('Institutional Holiday'),
            self::TYPE_WEEKLY_OFF => __('Weekly Off'),
            self::TYPE_WEEKLY_TEST => __('Weekly Test Day'),
            self::TYPE_EXAM_DAY => __('Examination Day'),
            self::TYPE_RESTRICTED_HOLIDAY => __('Restricted Holiday'),
            self::TYPE_EMERGENCY_OFF => __('Emergency Off'),
            self::TYPE_VACATION => __('Vacation / Break'),
            default => ucfirst(str_replace('_', ' ', $this->type)),
        };
    }

    public function typeBadgeColor(): string
    {
        return match ($this->type) {
            self::TYPE_GOVT_HOLIDAY => 'bg-red-500/10 text-red-600 dark:text-red-400 border-red-500/20',
            self::TYPE_INSTITUTION_HOLIDAY => 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20',
            self::TYPE_WEEKLY_OFF => 'bg-zinc-500/10 text-zinc-600 dark:text-zinc-400 border-zinc-500/20',
            self::TYPE_WEEKLY_TEST => 'bg-purple-500/10 text-purple-600 dark:text-purple-400 border-purple-500/20',
            self::TYPE_EXAM_DAY => 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 border-indigo-500/20',
            self::TYPE_RESTRICTED_HOLIDAY => 'bg-sky-500/10 text-sky-600 dark:text-sky-400 border-sky-500/20',
            self::TYPE_EMERGENCY_OFF => 'bg-orange-500/10 text-orange-600 dark:text-orange-400 border-orange-500/20',
            default => 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20',
        };
    }

    public function scopeForStaff(Builder $query): Builder
    {
        return $query->whereIn('target_type', ['staff', 'all']);
    }

    public function scopeForStudent(Builder $query): Builder
    {
        return $query->whereIn('target_type', ['student', 'all']);
    }
}
