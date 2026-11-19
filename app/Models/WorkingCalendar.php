<?php

namespace App\Models;

use App\Concerns\Auditable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class WorkingCalendar extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'target_type',
        'course_id',
        'batch_id',
        'department',
        'effective_from',
        'effective_to',
        'monday_active',
        'tuesday_active',
        'wednesday_active',
        'thursday_active',
        'friday_active',
        'saturday_active',
        'sunday_active',
        'saturday_rules',
        'expected_start_time',
        'expected_end_time',
        'late_grace_minutes',
        'half_day_hours',
        'is_default',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'monday_active' => 'boolean',
            'tuesday_active' => 'boolean',
            'wednesday_active' => 'boolean',
            'thursday_active' => 'boolean',
            'friday_active' => 'boolean',
            'saturday_active' => 'boolean',
            'sunday_active' => 'boolean',
            'late_grace_minutes' => 'integer',
            'half_day_hours' => 'decimal:1',
            'is_default' => 'boolean',
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

    public function isWorkingDay(Carbon $date): bool
    {
        $dayOfWeek = $date->dayOfWeek; // 0 = Sunday, 1 = Monday, ..., 6 = Saturday

        $isActive = match ($dayOfWeek) {
            CarbonInterface::MONDAY => $this->monday_active,
            CarbonInterface::TUESDAY => $this->tuesday_active,
            CarbonInterface::WEDNESDAY => $this->wednesday_active,
            CarbonInterface::THURSDAY => $this->thursday_active,
            CarbonInterface::FRIDAY => $this->friday_active,
            CarbonInterface::SATURDAY => $this->saturday_active,
            CarbonInterface::SUNDAY => $this->sunday_active,
            default => false,
        };

        if ($dayOfWeek === CarbonInterface::SATURDAY && $this->saturday_active) {
            if ($this->saturday_rules === '2nd_4th_off') {
                // Determine if this is 2nd or 4th Saturday of the month
                $weekOfMonth = (int) ceil($date->day / 7);
                if ($weekOfMonth === 2 || $weekOfMonth === 4) {
                    return false;
                }
            } elseif ($this->saturday_rules === 'all_off') {
                return false;
            }
        }

        return $isActive;
    }

    public function scopeForStaff(Builder $query): Builder
    {
        return $query->where('target_type', 'staff');
    }

    public function scopeForStudent(Builder $query): Builder
    {
        return $query->where('target_type', 'student');
    }

    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }
}
