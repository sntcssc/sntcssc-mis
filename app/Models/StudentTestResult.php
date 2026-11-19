<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $student_test_id
 * @property int $user_id
 * @property int|null $enrollment_id
 * @property int|null $section_id
 * @property string|null $roll_number
 * @property string $attendance_status
 * @property int $total_questions
 * @property int $attempted
 * @property int $right_answers
 * @property int $wrong_answers
 * @property int $blank_answers
 * @property float $positive_marks
 * @property float $negative_marks
 * @property float $marks_obtained
 * @property float $total_marks
 * @property float $percentage
 * @property float|null $percentile
 * @property int|null $rank
 * @property bool $is_top_ten
 * @property bool $is_passed
 * @property array|null $subject_scores
 * @property string|null $remarks
 * @property string|null $feedback
 * @property int|null $evaluated_by
 * @property Carbon|null $evaluated_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read StudentTest $test
 * @property-read User $user
 * @property-read Enrollment|null $enrollment
 * @property-read Section|null $section
 * @property-read User|null $evaluator
 */
class StudentTestResult extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    public const STATUS_PRESENT = 'present';

    public const STATUS_ABSENT = 'absent';

    public const STATUS_EXEMPTED = 'exempted';

    public const STATUS_DISQUALIFIED = 'disqualified';

    protected $fillable = [
        'uuid',
        'student_test_id',
        'user_id',
        'enrollment_id',
        'section_id',
        'roll_number',
        'attendance_status',
        'total_questions',
        'attempted',
        'right_answers',
        'wrong_answers',
        'blank_answers',
        'positive_marks',
        'negative_marks',
        'marks_obtained',
        'total_marks',
        'percentage',
        'percentile',
        'rank',
        'is_top_ten',
        'is_passed',
        'subject_scores',
        'remarks',
        'feedback',
        'evaluated_by',
        'evaluated_at',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'total_questions' => 'integer',
            'attempted' => 'integer',
            'right_answers' => 'integer',
            'wrong_answers' => 'integer',
            'blank_answers' => 'integer',
            'positive_marks' => 'decimal:2',
            'negative_marks' => 'decimal:2',
            'marks_obtained' => 'decimal:2',
            'total_marks' => 'decimal:2',
            'percentage' => 'decimal:2',
            'percentile' => 'decimal:2',
            'rank' => 'integer',
            'is_top_ten' => 'boolean',
            'is_passed' => 'boolean',
            'subject_scores' => 'array',
            'evaluated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (StudentTestResult $result) {
            if (empty($result->uuid)) {
                $result->uuid = (string) Str::uuid();
            }
            if (auth()->check() && empty($result->created_by)) {
                $result->created_by = auth()->id();
            }
        });

        static::updating(function (StudentTestResult $result) {
            if (auth()->check()) {
                $result->updated_by = auth()->id();
            }
        });

        static::deleting(function (StudentTestResult $result) {
            if (auth()->check() && ! $result->isForceDeleting() && empty($result->deleted_by)) {
                $result->deleted_by = auth()->id();
                $result->saveQuietly();
            }
        });
    }

    /* ----------------------------------------------------------------- *
     *  Relationships
     * ----------------------------------------------------------------- */

    public function test(): BelongsTo
    {
        return $this->belongsTo(StudentTest::class, 'student_test_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class, 'enrollment_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'section_id');
    }

    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluated_by');
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

    public function scopePresent(Builder $query): Builder
    {
        return $query->where('attendance_status', self::STATUS_PRESENT);
    }

    public function scopeAbsent(Builder $query): Builder
    {
        return $query->where('attendance_status', self::STATUS_ABSENT);
    }

    public function scopeTopTen(Builder $query): Builder
    {
        return $query->where('is_top_ten', true)->orderBy('rank', 'asc');
    }

    public function scopeForStudent(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /* ----------------------------------------------------------------- *
     *  Helpers & Calculation
     * ----------------------------------------------------------------- */

    public function isPresent(): bool
    {
        return $this->attendance_status === self::STATUS_PRESENT;
    }

    /**
     * Compute marks, percentages and blank answers based on MCQ parameters.
     */
    public function computeScores(?StudentTest $test = null): void
    {
        $test = $test ?? $this->test;
        if (! $test) {
            return;
        }

        $totalQuestions = $this->total_questions > 0 ? $this->total_questions : ($test->total_questions ?: 100);
        $this->total_questions = $totalQuestions;
        $this->total_marks = $this->total_marks > 0 ? $this->total_marks : $test->total_marks;

        if ($test->isMcq()) {
            $right = (int) $this->right_answers;
            $wrong = (int) $this->wrong_answers;
            $attempted = $right + $wrong;
            $blank = max(0, $totalQuestions - $attempted);

            $this->attempted = $attempted;
            $this->blank_answers = $blank;

            $markPerQ = (float) ($test->marks_per_question ?: 2.0);
            $negPerQ = $test->penaltyPerWrongAnswer();

            $posMarks = round($right * $markPerQ, 2);
            $negMarks = round($wrong * $negPerQ, 2);
            $netMarks = round($posMarks - $negMarks, 2);

            $this->positive_marks = $posMarks;
            $this->negative_marks = $negMarks;
            $this->marks_obtained = $netMarks;
        }

        if ($this->total_marks > 0) {
            $this->percentage = round(($this->marks_obtained / $this->total_marks) * 100, 2);
        } else {
            $this->percentage = 0.0;
        }

        if ($test->pass_marks !== null && $test->pass_marks > 0) {
            $this->is_passed = ($this->marks_obtained >= (float) $test->pass_marks);
        } else {
            $this->is_passed = true;
        }
    }

    public function rankBadgeColor(): string
    {
        return match ($this->rank) {
            1 => 'amber',
            2 => 'slate',
            3 => 'orange',
            default => 'violet',
        };
    }
}
