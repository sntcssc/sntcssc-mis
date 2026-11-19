<?php

namespace App\Models;

use App\Concerns\Auditable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property string $code
 * @property string $title
 * @property int $batch_id
 * @property int $course_id
 * @property int|null $section_id
 * @property string $test_stage
 * @property string $test_type
 * @property string $test_for
 * @property int $test_number
 * @property float $total_marks
 * @property float|null $pass_marks
 * @property int $total_questions
 * @property string $mode
 * @property Carbon $test_date
 * @property string|null $test_day
 * @property string|null $start_time
 * @property string|null $end_time
 * @property int $duration_minutes
 * @property string|null $venue_room
 * @property string|null $question_paper_path
 * @property string|null $question_paper_name
 * @property string|null $model_answer_path
 * @property string|null $model_answer_name
 * @property Carbon|null $solutions_published_at
 * @property string $status
 * @property string|null $instructions
 * @property float $negative_marking_ratio
 * @property float $marks_per_question
 * @property bool $notify_students
 * @property array|null $notification_channels
 * @property Carbon|null $notification_sent_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Batch $batch
 * @property-read Course $course
 * @property-read Section|null $section
 * @property-read Collection<int, StudentTestSubject> $subjects
 * @property-read Collection<int, StudentTestResult> $results
 * @property-read User|null $creator
 * @property-read User|null $updater
 */
