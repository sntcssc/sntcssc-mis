<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property string $member_number
 * @property int|null $user_id
 * @property string|null $student_id
 * @property string $member_type
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $gender
 * @property string|null $address
 * @property Carbon|null $date_of_birth
 * @property Carbon $joining_date
 * @property Carbon|null $expiry_date
 * @property string|null $photo_path
 * @property float|null $security_deposit_override
 * @property bool $bypass_deposit_check
 * @property string|null $deposit_bypass_reason
 * @property string $status
 * @property int $active_issued_count
 * @property int $total_issued_count
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class LibraryMember extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_CANCELLED = 'cancelled';

    public const TYPE_STUDENT = 'student';

    public const TYPE_FACULTY = 'faculty';

    public const TYPE_STAFF = 'staff';

    public const TYPE_EXTERNAL = 'external';

    protected $fillable = [
        'member_number',
        'user_id',
        'student_id',
        'member_type',
        'name',
        'email',
        'phone',
        'gender',
        'address',
        'date_of_birth',
        'joining_date',
        'expiry_date',
        'photo_path',
        'security_deposit_override',
        'bypass_deposit_check',
        'deposit_bypass_reason',
        'status',
        'active_issued_count',
        'total_issued_count',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'joining_date' => 'date',
            'expiry_date' => 'date',
            'security_deposit_override' => 'decimal:2',
            'bypass_deposit_check' => 'boolean',
            'active_issued_count' => 'integer',
            'total_issued_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (LibraryMember $member) {
            if (empty($member->joining_date)) {
                $member->joining_date = now()->toDateString();
            }
            if (auth()->check() && empty($member->created_by)) {
                $member->created_by = auth()->id();
            }
        });

        static::updating(function (LibraryMember $member) {
            if (auth()->check()) {
                $member->updated_by = auth()->id();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(BookIssue::class, 'library_member_id');
    }

    public function activeIssues(): HasMany
    {
        return $this->hasMany(BookIssue::class, 'library_member_id')->whereIn('status', ['issued', 'overdue', 'renewed']);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(BookReservation::class, 'library_member_id');
    }

    public function activeReservations(): HasMany
    {
        return $this->hasMany(BookReservation::class, 'library_member_id')->whereIn('status', ['pending', 'ready_for_pickup']);
    }

    public function fines(): HasMany
    {
        return $this->hasMany(BookFine::class, 'library_member_id');
    }

    public function pendingFines(): HasMany
    {
        return $this->hasMany(BookFine::class, 'library_member_id')->whereIn('status', ['pending', 'partially_paid']);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Compute the member's current active security deposit amount.
     */
    public function getActiveSecurityDeposit(): float
    {
        $deposit = 0.00;

        if ($this->user_id) {
            $userDeposits = LibraryDeposit::where('user_id', $this->user_id)
                ->where('status', LibraryDeposit::STATUS_ACTIVE)
                ->sum('amount');
            $deposit += (float) $userDeposits;
        }

        if ($this->security_deposit_override !== null && $this->security_deposit_override > 0) {
            $deposit += (float) $this->security_deposit_override;
        }

        return round($deposit, 2);
    }

    /**
     * Compute total replacement price of currently borrowed books.
     */
    public function getActiveBorrowedBooksValue(): float
    {
        return (float) $this->activeIssues()
            ->with('bookCopy')
            ->get()
            ->sum(function ($issue) {
                return (float) ($issue->book_price_at_issue ?: ($issue->bookCopy->purchase_cost ?? 0.00));
            });
    }

    /**
     * Recalculate and update issue counters.
     */
    public function refreshIssueCounters(): void
    {
        $this->updateQuietly([
            'active_issued_count' => $this->activeIssues()->count(),
            'total_issued_count' => $this->issues()->count(),
        ]);
    }

    /**
     * Get member avatar or profile photo URL.
     */
    public function photoUrl(): string
    {
        if ($this->photo_path) {
            if (str_starts_with($this->photo_path, 'http')) {
                return $this->photo_path;
            }
            if (Storage::disk('public')->exists($this->photo_path)) {
                return Storage::disk('public')->url($this->photo_path);
            }
            if (file_exists(public_path($this->photo_path))) {
                return asset($this->photo_path);
            }
        }

        if ($this->user && $this->user->avatar_url) {
            return $this->user->avatar_url;
        }

        return '';
    }
}
