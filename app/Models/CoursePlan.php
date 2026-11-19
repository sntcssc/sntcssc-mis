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
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property string $plan_code
 * @property string $title
 * @property int $batch_id
 * @property int $course_id
 * @property int|null $section_id
 * @property string $plan_type
 * @property string|null $academic_year
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property int $total_lectures_planned
 * @property string|null $topics_covered
 * @property string|null $description
 * @property string|null $document_path
 * @property string $version
 * @property string $status
 * @property Carbon|null $published_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Batch $batch
 * @property-read Course $course
 * @property-read Section|null $section
 * @property-read Collection<int, ClassSchedule> $classSchedules
 * @property-read User|null $creator
 * @property-read User|null $updater
 */
class CoursePlan extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $table = 'course_plans';

    protected $fillable = [
        'plan_code',
        'title',
        'batch_id',
        'course_id',
        'section_id',
        'plan_type',
        'academic_year',
        'start_date',
        'end_date',
        'total_lectures_planned',
        'topics_covered',
        'description',
        'document_path',
        'version',
        'status',
        'published_at',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'published_at' => 'datetime',
            'total_lectures_planned' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CoursePlan $plan) {
            if (empty($plan->plan_code)) {
                $count = static::withTrashed()->count() + 1;
                $plan->plan_code = 'CP-'.date('Y').'-'.str_pad((string) $count, 3, '0', STR_PAD_LEFT);
            }
            if ($plan->status === 'published' && empty($plan->published_at)) {
                $plan->published_at = now();
            }
            if (auth()->check() && empty($plan->created_by)) {
                $plan->created_by = auth()->id();
            }
        });

        static::updating(function (CoursePlan $plan) {
            if ($plan->status === 'published' && empty($plan->published_at)) {
                $plan->published_at = now();
            }
            if (auth()->check()) {
                $plan->updated_by = auth()->id();
            }
        });

        static::deleting(function (CoursePlan $plan) {
            if (auth()->check() && ! $plan->isForceDeleting() && empty($plan->deleted_by)) {
                $plan->deleted_by = auth()->id();
                $plan->saveQuietly();
            }
        });
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function classSchedules(): HasMany
    {
        return $this->hasMany(ClassSchedule::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    public function scopeForBatch(Builder $query, int $batchId): Builder
    {
        return $query->where('batch_id', $batchId);
    }

    public function scopeForCourse(Builder $query, int $courseId): Builder
    {
        return $query->where('course_id', $courseId);
    }

    public function getDocumentUrlAttribute(): ?string
    {
        return $this->document_path ? Storage::disk('public')->url($this->document_path) : null;
    }

    public function getDocumentFileNameAttribute(): ?string
    {
        return $this->document_path ? basename($this->document_path) : null;
    }

    public function getDocumentSizeFormattedAttribute(): ?string
    {
        if (! $this->document_path || ! Storage::disk('public')->exists($this->document_path)) {
            return null;
        }

        $bytes = Storage::disk('public')->size($this->document_path);
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2).' MB';
        }

        return round($bytes / 1024, 1).' KB';
    }

    /**
     * Conducted / Completed lectures count (excludes cancelled).
     */
    public function getConductedLecturesCountAttribute(): int
    {
        return $this->classSchedules()->where('status', 'completed')->count();
    }

    /**
     * Active scheduled lectures (in_progress or scheduled, excludes cancelled).
     */
    public function getScheduledLecturesCountAttribute(): int
    {
        return $this->classSchedules()->whereIn('status', ['scheduled', 'in_progress'])->count();
    }

    /**
     * Cancelled lectures count. Note: Canceled classes never count towards syllabus completion!
     */
    public function getCancelledLecturesCountAttribute(): int
    {
        return $this->classSchedules()->where('status', 'cancelled')->count();
    }

    /**
     * Effective lectures planned vs scheduled (excludes cancelled classes).
     */
    public function getEffectiveLecturesCountAttribute(): int
    {
        return $this->classSchedules()->whereNotIn('status', ['cancelled'])->count();
    }

    /**
     * Remaining lectures to be scheduled to meet planned quota.
     */
    public function getRemainingLecturesToScheduleAttribute(): int
    {
        return max(0, $this->total_lectures_planned - $this->effective_lectures_count);
    }

    /**
     * Percentage of syllabus conducted vs planned.
     */
    public function getProgressPercentageAttribute(): float
    {
        if ($this->total_lectures_planned <= 0) {
            return 0.0;
        }

        return round(min(100, ($this->conducted_lectures_count / $this->total_lectures_planned) * 100), 1);
    }
}