class StudentTest extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    public const STAGE_PRELIMS = 'prelims';

    public const STAGE_MAINS = 'mains';

    public const STAGE_INTERVIEW = 'interview_prep';

    public const TYPE_MCQ = 'mcq';

    public const TYPE_DESCRIPTIVE = 'descriptive';

    public const TYPE_ESSAY = 'essay';

    public const TYPE_ANSWER_WRITING = 'answer_writing';

    public const FOR_CLASS_TEST = 'class_test';

    public const FOR_MOCK_TEST = 'mock_test';

    public const FOR_LIVE_TEST = 'live_test';

    public const FOR_FULL_LENGTH = 'full_length_test';

    public const FOR_SECTIONAL = 'sectional_test';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_ONGOING = 'ongoing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'uuid',
        'code',
        'title',
        'batch_id',
        'course_id',
        'section_id',
        'test_stage',
        'test_type',
        'test_for',
        'test_number',
        'total_marks',
        'pass_marks',
        'total_questions',
        'mode',
        'test_date',
        'test_day',
        'start_time',
        'end_time',
        'duration_minutes',
        'venue_room',
        'question_paper_path',
        'question_paper_name',
        'model_answer_path',
        'model_answer_name',
        'solutions_published_at',
        'status',
        'instructions',
        'negative_marking_ratio',
        'marks_per_question',
        'notify_students',
        'notification_channels',
        'notification_sent_at',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'test_date' => 'date',
            'solutions_published_at' => 'datetime',
            'notification_sent_at' => 'datetime',
            'notify_students' => 'boolean',
            'notification_channels' => 'array',
            'total_marks' => 'decimal:2',
            'pass_marks' => 'decimal:2',
            'negative_marking_ratio' => 'decimal:2',
            'marks_per_question' => 'decimal:2',
            'duration_minutes' => 'integer',
            'total_questions' => 'integer',
            'test_number' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (StudentTest $test) {
            if (empty($test->uuid)) {
                $test->uuid = (string) Str::uuid();
            }
            if (empty($test->code)) {
                $year = date('Y');
                $count = static::withTrashed()->count() + 1;
                $test->code = sprintf('TEST-%s-%04d', $year, $count);
            }
            if ($test->test_date && empty($test->test_day)) {
                $test->test_day = Carbon::parse($test->test_date)->format('l');
            }
            if (auth()->check() && empty($test->created_by)) {
                $test->created_by = auth()->id();
            }
        });

        static::updating(function (StudentTest $test) {
            if ($test->isDirty('test_date') && $test->test_date) {
                $test->test_day = Carbon::parse($test->test_date)->format('l');
            }
            if (auth()->check()) {
                $test->updated_by = auth()->id();
            }
        });

        static::deleting(function (StudentTest $test) {
            if (auth()->check() && ! $test->isForceDeleting() && empty($test->deleted_by)) {
                $test->deleted_by = auth()->id();
                $test->saveQuietly();
            }
        });
    }

    /* ----------------------------------------------------------------- *
     *  Relationships
     * ----------------------------------------------------------------- */

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

    public function subjects(): HasMany
    {
        return $this->hasMany(StudentTestSubject::class)->orderBy('display_order');
    }

    public function results(): HasMany
    {
        return $this->hasMany(StudentTestResult::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /* ----------------------------------------------------------------- *
     *  Scopes
     * ----------------------------------------------------------------- */

    public function scopeForBatch(Builder $query, int $batchId): Builder
    {
        return $query->where('batch_id', $batchId);
    }

    public function scopeForCourse(Builder $query, int $courseId): Builder
    {
        return $query->where('course_id', $courseId);
    }

    public function scopeForSection(Builder $query, ?int $sectionId): Builder
    {
        if ($sectionId) {
            return $query->where(function ($q) use ($sectionId) {
                $q->where('section_id', $sectionId)->orWhereNull('section_id');
            });
        }

        return $query;
    }

    public function scopeStage(Builder $query, string $stage): Builder
    {
        return $query->where('test_stage', $stage);
    }

    public function scopeType(Builder $query, string $type): Builder
    {
        return $query->where('test_type', $type);
    }

    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('test_date', '>=', now()->toDateString());
    }

    public function scopePast(Builder $query): Builder
    {
        return $query->where('test_date', '<', now()->toDateString());
    }

    /* ----------------------------------------------------------------- *
     *  Convenience Helpers
     * ----------------------------------------------------------------- */

    public function isMcq(): bool
    {
        return $this->test_type === self::TYPE_MCQ;
    }

    public function isCsat(): bool
    {
        if (str_contains(strtolower($this->title), 'csat') || str_contains(strtolower($this->code), 'csat')) {
            return true;
        }

        return (int) $this->total_questions === 80 && abs((float) $this->marks_per_question - 2.50) < 0.01;
    }

    public function isQualifyingOnly(): bool
    {
        return $this->isCsat() || ($this->pass_marks !== null && (float) $this->pass_marks >= 66.0 && (float) $this->pass_marks <= 67.0 && (float) $this->total_marks == 200.0);
    }

    public function penaltyPerWrongAnswer(): float
    {
        $markPerQ = (float) ($this->marks_per_question ?: 2.0);

        // Standard UPSC Prelims official benchmarks
        if (abs($markPerQ - 2.00) < 0.01) {
            return 0.66; // UPSC GS Paper I standard (-0.66 Marks)
        }

        if (abs($markPerQ - 2.50) < 0.01) {
            return 0.83; // UPSC CSAT Paper II standard (-0.83 Marks)
        }

        $negRatio = (float) ($this->negative_marking_ratio > 0 ? $this->negative_marking_ratio : 0.33);

        return round($markPerQ * $negRatio, 2);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function getScheduledEndDatetimeAttribute(): Carbon
    {
        $dateStr = $this->test_date ? $this->test_date->format('Y-m-d') : now()->format('Y-m-d');
        if (! empty($this->end_time)) {
            try {
                return Carbon::parse($dateStr.' '.$this->end_time);
            } catch (\Throwable) {
            }
        }

        if (! empty($this->start_time)) {
            try {
                return Carbon::parse($dateStr.' '.$this->start_time)->addMinutes($this->duration_minutes ?: 120);
            } catch (\Throwable) {
            }
        }

        return Carbon::parse($dateStr.' 23:59:59');
    }

    public function getScheduledStartDatetimeAttribute(): Carbon
    {
        $dateStr = $this->test_date ? $this->test_date->format('Y-m-d') : now()->format('Y-m-d');
        if (! empty($this->start_time)) {
            try {
                return Carbon::parse($dateStr.' '.$this->start_time);
            } catch (\Throwable) {
            }
        }

        return Carbon::parse($dateStr.' 00:00:00');
    }

    /**
     * Determine if scheduled test duration is completely over.
     */
    public function hasConcluded(): bool
    {
        return now()->greaterThanOrEqualTo($this->scheduled_end_datetime);
    }

    /**
     * Check if a student can download question paper / model answers.
     * Rule: Available to all enrolled students once scheduled test date and time is over!
     */
    public function canDownloadSolutions(?User $user = null): bool
    {
        $user = $user ?? auth()->user();
        if (! $user) {
            return false;
        }

        // Staff / Admin / Faculty can always access
        if (! $user->isStudent()) {
            return true;
        }

        // If explicitly published
        if ($this->solutions_published_at && now()->greaterThanOrEqualTo($this->solutions_published_at)) {
            return true;
        }

        // Available after test conclude time
        return $this->hasConcluded();
    }

    public function rankers(int $limit = 10): Collection
    {
        return $this->results()
            ->with(['user', 'section'])
            ->where('attendance_status', 'present')
            ->whereNotNull('rank')
            ->orderBy('rank', 'asc')
            ->limit($limit)
            ->get();
    }

    public function getAttendedCountAttribute(): int
    {
        return $this->results()->where('attendance_status', 'present')->count();
    }

    public function getAbsentCountAttribute(): int
    {
        return $this->results()->where('attendance_status', 'absent')->count();
    }

    public function getAverageMarksAttribute(): float
    {
        return (float) ($this->results()->where('attendance_status', 'present')->avg('marks_obtained') ?? 0);
    }

    public function getHighestMarksAttribute(): float
    {
        return (float) ($this->results()->where('attendance_status', 'present')->max('marks_obtained') ?? 0);
    }

    public function getLowestMarksAttribute(): float
    {
        return (float) ($this->results()->where('attendance_status', 'present')->min('marks_obtained') ?? 0);
    }

    public function getTopScorerAttribute(): ?User
    {
        $topResult = $this->results()
            ->where('attendance_status', 'present')
            ->orderByDesc('marks_obtained')
            ->first();

        return $topResult?->user;
    }
}
