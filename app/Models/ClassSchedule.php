<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property string $schedule_code
 * @property int $batch_id
 * @property int $course_id
 * @property int|null $section_id
 * @property int|null $course_plan_id
 * @property string $class_type
 * @property Carbon $class_date
 * @property string $day_of_week
 * @property string $start_time
 * @property string $end_time
 * @property string|null $lecture_number
 * @property string|null $subject
 * @property string|null $topics
 * @property string|null $description
 * @property string $delivery_mode
 * @property string|null $classroom
 * @property string|null $platform
 * @property string|null $meeting_link
 * @property string|null $meeting_id
 * @property string|null $meeting_password
 * @property string|null $platform_other
 * @property string|null $recording_url
 * @property string|null $recording_file_path
 * @property string|null $recording_password
 * @property string|null $recording_notes
 * @property string $recording_status
 * @property string|null $recurrence_group_id
 * @property bool $is_recurring
 * @property string|null $recurrence_rule
 * @property string $status
 * @property string|null $cancellation_reason
 * @property int|null $rescheduled_from_id
 * @property string|null $rescheduled_reason
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Batch $batch
 * @property-read Course $course
 * @property-read Section|null $section
 * @property-read CoursePlan|null $coursePlan
 * @property-read Collection<int, Faculty> $faculties
 * @property-read ClassSchedule|null $rescheduledFrom
 * @property-read User|null $creator
 * @property-read User|null $updater
 */
