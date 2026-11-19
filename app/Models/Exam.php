<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Exam extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_REGISTRATION_CLOSED = 'registration_closed';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'uuid',
        'exam_code',
        'exam_name',
        'academic_year',
        'conducting_body',
        'notification_date',
        'apply_start',
        'apply_end',
        'exam_date',
        'exam_time',
        'reporting_time',
        'duration_minutes',
        'fee_general',
        'fee_reserved',
        'total_vacancies',
        'status',
        'instructions',
        'admit_card_instructions',
        'admit_card_release_date',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'notification_date' => 'date',
            'apply_start' => 'date',
            'apply_end' => 'date',
            'exam_date' => 'date',
            'admit_card_release_date' => 'date',
            'duration_minutes' => 'integer',
            'fee_general' => 'decimal:2',
            'fee_reserved' => 'decimal:2',
            'total_vacancies' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Exam $exam) {
            if (empty($exam->uuid)) {
                $exam->uuid = (string) Str::uuid();
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function centers(): BelongsToMany
    {
        return $this->belongsToMany(ExaminationCenter::class, 'exam_center_mappings', 'exam_id', 'examination_center_id')
            ->withPivot('allocated_seats', 'is_active')
            ->withTimestamps();
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(ExamCandidate::class, 'exam_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('exam_date', '>=', now()->toDateString());
    }
}
