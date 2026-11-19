<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $batch_id
 * @property int $course_id
 * @property int|null $capacity
 * @property float|null $fee
 * @property Carbon|null $start_date
 * @property Carbon|null $end_date
 * @property string $status
 * @property string|null $remarks
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Batch $batch
 * @property-read Course $course
 */
class BatchCourse extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $table = 'batch_courses';

    protected $fillable = [
        'batch_id',
        'course_id',
        'capacity',
        'fee',
        'start_date',
        'end_date',
        'status',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'fee' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class, 'batch_course_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'course_id', 'course_id')
            ->where('batch_id', $this->batch_id);
    }
}
