<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ExamCandidate extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    public const ATTENDANCE_PENDING = 'pending';

    public const ATTENDANCE_PRESENT = 'present';

    public const ATTENDANCE_ABSENT = 'absent';

    public const ATTENDANCE_DISQUALIFIED = 'disqualified';

    protected $fillable = [
        'exam_id',
        'student_id',
        'user_id',
        'candidate_name',
        'candidate_email',
        'candidate_phone',
        'roll_number',
        'examination_center_id',
        'examination_center_room_id',
        'seat_number',
        'admit_card_token',
        'admit_card_downloaded_at',
        'attendance_status',
        'attendance_marked_at',
        'marks_obtained',
        'rank',
        'is_qualified',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'admit_card_downloaded_at' => 'datetime',
            'attendance_marked_at' => 'datetime',
            'marks_obtained' => 'decimal:2',
            'rank' => 'integer',
            'is_qualified' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ExamCandidate $candidate) {
            if (empty($candidate->admit_card_token)) {
                $candidate->admit_card_token = Str::random(32);
            }
        });
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class, 'exam_id');
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(ExaminationCenter::class, 'examination_center_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(ExaminationCenterRoom::class, 'examination_center_room_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'student_id');
    }

    public function scopePresent(Builder $query): Builder
    {
        return $query->where('attendance_status', self::ATTENDANCE_PRESENT);
    }
}
