<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $faculty_code
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $designation
 * @property string|null $department
 * @property string|null $specialization
 * @property string|null $qualification
 * @property string|null $bio
 * @property string|null $avatar_path
 * @property string $status
 * @property array|null $subjects
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User|null $user
 * @property-read Collection<int, ClassSchedule> $classSchedules
 * @property-read User|null $creator
 * @property-read User|null $updater
 */
class Faculty extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    public const DEPARTMENTS = [
        'General Studies',
        'Geography',
        'Ancient History',
        'Medival History',
        'Medieval History',
        'Modern History',
        'Polity',
        'Economy',
        'Economics',
        'History',
        'Ethics',
        'Essay',
        'CSAT',
    ];

    public const FACULTY_TYPES = [
        'regular' => 'Regular Faculty',
        'guest' => 'Guest Faculty',
        'visiting' => 'Visiting Faculty',
        'contractual' => 'Contractual Faculty',
        'adjunct' => 'Adjunct Faculty',
    ];

    protected $table = 'faculties';

    protected $fillable = [
        'user_id',
        'partner_institute_id',
        'faculty_code',
        'name',
        'email',
        'phone',
        'designation',
        'faculty_type',
        'department',
        'specialization',
        'qualification',
        'bio',
        'avatar_path',
        'photo_path',
        'cv_path',
        'status',
        'subjects',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'subjects' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Faculty $faculty) {
            if (empty($faculty->faculty_code)) {
                $count = static::withTrashed()->count() + 1;
                $faculty->faculty_code = 'FAC-'.date('Y').'-'.str_pad((string) $count, 3, '0', STR_PAD_LEFT);
            }
            if (auth()->check() && empty($faculty->created_by)) {
                $faculty->created_by = auth()->id();
            }
        });

        static::updating(function (Faculty $faculty) {
            if (auth()->check()) {
                $faculty->updated_by = auth()->id();
            }
        });

        static::deleting(function (Faculty $faculty) {
            if (auth()->check() && ! $faculty->isForceDeleting() && empty($faculty->deleted_by)) {
                $faculty->deleted_by = auth()->id();
                $faculty->saveQuietly();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function partnerInstitute(): BelongsTo
    {
        return $this->belongsTo(PartnerInstitute::class, 'partner_institute_id');
    }

    public function getFacultyTypeLabelAttribute(): string
    {
        return self::FACULTY_TYPES[$this->faculty_type] ?? ucfirst($this->faculty_type ?? 'Regular');
    }

    public function classSchedules(): BelongsToMany
    {
        return $this->belongsToMany(ClassSchedule::class, 'class_schedule_faculty')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeForDepartment(Builder $query, string $dept): Builder
    {
        return $query->where('department', $dept);
    }

    public function getInitialsAttribute(): string
    {
        $words = explode(' ', trim($this->name));
        $initials = '';
        foreach (array_slice($words, 0, 2) as $word) {
            $initials .= mb_substr($word, 0, 1);
        }

        return strtoupper($initials ?: 'FA');
    }

    public function getAvatarUrlAttribute(): ?string
    {
        if ($this->photo_path) {
            return Storage::disk('public')->url($this->photo_path);
        }

        if ($this->avatar_path) {
            return Storage::disk('public')->url($this->avatar_path);
        }

        if ($this->user?->avatar_url) {
            return $this->user->avatar_url;
        }

        return null;
    }

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->getAvatarUrlAttribute();
    }

    public function getCvUrlAttribute(): ?string
    {
        return $this->cv_path ? Storage::disk('public')->url($this->cv_path) : null;
    }

    public function getCvFileNameAttribute(): ?string
    {
        return $this->cv_path ? basename($this->cv_path) : null;
    }
}
