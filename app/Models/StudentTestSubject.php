<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $student_test_id
 * @property string $subject_name
 * @property string|null $subject_code
 * @property int $questions_count
 * @property float $max_marks
 * @property float $marks_per_question
 * @property float $negative_marking
 * @property int $display_order
 * @property-read StudentTest $test
 */
class StudentTestSubject extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_test_id',
        'subject_name',
        'subject_code',
        'questions_count',
        'max_marks',
        'marks_per_question',
        'negative_marking',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'questions_count' => 'integer',
            'max_marks' => 'decimal:2',
            'marks_per_question' => 'decimal:2',
            'negative_marking' => 'decimal:2',
            'display_order' => 'integer',
        ];
    }

    public function test(): BelongsTo
    {
        return $this->belongsTo(StudentTest::class, 'student_test_id');
    }
}
