<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $alumni_id
 * @property int|null $course_id
 * @property int|null $batch_id
 * @property string $course_name
 * @property string $batch_name
 * @property string|null $enrollment_no
 * @property string|null $roll_no
 * @property string|null $academic_year
 * @property int|null $start_year
 * @property int|null $completion_year
 * @property string $status
 * @property string|null $remarks
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Alumni $alumni
 * @property-read Course|null $course
 * @property-read Batch|null $batch
 */
class AlumniCourseBatch extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $table = 'alumni_course_batches';

    protected $fillable = [
        'alumni_id',
        'course_id',
        'batch_id',
        'course_name',
        'batch_name',
        'enrollment_no',
        'roll_no',
        'academic_year',
        'start_year',
        'completion_year',
        'status',
        'remarks',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_year' => 'integer',
            'completion_year' => 'integer',
        ];
    }

    public function alumni(): BelongsTo
    {
        return $this->belongsTo(Alumni::class, 'alumni_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_id');
    }
}
