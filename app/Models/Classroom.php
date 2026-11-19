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
 * @property string $room_code
 * @property string $name
 * @property string $building
 * @property string $floor
 * @property int|null $capacity
 * @property string $room_type
 * @property string $status
 * @property array|null $facilities
 * @property array|null $assigned_courses
 * @property array|null $assigned_sections
 * @property string|null $remarks
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, ClassSchedule> $classSchedules
 * @property-read User|null $creator
 * @property-read User|null $updater
 */
class Classroom extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $table = 'classrooms';

    protected $fillable = [
        'room_code',
        'name',
        'building',
        'floor',
        'capacity',
        'room_type',
        'status',
        'facilities',
        'assigned_courses',
        'assigned_sections',
        'remarks',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'facilities' => 'array',
            'assigned_courses' => 'array',
            'assigned_sections' => 'array',
            'capacity' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Classroom $classroom) {
            if (empty($classroom->room_code)) {
                $count = static::withTrashed()->count() + 1;
                $classroom->room_code = 'CR-'.str_pad((string) $count, 3, '0', STR_PAD_LEFT);
            }
            if (auth()->check() && empty($classroom->created_by)) {
                $classroom->created_by = auth()->id();
            }
        });

        static::updating(function (Classroom $classroom) {
            if (auth()->check()) {
                $classroom->updated_by = auth()->id();
            }
        });

        static::deleting(function (Classroom $classroom) {
            if (auth()->check() && ! $classroom->isForceDeleting() && empty($classroom->deleted_by)) {
                $classroom->deleted_by = auth()->id();
                $classroom->saveQuietly();
            }
        });
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

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeBuilding(Builder $query, string $building): Builder
    {
        return $query->where('building', $building);
    }

    public function scopeFloor(Builder $query, string $floor): Builder
    {
        return $query->where('floor', $floor);
    }

    public function getDisplayNameAttribute(): string
    {
        $parts = [$this->name];
        if ($this->building) {
            $parts[] = $this->building;
        }
        if ($this->floor) {
            $parts[] = $this->floor;
        }
        if ($this->capacity) {
            $parts[] = "Cap: {$this->capacity}";
        }

        return implode(' - ', $parts);
    }

    /**
     * Check if classroom is suitable for a specific course and section.
     */
    public function isAssignedToCourseOrSection(?int $courseId = null, ?int $sectionId = null): bool
    {
        $courses = $this->assigned_courses ?? [];
        $sections = $this->assigned_sections ?? [];

        // If no restrictions are defined, available for any course/section
        if (empty($courses) && empty($sections)) {
            return true;
        }

        if ($courseId && ! empty($courses) && in_array($courseId, $courses, false)) {
            return true;
        }

        if ($sectionId && ! empty($sections) && in_array($sectionId, $sections, false)) {
            return true;
        }

        return false;
    }
}
