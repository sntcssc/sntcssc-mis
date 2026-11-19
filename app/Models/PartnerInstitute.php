<?php

namespace App\Models;

use App\Concerns\Auditable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string|null $image_path
 * @property string|null $website_url
 * @property string|null $email
 * @property string|null $mobile
 * @property string|null $phone
 * @property Carbon|null $start_date
 * @property Carbon|null $end_date
 * @property string $status
 * @property string|null $official_name
 * @property string|null $official_designation
 * @property string|null $official_contact
 * @property string|null $official_email
 * @property string|null $address
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, Faculty> $faculties
 * @property-read User|null $creator
 * @property-read User|null $updater
 */
class PartnerInstitute extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_MOU_EXPIRED = 'mou_expired';

    public const STATUS_PENDING = 'pending';

    public const STATUSES = [
        self::STATUS_ACTIVE => 'Active',
        self::STATUS_INACTIVE => 'Inactive',
        self::STATUS_MOU_EXPIRED => 'MoU Expired',
        self::STATUS_PENDING => 'Pending Onboarding',
    ];

    protected $table = 'partner_institutes';

    protected $fillable = [
        'code',
        'name',
        'description',
        'image_path',
        'website_url',
        'email',
        'mobile',
        'phone',
        'start_date',
        'end_date',
        'status',
        'official_name',
        'official_designation',
        'official_contact',
        'official_email',
        'address',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (PartnerInstitute $institute) {
            foreach (['code', 'description', 'image_path', 'website_url', 'email', 'mobile', 'phone', 'start_date', 'end_date', 'official_name', 'official_designation', 'official_contact', 'official_email', 'address'] as $field) {
                if (isset($institute->attributes[$field]) && is_string($institute->attributes[$field]) && trim($institute->attributes[$field]) === '') {
                    $institute->attributes[$field] = null;
                }
            }
        });

        static::creating(function (PartnerInstitute $institute) {
            if (empty($institute->code)) {
                $year = date('Y');
                $count = static::withTrashed()->whereYear('created_at', $year)->count() + 1;
                $institute->code = sprintf('INST-%s-%03d', $year, $count);
            }
        });
    }

    // ==========================================
    // Relationships
    // ==========================================

    public function faculties(): HasMany
    {
        return $this->hasMany(Faculty::class, 'partner_institute_id');
    }

    public function batches(): BelongsToMany
    {
        return $this->belongsToMany(Batch::class, 'partner_institute_batches')->withTimestamps();
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'partner_institute_courses')->withTimestamps();
    }

    public function sections(): BelongsToMany
    {
        return $this->belongsToMany(Section::class, 'partner_institute_sections')->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // ==========================================
    // Accessors
    // ==========================================

    public function getImageUrlAttribute(): ?string
    {
        if (empty($this->image_path)) {
            return null;
        }

        if (str_starts_with($this->image_path, 'http://') || str_starts_with($this->image_path, 'https://')) {
            return $this->image_path;
        }

        return Storage::disk('public')->url($this->image_path);
    }

    public function getStatusBadgeColorAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE => 'emerald',
            self::STATUS_INACTIVE => 'zinc',
            self::STATUS_MOU_EXPIRED => 'rose',
            self::STATUS_PENDING => 'amber',
            default => 'zinc',
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst(str_replace('_', ' ', $this->status));
    }

    public function getIsMouActiveAttribute(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        if ($this->end_date && $this->end_date->isPast()) {
            return false;
        }

        return true;
    }

    // ==========================================
    // Scopes
    // ==========================================

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (empty($term)) {
            return $query;
        }

        $term = '%'.trim($term).'%';

        return $query->where(function (Builder $q) use ($term) {
            $q->where('name', 'like', $term)
                ->orWhere('code', 'like', $term)
                ->orWhere('email', 'like', $term)
                ->orWhere('mobile', 'like', $term)
                ->orWhere('phone', 'like', $term)
                ->orWhere('official_name', 'like', $term)
                ->orWhere('official_email', 'like', $term)
                ->orWhere('official_contact', 'like', $term);
        });
    }
}
