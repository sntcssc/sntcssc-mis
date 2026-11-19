<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $calendar_code
 * @property string $academic_year
 * @property string $title
 * @property string|null $theme_or_motto
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property int $total_working_days
 * @property int $total_instructional_days
 * @property string|null $description
 * @property string $status
 * @property bool $is_current
 * @property int|null $current_version_id
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, AcademicCalendarVersion> $versions
 * @property-read AcademicCalendarVersion|null $currentVersion
 * @property-read Collection<int, AcademicCalendarActivity> $activities
 * @property-read User|null $creator
 * @property-read User|null $updater
 */
class AcademicCalendar extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $table = 'academic_calendars';

    protected $fillable = [
        'calendar_code',
        'academic_year',
        'title',
        'theme_or_motto',
        'start_date',
        'end_date',
        'total_working_days',
        'total_instructional_days',
        'description',
        'status',
        'is_current',
        'current_version_id',
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
            'is_current' => 'boolean',
            'total_working_days' => 'integer',
            'total_instructional_days' => 'integer',
        ];
    }

    /**
     * Boot model logic to generate calendar_code automatically if missing.
     */
    protected static function booted(): void
    {
        static::creating(function (AcademicCalendar $calendar) {
            if (empty($calendar->calendar_code)) {
                $calendar->calendar_code = static::generateCode($calendar->academic_year ?? date('Y'));
            }
        });
    }

    /**
     * Generate unique academic calendar code.
     */
    public static function generateCode(string $academicYear): string
    {
        $normalized = preg_replace('/[^0-9\-]/', '', $academicYear);
        if (empty($normalized)) {
            $normalized = date('Y').'-'.(date('y') + 1);
        }

        $base = 'AC-'.$normalized;
        $code = $base;
        $counter = 1;

        while (static::where('calendar_code', $code)->exists()) {
            $code = $base.'-'.str_pad((string) $counter, 2, '0', STR_PAD_LEFT);
            $counter++;
        }

        return $code;
    }

    /**
     * All revisions / versions uploaded for this academic year.
     *
     * @return HasMany<AcademicCalendarVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(AcademicCalendarVersion::class, 'academic_calendar_id')
            ->orderBy('revision_number', 'desc');
    }

    /**
     * The designated active / current version document.
     *
     * @return BelongsTo<AcademicCalendarVersion, $this>
     */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(AcademicCalendarVersion::class, 'current_version_id');
    }

    /**
     * Month-wise activities planned in this academic calendar.
     *
     * @return HasMany<AcademicCalendarActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(AcademicCalendarActivity::class, 'academic_calendar_id')
            ->orderBy('start_date', 'asc')
            ->orderBy('display_order', 'asc');
    }

    /**
     * User who created this calendar record.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * User who updated this calendar record.
     *
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Scope query to find current active calendar.
     *
     * @param  Builder<AcademicCalendar>  $query
     * @return Builder<AcademicCalendar>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('is_current', true);
    }

    /**
     * Scope query to published calendars.
     *
     * @param  Builder<AcademicCalendar>  $query
     * @return Builder<AcademicCalendar>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    /**
     * Scope query with search keyword.
     *
     * @param  Builder<AcademicCalendar>  $query
     * @return Builder<AcademicCalendar>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $term = trim($term);

        return $query->where(function (Builder $q) use ($term) {
            $q->where('calendar_code', 'like', "%{$term}%")
                ->orWhere('academic_year', 'like', "%{$term}%")
                ->orWhere('title', 'like', "%{$term}%")
                ->orWhere('theme_or_motto', 'like', "%{$term}%")
                ->orWhere('description', 'like', "%{$term}%");
        });
    }

    /**
     * Set this calendar as the current institute calendar and deactivate others.
     */
    public function makeCurrent(): void
    {
        static::where('id', '!=', $this->id)->update(['is_current' => false]);
        $this->update(['is_current' => true]);
    }

    /**
     * Formatted date period range (e.g., "01 Jul 2026 - 30 Jun 2027").
     */
    public function getFormattedPeriodAttribute(): string
    {
        return $this->start_date->format('d M Y').' - '.$this->end_date->format('d M Y');
    }

    /**
     * Helper to get status color badge classes.
     */
    public function getStatusBadgeClasses(): string
    {
        return match ($this->status) {
            'published' => 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20',
            'draft' => 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20',
            'archived' => 'bg-muted text-muted-foreground border-border',
            default => 'bg-secondary text-secondary-foreground border-border',
        };
    }
}
