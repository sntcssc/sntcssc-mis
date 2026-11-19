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
 * @property int $exam_year
 * @property string|null $roll_number
 * @property bool $prelims_cleared
 * @property bool $mains_cleared
 * @property bool $interview_cleared
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
class UpscAttempt extends Model
{
    use Auditable, HasFactory;

    protected $table = 'upsc_attempts';

    protected $fillable = [
        'user_id',
        'exam_year',
        'roll_number',
        'prelims_cleared',
        'mains_cleared',
        'interview_cleared',
    ];

    protected function casts(): array
    {
        return [
            'exam_year' => 'integer',
            'prelims_cleared' => 'boolean',
            'mains_cleared' => 'boolean',
            'interview_cleared' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
