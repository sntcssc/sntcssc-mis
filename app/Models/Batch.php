<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string $academic_year
 * @property Carbon|null $start_date
 * @property Carbon|null $end_date
 * @property string $status
 * @property string|null $description
 * @property int|null $max_students
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, Course> $courses
 * @property-read Collection<int, BatchCourse> $batchCourses
 * @property-read Collection<int, Section> $sections
 * @property-read Collection<int, Enrollment> $enrollments
 * @property-read User|null $creator
 * @property-read User|null $updater
 */
class Batch extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'academic_year',
        'start_date',
        'end_date',
        'status',
        'description',
        'max_students',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'max_students' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Batch $batch) {
            if (auth()->check() && empty($batch->created_by)) {
                $batch->created_by = auth()->id();
            }
        });

        static::updating(function (Batch $batch) {
            if (auth()->check()) {
                $batch->updated_by = auth()->id();
            }
        });

        static::deleting(function (Batch $batch) {
            if (auth()->check() && ! $batch->isForceDeleting() && empty($batch->deleted_by)) {
                $batch->deleted_by = auth()->id();
                $batch->saveQuietly();
            }
        });
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'batch_courses')
            ->withPivot(['id', 'capacity', 'fee', 'start_date', 'end_date', 'status', 'remarks'])
            ->withTimestamps();
    }

    public function partnerInstitutes(): BelongsToMany
    {
        return $this->belongsToMany(PartnerInstitute::class, 'partner_institute_batches')->withTimestamps();
    }

    public function batchCourses(): HasMany
    {
        return $this->hasMany(BatchCourse::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('status', 'upcoming');
    }

    public function scopeForYear(Builder $query, string $year): Builder
    {
        return $query->where('academic_year', $year);
    }

    public function getEnrolledCountAttribute(): int
    {
        return $this->enrollments()->where('status', 'active')->count();
    }

    public function getIntakeCapacityAttribute(): ?int
    {
        return $this->max_students;
    }

    public function feeSchedules(): HasMany
    {
        return $this->hasMany(FeeSchedule::class, 'batch_id');
    }

    public function studentTests(): HasMany
    {
        return $this->hasMany(StudentTest::class);
    }

    public function studyMaterials(): BelongsToMany
    {
        return $this->belongsToMany(StudyMaterial::class, 'study_material_batches')
            ->withTimestamps();
    }

    public function classSchedules(): HasMany
    {
        return $this->hasMany(ClassSchedule::class);
    }

    public function coursePlans(): HasMany
    {
        return $this->hasMany(CoursePlan::class);
    }
}
