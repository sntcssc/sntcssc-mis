<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContactSubmission extends Model
{
    use Auditable;
    use HasFactory;
    use SoftDeletes;

    public const STATUS_NEW = 'new';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_REPLIED = 'replied';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_CLOSED = 'closed';

    public const PRIORITY_LOW = 'low';

    public const PRIORITY_MEDIUM = 'medium';

    public const PRIORITY_HIGH = 'high';

    public const PRIORITY_URGENT = 'urgent';

    protected $fillable = [
        'reference_no',
        'name',
        'email',
        'mobile',
        'whatsapp',
        'department_id',
        'subject_id',
        'custom_subject',
        'message',
        'ip_address',
        'user_agent',
        'status',
        'priority',
        'admin_notes',
        'replied_at',
        'replied_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'replied_at' => 'datetime',
        ];
    }

    /* ----------------------------------------------------------------- *
     *  Booted: Unique Reference Generator
     * ----------------------------------------------------------------- */

    protected static function booted(): void
    {
        static::creating(function (self $submission) {
            if (empty($submission->reference_no)) {
                $submission->reference_no = self::generateReferenceNumber();
            }
        });
    }

    public static function generateReferenceNumber(): string
    {
        $year = date('Y');
        $random = strtoupper(bin2hex(random_bytes(3))); // 6 hex chars

        return "SNT-REQ-{$year}-{$random}";
    }

    /* ----------------------------------------------------------------- *
     *  Relationships
     * ----------------------------------------------------------------- */

    public function department(): BelongsTo
    {
        return $this->belongsTo(ContactDepartment::class, 'department_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(ContactSubject::class, 'subject_id');
    }

    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replied_by');
    }

    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    /* ----------------------------------------------------------------- *
     *  Scopes & Helpers
     * ----------------------------------------------------------------- */

    public function scopeNew(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_NEW);
    }

    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function getSubjectTitleAttribute(): string
    {
        return $this->subject?->name ?? $this->custom_subject ?? __('General Inquiry');
    }

    public function getDepartmentNameAttribute(): string
    {
        return $this->department?->name ?? __('General Administration');
    }
}
