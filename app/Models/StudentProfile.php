<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Services\UserStudentSyncService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $father_name
 * @property string|null $mother_name
 * @property Carbon|null $dob
 * @property string|null $gender
 * @property string|null $category
 * @property string|null $verified_category
 * @property int|null $verified_by
 * @property Carbon|null $verified_at
 * @property string|null $category_remark
 * @property float|null $distance
 * @property string|null $subcaste
 * @property string|null $cert_no
 * @property Carbon|null $issued_date
 * @property string|null $issued_by
 * @property bool $is_pwbd
 * @property string|null $pwbd_type
 * @property int $pwd_percentage
 * @property string|null $occupation
 * @property bool $is_working_professional
 * @property string|null $father_occupation
 * @property string|null $mother_occupation
 * @property float|null $family_income
 * @property string|null $highest_edu
 * @property string|null $school_language
 * @property string|null $upsc_community
 * @property string|null $activities
 * @property string|null $hobbies
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $user
 * @property-read User|null $verifier
 * @property-read Collection<int, Enrollment> $enrollments
 */
class StudentProfile extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'father_name',
        'mother_name',
        'dob',
        'gender',
        'category',
        'verified_category',
        'verified_by',
        'verified_at',
        'category_remark',
        'distance',
        'subcaste',
        'creamy_layer',
        'cert_no',
        'issued_date',
        'issued_by',
        'is_pwbd',
        'pwbd_type',
        'pwd_percentage',
        'pwbd_cert_no',
        'pwbd_issued_date',
        'pwbd_issued_by',
        'occupation',
        'is_working_professional',
        'father_occupation',
        'mother_occupation',
        'family_income',
        'highest_edu',
        'school_language',
        'upsc_community',
        'activities',
        'hobbies',
    ];

    protected function casts(): array
    {
        return [
            'dob' => 'date',
            'issued_date' => 'date',
            'pwbd_issued_date' => 'date',
            'verified_at' => 'datetime',
            'is_pwbd' => 'boolean',
            'is_working_professional' => 'boolean',
            'pwd_percentage' => 'integer',
            'family_income' => 'decimal:2',
            'distance' => 'decimal:2',
        ];
    }

    public function setDobAttribute(mixed $value): void
    {
        if (empty($value)) {
            $this->attributes['dob'] = null;
        } elseif ($value instanceof \DateTimeInterface) {
            $this->attributes['dob'] = $value->format('Y-m-d');
        } else {
            try {
                $this->attributes['dob'] = Carbon::parse($value)->format('Y-m-d');
            } catch (\Throwable) {
                $this->attributes['dob'] = null;
            }
        }
    }

    public function setIssuedDateAttribute(mixed $value): void
    {
        if (empty($value)) {
            $this->attributes['issued_date'] = null;
        } elseif ($value instanceof \DateTimeInterface) {
            $this->attributes['issued_date'] = $value->format('Y-m-d');
        } else {
            try {
                $this->attributes['issued_date'] = Carbon::parse($value)->format('Y-m-d');
            } catch (\Throwable) {
                $this->attributes['issued_date'] = null;
            }
        }
    }

    public function setPwbdIssuedDateAttribute(mixed $value): void
    {
        if (empty($value)) {
            $this->attributes['pwbd_issued_date'] = null;
        } elseif ($value instanceof \DateTimeInterface) {
            $this->attributes['pwbd_issued_date'] = $value->format('Y-m-d');
        } else {
            try {
                $this->attributes['pwbd_issued_date'] = Carbon::parse($value)->format('Y-m-d');
            } catch (\Throwable) {
                $this->attributes['pwbd_issued_date'] = null;
            }
        }
    }

    public function setFamilyIncomeAttribute(mixed $value): void
    {
        $this->attributes['family_income'] = (is_string($value) && trim($value) === '') || is_null($value) ? null : (float) $value;
    }

    public function setDistanceAttribute(mixed $value): void
    {
        $this->attributes['distance'] = (is_string($value) && trim($value) === '') || is_null($value) ? null : (float) $value;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'user_id', 'user_id');
    }

    public function getHighestQualificationAttribute(): ?string
    {
        return $this->highest_edu;
    }

    public function setHighestQualificationAttribute(?string $value): void
    {
        $this->attributes['highest_edu'] = $value;
    }

    public function getIsPwdAttribute(): bool
    {
        return (bool) $this->is_pwbd;
    }

    public function setIsPwdAttribute($value): void
    {
        $this->attributes['is_pwbd'] = (bool) $value;
    }

    public function getPwdTypeAttribute(): ?string
    {
        return $this->pwbd_type;
    }

    public function setPwdTypeAttribute(?string $value): void
    {
        $this->attributes['pwbd_type'] = $value;
    }

    protected static function booted(): void
    {
        static::saved(function (StudentProfile $profile) {
            UserStudentSyncService::syncFromProfile($profile);
        });
    }
}
