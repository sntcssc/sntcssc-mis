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
 * @property int $batch_id
 * @property int $course_id
 * @property int|null $batch_course_id
 * @property string $name
 * @property string $code
 * @property int $capacity
 * @property string|null $room_number
 * @property string|null $timing
 * @property string $status
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Batch $batch
 * @property-read Course $course
 * @property-read BatchCourse|null $batchCourse
 * @property-read Collection<int, Enrollment> $enrollments
 */
class Section extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'batch_id',
        'course_id',
        'batch_course_id',
        'name',
        'code',
        'capacity',
        'room_number',
        'timing',
        'status',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Section $section) {
            if (auth()->check() && empty($section->created_by)) {
                $section->created_by = auth()->id();
            }
        });

        static::updating(function (Section $section) {
            if (auth()->check()) {
                $section->updated_by = auth()->id();
            }
        });

        static::deleting(function (Section $section) {
            if (auth()->check() && ! $section->isForceDeleting() && empty($section->deleted_by)) {
                $section->deleted_by = auth()->id();
                $section->saveQuietly();
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

    public function batchCourse(): BelongsTo
    {
        return $this->belongsTo(BatchCourse::class);
    }

    public function partnerInstitutes(): BelongsToMany
    {
        return $this->belongsToMany(PartnerInstitute::class, 'partner_institute_sections')->withTimestamps();
    }

    public function studentTests(): HasMany
    {
        return $this->hasMany(StudentTest::class);
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

    public function getEnrolledCountAttribute(): int
    {
        return $this->enrollments()->where('status', 'active')->count();
    }

    public function getIsFullAttribute(): bool
    {
        return $this->capacity > 0 && $this->enrolled_count >= $this->capacity;
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
