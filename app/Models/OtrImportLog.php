<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int|null $user_id
 * @property int|null $enrollment_id
 * @property int|null $course_id
 * @property int|null $batch_id
 * @property int|null $section_id
 * @property int|null $imported_by
 * @property int|null $admission_selection_id
 * @property int|null $admission_form_id
 * @property string|null $admission_form_number
 * @property int|null $application_id
 * @property string|null $application_number
 * @property int|null $exam_id
 * @property string|null $exam_code
 * @property string|null $urn
 * @property string $candidate_name
 * @property string $candidate_email
 * @property string|null $candidate_phone
 * @property string $status
 * @property string $action_taken
 * @property int $downloaded_documents_count
 * @property array|null $payload_snapshot
 * @property string|null $error_message
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read User|null $user
 * @property-read Enrollment|null $enrollment
 * @property-read Course|null $course
 * @property-read Batch|null $batch
 * @property-read Section|null $section
 * @property-read User|null $importedBy
 */
class OtrImportLog extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ALREADY_ENROLLED = 'already_enrolled';
    public const STATUS_SKIPPED = 'skipped';

    public const ACTION_CREATED_AND_ENROLLED = 'created_student_and_enrolled';
    public const ACTION_ENROLLED_EXISTING = 'enrolled_existing_student';
    public const ACTION_ALREADY_ENROLLED = 'already_enrolled';
    public const ACTION_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'enrollment_id',
        'course_id',
        'batch_id',
        'section_id',
        'imported_by',
        'admission_selection_id',
        'admission_form_id',
        'admission_form_number',
        'application_id',
        'application_number',
        'exam_id',
        'exam_code',
        'urn',
        'candidate_name',
        'candidate_email',
        'candidate_phone',
        'status',
        'action_taken',
        'downloaded_documents_count',
        'payload_snapshot',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'payload_snapshot' => 'array',
            'downloaded_documents_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class, 'enrollment_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'section_id');
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SUCCESS);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $term = trim($term);

        return $query->where(function (Builder $q) use ($term) {
            $q->where('candidate_name', 'like', "%{$term}%")
                ->orWhere('candidate_email', 'like', "%{$term}%")
                ->orWhere('candidate_phone', 'like', "%{$term}%")
                ->orWhere('urn', 'like', "%{$term}%")
                ->orWhere('application_number', 'like', "%{$term}%")
                ->orWhere('admission_form_number', 'like', "%{$term}%");
        });
    }
}