class ClassSchedule extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $table = 'class_schedules';

    protected $fillable = [
        'schedule_code',
        'batch_id',
        'course_id',
        'section_id',
        'course_plan_id',
        'classroom_id',
        'class_type',
        'class_date',
        'day_of_week',
        'start_time',
        'end_time',
        'lecture_number',
        'subject',
        'topics',
        'description',
        'delivery_mode',
        'classroom',
        'platform',
        'meeting_link',
        'meeting_id',
        'meeting_password',
        'platform_other',
        'recording_url',
        'recording_file_path',
        'recording_password',
        'recording_notes',
        'recording_status',
        'recurrence_group_id',
        'is_recurring',
        'recurrence_rule',
        'status',
        'cancellation_reason',
        'rescheduled_from_id',
        'rescheduled_reason',
        'reminder_sent_at',
        'reminder_count',
        'last_reminder_channel',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'class_date' => 'date',
            'is_recurring' => 'boolean',
            'reminder_sent_at' => 'datetime',
            'reminder_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ClassSchedule $schedule) {
            if (empty($schedule->schedule_code)) {
                $count = static::withTrashed()->count() + 1;
                $schedule->schedule_code = 'CLS-'.date('Y').'-'.str_pad((string) $count, 5, '0', STR_PAD_LEFT);
            }

            if (! empty($schedule->class_date) && empty($schedule->day_of_week)) {
                $schedule->day_of_week = Carbon::parse($schedule->class_date)->format('l');
            }

            if (auth()->check() && empty($schedule->created_by)) {
                $schedule->created_by = auth()->id();
            }
        });

        static::updating(function (ClassSchedule $schedule) {
            if ($schedule->isDirty('class_date')) {
                $schedule->day_of_week = Carbon::parse($schedule->class_date)->format('l');
            }

            if (auth()->check()) {
                $schedule->updated_by = auth()->id();
            }
        });

        static::deleting(function (ClassSchedule $schedule) {
            if (auth()->check() && ! $schedule->isForceDeleting() && empty($schedule->deleted_by)) {
                $schedule->deleted_by = auth()->id();
                $schedule->saveQuietly();
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

    public function coursePlan(): BelongsTo
    {
        return $this->belongsTo(CoursePlan::class);
    }

    public function classroomRecord(): BelongsTo
    {
        return $this->belongsTo(Classroom::class, 'classroom_id');
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class, 'classroom_id');
    }

    public function faculties(): BelongsToMany
    {
        return $this->belongsToMany(Faculty::class, 'class_schedule_faculty')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function rescheduledFrom(): BelongsTo
    {
        return $this->belongsTo(ClassSchedule::class, 'rescheduled_from_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeToday(Builder $query): Builder
    {
        return $query->whereDate('class_date', Carbon::today());
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->whereDate('class_date', '>=', Carbon::today())
            ->whereNotIn('status', ['cancelled']);
    }

    public function scopePast(Builder $query): Builder
    {
        return $query->whereDate('class_date', '<', Carbon::today());
    }

    public function scopeScheduled(Builder $query): Builder
    {
        return $query->whereIn('status', ['scheduled', 'in_progress']);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', 'completed');
    }

    public function scopeCancelled(Builder $query): Builder
    {
        return $query->where('status', 'cancelled');
    }

    public function scopeNotCancelled(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['cancelled']);
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function getClassroomDisplayAttribute(): string
    {
        if ($this->classroomRecord) {
            return $this->classroomRecord->display_name;
        }

        return $this->classroom ?: 'Not Assigned';
    }

    /**
     * Scope for classes matching a student's enrolled batches, courses, and sections.
     */
    public function scopeForStudent(Builder $query, User $student): Builder
    {
        $activeEnrollments = $student->activeEnrollments()->get();

        if ($activeEnrollments->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($activeEnrollments) {
            foreach ($activeEnrollments as $idx => $enrollment) {
                $clause = function (Builder $sub) use ($enrollment) {
                    $sub->where('batch_id', $enrollment->batch_id)
                        ->where('course_id', $enrollment->course_id)
                        ->where(function (Builder $sec) use ($enrollment) {
                            $sec->whereNull('section_id');
                            if (! empty($enrollment->section_id)) {
                                $sec->orWhere('section_id', $enrollment->section_id);
                            }
                        });
                };

                if ($idx === 0) {
                    $q->where($clause);
                } else {
                    $q->orWhere($clause);
                }
            }
        });
    }

    public function getFormattedTimeAttribute(): string
    {
        $start = $this->start_time ? Carbon::parse($this->start_time)->format('h:i A') : '';
        $end = $this->end_time ? Carbon::parse($this->end_time)->format('h:i A') : '';

        return $start && $end ? "{$start} - {$end}" : ($start ?: ($end ?: 'TBD'));
    }

    public function getDurationMinutesAttribute(): int
    {
        if (! $this->start_time || ! $this->end_time) {
            return 0;
        }

        $start = Carbon::parse($this->start_time);
        $end = Carbon::parse($this->end_time);

        return max(0, $start->diffInMinutes($end));
    }

    public function isOnline(): bool
    {
        return in_array($this->delivery_mode, ['online', 'hybrid'], true);
    }

    public function isOffline(): bool
    {
        return in_array($this->delivery_mode, ['offline', 'hybrid'], true);
    }

    public function hasRecording(): bool
    {
        return $this->recording_status === 'available' || ! empty($this->recording_url) || ! empty($this->recording_file_path);
    }

    public function getRecordingAccessUrlAttribute(): ?string
    {
        if (! empty($this->recording_url)) {
            return $this->recording_url;
        }

        if (! empty($this->recording_file_path)) {
            return Storage::disk('public')->url($this->recording_file_path);
        }

        return null;
    }

    public function isHappeningNow(): bool
    {
        if (! $this->class_date->isToday()) {
            return false;
        }

        if (! $this->start_time || ! $this->end_time) {
            return false;
        }

        $now = now();
        $start = Carbon::parse($this->class_date->format('Y-m-d').' '.$this->start_time);
        $end = Carbon::parse($this->class_date->format('Y-m-d').' '.$this->end_time);

        return $now->between($start, $end);
    }

    public function getPrimaryFacultyNamesAttribute(): string
    {
        if ($this->faculties->isEmpty()) {
            return 'Faculty Unassigned';
        }

        return $this->faculties->pluck('name')->join(', ');
    }
}
