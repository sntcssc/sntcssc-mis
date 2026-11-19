<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Helpers\CourseData;
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
 * @property string $category
 * @property string|null $description
 * @property string|null $duration
 * @property float $fee
 * @property bool $has_sections
 * @property string|null $identifier_code
 * @property bool $admit_once_only
 * @property int|null $max_attempts
 * @property string $status
 * @property array|null $rules
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, Batch> $batches
 * @property-read Collection<int, BatchCourse> $batchCourses
 * @property-read Collection<int, Section> $sections
 * @property-read Collection<int, Enrollment> $enrollments
 * @property-read User|null $creator
 * @property-read User|null $updater
 */
class Course extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'category',
        'description',
        'duration',
        'fee',
        'fee_type',
        'fee_reserved',
        'fee_structure',
        'capacity',
        'has_sections',
        'identifier_code',
        'admit_once_only',
        'max_attempts',
        'status',
        'rules',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'fee' => 'decimal:2',
            'fee_reserved' => 'decimal:2',
            'fee_structure' => 'array',
            'capacity' => 'integer',
            'has_sections' => 'boolean',
            'admit_once_only' => 'boolean',
            'max_attempts' => 'integer',
            'rules' => 'array',
        ];
    }

    public function getFeeForCategory(?string $category = 'UR'): float
    {
        return CourseData::calculateFee($this, $category);
    }

    public function isUnlimitedCapacity(): bool
    {
        return $this->capacity === null;
    }

    public function isMonthlyFee(): bool
    {
        return ($this->fee_type ?? 'monthly') === 'monthly';
    }

    public function isOneTimeFee(): bool
    {
        return ($this->fee_type ?? 'monthly') === 'one_time';
    }

    public function isFree(): bool
    {
        return ($this->fee_type ?? '') === 'free' || ((float) $this->fee === 0.0 && ($this->fee_reserved === null || (float) $this->fee_reserved === 0.0));
    }

    protected static function booted(): void
    {
        static::creating(function (Course $course) {
            if (auth()->check() && empty($course->created_by)) {
                $course->created_by = auth()->id();
            }
        });

        static::updating(function (Course $course) {
            if (auth()->check()) {
                $course->updated_by = auth()->id();
            }
        });

        static::deleting(function (Course $course) {
            if (auth()->check() && ! $course->isForceDeleting() && empty($course->deleted_by)) {
                $course->deleted_by = auth()->id();
                $course->saveQuietly();
            }
        });
    }

    public function batches(): BelongsToMany
    {
        return $this->belongsToMany(Batch::class, 'batch_courses')
            ->withPivot(['id', 'capacity', 'fee', 'start_date', 'end_date', 'status', 'remarks'])
            ->withTimestamps();
    }

    public function partnerInstitutes(): BelongsToMany
    {
        return $this->belongsToMany(PartnerInstitute::class, 'partner_institute_courses')->withTimestamps();
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

    public function studyMaterials(): HasMany
    {
        return $this->hasMany(StudyMaterial::class);
    }

    public function feeSchedules(): HasMany
    {
        return $this->hasMany(FeeSchedule::class);
    }

    public function studentTests(): HasMany
    {
        return $this->hasMany(StudentTest::class);
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

    public function scopeWithSections(Builder $query): Builder
    {
        return $query->where('has_sections', true);
    }

    public function getActiveEnrolledCountAttribute(): int
    {
        return $this->enrollments()->where('status', 'active')->count();
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
