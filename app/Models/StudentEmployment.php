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
 * @property string|null $employer_name
 * @property string|null $designation
 * @property string|null $nature_of_employment
 * @property string|null $location
 * @property Carbon|null $from_date
 * @property Carbon|null $to_date
 * @property bool $is_current
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $user
 */
class StudentEmployment extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $table = 'student_employments';

    protected $fillable = [
        'user_id',
        'employer_name',
        'designation',
        'nature_of_employment',
        'location',
        'from_date',
        'to_date',
        'is_current',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
            'is_current' => 'boolean',
        ];
    }

    public function setFromDateAttribute(mixed $value): void
    {
        if (empty($value)) {
            $this->attributes['from_date'] = null;
        } elseif ($value instanceof \DateTimeInterface) {
            $this->attributes['from_date'] = $value->format('Y-m-d');
        } else {
            try {
                $this->attributes['from_date'] = Carbon::parse($value)->format('Y-m-d');
            } catch (\Throwable) {
                $this->attributes['from_date'] = null;
            }
        }
    }

    public function setToDateAttribute(mixed $value): void
    {
        if (empty($value)) {
            $this->attributes['to_date'] = null;
        } elseif ($value instanceof \DateTimeInterface) {
            $this->attributes['to_date'] = $value->format('Y-m-d');
        } else {
            try {
                $this->attributes['to_date'] = Carbon::parse($value)->format('Y-m-d');
            } catch (\Throwable) {
                $this->attributes['to_date'] = null;
            }
        }
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
