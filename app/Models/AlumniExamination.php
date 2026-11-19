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
 * @property string $examination_name
 * @property int $exam_year
 * @property string|null $service_allotted
 * @property int|null $all_india_rank
 * @property int|null $state_rank
 * @property string|null $roll_number
 * @property string|null $optional_subject
 * @property string|null $score_or_marks
 * @property string|null $category_rank
 * @property bool $is_primary
 * @property string|null $remarks
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Alumni $alumni
 */
class AlumniExamination extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $table = 'alumni_examinations';

    protected $fillable = [
        'alumni_id',
        'examination_name',
        'exam_year',
        'service_allotted',
        'all_india_rank',
        'state_rank',
        'roll_number',
        'optional_subject',
        'score_or_marks',
        'category_rank',
        'is_primary',
        'remarks',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'exam_year' => 'integer',
            'all_india_rank' => 'integer',
            'state_rank' => 'integer',
            'is_primary' => 'boolean',
        ];
    }

    public function alumni(): BelongsTo
    {
        return $this->belongsTo(Alumni::class, 'alumni_id');
    }
}
