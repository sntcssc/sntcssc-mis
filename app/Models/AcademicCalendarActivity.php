<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $academic_calendar_id
 * @property int|null $academic_calendar_version_id
 * @property string $activity_code
 * @property string $title
 * @property string $category
 * @property Carbon $start_date
 * @property Carbon|null $end_date
 * @property int $month
 * @property int $year
 * @property string $target_audience
 * @property string|null $department
 * @property string $venue_mode
 * @property string|null $venue_or_link
 * @property bool $is_tentative
 * @property bool $is_notified
 * @property string $status
 * @property string|null $description
 * @property string|null $remarks
 * @property int $display_order
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read AcademicCalendar $academicCalendar
 * @property-read AcademicCalendarVersion|null $version
 * @property-read User|null $creator
 * @property-read User|null $updater
 */
class AcademicCalendarActivity extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $table = 'academic_calendar_activities';

    protected $fillable = [
        'academic_calendar_id',
        'academic_calendar_version_id',
        'activity_code',
        'title',
        'category',
        'start_date',
        'end_date',
        'month',
        'year',
        'target_audience',
        'department',
        'venue_mode',
        'venue_or_link',
        'is_tentative',
        'is_notified',
        'status',
        'description',
        'remarks',
        'display_order',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'month' => 'integer',
            'year' => 'integer',
            'is_tentative' => 'boolean',
            'is_notified' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    /**
     * Boot model logic to generate code and compute month/year automatically.
     */
    protected static function booted(): void
    {
        static::creating(function (AcademicCalendarActivity $activity) {
            if ($activity->start_date) {
                $activity->month = (int) $activity->start_date->format('n');
                $activity->year = (int) $activity->start_date->format('Y');
            }

            if (empty($activity->activity_code)) {
                $activity->activity_code = static::generateCode($activity->year ?? (int) date('Y'));
            }
        });

        static::updating(function (AcademicCalendarActivity $activity) {
            if ($activity->isDirty('start_date') && $activity->start_date) {
                $activity->month = (int) $activity->start_date->format('n');
                $activity->year = (int) $activity->start_date->format('Y');
            }
        });
    }

    /**
     * Generate unique activity reference code.
     */
    public static function generateCode(int $year): string
    {
        $prefix = 'ACT-'.$year.'-';
        $last = static::where('activity_code', 'like', $prefix.'%')
            ->orderBy('id', 'desc')
            ->first();

        $maxNum = 0;
        if ($last && preg_match('/ACT-\d{4}-(\d+)/', $last->activity_code, $matches)) {
            $maxNum = (int) $matches[1];
        }

        return $prefix.str_pad((string) ($maxNum + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Parent Academic Calendar.
     *
     * @return BelongsTo<AcademicCalendar, $this>
     */
    public function academicCalendar(): BelongsTo
    {
        return $this->belongsTo(AcademicCalendar::class, 'academic_calendar_id');
    }

    /**
     * Optional link to specific version where introduced.
     *
     * @return BelongsTo<AcademicCalendarVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(AcademicCalendarVersion::class, 'academic_calendar_version_id');
    }

    /**
     * Creator user.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Updater user.
     *
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Scope for a specific month (1-12) and optional year.
     *
     * @param  Builder<AcademicCalendarActivity>  $query
     * @return Builder<AcademicCalendarActivity>
     */
    public function scopeForMonth(Builder $query, int $month, ?int $year = null): Builder
    {
        $query->where('month', $month);
        if ($year) {
            $query->where('year', $year);
        }

        return $query;
    }

    /**
     * Scope for activity category.
     *
     * @param  Builder<AcademicCalendarActivity>  $query
     * @return Builder<AcademicCalendarActivity>
     */
    public function scopeCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    /**
     * Scope upcoming activities.
     *
     * @param  Builder<AcademicCalendarActivity>  $query
     * @return Builder<AcademicCalendarActivity>
     */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->whereDate('start_date', '>=', Carbon::today())
            ->whereIn('status', ['planned', 'ongoing'])
            ->orderBy('start_date', 'asc');
    }

    /**
     * Search scope across title, description, code, department, venue.
     *
     * @param  Builder<AcademicCalendarActivity>  $query
     * @return Builder<AcademicCalendarActivity>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $term = trim($term);

        return $query->where(function (Builder $q) use ($term) {
            $q->where('title', 'like', "%{$term}%")
                ->orWhere('activity_code', 'like', "%{$term}%")
                ->orWhere('department', 'like', "%{$term}%")
                ->orWhere('venue_or_link', 'like', "%{$term}%")
                ->orWhere('description', 'like', "%{$term}%")
                ->orWhere('remarks', 'like', "%{$term}%");
        });
    }

    /**
     * Formatted date string (e.g., "15 Aug 2026" or "20 Oct 2026 - 28 Oct 2026").
     */
    public function getFormattedDateRangeAttribute(): string
    {
        if (! $this->end_date || $this->end_date->isSameDay($this->start_date)) {
            return $this->start_date->format('d M Y');
        }

        if ($this->start_date->format('M Y') === $this->end_date->format('M Y')) {
            return $this->start_date->format('d').' - '.$this->end_date->format('d M Y');
        }

        return $this->start_date->format('d M Y').' - '.$this->end_date->format('d M Y');
    }

    /**
     * Month name (e.g., "August").
     */
    public function getMonthNameAttribute(): string
    {
        return Carbon::create(null, $this->month, 1)->format('F');
    }

    /**
     * Badge classes for activity category.
     */
    public function getCategoryBadgeClasses(): string
    {
        return match ($this->category) {
            'academic' => 'bg-blue-500/10 text-blue-600 dark:text-blue-400 border-blue-500/20',
            'examination' => 'bg-red-500/10 text-red-600 dark:text-red-400 border-red-500/20',
            'admission' => 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 border-indigo-500/20',
            'holiday' => 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20',
            'co_curricular' => 'bg-purple-500/10 text-purple-600 dark:text-purple-400 border-purple-500/20',
            'administrative' => 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20',
            'sports_cultural' => 'bg-pink-500/10 text-pink-600 dark:text-pink-400 border-pink-500/20',
            default => 'bg-secondary text-secondary-foreground border-border',
        };
    }

    /**
     * Badge classes for activity status.
     */
    public function getStatusBadgeClasses(): string
    {
        return match ($this->status) {
            'completed' => 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20',
            'ongoing' => 'bg-sky-500/10 text-sky-600 dark:text-sky-400 border-sky-500/20',
            'planned' => 'bg-blue-500/10 text-blue-600 dark:text-blue-400 border-blue-500/20',
            'postponed' => 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20',
            'cancelled' => 'bg-rose-500/10 text-rose-600 dark:text-rose-400 border-rose-500/20',
            default => 'bg-secondary text-secondary-foreground border-border',
        };
    }

    /**
     * Human-friendly Category Label.
     */
    public function getCategoryLabel(): string
    {
        return match ($this->category) {
            'academic' => 'Academic Session',
            'examination' => 'Examination & Tests',
            'admission' => 'Admission & Enrolment',
            'holiday' => 'Holiday & Vacation',
            'co_curricular' => 'Co-Curricular & Seminars',
            'administrative' => 'Administrative & Meeting',
            'sports_cultural' => 'Sports & Cultural Events',
            default => 'General Activity',
        };
    }
}
