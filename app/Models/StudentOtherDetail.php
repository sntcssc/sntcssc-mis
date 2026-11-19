<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $attempts_already_given
 * @property int|null $attempts_left
 * @property string|null $optional_subject
 * @property int|null $year_of_appearing
 * @property string|null $activities
 * @property string|null $hobbies
 * @property array|null $dynamic_fields
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
class StudentOtherDetail extends Model
{
    use Auditable, HasFactory;

    protected $table = 'student_other_details';

    protected $fillable = [
        'user_id',
        'attempts_already_given',
        'attempts_left',
        'optional_subject',
        'year_of_appearing',
        'activities',
        'hobbies',
        'dynamic_fields',
    ];

    protected function casts(): array
    {
        return [
            'attempts_already_given' => 'integer',
            'attempts_left' => 'integer',
            'year_of_appearing' => 'integer',
            'dynamic_fields' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
