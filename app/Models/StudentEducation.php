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
 * @property int $user_id
 * @property string $level
 * @property string|null $board_university
 * @property string|null $institute
 * @property string|null $stream_subject
 * @property int|null $passing_year
 * @property int|null $total_marks
 * @property int|null $marks_obtained
 * @property float|null $percentage
 * @property float|null $cgpa
 * @property string|null $division
 * @property string|null $completion_status
 * @property bool $is_highest
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $user
 */
class StudentEducation extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $table = 'student_educations';

    protected $fillable = [
        'user_id',
        'level',
        'board_university',
        'institute',
        'stream_subject',
        'passing_year',
        'total_marks',
        'marks_obtained',
        'percentage',
        'cgpa',
        'division',
        'completion_status',
        'is_highest',
    ];

    protected function casts(): array
    {
        return [
            'passing_year' => 'integer',
            'total_marks' => 'integer',
            'marks_obtained' => 'integer',
            'percentage' => 'decimal:2',
            'cgpa' => 'decimal:2',
            'is_highest' => 'boolean',
        ];
    }

    public function setPassingYearAttribute(mixed $value): void
    {
        $this->attributes['passing_year'] = (is_string($value) && trim($value) === '') || is_null($value) ? null : (int) $value;
    }

    public function setTotalMarksAttribute(mixed $value): void
    {
        $this->attributes['total_marks'] = (is_string($value) && trim($value) === '') || is_null($value) ? null : (int) $value;
    }

    public function setMarksObtainedAttribute(mixed $value): void
    {
        $this->attributes['marks_obtained'] = (is_string($value) && trim($value) === '') || is_null($value) ? null : (int) $value;
    }

    public function setPercentageAttribute(mixed $value): void
    {
        $this->attributes['percentage'] = (is_string($value) && trim($value) === '') || is_null($value) ? null : (float) $value;
    }

    public function setCgpaAttribute(mixed $value): void
    {
        $this->attributes['cgpa'] = (is_string($value) && trim($value) === '') || is_null($value) ? null : (float) $value;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
