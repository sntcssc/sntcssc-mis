<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $issue_no
 * @property int $book_id
 * @property int $book_copy_id
 * @property int $library_member_id
 * @property int|null $user_id
 * @property Carbon $issue_date
 * @property Carbon $due_date
 * @property Carbon|null $return_date
 * @property string $status
 * @property int $renewal_count
 * @property int $max_renewals_allowed
 * @property Carbon|null $last_renewed_at
 * @property int|null $issued_by
 * @property int|null $returned_by
 * @property string|null $return_condition
 * @property float $book_price_at_issue
 * @property bool $is_deposit_bypassed
 * @property string|null $bypass_reason
 * @property string|null $notes
 * @property Carbon|null $last_reminder_sent_at
 * @property int $reminders_count
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class BookIssue extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    public const STATUS_ISSUED = 'issued';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_OVERDUE = 'overdue';

    public const STATUS_RENEWED = 'renewed';

    public const STATUS_LOST = 'lost';

    public const TYPE_CIRCULATION = 'circulation';

    public const TYPE_IN_HOUSE = 'in_house';

    public const RENEWAL_NONE = 'none';

    public const RENEWAL_REQUESTED = 'requested';

    public const RENEWAL_APPROVED = 'approved';

    public const RENEWAL_REJECTED = 'rejected';

    protected $fillable = [
        'issue_no',
        'issue_number',
        'book_id',
        'book_copy_id',
        'library_member_id',
        'user_id',
        'issue_date',
        'due_date',
        'return_date',
        'status',
        'issue_type',
        'inhouse_session_token',
        'reader_name',
        'reader_phone',
        'reader_identifier',
        'reading_room_seat_no',
        'due_time',
        'returned_time',
        'renewal_count',
        'max_renewals_allowed',
        'last_renewed_at',
        'renewal_status',
        'renewal_requested_at',
        'renewal_requested_days',
        'renewal_request_reason',
        'renewal_reviewed_by',
        'renewal_reviewed_at',
        'renewal_rejection_reason',
        'issued_by',
        'returned_by',
        'return_condition',
        'book_price_at_issue',
        'is_deposit_bypassed',
        'bypass_reason',
        'notes',
        'last_reminder_sent_at',
        'reminders_count',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'due_date' => 'date',
            'return_date' => 'date',
            'returned_time' => 'datetime',
            'last_renewed_at' => 'datetime',
            'last_reminder_sent_at' => 'datetime',
            'renewal_requested_at' => 'datetime',
            'renewal_reviewed_at' => 'datetime',
            'renewal_requested_days' => 'integer',
            'renewal_count' => 'integer',
            'max_renewals_allowed' => 'integer',
            'reminders_count' => 'integer',
            'book_price_at_issue' => 'decimal:2',
            'is_deposit_bypassed' => 'boolean',
        ];
    }

    public function getIssueNumberAttribute($value = null): string
    {
        return (string) ($value ?? $this->attributes['issue_no'] ?? '');
    }

    public function isInHouseReading(): bool
    {
        return $this->issue_type === self::TYPE_IN_HOUSE;
    }

    public function isRenewalPending(): bool
    {
        return $this->renewal_status === self::RENEWAL_REQUESTED;
    }

    public function isRenewalApproved(): bool
    {
        return $this->renewal_status === self::RENEWAL_APPROVED;
    }

    public function isRenewalRejected(): bool
    {
        return $this->renewal_status === self::RENEWAL_REJECTED;
    }

    public function scopePendingRenewal($query)
    {
        return $query->where('renewal_status', self::RENEWAL_REQUESTED);
    }

    public function scopeInHouse($query)
    {
        return $query->where('issue_type', self::TYPE_IN_HOUSE);
    }

    public function scopeCirculation($query)
    {
        return $query->where('issue_type', self::TYPE_CIRCULATION);
    }

    protected static function booted(): void
    {
        static::creating(function (BookIssue $issue) {
            if (empty($issue->issue_no) && ! empty($issue->issue_number)) {
                $issue->issue_no = $issue->issue_number;
            } elseif (empty($issue->issue_number) && ! empty($issue->issue_no)) {
                $issue->issue_number = $issue->issue_no;
            }

            if (empty($issue->renewal_status)) {
                $issue->renewal_status = self::RENEWAL_NONE;
            }
            if (auth()->check() && empty($issue->created_by)) {
                $issue->created_by = auth()->id();
            }
            if (auth()->check() && empty($issue->issued_by)) {
                $issue->issued_by = auth()->id();
            }
        });

        static::saving(function (BookIssue $issue) {
            if (! empty($issue->issue_no) && empty($issue->issue_number)) {
                $issue->issue_number = $issue->issue_no;
            } elseif (! empty($issue->issue_number) && empty($issue->issue_no)) {
                $issue->issue_no = $issue->issue_number;
            }
        });

        static::created(function (BookIssue $issue) {
            $issue->libraryMember?->refreshIssueCounters();
        });

        static::updated(function (BookIssue $issue) {
            $issue->libraryMember?->refreshIssueCounters();
        });

        static::deleted(function (BookIssue $issue) {
            $issue->libraryMember?->refreshIssueCounters();
        });
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class, 'book_id');
    }

    public function bookCopy(): BelongsTo
    {
        return $this->belongsTo(BookCopy::class, 'book_copy_id');
    }

    public function libraryMember(): BelongsTo
    {
        return $this->belongsTo(LibraryMember::class, 'library_member_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    public function renewalReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'renewal_reviewed_by');
    }

    public function fine(): HasOne
    {
        return $this->hasOne(BookFine::class, 'book_issue_id');
    }

    /**
     * Check if currently overdue.
     */
    public function isOverdue(): bool
    {
        if ($this->status === self::STATUS_RETURNED) {
            return false;
        }

        return now()->startOfDay()->gt($this->due_date->startOfDay());
    }

    /**
     * Compute overdue days.
     */
    public function getOverdueDays(): int
    {
        if (! $this->isOverdue()) {
            return 0;
        }

        $endDate = $this->return_date ? $this->return_date->startOfDay() : now()->startOfDay();

        return (int) max(0, $this->due_date->startOfDay()->diffInDays($endDate, false));
    }

    /**
     * Calculate overdue fine based on application settings.
     */
    public function calculateCurrentFine(float $fineRatePerDay = 5.00, bool $finesEnabled = true): float
    {
        if (! $finesEnabled) {
            return 0.00;
        }

        $overdueDays = $this->getOverdueDays();

        return round($overdueDays * $fineRatePerDay, 2);
    }

    public function getBypassJustificationAttribute(): ?string
    {
        return $this->attributes['bypass_reason'] ?? null;
    }
}
